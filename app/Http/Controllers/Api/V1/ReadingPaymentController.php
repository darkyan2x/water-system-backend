<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\ReadingPayment;
use App\Models\User;
use App\Services\CustomerStatusBadgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReadingPaymentController extends Controller
{
    public function store(Request $request, Reading $reading)
    {
        $auth = $request->user();

        $role = strtolower((string) $auth->role);

        if (!in_array($role, ['master', 'admin', 'teller', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
            'or_number' => ['nullable', 'string', 'max:255'],
            'payment_method' => [
                'nullable',
                'string',
                'max:50',
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($reading, $validated, $auth) {
            /**
             * Lock the reading row so two tellers cannot accidentally
             * post payment to the same bill at the same time.
             */
            $lockedReading = Reading::query()
                ->whereKey($reading->id)
                ->lockForUpdate()
                ->firstOrFail();

            $amountDue = (float) $lockedReading->amount_due;

            /**
             * Source of truth: reading_payments table.
             * Recompute amount_paid from payment records.
             */
            $currentPaid = (float) ReadingPayment::query()
                ->where('reading_id', $lockedReading->id)
                ->sum('amount');

            $currentBalance = max($amountDue - $currentPaid, 0);

            if ($currentBalance <= 0) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'This bill is already fully paid.',
                ];
            }

            $paymentAmount = round((float) $validated['amount'], 2);

            if ($paymentAmount > $currentBalance) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'Payment amount is greater than the remaining balance.',
                    'balance' => $currentBalance,
                ];
            }

            $payment = ReadingPayment::create([
                'reading_id' => $lockedReading->id,
                'user_id' => $lockedReading->user_id,
                'teller_user_id' => $auth->id,
                'amount' => $paymentAmount,
                'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
                'or_number' => $validated['or_number'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $newPaid = (float) ReadingPayment::query()
                ->where('reading_id', $lockedReading->id)
                ->sum('amount');

            $newBalance = max($amountDue - $newPaid, 0);

            if ($newPaid <= 0) {
                $paymentStatus = 'unpaid';
            } elseif ($newPaid >= $amountDue) {
                $paymentStatus = 'paid';
            } else {
                $paymentStatus = 'partial';
            }

            $lockedReading->update([
                'amount_paid' => $newPaid,
                'balance' => $newBalance,
                'payment_status' => $paymentStatus,
                'status' => $paymentStatus,
            ]);

            $this->recalculateCustomerBillingStatus($lockedReading->user_id);

            return [
                'error' => false,
                'payment' => $payment,
                'reading' => $lockedReading->fresh(['payments']),
            ];
        });

        if ($result['error'] ?? false) {
            return response()->json([
                'message' => $result['message'],
                'balance' => $result['balance'] ?? null,
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'message' => 'Payment saved successfully.',
            'payment' => $result['payment'],
            'reading' => $result['reading'],
        ], 201);
    }

    public function index(Request $request, Reading $reading)
    {
        $payments = ReadingPayment::query()
            ->where('reading_id', $reading->id)
            ->with(['teller:id,name,account_name,role'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $payments,
            'reading' => $reading->fresh(),
        ]);
    }

    private function recalculateCustomerBillingStatus(int $userId): void
    {
        $user = User::query()
            ->whereKey($userId)
            ->first();

        if (!$user) {
            return;
        }

        //refresh status
        app(CustomerStatusBadgeService::class)->refresh($user->fresh());
    }
}