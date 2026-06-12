<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    /**
     * GET /api/v1/admin/dashboard?usage_days=30
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        $role = strtolower((string) ($actor->role ?? ''));

        if (! in_array($role, ['master', 'admin', 'operator', 'teller'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $usageDays = (int) $request->query('usage_days', 30);
        $usageDays = max(1, min($usageDays, 365));

        $today = Carbon::today();
        $usageStartDate = $today->copy()->subDays($usageDays - 1)->toDateString();

        $customerQuery = User::query()->where('role', 'user');

        $statusCounts = $this->getCustomerStatusCounts();

        $totalClients = (clone $customerQuery)->count();
        $totalReaders = User::query()->where('role', 'reader')->count();
        $totalTellers = User::query()->where('role', 'teller')->count();
        $totalOperators = User::query()->where('role', 'operator')->count();

        $totalDue = $this->getTotalReceivables();
        $unpaidAccounts = $this->getUnpaidAccountsCount();
        $paymentCounts = $this->getPaymentStatusCounts();

        $billedThisCycle = $this->getBilledThisCycleCount($today);
        $forReading = $this->getForReadingCount($today);
        $totalUsage = $this->getTotalUsage($usageStartDate);

        $untaggedCoordinates = $this->getUntaggedCoordinatesCount();
        $metersForSetup = $this->getMetersForSetupCount();

        return response()->json([
            'message' => 'Dashboard loaded successfully.',
            'data' => [
                'total_clients' => $totalClients,
                'total_customers' => $totalClients,
                'total_readers' => $totalReaders,
                'total_tellers' => $totalTellers,
                'total_operators' => $totalOperators,

                'total_due' => $totalDue,
                'total_balance' => $totalDue,
                'accounts_receivable' => $totalDue,

                'unpaid_accounts' => $unpaidAccounts,
                'unpaid_bills_count' => (int) ($paymentCounts['unpaid'] ?? 0),
                'partial_bills_count' => (int) ($paymentCounts['partial'] ?? 0),
                'paid_bills_count' => (int) ($paymentCounts['paid'] ?? 0),

                'billed_ok' => $billedThisCycle,
                'billed_this_cycle' => $billedThisCycle,
                'readings_this_cycle' => $billedThisCycle,

                'for_reading' => $forReading,
                'for_reading_count' => $forReading,
                'queue_for_reading' => $forReading,

                'setup' => (int) ($statusCounts['setup'] ?? 0),
                'setup_count' => (int) ($statusCounts['setup'] ?? 0),
                'delinquents' => (int) ($statusCounts['delinquent'] ?? 0),
                'delinquent_count' => (int) ($statusCounts['delinquent'] ?? 0),
                'disconnected' => (int) ($statusCounts['disconnected'] ?? 0),
                'disconnected_count' => (int) ($statusCounts['disconnected'] ?? 0),

                'total_usage' => $totalUsage,
                'water_usage' => $totalUsage,
                'usage_days' => $usageDays,
                'usage_start_date' => $usageStartDate,
                'usage_end_date' => $today->toDateString(),

                'untagged_coordinates_count' => $untaggedCoordinates,
                'meters_for_setup_count' => $metersForSetup,

                'status_counts' => $statusCounts,
                'payment_counts' => $paymentCounts,

                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    private function getCustomerStatusCounts(): array
    {
        $default = [
            'ok' => 0,
            'setup' => 0,
            'due' => 0,
            'delinquent' => 0,
            'for_reading' => 0,
            'partially_paid' => 0,
            'disconnected' => 0,
        ];

        if (! Schema::hasColumn('users', 'status')) {
            return $default;
        }

        $rows = User::query()
            ->where('role', 'user')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $this->normalizeStatusKey($row->status);

            if (! array_key_exists($status, $default)) {
                $default[$status] = 0;
            }

            $default[$status] += (int) $row->total;
        }

        return $default;
    }

    private function getPaymentStatusCounts(): array
    {
        $default = [
            'paid' => 0,
            'unpaid' => 0,
            'partial' => 0,
        ];

        if (! Schema::hasTable('readings')) {
            return $default;
        }

        $statusColumn = null;

        if (Schema::hasColumn('readings', 'payment_status')) {
            $statusColumn = 'payment_status';
        } elseif (Schema::hasColumn('readings', 'status')) {
            $statusColumn = 'status';
        }

        if (! $statusColumn) {
            return $default;
        }

        $rows = Reading::query()
            ->select($statusColumn, DB::raw('COUNT(*) as total'))
            ->groupBy($statusColumn)
            ->get();

        foreach ($rows as $row) {
            $status = strtolower(trim((string) $row->{$statusColumn}));

            if (in_array($status, ['paid', 'pd'], true)) {
                $default['paid'] += (int) $row->total;
            } elseif (in_array($status, ['partial', 'partially_paid', 'partial_paid'], true)) {
                $default['partial'] += (int) $row->total;
            } else {
                $default['unpaid'] += (int) $row->total;
            }
        }

        return $default;
    }

    private function getTotalReceivables(): float
    {
        if (Schema::hasTable('readings') && Schema::hasColumn('readings', 'balance')) {
            return (float) Reading::query()
                ->where('balance', '>', 0)
                ->sum('balance');
        }

        if (Schema::hasColumn('users', 'balance')) {
            return (float) User::query()
                ->where('role', 'user')
                ->where('balance', '>', 0)
                ->sum('balance');
        }

        return 0.0;
    }

    private function getUnpaidAccountsCount(): int
    {
        if (Schema::hasTable('readings') && Schema::hasColumn('readings', 'balance')) {
            return (int) Reading::query()
                ->where('balance', '>', 0)
                ->distinct('user_id')
                ->count('user_id');
        }

        if (Schema::hasColumn('users', 'balance')) {
            return (int) User::query()
                ->where('role', 'user')
                ->where('balance', '>', 0)
                ->count();
        }

        return 0;
    }

    private function getBilledThisCycleCount(Carbon $today): int
    {
        /*
         * Dashboard card: Billed This Cycle / Meters Reported OK
         *
         * Requested rule:
         * Count customer accounts only where:
         * - users.role = user
         * - users.status = ok
         */
        if (! Schema::hasColumn('users', 'status')) {
            return 0;
        }

        return (int) User::query()
            ->where('role', 'user')
            ->where('status', 'ok')
            ->count();
    }

    private function getForReadingCount(Carbon $today): int
    {
        $query = User::query()
            ->where('role', 'user');

        if (Schema::hasColumn('users', 'status')) {
            $query->whereNotIn('status', ['disconnected', 'setup'])
                ->where(function ($q) use ($today) {
                    $q->where('status', 'for_reading');

                    if (Schema::hasColumn('users', 'next_reading_date')) {
                        $q->orWhereDate('next_reading_date', '<=', $today->toDateString());
                    }
                });
        } elseif (Schema::hasColumn('users', 'next_reading_date')) {
            $query->whereDate('next_reading_date', '<=', $today->toDateString());
        } else {
            return 0;
        }

        return (int) $query->count();
    }

    private function getTotalUsage(string $usageStartDate): float
    {
        if (! Schema::hasTable('readings') || ! Schema::hasColumn('readings', 'date')) {
            return 0.0;
        }

        $query = Reading::query()
            ->whereDate('date', '>=', $usageStartDate);

        if (Schema::hasColumn('readings', 'usage')) {
            return (float) $query->sum('usage');
        }

        if (Schema::hasColumn('readings', 'consumption')) {
            return (float) $query->sum('consumption');
        }

        if (
            Schema::hasColumn('readings', 'current_reading') &&
            Schema::hasColumn('readings', 'previous_reading')
        ) {
            return (float) $query->sum(DB::raw('GREATEST(current_reading - previous_reading, 0)'));
        }

        return 0.0;
    }

    private function getUntaggedCoordinatesCount(): int
    {
        $query = User::query()
            ->where('role', 'user');

        if (Schema::hasColumn('users', 'latitude') && Schema::hasColumn('users', 'longitude')) {
            $query->where(function ($q) {
                $q->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhere('latitude', '')
                    ->orWhere('longitude', '')
                    ->orWhere('latitude', 0)
                    ->orWhere('longitude', 0);
            });

            return (int) $query->count();
        }

        return 0;
    }

    private function getMetersForSetupCount(): int
    {
        $query = User::query()
            ->where('role', 'user');

        if (Schema::hasColumn('users', 'status')) {
            $query->where('status', 'setup');
            return (int) $query->count();
        }

        if (Schema::hasColumn('users', 'previous_reading')) {
            $query->where(function ($q) {
                $q->whereNull('previous_reading')
                    ->orWhere('previous_reading', '<=', 0);
            });

            return (int) $query->count();
        }

        return 0;
    }

    private function normalizeStatusKey($status): string
    {
        $raw = strtolower(trim((string) $status));
        $raw = str_replace(['-', ' '], '_', $raw);

        if (in_array($raw, ['for_rdng', 'for_meter_reading'], true)) {
            return 'for_reading';
        }

        if (in_array($raw, ['partial', 'partial_paid'], true)) {
            return 'partially_paid';
        }

        if ($raw === 'deliquent') {
            return 'delinquent';
        }

        return $raw ?: 'ok';
    }
}
