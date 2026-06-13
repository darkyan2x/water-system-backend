<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserDashboardController extends Controller
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

        $openBills = Reading::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                if (Schema::hasColumn('readings', 'balance')) {
                    $query->where('balance', '>', 0);
                }

                if (Schema::hasColumn('readings', 'payment_status')) {
                    $query->orWhereIn('payment_status', ['unpaid', 'partial', 'partially_paid']);
                }

                if (Schema::hasColumn('readings', 'status')) {
                    $query->orWhereIn('status', ['unpaid', 'partial', 'partially_paid']);
                }
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $latestReading = Reading::query()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        $unpaidBills = $openBills
            ->map(fn ($reading) => $this->transformBill($reading))
            ->values();

        $currentBill = $unpaidBills->first();

        $totalAccountPayables = $openBills->sum(function ($reading) {
            return $this->readingBalance($reading);
        });

        $previousReadingDisplay = $latestReading
            ? $this->readingCurrentValue($latestReading)
            : max(
                (float) ($user->previous_reading ?? 0),
                (float) ($user->starting_meter ?? 0),
                (float) ($user->current_reading ?? 0)
            );

        return response()->json([
            'customer' => [
                'id' => $user->id,
                'name' => $user->name ?? $user->account_name ?? null,
                'account_name' => $user->account_name ?? $user->name ?? null,
                'account_number' => $user->account_number ?? null,
                'account_type' => $user->account_type ?? null,
                'balance' => (float) ($user->balance ?? $totalAccountPayables),
                'previous_reading' => (float) ($user->previous_reading ?? 0),
                'current_reading' => (float) ($user->current_reading ?? $previousReadingDisplay),
            ],
            'current_bill' => $currentBill,
            'unpaid_bills' => $unpaidBills,
            'current_amount_due' => $currentBill['balance'] ?? 0,
            'total_account_payables' => $totalAccountPayables,
            'previous_reading_display' => $previousReadingDisplay,
            'last_updated' => now()->toDateTimeString(),
        ]);
    }

    private function transformBill(Reading $reading): array
    {
        return [
            'id' => $reading->id,
            'date' => $reading->date,
            'usage' => $this->readingUsage($reading),
            'amount_due' => $this->readingAmountDue($reading),
            'balance' => $this->readingBalance($reading),
            'payment_status' => $reading->payment_status ?? $reading->status ?? 'unpaid',
            'previous_reading' => (float) ($reading->previous_reading ?? 0),
            'current_reading' => $this->readingCurrentValue($reading),
        ];
    }

    private function readingUsage(Reading $reading): float
    {
        if (isset($reading->usage)) {
            return (float) ($reading->usage ?? 0);
        }

        if (isset($reading->consumption)) {
            return (float) ($reading->consumption ?? 0);
        }

        return max(
            0,
            $this->readingCurrentValue($reading) - (float) ($reading->previous_reading ?? 0)
        );
    }

    private function readingAmountDue(Reading $reading): float
    {
        if (isset($reading->amount_due)) {
            return (float) ($reading->amount_due ?? 0);
        }

        if (isset($reading->amount)) {
            return (float) ($reading->amount ?? 0);
        }

        if (isset($reading->total_amount)) {
            return (float) ($reading->total_amount ?? 0);
        }

        return (float) ($reading->balance ?? 0);
    }

    private function readingBalance(Reading $reading): float
    {
        if (isset($reading->balance)) {
            return max(0, (float) ($reading->balance ?? 0));
        }

        $amountDue = $this->readingAmountDue($reading);
        $amountPaid = (float) ($reading->amount_paid ?? 0);

        return max(0, $amountDue - $amountPaid);
    }

    private function readingCurrentValue(Reading $reading): float
    {
        if (isset($reading->current_reading)) {
            return (float) ($reading->current_reading ?? 0);
        }

        if (isset($reading->value)) {
            return (float) ($reading->value ?? 0);
        }

        return 0;
    }
}
