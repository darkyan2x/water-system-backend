<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MapAccountController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->user();
        $role = strtolower((string) $authUser->role);

        if (!in_array($role, ['master', 'admin', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $barangay = trim((string) $request->query('barangay', 'all'));
        $status = trim((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where(function ($q) {
                $q->where('role', 'user')
                    ->orWhere('role', 'users')
                    ->orWhere('role', 'customer')
                    ->orWhereNull('role');
            });

        if ($barangay !== '' && strtolower($barangay) !== 'all') {
            $query->whereRaw('LOWER(TRIM(barangay)) = ?', [
                strtolower(trim($barangay)),
            ]);
        }

        if ($status !== '' && strtolower($status) !== 'all') {
            $query->whereRaw('LOWER(TRIM(status)) = ?', [
                strtolower(trim($status)),
            ]);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('barangay', 'like', "%{$search}%")
                    ->orWhere('purok', 'like', "%{$search}%");
            });
        }

        $accounts = $query
            ->orderBy('barangay')
            ->orderBy('name')
            ->get()
            ->map(function ($account) {
                $lat = $account->latitude ?? $account->x_coordinate;
                $lng = $account->longitude ?? $account->y_coordinate;

                $hasCoordinates = $this->hasValidCoordinates($lat, $lng);

                return [
                    'id' => $account->id,
                    'name' => $account->name ?? $account->account_name ?? 'Unnamed Account',
                    'account_number' => $account->account_number,
                    'barangay' => $account->barangay,
                    'purok' => $account->purok,
                    'address' => $account->address,
                    'status' => $account->status ?? 'ok',
                    'badge' => $account->badge,
                    'status_badges' => $account->badge,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'x_coordinate' => $account->x_coordinate,
                    'y_coordinate' => $account->y_coordinate,
                    'has_coordinates' => $hasCoordinates,
                ];
            })
            ->values();

        $barangays = User::query()
            ->whereNotNull('barangay')
            ->where('barangay', '!=', '')
            ->where(function ($q) {
                $q->where('role', 'user')
                    ->orWhere('role', 'users')
                    ->orWhere('role', 'customer')
                    ->orWhereNull('role');
            })
            ->select('barangay')
            ->distinct()
            ->orderBy('barangay')
            ->pluck('barangay')
            ->values();

        return response()->json([
            'data' => $accounts,
            'barangays' => $barangays,
            'totals' => [
                'total' => $accounts->count(),
                'pinned' => $accounts->where('has_coordinates', true)->count(),
                'untagged' => $accounts->where('has_coordinates', false)->count(),
            ],
        ]);
    }

    public function updateCoordinates(Request $request, User $user)
    {
        $authUser = $request->user();
        $role = strtolower((string) $authUser->role);

        if (!in_array($role, ['master', 'admin', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid coordinates.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        $user->latitude = $lat;
        $user->longitude = $lng;
        $user->x_coordinate = $lat;
        $user->y_coordinate = $lng;
        $user->save();

        return response()->json([
            'message' => 'Coordinates updated successfully.',
            'data' => [
                'id' => $user->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'x_coordinate' => $lat,
                'y_coordinate' => $lng,
            ],
        ]);
    }

    private function hasValidCoordinates($lat, $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat == 0.0 || $lng == 0.0) {
            return false;
        }

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }
}