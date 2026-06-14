<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        $role = strtolower((string) ($auth->role ?? ''));

        if (! in_array($role, ['master', 'admin', 'operator'], true)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(5, min($perPage, 100));

        $query = ActivityLog::query()
            ->latest('created_at')
            ->latest('id');

        if ($request->filled('module')) {
            $query->where('module', $request->query('module'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('actor_role')) {
            $query->where('actor_role', strtolower((string) $request->query('actor_role')));
        }

        if ($request->filled('target_user_id')) {
            $query->where('target_user_id', $request->query('target_user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('actor_name', 'like', "%{$search}%")
                    ->orWhere('target_account_name', 'like', "%{$search}%")
                    ->orWhere('target_account_number', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
