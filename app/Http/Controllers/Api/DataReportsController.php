<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataReportsController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        $role = strtolower((string) ($auth->role ?? ''));

        if (in_array($role, ['user', 'users', 'reader'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $paymentFrom = $this->normalizeDate(
            $request->query('payment_from'),
            Carbon::now()->startOfMonth()
        );
        $paymentTo = $this->normalizeDate(
            $request->query('payment_to'),
            Carbon::now()
        );
        $paymentBarangay = $this->normalizeBarangay($request->query('payment_barangay'));

        $usageFrom = $this->normalizeDate(
            $request->query('usage_from'),
            Carbon::now()->startOfMonth()
        );
        $usageTo = $this->normalizeDate(
            $request->query('usage_to'),
            Carbon::now()
        );
        $usageBarangay = $this->normalizeBarangay($request->query('usage_barangay'));

        return response()->json([
            'payment_summary' => $this->buildPaymentSummary(
                $paymentFrom,
                $paymentTo,
                $paymentBarangay
            ),
            'usage_summary' => $this->buildUsageSummary(
                $usageFrom,
                $usageTo,
                $usageBarangay
            ),
            'filters' => [
                'payment_from' => $paymentFrom->toDateString(),
                'payment_to' => $paymentTo->toDateString(),
                'payment_barangay' => $paymentBarangay ?: 'All',
                'usage_from' => $usageFrom->toDateString(),
                'usage_to' => $usageTo->toDateString(),
                'usage_barangay' => $usageBarangay ?: 'All',
            ],
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    private function buildPaymentSummary(Carbon $from, Carbon $to, ?string $barangay): array
    {
        $receivedPayments = 0.0;
        $paidAccounts = 0;

        if (Schema::hasTable('reading_payments')) {
            $paymentQuery = DB::table('reading_payments as p');

            if (Schema::hasTable('users')) {
                $paymentQuery->leftJoin('users as c', 'c.id', '=', 'p.user_id');
            }

            $dateColumn = Schema::hasColumn('reading_payments', 'payment_date')
                ? 'p.payment_date'
                : 'p.created_at';

            $paymentQuery->whereDate($dateColumn, '>=', $from->toDateString())
                ->whereDate($dateColumn, '<=', $to->toDateString());

            if ($barangay && Schema::hasTable('users') && Schema::hasColumn('users', 'barangay')) {
                $paymentQuery->where('c.barangay', $barangay);
            }

            $receivedPayments = (float) (clone $paymentQuery)->sum('p.amount');
            $paidAccounts = (int) (clone $paymentQuery)
                ->whereNotNull('p.user_id')
                ->distinct('p.user_id')
                ->count('p.user_id');
        }

        $billedMeters = 0;
        $duePayments = 0.0;
        $unpaidAccounts = 0;

        if (Schema::hasTable('readings')) {
            $readingQuery = DB::table('readings as r');

            if (Schema::hasTable('users')) {
                $readingQuery->leftJoin('users as c', 'c.id', '=', 'r.user_id');
            }

            $readingDateColumn = Schema::hasColumn('readings', 'date')
                ? 'r.date'
                : 'r.created_at';

            $readingQuery->whereDate($readingDateColumn, '>=', $from->toDateString())
                ->whereDate($readingDateColumn, '<=', $to->toDateString());

            if ($barangay && Schema::hasTable('users') && Schema::hasColumn('users', 'barangay')) {
                $readingQuery->where('c.barangay', $barangay);
            }

            $billedMeters = (int) (clone $readingQuery)->count('r.id');

            $unpaidQuery = clone $readingQuery;

            if (Schema::hasColumn('readings', 'payment_status')) {
                $unpaidQuery->where(function ($query) {
                    $query->whereNull('r.payment_status')
                        ->orWhereIn('r.payment_status', ['unpaid', 'partial', 'partially_paid']);
                });
            } elseif (Schema::hasColumn('readings', 'status')) {
                $unpaidQuery->where(function ($query) {
                    $query->whereNull('r.status')
                        ->orWhereIn('r.status', ['unpaid', 'partial', 'partially_paid']);
                });
            }

            if (Schema::hasColumn('readings', 'balance')) {
                $unpaidQuery->where('r.balance', '>', 0);
                $duePayments = (float) (clone $unpaidQuery)->sum('r.balance');
            } elseif (Schema::hasColumn('readings', 'amount_due')) {
                $duePayments = (float) (clone $unpaidQuery)->sum('r.amount_due');
            }

            $unpaidAccounts = (int) (clone $unpaidQuery)
                ->whereNotNull('r.user_id')
                ->distinct('r.user_id')
                ->count('r.user_id');
        }

        $forReading = $this->countForReadingAccounts($from, $to, $barangay);

        return [
            'received_payments' => round($receivedPayments, 2),
            'billed_meters' => $billedMeters,
            'due_payments' => round($duePayments, 2),
            'paid_accounts' => $paidAccounts,
            'for_reading' => $forReading,
            'unpaid_accounts' => $unpaidAccounts,
        ];
    }

    private function buildUsageSummary(Carbon $from, Carbon $to, ?string $barangay): array
    {
        $usage = 0.0;

        if (Schema::hasTable('readings')) {
            $usageColumn = Schema::hasColumn('readings', 'usage')
                ? 'r.usage'
                : (Schema::hasColumn('readings', 'consumption') ? 'r.consumption' : null);

            if ($usageColumn) {
                $query = DB::table('readings as r');

                if (Schema::hasTable('users')) {
                    $query->leftJoin('users as c', 'c.id', '=', 'r.user_id');
                }

                $readingDateColumn = Schema::hasColumn('readings', 'date')
                    ? 'r.date'
                    : 'r.created_at';

                $query->whereDate($readingDateColumn, '>=', $from->toDateString())
                    ->whereDate($readingDateColumn, '<=', $to->toDateString());

                if ($barangay && Schema::hasTable('users') && Schema::hasColumn('users', 'barangay')) {
                    $query->where('c.barangay', $barangay);
                }

                $usage = (float) $query->sum($usageColumn);
            }
        }

        return [
            'usage_cubic_meters' => round($usage, 2),
        ];
    }

    private function countForReadingAccounts(Carbon $from, Carbon $to, ?string $barangay): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        $query = DB::table('users');

        if (Schema::hasColumn('users', 'role')) {
            $query->where(function ($inner) {
                $inner->whereNull('role')
                    ->orWhereIn('role', ['user', 'users', 'customer']);
            });
        }

        if (Schema::hasColumn('users', 'status')) {
            $query->where('status', 'for_reading');
        }

        if ($barangay && Schema::hasColumn('users', 'barangay')) {
            $query->where('barangay', $barangay);
        }

        if (Schema::hasColumn('users', 'next_reading_date')) {
            $query->whereDate('next_reading_date', '>=', $from->toDateString())
                ->whereDate('next_reading_date', '<=', $to->toDateString());
        }

        return (int) $query->count();
    }

    private function normalizeDate($value, Carbon $fallback): Carbon
    {
        try {
            if (! $value) {
                return $fallback->copy()->startOfDay();
            }

            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return $fallback->copy()->startOfDay();
        }
    }

    private function normalizeBarangay($value): ?string
    {
        $barangay = trim((string) ($value ?? ''));

        if ($barangay === '' || strtolower($barangay) === 'all') {
            return null;
        }

        return $barangay;
    }
}
