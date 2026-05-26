<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerFromUserResource;
use App\Services\CustomerStatusBadgeService;

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
        * Reader safety net:
        * Readers can only set up their assigned customer accounts.
        * They can only update:
        * - startingMeter
        * - currentReading payload, which is your "Prev. Reading" field
        * - latitude / longitude GPS coordinates
        *
        * After successful reader setup:
        * - status = for_reading
        * - badge = ["for_reading"]
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

            if ($customer->status !== 'setup') {
                return response()->json([
                    'message' => 'This account is no longer available for setup.',
                ], 422);
            }

            $data = $request->validate([
                'startingMeter' => ['required', 'numeric', 'min:0'],

                // This is the Prev. Reading field on your setup/edit form
                'currentReading' => ['required', 'numeric', 'min:0'],

                // GPS coordinates from reader setup map
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            $customer->starting_meter = $data['startingMeter'];
            $customer->previous_reading = $data['currentReading'];

            /*
            * Save GPS coordinates.
            * This supports both possible column naming styles:
            * - latitude / longitude
            * - x_coordinate / y_coordinate
            *
            * It will only write to columns that exist in your users table.
            */
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'latitude')) {
                $customer->latitude = $data['latitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'longitude')) {
                $customer->longitude = $data['longitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'x_coordinate')) {
                $customer->x_coordinate = $data['latitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'y_coordinate')) {
                $customer->y_coordinate = $data['longitude'];
            }

            // Reader setup releases the account for normal reading.
            $customer->status = 'for_reading';
            $customer->status_badges = ['for_reading'];

            $customer->save();

            return $this->show($customer->fresh());
        }

        /*
        * Admin/master/operator path:
        * Keep existing full edit behavior.
        */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'accountNumber' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'account_number')->ignore($customer->id),
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

            // This is the Prev. Reading field on your edit form
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

            // Optional GPS coordinates for admin edits too
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $customer->name = $data['name'];
        $customer->account_name = $data['name'];

        $customer->account_number = $data['accountNumber'] ?? $customer->account_number;
        $customer->account_type = $data['accountType'];
        $customer->barangay = $data['barangay'] ?? null;
        $customer->mobile = $data['contact'] ?? null;

        $customer->starting_meter = $data['startingMeter'] ?? 0;
        $customer->billing_date = $data['billingDate'];
        $customer->previous_reading = $data['currentReading'] ?? 0;

        $customer->status = $data['status'];

        /*
        * Save GPS coordinates if provided.
        * We only update coordinates when both values are present.
        */
        if (
            array_key_exists('latitude', $data) &&
            array_key_exists('longitude', $data) &&
            $data['latitude'] !== null &&
            $data['longitude'] !== null
        ) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'latitude')) {
                $customer->latitude = $data['latitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'longitude')) {
                $customer->longitude = $data['longitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'x_coordinate')) {
                $customer->x_coordinate = $data['latitude'];
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'y_coordinate')) {
                $customer->y_coordinate = $data['longitude'];
            }
        }

        $customer->save();

        /*
        * Refresh status_badges using centralized service for admin edits.
        * We do not run this on reader setup because reader setup must force:
        * status = for_reading, badge = ["for_reading"].
        */
        app(CustomerStatusBadgeService::class)->refresh($customer->fresh());

        return $this->show($customer->fresh());
    }
}