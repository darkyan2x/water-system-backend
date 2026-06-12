<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneralPaymentHistoryController extends Controller
{
    private string $paymentTable = 'reading_payments';

    public function index(Request $request)
    {
        $auth = $request->user();
        $role = strtolower((string) ($auth->role ?? ''));

        if (in_array($role, ['user', 'users'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (! Schema::hasTable($this->paymentTable)) {
            return $this->emptyResponse();
        }

        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);

        $paymentDateColumn = $this->firstExistingColumn($this->paymentTable, [
            'payment_date',
            'paid_at',
            'date',
            'created_at',
        ]) ?? 'created_at';

        $paymentAmountColumn = $this->firstExistingColumn($this->paymentTable, [
            'amount',
            'amount_paid',
            'paid_amount',
            'payment_amount',
        ]);

        $paymentMethodColumn = $this->firstExistingColumn($this->paymentTable, [
            'payment_method',
            'method',
            'payment_type',
            'type',
        ]);

        $orNumberColumn = $this->firstExistingColumn($this->paymentTable, [
            'or_number',
            'receipt_number',
            'reference_number',
            'reference',
            'transaction_no',
        ]);

        $remarksColumn = $this->firstExistingColumn($this->paymentTable, [
            'remarks',
            'notes',
            'description',
        ]);

        $readingIdColumn = $this->firstExistingColumn($this->paymentTable, [
            'reading_id',
            'billing_id',
        ]);

        $customerIdColumn = $this->firstExistingColumn($this->paymentTable, [
            'user_id',
            'customer_id',
        ]);

        $tellerIdColumn = $this->firstExistingColumn($this->paymentTable, [
            'teller_user_id',
            'teller_id',
            'encoder_user_id',
            'received_by_id',
            'created_by',
        ]);

        $hasReadings = $readingIdColumn && Schema::hasTable('readings');
        $readingUserColumn = $hasReadings
            ? $this->firstExistingColumn('readings', ['user_id', 'customer_id'])
            : null;

        $hasCustomers = Schema::hasTable('users') && ($customerIdColumn || ($hasReadings && $readingUserColumn));
        $hasTeller = $tellerIdColumn && Schema::hasTable('users');

        $query = DB::table($this->paymentTable . ' as p');

        if ($hasReadings) {
            $query->leftJoin('readings as r', 'r.id', '=', "p.$readingIdColumn");
        }

        if ($hasCustomers) {
            if ($customerIdColumn) {
                $query->leftJoin('users as c', 'c.id', '=', "p.$customerIdColumn");
            } elseif ($hasReadings && $readingUserColumn) {
                $query->leftJoin('users as c', 'c.id', '=', "r.$readingUserColumn");
            }
        }

        if ($hasTeller) {
            $query->leftJoin('users as t', 't.id', '=', "p.$tellerIdColumn");
        }

        $this->applyFilters(
            query: $query,
            request: $request,
            paymentDateColumn: $paymentDateColumn,
            paymentMethodColumn: $paymentMethodColumn,
            orNumberColumn: $orNumberColumn,
            hasCustomers: (bool) $hasCustomers
        );

        $summaryQuery = clone $query;

        $totalTransactions = (clone $summaryQuery)->count('p.id');
        $totalCollected = $paymentAmountColumn
            ? (float) ((clone $summaryQuery)->sum("p.$paymentAmountColumn") ?? 0)
            : 0.0;

        $breakdown = [
            'cash' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'cash'),
            'counter' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'counter'),
            'gcash' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'gcash'),
            'maya' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'maya'),
            'paymaya' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'paymaya'),
            'bank' => $this->sumByPaymentMethod($summaryQuery, $paymentAmountColumn, $paymentMethodColumn, 'bank'),
        ];

        // Frontend card expects Counter, GCash/Maya, and Bank totals.
        $breakdown['counter_total'] = $breakdown['cash'] + $breakdown['counter'];
        $breakdown['gcash_maya'] = $breakdown['gcash'] + $breakdown['maya'] + $breakdown['paymaya'];

        $selects = $this->buildSelects(
            paymentDateColumn: $paymentDateColumn,
            paymentAmountColumn: $paymentAmountColumn,
            paymentMethodColumn: $paymentMethodColumn,
            orNumberColumn: $orNumberColumn,
            remarksColumn: $remarksColumn,
            readingIdColumn: $readingIdColumn,
            customerIdColumn: $customerIdColumn,
            hasReadings: (bool) $hasReadings,
            hasCustomers: (bool) $hasCustomers,
            hasTeller: (bool) $hasTeller
        );

        $paginator = $query
            ->select($selects)
            ->orderByDesc("p.$paymentDateColumn")
            ->orderByDesc('p.id')
            ->paginate($perPage);

        $records = collect($paginator->items())->map(function ($row) {
            return [
                'id' => $row->id,
                'payment_id' => $row->payment_id,
                'reading_id' => $row->reading_id,
                'payment_date' => $row->payment_date,
                'payment_method' => $row->payment_method,
                'or_number' => $row->or_number,
                'remarks' => $row->remarks,
                'amount' => (float) ($row->amount ?? 0),
                'customer' => [
                    'id' => $row->customer_id,
                    'account_number' => $row->account_number,
                    'account_no' => $row->account_number,
                    'name' => $row->customer_name,
                    'mobile' => $row->mobile,
                    'barangay' => $row->barangay,
                    'account_type' => $row->account_type,
                ],
                'billing' => [
                    'date' => $row->billing_date,
                    'previous_reading' => (float) ($row->previous_reading ?? 0),
                    'current_reading' => (float) ($row->current_reading ?? 0),
                    'usage' => (float) ($row->reading_usage ?? 0),
                    'amount_due' => (float) ($row->amount_due ?? 0),
                    'amount_paid' => (float) ($row->amount_paid ?? 0),
                    'balance' => (float) ($row->balance ?? 0),
                    'payment_status' => $row->payment_status,
                ],
                'teller' => [
                    'id' => $row->teller_id,
                    'name' => $row->teller_name,
                ],
                'created_at' => $row->created_at,
            ];
        })->values();

        return response()->json([
            'data' => $records,
            'summary' => [
                'total_collected' => $totalCollected,
                'total_transactions' => $totalTransactions,
                'average_receipt' => $totalTransactions > 0 ? $totalCollected / $totalTransactions : 0,
                'liquidations_breakdown' => $breakdown,
                'method_breakdown' => $breakdown,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function emptyResponse()
    {
        return response()->json([
            'data' => [],
            'summary' => [
                'total_collected' => 0,
                'total_transactions' => 0,
                'average_receipt' => 0,
                'liquidations_breakdown' => [
                    'cash' => 0,
                    'counter' => 0,
                    'counter_total' => 0,
                    'gcash' => 0,
                    'maya' => 0,
                    'paymaya' => 0,
                    'gcash_maya' => 0,
                    'bank' => 0,
                ],
                'method_breakdown' => [
                    'cash' => 0,
                    'counter' => 0,
                    'counter_total' => 0,
                    'gcash' => 0,
                    'maya' => 0,
                    'paymaya' => 0,
                    'gcash_maya' => 0,
                    'bank' => 0,
                ],
            ],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 50,
                'total' => 0,
            ],
        ]);
    }

    private function applyFilters($query, Request $request, string $paymentDateColumn, ?string $paymentMethodColumn, ?string $orNumberColumn, bool $hasCustomers): void
    {
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search, $orNumberColumn, $hasCustomers) {
                if ($orNumberColumn) {
                    $q->orWhere("p.$orNumberColumn", 'like', "%{$search}%");
                }

                if ($hasCustomers) {
                    foreach (['account_number', 'account_no', 'account_name', 'name', 'full_name', 'mobile'] as $column) {
                        if (Schema::hasColumn('users', $column)) {
                            $q->orWhere("c.$column", 'like', "%{$search}%");
                        }
                    }
                }

                if (is_numeric($search)) {
                    $q->orWhere('p.id', $search);
                }
            });
        }

        $barangay = trim((string) $request->query('barangay', ''));

        if ($barangay !== '' && strtolower($barangay) !== 'all' && $hasCustomers && Schema::hasColumn('users', 'barangay')) {
            $query->where('c.barangay', $barangay);
        }

        $method = trim((string) $request->query('payment_method', ''));

        if ($method !== '' && strtolower($method) !== 'all' && $paymentMethodColumn) {
            $this->wherePaymentMethod($query, $paymentMethodColumn, $method);
        }

        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        if ($dateFrom !== '') {
            $query->whereDate("p.$paymentDateColumn", '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate("p.$paymentDateColumn", '<=', $dateTo);
        }
    }

    private function sumByPaymentMethod($baseQuery, ?string $amountColumn, ?string $methodColumn, string $method): float
    {
        if (! $amountColumn) {
            return 0.0;
        }

        $query = clone $baseQuery;

        if ($methodColumn) {
            $this->wherePaymentMethod($query, $methodColumn, $method);
        } elseif (! in_array($method, ['cash', 'counter'], true)) {
            return 0.0;
        }

        return (float) ($query->sum("p.$amountColumn") ?? 0);
    }

    private function wherePaymentMethod($query, string $methodColumn, string $method): void
    {
        $method = strtolower($method);

        $query->where(function ($q) use ($methodColumn, $method) {
            if ($method === 'cash' || $method === 'counter') {
                $q->whereRaw("LOWER(p.$methodColumn) LIKE ?", ['%cash%'])
                    ->orWhereRaw("LOWER(p.$methodColumn) LIKE ?", ['%counter%']);
                return;
            }

            if ($method === 'gcash') {
                $q->whereRaw("LOWER(p.$methodColumn) LIKE ?", ['%gcash%']);
                return;
            }

            if ($method === 'maya' || $method === 'paymaya') {
                $q->whereRaw("LOWER(p.$methodColumn) LIKE ?", ['%paymaya%'])
                    ->orWhereRaw("LOWER(p.$methodColumn) LIKE ?", ['%maya%']);
                return;
            }

            if ($method === 'bank') {
                $q->whereRaw("LOWER(p.$methodColumn) LIKE ?", ['%bank%']);
                return;
            }

            $q->whereRaw("LOWER(p.$methodColumn) = ?", [$method]);
        });
    }

    private function buildSelects(?string $paymentDateColumn, ?string $paymentAmountColumn, ?string $paymentMethodColumn, ?string $orNumberColumn, ?string $remarksColumn, ?string $readingIdColumn, ?string $customerIdColumn, bool $hasReadings, bool $hasCustomers, bool $hasTeller): array
    {
        return [
            'p.id as id',
            'p.id as payment_id',
            $readingIdColumn ? DB::raw("p.$readingIdColumn as reading_id") : DB::raw('NULL as reading_id'),
            $paymentDateColumn ? DB::raw("p.$paymentDateColumn as payment_date") : DB::raw('NULL as payment_date'),
            $paymentMethodColumn ? DB::raw("p.$paymentMethodColumn as payment_method") : DB::raw("'cash' as payment_method"),
            $orNumberColumn ? DB::raw("p.$orNumberColumn as or_number") : DB::raw("CONCAT('PAY-', p.id) as or_number"),
            $remarksColumn ? DB::raw("p.$remarksColumn as remarks") : DB::raw('NULL as remarks'),
            $paymentAmountColumn ? DB::raw("p.$paymentAmountColumn as amount") : DB::raw('0 as amount'),
            Schema::hasColumn($this->paymentTable, 'created_at') ? DB::raw('p.created_at as created_at') : DB::raw('NULL as created_at'),

            $hasCustomers ? DB::raw('c.id as customer_id') : DB::raw('NULL as customer_id'),
            $hasCustomers ? DB::raw($this->coalesceExpression('users', 'c', ['account_number', 'account_no'], '') . ' as account_number') : DB::raw("'' as account_number"),
            $hasCustomers ? DB::raw($this->coalesceExpression('users', 'c', ['account_name', 'name', 'full_name'], 'Unknown Client') . ' as customer_name') : DB::raw("'Unknown Client' as customer_name"),
            $hasCustomers ? DB::raw($this->coalesceExpression('users', 'c', ['mobile', 'contact', 'phone'], '') . ' as mobile') : DB::raw("'' as mobile"),
            $hasCustomers && Schema::hasColumn('users', 'barangay') ? DB::raw('c.barangay as barangay') : DB::raw("'N/A' as barangay"),
            $hasCustomers ? DB::raw($this->coalesceExpression('users', 'c', ['account_type', 'accountType'], '') . ' as account_type') : DB::raw("'' as account_type"),

            $hasReadings && Schema::hasColumn('readings', 'date') ? DB::raw('r.date as billing_date') : DB::raw('NULL as billing_date'),
            $hasReadings && Schema::hasColumn('readings', 'previous_reading') ? DB::raw('r.previous_reading as previous_reading') : DB::raw('0 as previous_reading'),
            $hasReadings && Schema::hasColumn('readings', 'current_reading') ? DB::raw('r.current_reading as current_reading') : DB::raw('0 as current_reading'),
            $hasReadings ? DB::raw($this->coalesceExpression('readings', 'r', ['usage', 'consumption'], 0) . ' as reading_usage') : DB::raw('0 as reading_usage'),
            $hasReadings ? DB::raw($this->coalesceExpression('readings', 'r', ['amount_due', 'amount', 'total_amount'], 0) . ' as amount_due') : DB::raw('0 as amount_due'),
            $hasReadings && Schema::hasColumn('readings', 'amount_paid') ? DB::raw('r.amount_paid as amount_paid') : DB::raw('0 as amount_paid'),
            $hasReadings && Schema::hasColumn('readings', 'balance') ? DB::raw('r.balance as balance') : DB::raw('0 as balance'),
            $hasReadings ? DB::raw($this->coalesceExpression('readings', 'r', ['payment_status', 'status'], 'paid') . ' as payment_status') : DB::raw("'paid' as payment_status"),

            $hasTeller ? DB::raw('t.id as teller_id') : DB::raw('NULL as teller_id'),
            $hasTeller ? DB::raw($this->coalesceExpression('users', 't', ['account_name', 'name', 'full_name'], 'N/A') . ' as teller_name') : DB::raw("'N/A' as teller_name"),
        ];
    }

    private function coalesceExpression(string $table, string $alias, array $columns, $fallback): string
    {
        $existing = array_values(array_filter($columns, function ($column) use ($table) {
            return Schema::hasColumn($table, $column);
        }));

        $parts = array_map(fn ($column) => "$alias.$column", $existing);

        if (is_numeric($fallback)) {
            $parts[] = (string) $fallback;
        } else {
            $safeFallback = str_replace("'", "''", (string) $fallback);
            $parts[] = "'$safeFallback'";
        }

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
