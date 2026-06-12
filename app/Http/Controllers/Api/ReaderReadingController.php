<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\User;
use App\Services\WaterBillCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReaderReadingController extends Controller
{
    public function authorizedUpdateLatestReading(Request $request, Reading $reading)
    {
        $auth = $request->user();

        if (! $auth) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) $auth->role);

        if (in_array($role, ['user', 'users'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'authorization_password' => ['required', 'string'],
            'current_reading' => ['required', 'numeric', 'min:1'],
        ]);

        if (! Hash::check($validated['authorization_password'], $auth->password)) {
            throw ValidationException::withMessages([
                'authorization_password' => [
                    'The provided password is incorrect.',
                ],
            ]);
        }

        $result = DB::transaction(function () use ($reading, $validated, $auth, $role) {
            $lockedReading = Reading::whereKey($reading->id)
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::whereKey($lockedReading->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->status === 'disconnected') {
                return [
                    'error' => 'This customer account is disconnected and cannot update readings.',
                    'status' => 422,
                ];
            }

            if ($role === 'reader') {
                $assignedBarangays = $this->normalizeAssignedBarangays(
                    $auth->tagged_barangays ?? []
                );

                if (
                    count($assignedBarangays) > 0 &&
                    ! in_array($user->barangay, $assignedBarangays, true)
                ) {
                    return [
                        'error' => 'You are not allowed to update readings for this barangay.',
                        'status' => 403,
                    ];
                }
            }

            $latestReading = Reading::where('user_id', $user->id)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (
                ! $latestReading ||
                (string) $latestReading->id !== (string) $lockedReading->id
            ) {
                return [
                    'error' => 'Only the latest reading can be updated.',
                    'status' => 422,
                ];
            }

            $paymentStatus = strtolower((string) (
                $lockedReading->payment_status ??
                $lockedReading->status ??
                'unpaid'
            ));

            if (! in_array($paymentStatus, ['unpaid', 'unpd'], true)) {
                return [
                    'error' => 'Only unpaid readings can be updated.',
                    'status' => 422,
                ];
            }

            $amountPaid = (float) ($lockedReading->amount_paid ?? 0);

            if ($amountPaid > 0) {
                return [
                    'error' => 'This reading already has payment records and cannot be corrected here.',
                    'status' => 422,
                ];
            }

            $previousReading = (int) ($lockedReading->previous_reading ?? 0);
            $currentReading = (int) $validated['current_reading'];

            if ($currentReading < $previousReading) {
                return [
                    'error' => "Current reading cannot be lower than previous reading ({$previousReading}).",
                    'status' => 422,
                    'errors' => [
                        'current_reading' => [
                            "Current reading cannot be lower than previous reading ({$previousReading}).",
                        ],
                    ],
                ];
            }

            $oldReadingBalance = (float) ($lockedReading->balance ?? 0);

            $calculator = new WaterBillCalculator();

            $billResult = $calculator->calculate(
                accountType: $user->account_type ?? 'commercial',
                currentReading: $currentReading,
                previousReading: $previousReading
            );

            $newCharge = (float) $billResult['total_amount'];
            $newUsage = (int) $billResult['usage'];
            $newReadingBalance = $newCharge;

            $lockedReading->previous_reading = $previousReading;
            $lockedReading->current_reading = $currentReading;
            $lockedReading->usage = $newUsage;
            $lockedReading->amount_due = $newCharge;
            $lockedReading->balance = $newReadingBalance;
            $lockedReading->amount_paid = 0;
            $lockedReading->payment_status = 'unpaid';

            if (Schema::hasColumn('readings', 'status')) {
                $lockedReading->status = 'unpaid';
            }

            $lockedReading->save();

            /*
             * Update customer display/cache values.
             *
             * Keep users.previous_reading as the previous base used by this bill.
             * Only update users.current_reading to the corrected latest reading.
             */
            $user->previous_reading = $previousReading;
            $user->current_reading = $currentReading;
            $user->last_usage = $newUsage;

            /*
             * Adjust customer's running balance:
             * remove old reading balance, then add corrected reading balance.
             */
            $user->balance = max(
                0,
                ((float) ($user->balance ?? 0)) - $oldReadingBalance + $newReadingBalance
            );

            /*
             * Keep status as due because this is still an unpaid bill.
             */
            if ($user->status !== 'disconnected') {
                $user->status = 'due';

                $badges = collect($user->status_badges ?? [])
                    ->reject(fn ($badge) => in_array($badge, ['for_reading', 'setup'], true))
                    ->push('due')
                    ->unique()
                    ->values()
                    ->all();

                $user->status_badges = $badges;
            }

            $user->save();

            return [
                'reading' => $lockedReading->fresh(),
                'user' => $user->fresh(),
                'old_balance' => $oldReadingBalance,
                'new_balance' => $newReadingBalance,
                'new_charge' => $newCharge,
                'new_usage' => $newUsage,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
                'errors' => $result['errors'] ?? null,
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'message' => 'Reading updated successfully.',
            'reading' => $result['reading'],
            'user' => $result['user'],
            'old_balance' => $result['old_balance'],
            'new_balance' => $result['new_balance'],
            'new_charge' => $result['new_charge'],
            'new_usage' => $result['new_usage'],
        ]);
    }

    private function normalizeAssignedBarangays($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded));
            }

            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }
}