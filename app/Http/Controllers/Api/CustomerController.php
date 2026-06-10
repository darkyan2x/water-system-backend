<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerFromUserResource;
use App\Services\CustomerStatusBadgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class CustomerController extends Controller
{
    /**
     * GET /api/v1/customers
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $limit  = (int) $request->query('limit', 25);
        $limit  = max(1, min($limit, 100));

        $sort = $request->query('sort', 'name');
        $dir  = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'account_number', 'created_at'];

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }

        $q = User::query()
            ->select([
                'id',
                'name',
                'account_number',
                'meter_no',
                'purok',
                'barangay',
                'role',
            ])
            ->whereIn('role', ['user']);

        if ($search !== '') {
            $looksNumeric = preg_match('/^[0-9]+$/', $search) === 1;

            $q->where(function ($w) use ($search, $looksNumeric) {
                if ($looksNumeric) {
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
     * GET /api/v1/customers/{customer}
     */
    public function show(User $customer)
    {
        abort_unless($customer->role === 'user', 404);

        $customer->load([
            'readings' => fn ($q) => $q->latest()->limit(6),
        ]);

        $startingMeter = $customer->starting_meter ?? 0;
        $previousReading = $customer->previous_reading ?? 0;
        $billingDate = $customer->billing_date ?? 1;

        return response()->json([
            'id' => $customer->id,

            'name' => $customer->name,
            'account_name' => $customer->account_name ?? $customer->name,
            'meter_no' => $customer->meter_no,
            'meterNo' => $customer->meter_no,

            // Keep both styles so current frontend will not break
            'accountNumber' => $customer->account_number,
            'account_number' => $customer->account_number,

            'accountType' => $customer->account_type ?? 'residential',
            'account_type' => $customer->account_type ?? 'residential',

            'barangay' => $customer->barangay,

            'contact' => $customer->mobile ?? $customer->contact ?? null,
            'mobile' => $customer->mobile ?? $customer->contact ?? null,

            // Important: populate edit form
            'startingMeter' => $startingMeter,
            'starting_meter' => $startingMeter,

            'billingDate' => $billingDate,
            'billing_date' => $billingDate,

            // Your edit page currently uses currentReading as Prev. Reading
            'currentReading' => $previousReading,
            'previousReading' => $previousReading,
            'previous_reading' => $previousReading,

            'status' => $customer->status ?? 'setup',
            'status_badges' => $customer->status_badges ?? [],

             // Add these
            'latitude' => $customer->latitude
                ?? $customer->x_coordinate
                ?? null,

            'longitude' => $customer->longitude
                ?? $customer->y_coordinate
                ?? null,

            'x_coordinate' => $customer->x_coordinate
                ?? $customer->latitude
                ?? null,

            'y_coordinate' => $customer->y_coordinate
                ?? $customer->longitude
                ?? null,
            'previousReadings' => $customer->readings,
            'readings' => $customer->readings,
        ]);
    }

    /**
     * PUT/PATCH /api/v1/customers/{customer}
     */
    public function update(Request $request, User $customer)
    {
        abort_unless($customer->role === 'user', 404);

        $actor = $request->user();
        $actorRole = strtolower((string) ($actor->role ?? ''));

        /*
        * Reader path:
        *
        * 1. Normal setup flow:
        *    - requires startingMeter + currentReading
        *    - GPS optional
        *    - status becomes for_reading
        *
        * 2. Untagged Coordinates flow:
        *    - coordinates-only update is allowed
        *    - startingMeter/currentReading are NOT required
        *    - status/readings/starting meter are NOT touched
        */
        if ($actorRole === 'reader') {
            $taggedBarangays = $actor->tagged_barangays ?? [];

            if (is_string($taggedBarangays)) {
                $decoded = json_decode($taggedBarangays, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $taggedBarangays = $decoded;
                } else {
                    $taggedBarangays = array_filter(array_map('trim', explode(',', $taggedBarangays)));
                }
            }

            if (!is_array($taggedBarangays)) {
                $taggedBarangays = [];
            }

            $allowedBarangays = collect($taggedBarangays)
                ->map(fn ($barangay) => strtolower(trim((string) $barangay)))
                ->filter()
                ->values()
                ->all();

            $customerBarangay = strtolower(trim((string) $customer->barangay));

            abort_unless(in_array($customerBarangay, $allowedBarangays, true), 403);

            $requestHasLatitude = $request->filled('latitude');
            $requestHasLongitude = $request->filled('longitude');
            $requestHasCoordinates = $requestHasLatitude && $requestHasLongitude;

            if ($requestHasLatitude xor $requestHasLongitude) {
                return response()->json([
                    'message' => 'Both latitude and longitude are required when setting coordinates.',
                    'errors' => [
                        'latitude' => ['Latitude and longitude must be supplied together.'],
                        'longitude' => ['Latitude and longitude must be supplied together.'],
                    ],
                ], 422);
            }

            $isCoordinatesOnlyUpdate =
                $requestHasCoordinates &&
                !$request->has('startingMeter') &&
                !$request->has('currentReading');

            if ($isCoordinatesOnlyUpdate) {
                $data = $request->validate([
                    'latitude' => ['required', 'numeric', 'between:-90,90'],
                    'longitude' => ['required', 'numeric', 'between:-180,180'],
                ]);

                if (Schema::hasColumn('users', 'latitude')) {
                    $customer->latitude = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'longitude')) {
                    $customer->longitude = $data['longitude'];
                }

                if (Schema::hasColumn('users', 'x_coordinate')) {
                    $customer->x_coordinate = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'y_coordinate')) {
                    $customer->y_coordinate = $data['longitude'];
                }

                $customer->save();

                return $this->show($customer->fresh());
            }

            if ($customer->status !== 'setup') {
                if (!$requestHasCoordinates) {
                    return response()->json([
                        'message' => 'This account is no longer available for setup.',
                    ], 422);
                }

                $data = $request->validate([
                    'latitude' => ['required', 'numeric', 'between:-90,90'],
                    'longitude' => ['required', 'numeric', 'between:-180,180'],
                ]);

                if (Schema::hasColumn('users', 'latitude')) {
                    $customer->latitude = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'longitude')) {
                    $customer->longitude = $data['longitude'];
                }

                if (Schema::hasColumn('users', 'x_coordinate')) {
                    $customer->x_coordinate = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'y_coordinate')) {
                    $customer->y_coordinate = $data['longitude'];
                }

                $customer->save();

                return $this->show($customer->fresh());
            }

            $data = $request->validate([
                'startingMeter' => ['required', 'numeric', 'min:0'],
                'currentReading' => ['required', 'numeric', 'min:0'],

                'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            ]);

            $customer->starting_meter = $data['startingMeter'];
            $customer->previous_reading = $data['currentReading'];

            $hasCoordinates =
                array_key_exists('latitude', $data) &&
                array_key_exists('longitude', $data) &&
                $data['latitude'] !== null &&
                $data['longitude'] !== null &&
                $data['latitude'] !== '' &&
                $data['longitude'] !== '';

            if ($hasCoordinates) {
                if (Schema::hasColumn('users', 'latitude')) {
                    $customer->latitude = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'longitude')) {
                    $customer->longitude = $data['longitude'];
                }

                if (Schema::hasColumn('users', 'x_coordinate')) {
                    $customer->x_coordinate = $data['latitude'];
                }

                if (Schema::hasColumn('users', 'y_coordinate')) {
                    $customer->y_coordinate = $data['longitude'];
                }
            }

            $customer->status = 'ok';
            $customer->status_badges = ['ok'];

            $customer->save();

            return $this->show($customer->fresh());
        }

        /*
        * Admin/master/operator path:
        * Full edit behavior.
        * Meter No is editable here.
        * Requires the logged-in admin/operator/master password.
        */
        if (!in_array($actorRole, ['master', 'admin', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $authData = $request->validate([
            'authorization_password' => ['required', 'string'],
        ]);

        if (!Hash::check($authData['authorization_password'], $actor->password)) {
            return response()->json([
                'message' => 'Invalid authorization password.',
                'errors' => [
                    'authorization_password' => [
                        'The password you entered is incorrect.',
                    ],
                ],
            ], 422);
        }

        // Accept either meterNo or meter_no from frontend.
        if ($request->has('meter_no') && !$request->has('meterNo')) {
            $request->merge([
                'meterNo' => $request->input('meter_no'),
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'accountNumber' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'account_number')->ignore($customer->id),
            ],

            'meterNo' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^\S+$/',
                Rule::unique('users', 'meter_no')->ignore($customer->id),
            ],

            'accountType' => [
                'required',
                Rule::in([
                    'residential',
                    'commercial',
                    'industrial',
                    'special_use',
                ]),
            ],

            'barangay' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:30'],

            'startingMeter' => ['required', 'numeric', 'min:0'],
            'billingDate' => ['required', 'integer', 'min:1', 'max:31'],

            'currentReading' => ['required', 'numeric', 'min:0'],

            'status' => [
                'required',
                Rule::in([
                    'ok',
                    'delinquent',
                    'due',
                    'setup',
                    'for_reading',
                    'disconnected',
                ]),
            ],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'clear_coordinates' => ['nullable', 'boolean'],
        ]);

        $customer->name = $data['name'];
        $customer->account_name = $data['name'];

        $customer->account_number = $data['accountNumber'] ?? $customer->account_number;

        if ($request->has('meterNo') || $request->has('meter_no')) {
            $customer->meter_no = isset($data['meterNo']) && $data['meterNo'] !== ''
                ? trim($data['meterNo'])
                : null;
        }

        $customer->account_type = $data['accountType'];
        $customer->barangay = $data['barangay'] ?? null;
        $customer->mobile = $data['contact'] ?? null;

        $customer->starting_meter = $data['startingMeter'] ?? 0;
        $customer->billing_date = $data['billingDate'];
        $customer->previous_reading = $data['currentReading'] ?? 0;

        $customer->status = $data['status'];

        $shouldClearCoordinates = $request->boolean('clear_coordinates');

        $hasCoordinates =
            !$shouldClearCoordinates &&
            array_key_exists('latitude', $data) &&
            array_key_exists('longitude', $data) &&
            $data['latitude'] !== null &&
            $data['longitude'] !== null &&
            $data['latitude'] !== '' &&
            $data['longitude'] !== '';

        if ($shouldClearCoordinates) {
            if (Schema::hasColumn('users', 'latitude')) {
                $customer->latitude = null;
            }

            if (Schema::hasColumn('users', 'longitude')) {
                $customer->longitude = null;
            }

            if (Schema::hasColumn('users', 'x_coordinate')) {
                $customer->x_coordinate = null;
            }

            if (Schema::hasColumn('users', 'y_coordinate')) {
                $customer->y_coordinate = null;
            }
        } elseif ($hasCoordinates) {
            if (Schema::hasColumn('users', 'latitude')) {
                $customer->latitude = $data['latitude'];
            }

            if (Schema::hasColumn('users', 'longitude')) {
                $customer->longitude = $data['longitude'];
            }

            if (Schema::hasColumn('users', 'x_coordinate')) {
                $customer->x_coordinate = $data['latitude'];
            }

            if (Schema::hasColumn('users', 'y_coordinate')) {
                $customer->y_coordinate = $data['longitude'];
            }
        }

        $customer->save();

        app(CustomerStatusBadgeService::class)->refresh($customer->fresh());

        return $this->show($customer->fresh());
    }
    /**
     * DELETE /api/v1/customers/{customer}
     */
    public function destroy(Request $request, User $customer)
    {
        abort_unless($customer->role === 'user', 404);

        $actor = $request->user();
        $actorRole = strtolower((string) ($actor->role ?? ''));

        if (!in_array($actorRole, ['master', 'admin', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $data = $request->validate([
            'authorization_password' => ['required', 'string'],
        ]);

        if (!Hash::check($data['authorization_password'], $actor->password)) {
            return response()->json([
                'message' => 'Invalid authorization password.',
                'errors' => [
                    'authorization_password' => [
                        'The password you entered is incorrect.',
                    ],
                ],
            ], 422);
        }

        DB::transaction(function () use ($customer) {
            /*
             * Delete related billing/history records first, so the customer delete
             * will not fail on foreign key constraints.
             */
            $readingIds = collect();

            if (Schema::hasTable('readings') && Schema::hasColumn('readings', 'user_id')) {
                $readingIds = DB::table('readings')
                    ->where('user_id', $customer->id)
                    ->pluck('id');
            }

            foreach (['payments', 'reading_payments'] as $paymentTable) {
                if (!Schema::hasTable($paymentTable)) {
                    continue;
                }

                if (Schema::hasColumn($paymentTable, 'user_id')) {
                    DB::table($paymentTable)
                        ->where('user_id', $customer->id)
                        ->delete();
                }

                if (Schema::hasColumn($paymentTable, 'customer_id')) {
                    DB::table($paymentTable)
                        ->where('customer_id', $customer->id)
                        ->delete();
                }

                if (
                    $readingIds->isNotEmpty() &&
                    Schema::hasColumn($paymentTable, 'reading_id')
                ) {
                    DB::table($paymentTable)
                        ->whereIn('reading_id', $readingIds)
                        ->delete();
                }
            }

            if (Schema::hasTable('readings') && Schema::hasColumn('readings', 'user_id')) {
                DB::table('readings')
                    ->where('user_id', $customer->id)
                    ->delete();
            }

            $customer->delete();
        });

        return response()->json([
            'message' => 'Customer account deleted successfully.',
        ]);
    }

    public function updateConnectionStatus(Request $request, User $customer)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['disconnect', 'reconnect'])],
            'password' => ['required', 'string'],
        ]);

        $admin = $request->user();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            return response()->json([
                'message' => 'Invalid authorization password.',
            ], 422);
        }

        if ($customer->role !== 'user') {
            return response()->json([
                'message' => 'Only customer accounts can be disconnected or reconnected.',
            ], 422);
        }

        $badges = $customer->status_badges;

        if (is_string($badges)) {
            $decoded = json_decode($badges, true);
            $badges = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($badges)) {
            $badges = [];
        }

        $badges = array_values(array_unique(array_filter($badges)));

        if ($validated['action'] === 'disconnect') {
            if (!in_array('disconnected', $badges, true)) {
                $badges[] = 'disconnected';
            }

            $customer->forceFill([
                'status' => 'disconnected',
                'status_badges' => array_values(array_unique($badges)),
            ])->save();

            return response()->json([
                'message' => 'Customer disconnected successfully.',
                'user' => $customer->fresh(),
            ]);
        }

        // reconnect
        $badges = array_values(array_filter($badges, function ($badge) {
            return $badge !== 'disconnected';
        }));

        $customer->forceFill([
            'status' => 'ok',
            'status_badges' => $badges,
        ])->save();

        return response()->json([
            'message' => 'Customer reconnected successfully.',
            'user' => $customer->fresh(),
        ]);
    }

}