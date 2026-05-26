<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReadingPayment;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $viewerRole = strtolower((string) $authUser->role);

        if (!in_array($viewerRole, ['admin', 'master', 'teller', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $search = trim((string) $request->get('search', ''));
        $barangay = trim((string) $request->get('barangay', 'all'));
        $paymentMethod = trim((string) $request->get('payment_method', 'all'));
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $perPage = (int) $request->get('per_page', 50);
        $perPage = $perPage > 0 ? min($perPage, 50) : 50;

        $query = ReadingPayment::query()
            ->join('users as customers', 'customers.id', '=', 'reading_payments.user_id')
            ->join('readings', 'readings.id', '=', 'reading_payments.reading_id')
            ->leftJoin('users as tellers', 'tellers.id', '=', 'reading_payments.teller_user_id')
            ->select([
                'reading_payments.id',
                'reading_payments.reading_id',
                'reading_payments.user_id',
                'reading_payments.teller_user_id',
                'reading_payments.amount',
                'reading_payments.payment_date',
                'reading_payments.payment_method',
                'reading_payments.or_number',
                'reading_payments.remarks',
                'reading_payments.created_at',

                'customers.account_number',
                'customers.name as customer_name',
                'customers.account_name',
                'customers.mobile',
                'customers.barangay',
                'customers.account_type',

                'readings.date as billing_date',
                'readings.previous_reading',
                'readings.current_reading',
                'readings.usage',
                'readings.amount_due',
                'readings.amount_paid',
                'readings.balance',
                'readings.payment_status',

                'tellers.name as teller_name',
                'tellers.account_name as teller_account_name',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customers.account_number', 'LIKE', "%{$search}%")
                        ->orWhere('customers.name', 'LIKE', "%{$search}%")
                        ->orWhere('customers.account_name', 'LIKE', "%{$search}%")
                        ->orWhere('customers.mobile', 'LIKE', "%{$search}%")
                        ->orWhere('reading_payments.or_number', 'LIKE', "%{$search}%");
                });
            })
            ->when(strtolower($barangay) !== 'all', function ($query) use ($barangay) {
                $query->where('customers.barangay', $barangay);
            })
            ->when(strtolower($paymentMethod) !== 'all', function ($query) use ($paymentMethod) {
                $normalized = strtolower($paymentMethod);

                $query->where(function ($q) use ($normalized, $paymentMethod) {
                    $q->where('reading_payments.payment_method', $paymentMethod)
                        ->orWhere('reading_payments.payment_method', $normalized);

                    if ($normalized === 'cash') {
                        $q->orWhere('reading_payments.payment_method', 'cash_at_counter')
                            ->orWhere('reading_payments.payment_method', 'Cash at Counter');
                    }

                    if ($normalized === 'bank') {
                        $q->orWhere('reading_payments.payment_method', 'bank_transfer')
                            ->orWhere('reading_payments.payment_method', 'Bank Transfer');
                    }

                    if ($normalized === 'paymaya') {
                        $q->orWhere('reading_payments.payment_method', 'maya')
                            ->orWhere('reading_payments.payment_method', 'PayMaya');
                    }

                    if ($normalized === 'gcash') {
                        $q->orWhere('reading_payments.payment_method', 'GCash');
                    }
                });
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('reading_payments.payment_date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('reading_payments.payment_date', '<=', $dateTo);
            });

        $totalCollected = (clone $query)->sum('reading_payments.amount');
        $totalTransactions = (clone $query)->count();

        $payments = $query
            ->orderByDesc('reading_payments.payment_date')
            ->orderByDesc('reading_payments.id')
            ->paginate($perPage);

        return response()->json([
            'data' => $payments->getCollection()->map(function ($payment) {
                $customerName = $payment->customer_name ?: $payment->account_name;
                $tellerName = $payment->teller_name ?: $payment->teller_account_name;

                return [
                    'id' => $payment->id,
                    'payment_id' => $payment->id,
                    'reading_id' => $payment->reading_id,

                    'payment_date' => $payment->payment_date,
                    'payment_method' => $payment->payment_method ?: 'cash',
                    'or_number' => $payment->or_number,
                    'remarks' => $payment->remarks,
                    'amount' => (float) $payment->amount,

                    'customer' => [
                        'id' => $payment->user_id,
                        'account_number' => $payment->account_number,
                        'account_no' => $payment->account_number,
                        'name' => $customerName,
                        'mobile' => $payment->mobile,
                        'barangay' => $payment->barangay,
                        'account_type' => $payment->account_type,
                    ],

                    'billing' => [
                        'date' => $payment->billing_date,
                        'previous_reading' => $payment->previous_reading,
                        'current_reading' => $payment->current_reading,
                        'usage' => $payment->usage,
                        'amount_due' => (float) $payment->amount_due,
                        'amount_paid' => (float) $payment->amount_paid,
                        'balance' => (float) $payment->balance,
                        'payment_status' => $payment->payment_status,
                    ],

                    'teller' => [
                        'id' => $payment->teller_user_id,
                        'name' => $tellerName,
                    ],

                    'created_at' => $payment->created_at,
                ];
            }),

            'summary' => [
                'total_collected' => (float) $totalCollected,
                'total_transactions' => $totalTransactions,
            ],

            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }
}
