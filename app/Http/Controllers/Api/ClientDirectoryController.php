<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Http\Request;

class ClientDirectoryController extends Controller
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

        if (!in_array($viewerRole, ['admin', 'master'], true)) {
            return response()->json([
                'message' => 'Only admin or master users can access clients directory.',
            ], 403);
        }

        $search = trim($request->get('search', ''));

        $status = strtolower(trim((string) $request->get('status', 'all')));
        $barangay = trim($request->get('barangay', 'all'));

        $perPage = (int) $request->get('per_page', 50);
        $perPage = $perPage > 0 ? min($perPage, 50) : 50;

        $statusMap = [
            'all' => 'all',

            'ok' => 'ok',
            'due' => 'due',
            'setup' => 'setup',
            'delinquent' => 'delinquent',
            'disconnected' => 'disconnected',

            'for rdng' => 'for_reading',
            'for reading' => 'for_reading',
            'for_reading' => 'for_reading',

            'partially paid' => 'partially_paid',
            'partially_paid' => 'partially_paid',
            'partial' => 'partially_paid',
        ];

        $dbStatus = $statusMap[$status] ?? str_replace(' ', '_', $status);

        $clients = User::query()
            ->where('role', 'user')

            /**
             * Total customer balance from all unpaid/partial readings.
             *
             * readings.balance = balance for one billing cycle
             * total_balance = total collectible balance of the customer
             */
            ->addSelect([
                'total_balance' => Reading::query()
                    ->selectRaw('COALESCE(SUM(balance), 0)')
                    ->whereColumn('readings.user_id', 'users.id')
                    ->whereIn('payment_status', ['unpaid', 'partial'])
            ])

            /**
             * Status filter:
             * Search inside users.status_badges JSON first.
             *
             * Example:
             * status_badges = ["due", "delinquent"]
             *
             * If filter is "delinquent", this user will still appear.
             *
             * The orWhere('status', $dbStatus) is a fallback for old records
             * where status_badges may still be NULL.
             */
            ->when($dbStatus !== 'all', function ($query) use ($dbStatus) {
                $query->where(function ($q) use ($dbStatus) {
                    $q->whereJsonContains('status_badges', $dbStatus)
                        ->orWhere('status', $dbStatus);
                });
            })

            ->when(strtolower($barangay) !== 'all', function ($query) use ($barangay) {
                $query->where('barangay', $barangay);
            })

            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('account_number', 'LIKE', "%{$search}%")
                        ->orWhere('name', 'LIKE', "%{$search}%")
                        ->orWhere('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%");
                });
            })

            ->orderBy('account_number', 'asc')
            ->paginate($perPage);

        return response()->json([
            'clients' => $clients->getCollection()->map(function ($client) {
                $fullName = $client->name;

                if (!$fullName) {
                    $fullName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
                }

                $statusBadges = $client->status_badges ?? [];

                // Safety if status_badges is ever returned as JSON string
                if (is_string($statusBadges)) {
                    $decoded = json_decode($statusBadges, true);
                    $statusBadges = is_array($decoded) ? $decoded : [];
                }

                if (!is_array($statusBadges)) {
                    $statusBadges = [];
                }

                return [
                    'id' => $client->id,
                    'account_no' => $client->account_number,
                    'name' => $fullName,
                    'barangay' => $client->barangay,
                    'status' => $client->status,
                    'status_badges' => $statusBadges,
                    'bill_date' => $client->bill_date ?? $client->bill_day ?? null,
                    'previous_reading' => $client->previous_reading,

                    // This is now total balance from all unpaid/partial readings
                    'balance' => (float) ($client->total_balance ?? 0),

                    'account_type' => $client->account_type ?? null,
                ];
            }),
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
        ]);
    }
}