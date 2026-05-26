<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\ReadingPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerBillingController extends Controller
{
    public function billings(Request $request, User $customer)
    {
        $auth = $request->user();

        if (!$auth) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) $auth->role);

        if (!in_array($role, ['master', 'admin', 'teller', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if ($customer->role !== 'user') {
            return response()->json([
                'message' => 'Selected account is not a customer account.',
            ], 422);
        }

        $readings = Reading::query()
            ->where('user_id', $customer->id)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $billings = $readings
            ->map(function ($reading) {
                $amountDue = (float) ($reading->amount_due ?? 0);
                $amountPaid = (float) ($reading->amount_paid ?? 0);

                /*
                 * Safer balance calculation.
                 * Uses amount_due - amount_paid, but falls back to readings.balance
                 * if needed for older rows.
                 */
                $computedBalance = max($amountDue - $amountPaid, 0);
                $storedBalance = (float) ($reading->balance ?? 0);

                $balance = $computedBalance > 0
                    ? $computedBalance
                    : max($storedBalance, 0);

                if ($balance <= 0) {
                    $paymentStatus = 'paid';
                } elseif ($amountPaid > 0) {
                    $paymentStatus = 'partial';
                } else {
                    $paymentStatus = 'unpaid';
                }

                return [
                    'id' => $reading->id,
                    'reading_id' => $reading->id,

                    'date' => optional($reading->date)->format('Y-m-d') ?? $reading->date,
                    'bill_date' => optional($reading->date)->format('M j, Y') ?? $reading->date,

                    'previous_reading' => (int) ($reading->previous_reading ?? 0),
                    'current_reading' => (int) ($reading->current_reading ?? 0),
                    'usage' => (int) ($reading->usage ?? 0),

                    'amount_due' => round($amountDue, 2),
                    'amount_paid' => round($amountPaid, 2),
                    'balance' => round($balance, 2),

                    'payment_status' => $paymentStatus,
                    'status' => $reading->status,
                ];
            })
            ->filter(function ($billing) {
                return (float) $billing['balance'] > 0;
            })
            ->values();

        $totalBalance = $billings->sum('balance');

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'account_no' => $customer->account_number,
                'account_number' => $customer->account_number,
                'name' => $customer->name ?? $customer->account_name ?? '',
                'account_name' => $customer->account_name ?? $customer->name ?? '',
                'barangay' => $customer->barangay,
                'account_type' => $customer->account_type,
                'status' => $customer->status,
                'status_badges' => $this->normalizeStatusBadges($customer->status_badges),
            ],

            /*
             * Return both names so frontend can use either:
             * data or billings.
             */
            'data' => $billings,
            'billings' => $billings,

            'total_balance' => round((float) $totalBalance, 2),
        ]);
    }

    public function pay(Request $request, User $customer)
    {
        $auth = $request->user();

        if (!$auth) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) $auth->role);

        if (!in_array($role, ['master', 'admin', 'teller', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if ($customer->role !== 'user') {
            return response()->json([
                'message' => 'Selected account is not a customer account.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],

            'reading_ids' => ['nullable', 'array'],
            'reading_ids.*' => ['integer'],

            'selected_reading_ids' => ['nullable', 'array'],
            'selected_reading_ids.*' => ['integer'],

            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'or_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($validated, $customer, $auth) {
            $paymentAmount = round((float) $validated['amount'], 2);
            $remainingPayment = $paymentAmount;

            $selectedReadingIds = $validated['selected_reading_ids']
                ?? $validated['reading_ids']
                ?? [];

            $readingsQuery = Reading::query()
                ->where('user_id', $customer->id);

            if (is_array($selectedReadingIds) && count($selectedReadingIds) > 0) {
                $readingsQuery->whereIn('id', $selectedReadingIds);
            }

            $readings = $readingsQuery
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($readings->isEmpty()) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'No billings selected for payment.',
                ];
            }

            /*
            * Do not attach computed values directly to the Eloquent model.
            * That causes Laravel to try saving computed_balance_for_payment
            * as if it is a real column.
            */
            $payableReadings = $readings
                ->map(function ($reading) {
                    $amountDue = (float) ($reading->amount_due ?? 0);
                    $amountPaid = (float) ($reading->amount_paid ?? 0);

                    $computedBalance = max($amountDue - $amountPaid, 0);
                    $storedBalance = (float) ($reading->balance ?? 0);

                    $balance = $computedBalance > 0
                        ? $computedBalance
                        : max($storedBalance, 0);

                    return [
                        'reading' => $reading,
                        'balance' => round($balance, 2),
                    ];
                })
                ->filter(function ($item) {
                    return (float) $item['balance'] > 0;
                })
                ->values();

            if ($payableReadings->isEmpty()) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The selected billing is already fully paid.',
                ];
            }

            $totalSelectedBalance = round(
                (float) $payableReadings->sum('balance'),
                2
            );

            if ($paymentAmount > $totalSelectedBalance) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'Payment amount is greater than the selected balance.',
                    'balance' => $totalSelectedBalance,
                ];
            }

            $createdPayments = [];

            foreach ($payableReadings as $item) {
                if ($remainingPayment <= 0) {
                    break;
                }

                $reading = $item['reading'];
                $readingBalance = round((float) $item['balance'], 2);

                if ($readingBalance <= 0) {
                    continue;
                }

                $allocationAmount = min($remainingPayment, $readingBalance);
                $allocationAmount = round($allocationAmount, 2);

                if ($allocationAmount <= 0) {
                    continue;
                }

                $payment = ReadingPayment::create([
                    'reading_id' => $reading->id,
                    'user_id' => $customer->id,
                    'teller_user_id' => $auth->id,
                    'amount' => $allocationAmount,
                    'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'or_number' => $validated['or_number'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                ]);

                $createdPayments[] = $payment;

                $newAmountPaid = round(((float) $reading->amount_paid) + $allocationAmount, 2);
                $amountDue = round((float) $reading->amount_due, 2);
                $newBalance = max($amountDue - $newAmountPaid, 0);

                if ($newBalance <= 0) {
                    $paymentStatus = 'paid';
                } elseif ($newAmountPaid > 0) {
                    $paymentStatus = 'partial';
                } else {
                    $paymentStatus = 'unpaid';
                }

                /*
                * Your readings.status column may still only allow paid/unpaid.
                * So payment_status gets unpaid/partial/paid,
                * while status stays safe as paid/unpaid.
                */
                $safeReadingStatus = $paymentStatus === 'paid'
                    ? 'paid'
                    : 'unpaid';

                $reading->update([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'payment_status' => $paymentStatus,
                    'status' => $safeReadingStatus,
                ]);

                $remainingPayment = round($remainingPayment - $allocationAmount, 2);
            }

            $this->recalculateCustomerBillingStatus($customer->id);

            $freshCustomer = User::query()
                ->whereKey($customer->id)
                ->first();

            return [
                'error' => false,
                'payments' => $createdPayments,
                'customer' => $freshCustomer,
                'amount_paid' => $paymentAmount,
                'remaining_payment' => $remainingPayment,
            ];
        });

        if ($result['error'] ?? false) {
            return response()->json([
                'message' => $result['message'],
                'balance' => $result['balance'] ?? null,
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'message' => 'Payment processed successfully.',
            'amount_paid' => $result['amount_paid'],
            'payments' => $result['payments'],
            'customer' => $result['customer'],
        ], 201);
    }

    private function recalculateCustomerBillingStatus(int $customerId): void
    {
        $customer = User::query()
            ->whereKey($customerId)
            ->first();

        if (!$customer) {
            return;
        }

        /*
         * Disconnected is manual.
         * Never overwrite from payment logic.
         */
        if ($customer->status === 'disconnected') {
            return;
        }

        $readings = Reading::query()
            ->where('user_id', $customerId)
            ->get();

        $openBillCount = 0;
        $hasPartial = false;

        foreach ($readings as $reading) {
            $amountDue = (float) ($reading->amount_due ?? 0);
            $amountPaid = (float) ($reading->amount_paid ?? 0);

            $balance = max($amountDue - $amountPaid, 0);

            if ($balance > 0) {
                $openBillCount++;
            }

            if ($amountPaid > 0 && $balance > 0) {
                $hasPartial = true;
            }
        }

        $badges = [];

        if ($openBillCount >= 2) {
            $status = 'delinquent';
            $badges[] = 'due';
            $badges[] = 'delinquent';
        } elseif ($openBillCount === 1) {
            $status = 'due';
            $badges[] = 'due';
        } else {
            $status = 'ok';
        }

        if ($hasPartial) {
            $badges[] = 'partially_paid';
        }

        $customer->update([
            'status' => $status,
            'status_badges' => array_values(array_unique($badges)),
        ]);
    }

    private function normalizeStatusBadges($badges): array
    {
        if (is_string($badges)) {
            $decoded = json_decode($badges, true);
            $badges = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($badges)) {
            return [];
        }

        return array_values($badges);
    }
}