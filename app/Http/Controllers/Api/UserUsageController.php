<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserUsageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $usageColumn = null;

        if (Schema::hasColumn('readings', 'usage')) {
            $usageColumn = 'usage';
        } elseif (Schema::hasColumn('readings', 'consumption')) {
            $usageColumn = 'consumption';
        }

        $readings = Reading::query()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->sortBy(function ($reading) {
                return sprintf('%s-%012d', $reading->date, $reading->id);
            })
            ->values();

        $records = $readings->map(function ($reading) use ($usageColumn) {
            $date = $reading->date ? Carbon::parse($reading->date) : null;

            $usage = $usageColumn
                ? (float) ($reading->{$usageColumn} ?? 0)
                : max(
                    0,
                    (float) ($reading->current_reading ?? $reading->value ?? 0) -
                    (float) ($reading->previous_reading ?? 0)
                );

            return [
                'id' => $reading->id,
                'date' => $date ? $date->toDateString() : null,
                'month' => $date ? $date->format('M') : 'N/A',
                'period' => $date ? $date->format('M j, Y') : 'N/A',
                'usage' => $usage,
                'previous_reading' => $reading->previous_reading ?? null,
                'current_reading' => $reading->current_reading ?? $reading->value ?? null,
            ];
        });

        $totalUsage = (float) $records->sum('usage');
        $count = $records->count();
        $averageUsage = $count > 0 ? round($totalUsage / $count, 2) : 0;
        $highestUsage = $count > 0 ? (float) $records->max('usage') : 0;
        $latest = $records->last();

        return response()->json([
            'message' => 'Usage loaded successfully.',
            'records' => $records,
            'total_usage' => round($totalUsage, 2),
            'average_usage' => $averageUsage,
            'highest_usage' => round($highestUsage, 2),
            'latest_usage' => $latest['usage'] ?? 0,
            'latest_month' => $latest['period'] ?? null,
            'last_updated' => now()->toDateTimeString(),
        ]);
    }
}
