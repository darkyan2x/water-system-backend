<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use Illuminate\Http\Request;

class UserBillsController extends Controller
{
    public function index(Request $request)
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

        $readings = Reading::query()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $bills = $readings
            ->map(fn (Reading $reading) => $this->transformBill($reading))
            ->values();

        $computedBalance = $readings->sum(function (Reading $reading) {
            return $this->readingBalance($reading);
        });

        $accountBalance = $user->balance !== null
            ? max(0, (float) $user->balance)
            : max(0, (float) $computedBalance);

        return response()->json([
            'customer' => [
                'id' => $user->id,
                'name' => $user->name ?? $user->account_name ?? null,
                'account_name' => $user->account_name ?? $user->name ?? null,
                'account_number' => $user->account_number ?? null,
                'account_type' => $user->account_type ?? null,
            ],
            'balance' => $accountBalance,
            'total_balance' => $accountBalance,
            'bills' => $bills,
            'last_updated' => now()->toDateTimeString(),
        ]);
    }

    private function transformBill(Reading $reading): array
    {
        $status = $this->readingStatus($reading);
        $amountDue = $this->readingAmountDue($reading);
        $amountPaid = (float) ($reading->amount_paid ?? 0);
        $balance = $this->readingBalance($reading);

        return [
            'id' => $reading->id,
            'date' => $reading->date,
            'usage' => $this->readingUsage($reading),
            'amount_due' => $amountDue,
            'amount' => $amountDue,
            'amount_paid' => $amountPaid,
            'balance' => $balance,
            'payment_status' => $status,
            'status' => $status,
            'previous_reading' => (float) ($reading->previous_reading ?? 0),
            'current_reading' => $this->readingCurrentValue($reading),
        ];
    }

    private function readingStatus(Reading $reading): string
    {
        $raw = strtolower((string) ($reading->payment_status ?? $reading->status ?? 'unpaid'));
        $balance = $this->readingBalance($reading);
        $amountPaid = (float) ($reading->amount_paid ?? 0);

        if (in_array($raw, ['paid', 'pd'], true)) {
            return 'paid';
        }

        if (in_array($raw, ['partial', 'partially_paid', 'part'], true)) {
            return 'partial';
        }

        if ($balance <= 0 && $amountPaid > 0) {
            return 'paid';
        }

        if ($amountPaid > 0 && $balance > 0) {
            return 'partial';
        }

        return 'unpaid';
    }

    private function readingUsage(Reading $reading): float
    {
        if ($reading->usage !== null) {
            return (float) $reading->usage;
        }

        if (isset($reading->consumption) && $reading->consumption !== null) {
            return (float) $reading->consumption;
        }

        return max(
            0,
            $this->readingCurrentValue($reading) - (float) ($reading->previous_reading ?? 0)
        );
    }

    private function readingAmountDue(Reading $reading): float
    {
        if ($reading->amount_due !== null) {
            return (float) $reading->amount_due;
        }

        if (isset($reading->amount) && $reading->amount !== null) {
            return (float) $reading->amount;
        }

        if (isset($reading->total_amount) && $reading->total_amount !== null) {
            return (float) $reading->total_amount;
        }

        return (float) ($reading->balance ?? 0);
    }

    private function readingBalance(Reading $reading): float
    {
        if ($reading->balance !== null) {
            return max(0, (float) $reading->balance);
        }

        $amountDue = $this->readingAmountDue($reading);
        $amountPaid = (float) ($reading->amount_paid ?? 0);

        return max(0, $amountDue - $amountPaid);
    }

    private function readingCurrentValue(Reading $reading): float
    {
        if ($reading->current_reading !== null) {
            return (float) $reading->current_reading;
        }

        if (isset($reading->value) && $reading->value !== null) {
            return (float) $reading->value;
        }

        return 0;
    }
}
