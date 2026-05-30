<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CustomerStatusBadgeService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;

class UpdateCustomerStatusBadges extends Command
{
    protected $signature = 'customers:update-status-badges';

    protected $description = 'Update customer status and status_badges based on readings, payments, and billing date.';

    public function handle(CustomerStatusBadgeService $statusBadgeService): int
    {
        /*
        * Important:
        * Set APP_TIMEZONE=Asia/Manila in .env also.
        */
        $timezone = config('app.timezone', 'Asia/Manila');

        $today = Carbon::now($timezone)->startOfDay();

        $refreshed = 0;
        $skippedDisconnected = 0;

        $customerRoles = ['user', 'users', 'customer'];

        User::query()
            ->whereRaw('LOWER(TRIM(role)) IN (?, ?, ?)', $customerRoles)
            ->orderBy('id')
            ->chunkById(200, function ($customers) use (
                $statusBadgeService,
                $today,
                &$refreshed,
                &$skippedDisconnected
            ) {
                foreach ($customers as $customer) {
                    /*
                    * Disconnected accounts are manually controlled by operator/admin.
                    * Scheduler must not touch status or status_badges.
                    */
                    if (strtolower(trim((string) $customer->status)) === 'disconnected') {
                        $skippedDisconnected++;
                        continue;
                    }

                    /*
                    * All rules are now handled inside CustomerStatusBadgeService:
                    * - setup
                    * - ok
                    * - due
                    * - delinquent
                    * - for_reading
                    * - partially_paid
                    * - disconnected protection
                    */
                    $statusBadgeService->refresh($customer->fresh(), $today->copy());

                    $refreshed++;
                }
            });

        $this->info("Today: {$today->toDateString()}");
        $this->info("Customer status/badges refreshed: {$refreshed}");
        $this->info("Disconnected customers skipped: {$skippedDisconnected}");

        return self::SUCCESS;
    }
}