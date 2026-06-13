<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) ($user->role ?? ''));

        if (! in_array($role, ['user', 'users', 'customer', 'client'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return response()->json([
            'customer' => [
                'id' => $user->id,
                'name' => $user->name ?? $user->account_name ?? null,
                'account_name' => $user->account_name ?? $user->name ?? null,
                'account_number' => $user->account_number ?? null,
                'account_type' => $user->account_type ?? null,
                'meter_no' => $user->meter_no ?? null,
                'mobile' => $user->mobile ?? $user->contact ?? null,
                'contact' => $user->contact ?? $user->mobile ?? null,
                'barangay' => $user->barangay ?? null,
                'purok' => $user->purok ?? null,
                'address' => $user->address ?? null,
                'status' => $user->status ?? null,
                'billing_date' => $user->billing_date ?? null,
                'next_reading_date' => $user->next_reading_date ?? null,
            ],
            'last_updated' => now()->toDateTimeString(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) ($user->role ?? ''));

        if (! in_array($role, ['user', 'users', 'customer', 'client'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:5', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [
                    'The current password is incorrect.',
                ],
            ]);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json([
            'message' => 'Password has been changed successfully.',
        ]);
    }
}
