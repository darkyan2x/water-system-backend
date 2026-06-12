<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReadingRequest;
use App\Models\Reading;
use App\Models\User;
use App\Services\WaterBillCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserReadingController extends Controller
{
    protected $error;
    // GET /api/v1/users/{user}/readings
    public function index(User $user, Request $request)
    {
        $auth = $request->user();
        $role = strtolower((string) $auth->role);

        // If role is "user", only allow viewing own record
        if ($role === 'user' && $auth->id !== $user->id) {

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = (int) $request->query('per_page', 25);

        $readings = $user->readings()
            ->orderByDesc('date')
            ->paginate($perPage);

        return response()->json([
            'user' => $user,
            'readings' => $readings,
        ]);
    }

    // POST /api/v1/users/{user}/readings
    public function store(StoreReadingRequest $request)
    {
        $auth = $request->user();
        $role = strtolower((string) $auth->role);

        // Customers/users cannot create readings
        if (in_array($role, ['user', 'users'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validated();

        $result = DB::transaction(function () use ($data, $auth) {
            $user = User::whereKey($data['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Disconnected accounts should not be updated by readers
            if ($user->status === 'disconnected') {
                $this->error = 'This customer account is disconnected and cannot accept new readings.';
                return false;
            }

            $date = Carbon::parse($data['date']);

            /*
            * current_reading = actual reading value from the meter.
            */
            $currentReading = (int) ($data['current_reading'] ?? $data['value'] ?? 0);

            if ($currentReading <= 0) {
                $this->error = 'Current reading is required.';
                return false;
            }

            // Prevent duplicate reading per customer per month
            $exists = Reading::where('user_id', $user->id)
                ->whereYear('date', $date->year)
                ->whereMonth('date', $date->month)
                ->exists();

            if ($exists) {
                $this->error = 'A reading for this month already exists.';
                return false;
            }

            /*
            * Previous reading rule:
            *
            * 1. If the customer already has reading history, use the latest
            *    readings.current_reading before this new reading date.
            *
            * 2. If this is the first ever reading, use the setup values from users:
            *    - users.previous_reading
            *    - users.starting_meter
            *    - users.current_reading
            *
            * In your reader setup workflow, Starting Meter and Prev. Reading
            * should always be entered as the same value. We still use max()
            * as a safety net in case one field was saved but another remained 0.
            */
            $lastReading = Reading::where('user_id', $user->id)
                ->whereDate('date', '<', $date->toDateString())
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($lastReading) {
                $previousReading = (int) (
                    $lastReading->current_reading
                    ?? $lastReading->value
                    ?? 0
                );
            } else {
                $previousReading = max(
                    (int) ($user->previous_reading ?? 0),
                    (int) ($user->starting_meter ?? 0),
                    (int) ($user->current_reading ?? 0)
                );
            }

            if ($currentReading < $previousReading) {
                $this->error = "Current reading cannot be lower than previous reading ({$previousReading}).";
                return false;
            }

            $calculator = new WaterBillCalculator();

            $billResult = $calculator->calculate(
                accountType: $user->account_type ?? 'commercial',
                currentReading: $currentReading,
                previousReading: $previousReading
            );

            $charge = (float) $billResult['total_amount'];

            $reading = $user->readings()->create([
                'user_id' => $user->id,
                'date' => $date->toDateString(),

                /*
                * Fixed:
                * For first reading, this now uses the setup value from users.previous_reading
                * or users.starting_meter instead of incorrectly using 0.
                */
                'previous_reading' => $previousReading,
                'current_reading' => $currentReading,
                'usage' => $billResult['usage'],
                'amount_due' => $charge,

                // Billing defaults
                'balance' => $charge,
                'amount_paid' => 0,
                'payment_status' => 'unpaid',

                'encoder_user_id' => $auth->id,

                // Keep this only if your readings table still has "status"
                'status' => 'unpaid',
            ]);

            /*
            * Update customer current state/cache.
            *
            * Keep users.previous_reading as the previous base used for this bill.
            * Set users.current_reading as the latest actual meter value.
            *
            * The next reading will use the latest readings.current_reading anyway,
            * so this keeps the account display and history consistent.
            */
            $user->previous_reading = $previousReading;
            $user->current_reading = $currentReading;
            $user->last_usage = $billResult['usage'];

            /*
            * Set the customer's next reading date based on the reading date
            * and the customer's assigned billing day.
            *
            * Example:
            * Reading date: Jun 10, 2026
            * Billing date: 10
            * Next reading date: Jul 10, 2026
            *
            * If the billing day is 31 and the next month has fewer days,
            * use the last valid day of that next month.
            */
            if (Schema::hasColumn('users', 'next_reading_date')) {
                $user->next_reading_date = $this->resolveNextReadingDate($date, $user->billing_date);
            }

            /*
            * After reader punches reading:
            * for_reading becomes due because a new bill was created.
            */
            $user->status = 'due';

            /*
            * Update badge array:
            * remove for_reading
            * remove setup
            * add due
            *
            * Keep other badges like delinquent / partially_paid if already present.
            */
            $badges = collect($user->status_badges ?? [])
                ->reject(fn ($badge) => in_array($badge, ['for_reading', 'setup'], true))
                ->push('due')
                ->unique()
                ->values()
                ->all();

            $user->status_badges = $badges;

            /*
            * Add new bill amount to customer's running balance.
            */
            $user->balance = ((float) $user->balance) + $charge;

            $user->save();

            return [
                'reading' => $reading,
                'user' => $user->fresh(),
                'charge' => $charge,
            ];
        });

        if (! $result) {
            return response()->json([
                'message' => $this->error,
                'code' => 'READING_ERROR',
            ], 422);
        }

        return response()->json([
            'message' => 'Reading saved successfully.',
            'charge_added' => $result['charge'],
            'reading' => $result['reading'],
            'user' => $result['user'],
        ], 201);
    }

    private function resolveNextReadingDate(Carbon $readingDate, $billingDate): string
    {
        $billingDay = (int) ($billingDate ?: $readingDate->day);

        if ($billingDay < 1) {
            $billingDay = 1;
        }

        if ($billingDay > 31) {
            $billingDay = 31;
        }

        $nextMonth = $readingDate->copy()
            ->startOfMonth()
            ->addMonthNoOverflow();

        $day = min($billingDay, $nextMonth->daysInMonth);

        return $nextMonth->copy()
            ->day($day)
            ->toDateString();
    }

    /**
     * Simple rate calculator (edit this to match Bacong rates)
     * Returns charge amount (float).
     */
    private function computeCharge(string $accountType, int $usage): float
    {
        // Example dummy rates:
        // residential: 25 base + 10 per unit
        // commercial:  50 base + 15 per unit
        // industrial:  100 base + 20 per unit
        // special_use: 30 base + 12 per unit

        $accountType = strtolower($accountType);

        return match ($accountType) {
            'commercial' => 50 + ($usage * 15),
            'industrial' => 100 + ($usage * 20),
            'special_use' => 30 + ($usage * 12),
            default => 25 + ($usage * 10), // residential
        };
    }
    public function history(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $readerId = $request->get('reader_id');
        $barangay = trim((string) $request->get('barangay', ''));
        $month = trim((string) $request->get('month', ''));

        $page = max((int) $request->get('page', 1), 1);
        $perPage = min(max((int) $request->get('per_page', 50), 1), 50);

        $query = \DB::table('readings')
            ->leftJoin('users as customers', 'customers.id', '=', 'readings.user_id')
            ->leftJoin('users as readers', 'readers.id', '=', 'readings.encoder_user_id')
            ->select([
                'readings.id',
                'readings.user_id',
                'readings.encoder_user_id',

                'readings.previous_reading',
                'readings.current_reading as reading',
                'readings.usage',
                'readings.amount_due as amount',

                'readings.status',
                'readings.date',
                'readings.created_at',

                'customers.name as customer_name',
                'customers.account_name as customer_account_name',
                'customers.account_number',
                'customers.barangay',
                'customers.purok',

                'readers.name as reader_name',
                'readers.email as reader_email',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('customers.name', 'like', "%{$search}%")
                    ->orWhere('customers.account_name', 'like', "%{$search}%")
                    ->orWhere('customers.account_number', 'like', "%{$search}%")
                    ->orWhere('customers.mobile', 'like', "%{$search}%");
            });
        }

        if (!empty($readerId) && $readerId !== 'all') {
            $query->where('readings.encoder_user_id', $readerId);
        }

        if ($barangay !== '' && strtolower($barangay) !== 'all') {
            $query->where('customers.barangay', $barangay);
        }

        if ($month !== '') {
            try {
                $start = \Carbon\Carbon::parse($month . '-01')
                    ->startOfMonth()
                    ->toDateString();

                $end = \Carbon\Carbon::parse($month . '-01')
                    ->endOfMonth()
                    ->toDateString();

                $query->whereBetween('readings.date', [$start, $end]);
            } catch (\Throwable $e) {
                // Ignore invalid month filter.
            }
        }

        $paginator = $query
            ->orderByDesc('readings.date')
            ->orderByDesc('readings.id')
            ->paginate($perPage, ['*'], 'page', $page);

        $readings = $paginator->getCollection()->map(function ($reading) {
            return [
                'id' => $reading->id,

                'customerId' => $reading->user_id,
                'customerName' => $reading->customer_name
                    ?: $reading->customer_account_name
                    ?: 'Unknown Customer',

                'accountNumber' => $reading->account_number ?? '',
                'barangay' => $reading->barangay ?? '',
                'purok' => $reading->purok ?? '',

                'readerId' => $reading->encoder_user_id,
                'readerName' => $reading->reader_name ?: 'Unknown Reader',
                'readerEmail' => $reading->reader_email ?? '',

                'date' => $reading->date,

                'previousReading' => $reading->previous_reading,
                'reading' => $reading->reading,
                'usage' => $reading->usage,
                'amount' => $reading->amount,

                'status' => $reading->status,
                'createdAt' => $reading->created_at,
            ];
        });

        $readers = \App\Models\User::query()
            ->where('role', 'reader')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(function ($reader) {
                return [
                    'id' => $reader->id,
                    'name' => $reader->name,
                    'email' => $reader->email,
                ];
            });

        $barangays = \App\Models\User::query()
            ->whereNotNull('barangay')
            ->where('barangay', '!=', '')
            ->distinct()
            ->orderBy('barangay')
            ->pluck('barangay')
            ->values();

        return response()->json([
            'data' => $readings,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'readers' => $readers,
                'barangays' => $barangays,
            ],
        ]);
    }
}
