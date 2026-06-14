<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\User;
use App\Services\ActivityLogger;
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

        $result = DB::transaction(function () use ($reading, $validated, $auth, $role, $request) {
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

            $oldReadingValues = [
                'id' => $lockedReading->id,
                'date' => $lockedReading->date,
                'previous_reading' => $lockedReading->previous_reading ?? null,
                'current_reading' => $lockedReading->current_reading ?? null,
                'usage' => $lockedReading->usage ?? null,
                'amount_due' => $lockedReading->amount_due ?? null,
                'balance' => $lockedReading->balance ?? null,
                'amount_paid' => $lockedReading->amount_paid ?? null,
                'payment_status' => $lockedReading->payment_status ?? null,
                'status' => $lockedReading->status ?? null,
            ];

            $oldCustomerValues = [
                'previous_reading' => $user->previous_reading ?? null,
                'current_reading' => $user->current_reading ?? null,
                'last_usage' => $user->last_usage ?? null,
                'status' => $user->status ?? null,
                'status_badges' => $user->status_badges ?? null,
                'balance' => $user->balance ?? null,
            ];

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

            if (Schema::hasColumn('readings', 'usage')) {
                $lockedReading->usage = $newUsage;
            }

            if (Schema::hasColumn('readings', 'consumption')) {
                $lockedReading->consumption = $newUsage;
            }

            if (Schema::hasColumn('readings', 'amount_due')) {
                $lockedReading->amount_due = $newCharge;
            }

            if (Schema::hasColumn('readings', 'amount')) {
                $lockedReading->amount = $newCharge;
            }

            $lockedReading->balance = $newReadingBalance;
            $lockedReading->amount_paid = 0;
            $lockedReading->payment_status = 'unpaid';

            if (Schema::hasColumn('readings', 'status')) {
                $lockedReading->status = 'unpaid';
            }

            $lockedReading->save();

            /*
             * Update customer display/cache values.
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

            $freshReading = $lockedReading->fresh();
            $freshUser = $user->fresh();

            $newReadingValues = [
                'id' => $freshReading->id,
                'date' => $freshReading->date,
                'previous_reading' => $freshReading->previous_reading ?? null,
                'current_reading' => $freshReading->current_reading ?? null,
                'usage' => $freshReading->usage ?? ($freshReading->consumption ?? null),
                'amount_due' => $freshReading->amount_due ?? ($freshReading->amount ?? null),
                'balance' => $freshReading->balance ?? null,
                'amount_paid' => $freshReading->amount_paid ?? null,
                'payment_status' => $freshReading->payment_status ?? null,
                'status' => $freshReading->status ?? null,
            ];

            $newCustomerValues = [
                'previous_reading' => $freshUser->previous_reading ?? null,
                'current_reading' => $freshUser->current_reading ?? null,
                'last_usage' => $freshUser->last_usage ?? null,
                'status' => $freshUser->status ?? null,
                'status_badges' => $freshUser->status_badges ?? null,
                'balance' => $freshUser->balance ?? null,
            ];

            ActivityLogger::log([
                'module' => 'readings',
                'action' => 'reading_corrected',
                'target_user' => $freshUser,
                'description' => sprintf(
                    'Corrected latest unpaid reading for account %s.',
                    $freshUser->account_number ?? $freshUser->account_name ?? $freshUser->name ?? $freshUser->id
                ),
                'old_values' => [
                    'reading' => $oldReadingValues,
                    'customer' => $oldCustomerValues,
                ],
                'new_values' => [
                    'reading' => $newReadingValues,
                    'customer' => $newCustomerValues,
                ],
                'metadata' => [
                    'reading_id' => $freshReading->id,
                    'customer_id' => $freshUser->id,
                    'corrected_by_user_id' => $auth->id,
                    'old_current_reading' => $oldReadingValues['current_reading'],
                    'new_current_reading' => $newReadingValues['current_reading'],
                    'old_usage' => $oldReadingValues['usage'],
                    'new_usage' => $newUsage,
                    'old_charge' => $oldReadingValues['amount_due'],
                    'new_charge' => $newCharge,
                    'old_reading_balance' => $oldReadingBalance,
                    'new_reading_balance' => $newReadingBalance,
                    'account_type' => $freshUser->account_type ?? null,
                ],
            ], $request);

            return [
                'reading' => $freshReading,
                'user' => $freshUser,
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
