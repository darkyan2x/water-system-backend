<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateCustomerStatusBadges extends Command
{
    protected $signature = 'customers:update-status-badges';

    protected $description = 'Update customer status and status_badges using next_reading_date, readings, and payments.';

    public function handle(): int
    {
        /*
        * Important:
        * Set APP_TIMEZONE=Asia/Manila in .env also.
        */
        $timezone = config('app.timezone', 'Asia/Manila');
        $today = Carbon::now($timezone)->startOfDay();

        $refreshed = 0;
        $changed = 0;
        $skippedDisconnected = 0;

        $customerRoles = ['user', 'users', 'customer'];

        User::query()
            ->whereRaw('LOWER(TRIM(role)) IN (?, ?, ?)', $customerRoles)
            ->orderBy('id')
            ->chunkById(200, function ($customers) use (
                $today,
                &$refreshed,
                &$changed,
                &$skippedDisconnected
            ) {
                foreach ($customers as $customer) {
                    if (strtolower(trim((string) $customer->status)) === 'disconnected') {
                        $skippedDisconnected++;
                        continue;
                    }

                    [$newStatus, $newBadges] = $this->resolveCustomerStatusAndBadges(
                        $customer,
                        $today->copy()
                    );

                    $currentStatus = strtolower(trim((string) $customer->status));

                    if (
                        $currentStatus !== $newStatus ||
                        !$this->badgesAreSame($customer->status_badges, $newBadges)
                    ) {
                        $customer->forceFill([
                            'status' => $newStatus,
                            'status_badges' => $this->badgesPayload($customer, $newBadges),
                        ])->save();

                        $changed++;
                    }

                    $refreshed++;
                }
            });

        $this->info("Today: {$today->toDateString()}");
        $this->info("Customer status/badges checked: {$refreshed}");
        $this->info("Customer status/badges changed: {$changed}");
        $this->info("Disconnected customers skipped: {$skippedDisconnected}");

        return self::SUCCESS;
    }

    private function resolveCustomerStatusAndBadges(User $customer, Carbon $today): array
    {
        $badges = [];

        $isSetup = $this->isSetupNeeded($customer);
        $isForReading = $this->isForReadingToday($customer, $today);
        $unpaidCount = $this->countUnpaidOrPartialReadings($customer);
        $hasPartialPayment = $this->hasPartialPayment($customer);

        if ($isSetup) {
            $badges[] = 'setup';

            return [
                'setup',
                $this->uniqueBadges($badges),
            ];
        }

        if ($isForReading) {
            $badges[] = 'for_reading';
        }

        if ($unpaidCount >= 2) {
            $badges[] = 'delinquent';
        } elseif ($unpaidCount === 1) {
            $badges[] = 'due';
        }

        if ($hasPartialPayment) {
            $badges[] = 'partially_paid';
        }

        if ($isForReading) {
            $status = 'for_reading';
        } elseif ($unpaidCount >= 2) {
            $status = 'delinquent';
        } elseif ($unpaidCount === 1) {
            $status = 'due';
        } elseif ($hasPartialPayment) {
            $status = 'partially_paid';
        } else {
            $status = 'ok';
        }

        return [
            $status,
            $this->uniqueBadges($badges),
        ];
    }

    private function isSetupNeeded(User $customer): bool
    {
        $previousReading = $customer->previous_reading;

        if ($previousReading === null || $previousReading === '') {
            return true;
        }

        return (float) $previousReading <= 0;
    }

    private function isForReadingToday(User $customer, Carbon $today): bool
    {
        if (empty($customer->next_reading_date)) {
            return false;
        }

        try {
            $nextReadingDate = $customer->next_reading_date instanceof Carbon
                ? $customer->next_reading_date->copy()->startOfDay()
                : Carbon::parse($customer->next_reading_date, $today->timezone)->startOfDay();
        } catch (\Throwable $e) {
            return false;
        }

        return $today->greaterThanOrEqualTo($nextReadingDate);
    }

    private function countUnpaidOrPartialReadings(User $customer): int
    {
        return DB::table('readings')
            ->where('user_id', $customer->id)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();
    }

    private function hasPartialPayment(User $customer): bool
    {
        return DB::table('readings')
            ->where('user_id', $customer->id)
            ->where('payment_status', 'partial')
            ->exists();
    }

    private function uniqueBadges(array $badges): array
    {
        return array_values(array_unique(array_filter($badges)));
    }

    private function badgesPayload(User $customer, array $badges)
    {
        if ($customer->hasCast('status_badges', ['array', 'json', 'collection', 'encrypted:array', 'encrypted:json'])) {
            return $badges;
        }

        return json_encode($badges);
    }

    private function badgesAreSame($currentBadges, array $newBadges): bool
    {
        if (is_string($currentBadges)) {
            $decoded = json_decode($currentBadges, true);
            $currentBadges = is_array($decoded) ? $decoded : [];
        }

        if ($currentBadges === null) {
            $currentBadges = [];
        }

        if (!is_array($currentBadges)) {
            $currentBadges = [];
        }

        $currentBadges = $this->uniqueBadges($currentBadges);
        $newBadges = $this->uniqueBadges($newBadges);

        sort($currentBadges);
        sort($newBadges);

        return $currentBadges === $newBadges;
    }
}