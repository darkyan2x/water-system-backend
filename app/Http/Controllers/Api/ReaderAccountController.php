<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ReaderAccountController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->get('search', ''));

        $readers = User::query()
            ->where('role', 'reader')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'fullName' => $user->full_name ?? $user->name ?? '',
                    'username' => $user->username ?? $user->email ?? '',
                    'address' => $user->address ?? '',
                    'contactNumber' => $user->contact_number ?? $user->phone ?? $user->mobile ?? '',
                    'taggedBarangays' => $user->tagged_barangays ?? [],
                    'role' => $user->role,
                ];
            });

        return response()->json([
            'data' => $readers,
        ]);
    }
    public function update(Request $request, User $user)
    {
        if ($user->role !== 'reader') {
            return response()->json([
                'message' => 'This account is not a reader account.',
            ], 422);
        }

        $validated = $request->validate([
            'fullName' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'contactNumber' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'taggedBarangays' => ['nullable', 'array'],
            'taggedBarangays.*' => ['string', 'max:100'],
        ]);

        $user->name = $validated['fullName'];
        $user->email = $validated['username'];
        $user->mobile = $validated['contactNumber'] ?? null;
        $user->address = $validated['address'] ?? null;
        $user->tagged_barangays = $validated['taggedBarangays'] ?? [];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json([
            'message' => 'Reader account updated successfully.',
            'data' => [
                'id' => $user->id,
                'fullName' => $user->name ?? '',
                'username' => $user->email ?? '',
                'address' => $user->address ?? '',
                'contactNumber' => $user->mobile ?? '',
                'taggedBarangays' => $user->tagged_barangays ?? [],
                'role' => $user->role,
            ],
        ]);
    }
    public function assignedAccounts(Request $request)
    {
        $reader = $request->user();

        $assignedBarangays = $reader->tagged_barangays ?? [];

        // Safety: handle if tagged_barangays is stored as JSON string
        if (is_string($assignedBarangays)) {
            $decoded = json_decode($assignedBarangays, true);
            $assignedBarangays = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($assignedBarangays)) {
            $assignedBarangays = [];
        }

        $assignedBarangays = collect($assignedBarangays)
            ->map(fn ($barangay) => trim((string) $barangay))
            ->filter()
            ->values()
            ->all();

        if (count($assignedBarangays) === 0) {
            return response()->json([
                'data' => [],
                'assignedBarangays' => [],
            ]);
        }

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $accounts = User::query()
            ->whereIn('barangay', $assignedBarangays)

            // Only customer accounts, not admin/master/reader accounts
            ->whereNotIn('role', ['admin', 'master', 'reader'])

            // Do not show customers that are not fully set up yet
            // previous_reading must already be initialized from starting_meter
            ->where('status','for_reading')

            // Remove customers that already have a reading this month
            ->whereNotIn('id', function ($query) use ($monthStart, $monthEnd) {
                $query->select('user_id')
                    ->from('readings')
                    ->whereNotNull('user_id')
                    ->whereBetween('date', [$monthStart, $monthEnd]);
            })

            ->orderBy('barangay')
            ->orderBy('purok')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,

                    // Reader dashboard display name
                    'name' => $user->name ?? '',

                    // Keep account_name available for other pages
                    'accountName' => $user->account_name ?? '',
                    'account_name' => $user->account_name ?? '',

                    'accountNumber' => $user->account_number ?? '',
                    'account_number' => $user->account_number ?? '',

                    'barangay' => $user->barangay ?? '',
                    'purok' => $user->purok ?? '',
                    'mobile' => $user->mobile ?? '',
                    'address' => $user->address ?? '',

                    'current_reading' => $user->current_reading ?? null,
                    'previous_reading' => $user->previous_reading ?? null,
                    'starting_meter' => $user->starting_meter ?? null,

                    'account_type' => $user->account_type ?? null,
                    'status' => $user->status ?? null,
                ];
            });

        return response()->json([
            'data' => $accounts,
            'assignedBarangays' => $assignedBarangays,
            'month' => now()->format('Y-m'),
        ]);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullName' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:4'],
            'contactNumber' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'taggedBarangays' => ['nullable', 'array'],
            'taggedBarangays.*' => ['string', 'max:100'],
        ]);

        $user = new User();
        $user->name = $validated['fullName'];
        $user->email = $validated['username'];
        $user->password = Hash::make($validated['password']);
        $user->role = 'reader';
        $user->mobile = $validated['contactNumber'] ?? null;
        $user->address = $validated['address'] ?? null;
        $user->tagged_barangays = $validated['taggedBarangays'] ?? [];

        $user->save();

        return response()->json([
            'message' => 'Reader account created successfully.',
            'data' => $this->formatReaderUser($user),
        ], 201);
    }

    private function formatReaderUser(User $user): array
    {
        return [
            'id' => $user->id,
            'fullName' => $user->name ?? '',
            'username' => $user->email ?? '',
            'address' => $user->address ?? '',
            'contactNumber' => $user->mobile ?? '',
            'taggedBarangays' => $user->tagged_barangays ?? [],
            'role' => $user->role,
        ];
    }

    public function setupAccounts(Request $request)
    {
        $reader = $request->user();

        if (!$reader) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $taggedBarangays = $reader->tagged_barangays ?? [];

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

        $taggedBarangays = collect($taggedBarangays)
            ->map(fn ($barangay) => trim((string) $barangay))
            ->filter()
            ->values()
            ->all();

        if (empty($taggedBarangays)) {
            return response()->json([
                'data' => [],
                'assigned_barangays' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => (int) $request->input('per_page', 25),
            ]);
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $search = trim((string) $request->input('search', ''));

        $query = User::query()
            ->where('role', 'user')
            ->where('status', 'setup')
            ->whereIn('barangay', $taggedBarangays);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('purok', 'like', "%{$search}%")
                    ->orWhere('barangay', 'like', "%{$search}%");
            });
        }

        $accounts = $query
            ->orderBy('barangay')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $accounts->getCollection()->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'account_number' => $account->account_number,
                    'accountNumber' => $account->account_number,
                    'mobile' => $account->mobile,
                    'barangay' => $account->barangay,
                    'purok' => $account->purok,
                    'address' => $account->address,
                    'status' => $account->status,
                    'badge' => $account->badge,
                    'account_type' => $account->account_type,
                    'billing_date' => $account->billing_date,
                    'previous_reading' => $account->previous_reading,
                    'current_reading' => $account->current_reading,
                ];
            })->values(),
            'assigned_barangays' => $taggedBarangays,
            'total' => $accounts->total(),
            'current_page' => $accounts->currentPage(),
            'last_page' => $accounts->lastPage(),
            'per_page' => $accounts->perPage(),
            'has_next' => $accounts->hasMorePages(),
            'next_page_url' => $accounts->nextPageUrl(),
        ]);
    }

    public function assignedMeterAccounts(Request $request)
    {
        $reader = $request->user();

        $role = strtolower(trim((string) $reader->role));

        if ($role !== 'reader') {
            return response()->json([
                'message' => 'Forbidden. Only reader accounts can access this resource.',
            ], 403);
        }

        $taggedBarangays = $reader->tagged_barangays;

        if (is_string($taggedBarangays)) {
            $decoded = json_decode($taggedBarangays, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $taggedBarangays = $decoded;
            } else {
                $taggedBarangays = array_map('trim', explode(',', $taggedBarangays));
            }
        }

        if (!is_array($taggedBarangays)) {
            $taggedBarangays = [];
        }

        $taggedBarangays = collect($taggedBarangays)
            ->map(fn ($barangay) => trim((string) $barangay))
            ->filter()
            ->values()
            ->all();

        $normalizedTaggedBarangays = collect($taggedBarangays)
            ->map(fn ($barangay) => mb_strtolower(trim((string) $barangay)))
            ->filter()
            ->values()
            ->all();

        if (empty($normalizedTaggedBarangays)) {
            return response()->json([
                'assigned_barangays' => [],
                'data' => [],
            ]);
        }

        $today = Carbon::today();

        $decodeBadges = function ($value) {
            if (is_array($value)) {
                $badges = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $badges = $decoded;
                } else {
                    $badges = array_map('trim', explode(',', $value));
                }
            } else {
                $badges = [];
            }

            return collect($badges)
                ->map(fn ($badge) => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $badge))))
                ->filter()
                ->values()
                ->all();
        };

        $accounts = User::query()
            ->whereIn(\DB::raw('LOWER(TRIM(barangay))'), $normalizedTaggedBarangays)
            ->whereIn(\DB::raw('LOWER(TRIM(role))'), ['user', 'users', 'customer'])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereRaw('LOWER(TRIM(status)) != ?', ['disconnected']);
            })
            ->withCount('readings')
            ->withExists([
                'readings as has_current_month_reading' => function ($query) use ($today) {
                    $query->whereYear('date', $today->year)
                        ->whereMonth('date', $today->month);
                },
            ])
            ->orderByRaw('LOWER(TRIM(barangay)) ASC')
            ->orderBy('name')
            ->get()
            ->map(function ($account) use ($decodeBadges) {
                $status = str_replace(
                    [' ', '-'],
                    '_',
                    mb_strtolower(trim((string) $account->status))
                );

                $badges = $decodeBadges($account->badge);

                $hasCurrentMonthReading = (bool) $account->has_current_month_reading;
                $readingsCount = (int) $account->readings_count;

                $isSetup =
                    $status === 'setup' ||
                    in_array('setup', $badges, true);

                $isForReading =
                    $status === 'for_reading' ||
                    $status === 'for_rdng' ||
                    in_array('for_reading', $badges, true) ||
                    in_array('for_rdng', $badges, true);

                $isCompletedReading =
                    $hasCurrentMonthReading ||
                    $status === 'due';

                if ($isSetup) {
                    $meterState = 'setup';
                    $buttonType = 'setup';
                    $buttonLabel = 'Setup';
                } elseif ($isForReading) {
                    $meterState = 'for_reading';
                    $buttonType = 'reading';
                    $buttonLabel = 'Enter Reading';
                } elseif ($isCompletedReading) {
                    $meterState = 'completed';
                    $buttonType = 'edit';
                    $buttonLabel = 'Edit Account';
                } else {
                    $meterState = 'completed';
                    $buttonType = 'edit';
                    $buttonLabel = 'Edit Account';
                }

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'account_number' => $account->account_number,
                    'barangay' => $account->barangay,
                    'purok' => $account->purok,
                    'status' => $account->status,
                    'badge' => $account->badge,
                    'status_badges' => $badges,

                    'readings_count' => $readingsCount,
                    'read_this_month' => $hasCurrentMonthReading,
                    'has_current_month_reading' => $hasCurrentMonthReading,

                    'meter_state' => $meterState,
                    'assigned_meter_status' => $meterState,
                    'button_type' => $buttonType,
                    'button_label' => $buttonLabel,

                    'is_setup' => $meterState === 'setup',
                    'is_for_reading' => $meterState === 'for_reading',
                    'is_read_completed' => $meterState === 'completed',
                ];
            })
            ->values();

        return response()->json([
            'assigned_barangays' => $taggedBarangays,
            'data' => $accounts,
            'total' => $accounts->count(),
        ]);
    }

    public function untaggedMeters(Request $request)
    {
        $reader = $request->user();

        $role = strtolower((string) $reader->role);

        if ($role !== 'reader') {
            return response()->json([
                'message' => 'Forbidden. Only reader accounts can access this resource.',
            ], 403);
        }

        $taggedBarangays = $reader->tagged_barangays;

        if (is_string($taggedBarangays)) {
            $decoded = json_decode($taggedBarangays, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $taggedBarangays = $decoded;
            } else {
                $taggedBarangays = array_map('trim', explode(',', $taggedBarangays));
            }
        }

        if (!is_array($taggedBarangays)) {
            $taggedBarangays = [];
        }

        $taggedBarangays = collect($taggedBarangays)
            ->map(fn ($barangay) => trim((string) $barangay))
            ->filter()
            ->values()
            ->all();

        if (empty($taggedBarangays)) {
            return response()->json([
                'assigned_barangays' => [],
                'count' => 0,
                'data' => [],
            ]);
        }

        $accounts = User::query()
            ->whereIn('barangay', $taggedBarangays)
            ->where(function ($query) {
                $query->where('role', 'user')
                    ->orWhere('role', 'users')
                    ->orWhere('role', 'customer');
            })
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'disconnected');
            })

            /*
            * Untagged means latitude/longitude are missing or invalid.
            * We intentionally check latitude/longitude only here.
            */
            ->where(function ($query) {
                $query
                    ->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhere('latitude', '')
                    ->orWhere('longitude', '')
                    ->orWhere('latitude', 0)
                    ->orWhere('longitude', 0);
            })
            ->orderBy('barangay')
            ->orderBy('name')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'account_number' => $account->account_number,
                    'barangay' => $account->barangay,
                    'purok' => $account->purok,
                    'address' => $account->address,
                    'status' => $account->status,
                    'badge' => $account->badge,
                    'status_badges' => $account->badge,
                    'latitude' => $account->latitude,
                    'longitude' => $account->longitude,
                    'x_coordinate' => $account->x_coordinate,
                    'y_coordinate' => $account->y_coordinate,
                    'has_coordinates' => false,
                    'button_type' => 'tag_location',
                    'button_label' => 'Tag Location',
                ];
            })
            ->values();

        return response()->json([
            'assigned_barangays' => $taggedBarangays,
            'count' => $accounts->count(),
            'data' => $accounts,
        ]);
    }
}