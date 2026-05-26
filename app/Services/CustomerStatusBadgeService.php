<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\User;
use Illuminate\Support\Carbon;

class CustomerStatusBadgeService
{
    public const SETUP = 'setup';
    public const OK = 'ok';
    public const DELINQUENT = 'delinquent';
    public const DUE = 'due';
    public const FOR_READING = 'for_reading';
    public const PARTIALLY_PAID = 'partially_paid';
    public const DISCONNECTED = 'disconnected';

    /**
     * Refresh one customer's status and status_badges.
     *
     * Main rules:
     * - disconnected is manual and must never be overwritten.
     * - setup = starting_meter is 0 and previous_reading is 0.
     * - for_reading = billing_date is today and no reading exists this month.
     * - for_reading is the highest priority main status.
     * - due = one open/unpaid billing.
     * - delinquent = two or more open/unpaid billings.
     * - partially_paid is a badge when any billing has partial payment.
     * - ok has no badge.
     */
    public function refresh(User $customer, ?Carbon $today = null): User
    {
        $today = $today ?: now();

        /**
         * Only customer accounts should be evaluated.
         */
        $role = strtolower(trim((string) $customer->role));

        if (!in_array($role, ['user', 'users', 'customer'], true)) {
            return $customer;
        }

        /**
         * Disconnected is manually controlled by operator/admin.
         * Never update status or status_badges.
         */
        if (strtolower(trim((string) $customer->status)) === self::DISCONNECTED) {
            return $customer;
        }

        /**
         * Setup:
         * New condition:
         * starting_meter = 0 AND previous_reading = 0
         */
        if ($this->isSetupAccount($customer)) {
            return $this->saveStatusAndBadges($customer, self::SETUP, [
                self::SETUP,
            ]);
        }

        $badges = [];

        $hasReadingThisMonth = Reading::query()
            ->where('user_id', $customer->id)
            ->whereBetween('date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ])
            ->exists();

        $isForReading = $this->isBillingDay($customer, $today) && !$hasReadingThisMonth;

        $billingSummary = $this->getBillingSummary($customer);

        $openBillCount = $billingSummary['open_bill_count'];
        $hasPartialPayment = $billingSummary['has_partial_payment'];

        /**
         * Add for_reading badge when applicable.
         * This will become the main status later because it is highest priority.
         */
        if ($isForReading) {
            $badges[] = self::FOR_READING;
        }

        /**
         * Due / Delinquent badges.
         *
         * If delinquent, we also include due badge because the account
         * is still due. This supports filters like:
         * ["due", "delinquent"]
         */
        if ($openBillCount >= 2) {
            $badges[] = self::DUE;
            $badges[] = self::DELINQUENT;

            $billingStatus = self::DELINQUENT;
        } elseif ($openBillCount === 1) {
            $badges[] = self::DUE;

            $billingStatus = self::DUE;
        } else {
            $billingStatus = self::OK;
        }

        /**
         * Partially paid is a badge, not the main status.
         */
        if ($hasPartialPayment) {
            $badges[] = self::PARTIALLY_PAID;
        }

        /**
         * Main status priority:
         * 1. for_reading
         * 2. delinquent
         * 3. due
         * 4. ok
         */
        if ($isForReading) {
            $status = self::FOR_READING;
        } else {
            $status = $billingStatus;
        }

        /**
         * OK does not need a badge.
         */
        return $this->saveStatusAndBadges($customer, $status, $badges);
    }

    public function refreshById(int $customerId): ?User
    {
        $customer = User::query()
            ->whereKey($customerId)
            ->first();

        if (!$customer) {
            return null;
        }

        return $this->refresh($customer);
    }

    private function isSetupAccount(User $customer): bool
    {
        /**
         * Setup rule:
         * Account is considered setup if BOTH values are exactly 0.
         *
         * starting_meter = 0
         * previous_reading = 0
         *
         * NULL is treated differently from 0.
         */
        return $customer->starting_meter !== null
            && $customer->previous_reading !== null
            && (float) $customer->starting_meter === 0.0
            && (float) $customer->previous_reading === 0.0;
    }

    private function isBillingDay(User $customer, Carbon $today): bool
    {
        if (!$customer->billing_date) {
            return false;
        }

        $billingDate = (int) $customer->billing_date;

        if ($billingDate < 1 || $billingDate > 31) {
            return false;
        }

        /**
         * Handles months with fewer than 31 days.
         *
         * Example:
         * billing_date = 31
         * February max day = 28 or 29
         * Customer becomes for_reading on the last day of February.
         */
        $effectiveBillingDay = min(
            $billingDate,
            (int) $today->copy()->endOfMonth()->format('d')
        );

        return (int) $today->format('d') === $effectiveBillingDay;
    }

    private function getBillingSummary(User $customer): array
    {
        $readings = Reading::query()
            ->where('user_id', $customer->id)
            ->get();

        $openBillCount = 0;
        $hasPartialPayment = false;

        foreach ($readings as $reading) {
            $balance = $this->resolveReadingBalance($reading);

            if ($balance > 0) {
                $openBillCount++;
            }

            $amountPaid = (float) ($reading->amount_paid ?? 0);
            $paymentStatus = strtolower(trim((string) ($reading->payment_status ?? '')));

            /**
             * Partial payment:
             * - has paid something
             * - still has remaining balance
             *
             * Also supports existing payment_status values:
             * partial / partially_paid
             */
            if ($amountPaid > 0 && $balance > 0) {
                $hasPartialPayment = true;
            }

            if (in_array($paymentStatus, ['partial', self::PARTIALLY_PAID], true)) {
                $hasPartialPayment = true;
            }
        }

        return [
            'open_bill_count' => $openBillCount,
            'has_partial_payment' => $hasPartialPayment,
        ];
    }

    private function resolveReadingBalance(Reading $reading): float
    {
        $amountDue = (float) ($reading->amount_due ?? 0);
        $amountPaid = (float) ($reading->amount_paid ?? 0);
        $storedBalance = (float) ($reading->balance ?? 0);

        /**
         * If amount_due exists, use the computed balance because it is safer.
         * This prevents stale readings.balance from wrongly keeping a paid bill open.
         */
        if ($reading->amount_due !== null && $amountDue > 0) {
            return round(max($amountDue - $amountPaid, 0), 2);
        }

        /**
         * Fallback for old rows where amount_due may not have been populated,
         * but balance already exists.
         */
        return round(max($storedBalance, 0), 2);
    }

    private function saveStatusAndBadges(User $customer, string $status, array $badges): User
    {
        $badges = collect($badges)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $customer->forceFill([
            'status' => $status,
            'status_badges' => $badges,
        ])->save();

        return $customer->fresh();
    }
}