<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerFromUserResource;

class CustomerController extends Controller
{
    /**
     * GET /api/v1/customers
     * Query params:
     * - search: string (name or account_number)
     * - page: int
     * - limit: int (default 25, max 100)
     * - sort: name|account_number|created_at (optional)
     * - dir: asc|desc (optional)
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $limit  = (int) $request->query('limit', 25);
        $limit  = max(1, min($limit, 100));

        $sort = $request->query('sort', 'name');
        $dir  = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'account_number', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) $sort = 'name';

        $q = User::query()
            ->select(['id', 'name', 'account_number', 'purok', 'barangay', 'role'])
            ->whereIn('role', ['user']); // ✅ customers

        // Large dataset friendly search
        if ($search !== '') {
            $looksNumeric = preg_match('/^[0-9]+$/', $search) === 1;

            $q->where(function ($w) use ($search, $looksNumeric) {
                if ($looksNumeric) {
                    // fast prefix match helps for account searches
                    $w->where('account_number', 'like', $search . '%');
                }

                $w->orWhere('name', 'like', '%' . $search . '%')
                  ->orWhere('account_number', 'like', '%' . $search . '%');
            });
        }

        $q->orderBy($sort, $dir)->orderBy('id', 'asc');

        $paginator = $q->paginate($limit)->appends($request->query());

        return response()->json([
            'data' => CustomerFromUserResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'hasNext' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/v1/customers/{user}
     */
    public function show(User $customer)
    {
        abort_unless($customer->role === 'user', 404);

        // If you have readings/bills history, load it here.
        // Example (adjust relation name):
        // $readings = $customer->load(['readings' => fn($q) => $q->latest()->limit(6)]);
        $readings = $customer->load(['readings' => fn($q) => $q->latest()->limit(6)]);


        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'accountNumber' => $customer->account_number ?? $customer->account_number ?? null,
            'accountType' => $customer->account_type ?? 'Residential',
            'barangay' => $customer->barangay,
            'contact' => $customer->mobile ?? $customer->contact ?? null,
            // 'startingMeter' => $customer->starting_meter ?? null,
            // 'billingDate' => $customer->billing_date ?? 1,
            'currentReading' => $customer->previous_reading ?? null,
            'status' => $customer->status ?? 'Ok',
            'previousReadings' => $readings, // fill once you wire readings/billing
        ]);
    }

    public function update(Request $request, User $customer)
    {
        abort_unless($customer->role === 'user', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'accountNumber' => ['nullable', 'string', 'max:50'], // or required if you want
            'accountType' => ['required', Rule::in(['residential','commercial','industrial','special_use'])],
            'barangay' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:30'],
            'startingMeter' => ['nullable', 'numeric', 'min:0'],
            'billingDate' => ['required', 'integer', 'min:1', 'max:31'],
            'currentReading' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['ok','delinquent','due','setup','for_reading','disconnected'])],
        ]);

        // Map payload fields -> your DB columns
        $customer->name = $data['name'];

        // choose your real column names here:
        $customer->account_number = $data['accountNumber'] ?? $customer->account_number;
        $customer->account_type = $data['accountType'];
        $customer->barangay = $data['barangay'] ?? null;
        $customer->mobile = $data['contact'] ?? null;

        // $customer->starting_meter = $data['startingMeter'] ?? null;
        // $customer->billing_date = $data['billingDate'];
        $customer->previous_reading = $data['currentReading'] ?? null;
        $customer->status = $data['status'];

        $customer->save();

        // return in same shape as show()
        return $this->show($customer);
    }
}
