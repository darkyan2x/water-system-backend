<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class ActivityLogger
{
    /**
     * Create an activity log without breaking the main transaction/action.
     *
     * Example:
     * ActivityLogger::log([
     *     'module' => 'customers',
     *     'action' => 'customer_updated',
     *     'target_user' => $customer,
     *     'description' => 'Updated customer account.',
     *     'old_values' => [...],
     *     'new_values' => [...],
     * ], $request);
     */
    public static function log(array $data, ?Request $request = null): ?ActivityLog
    {
        try {
            $request = $request ?: request();
            $actor = $data['actor'] ?? $request?->user();
            $targetUser = $data['target_user'] ?? null;

            if ($targetUser && ! $targetUser instanceof User) {
                $targetUser = null;
            }

            return ActivityLog::create([
                'actor_user_id' => $data['actor_user_id'] ?? $actor?->id,
                'actor_name' => $data['actor_name'] ?? self::resolveUserName($actor),
                'actor_role' => $data['actor_role'] ?? ($actor?->role ? strtolower((string) $actor->role) : null),

                'target_user_id' => $data['target_user_id'] ?? $targetUser?->id,
                'target_account_number' => $data['target_account_number']
                    ?? $targetUser?->account_number
                    ?? $targetUser?->accountNumber
                    ?? null,
                'target_account_name' => $data['target_account_name']
                    ?? self::resolveUserName($targetUser)
                    ?? null,

                'module' => $data['module'] ?? 'system',
                'action' => $data['action'] ?? 'activity',
                'description' => $data['description'] ?? null,

                'old_values' => $data['old_values'] ?? null,
                'new_values' => $data['new_values'] ?? null,
                'metadata' => $data['metadata'] ?? null,

                'ip_address' => $data['ip_address'] ?? $request?->ip(),
                'user_agent' => $data['user_agent'] ?? $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public static function changedValues(array $oldValues, array $newValues): array
    {
        $oldChanged = [];
        $newChanged = [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;

            if ((string) $oldValue !== (string) $newValue) {
                $oldChanged[$key] = $oldValue;
                $newChanged[$key] = $newValue;
            }
        }

        return [$oldChanged, $newChanged];
    }

    private static function resolveUserName($user): ?string
    {
        if (! $user) {
            return null;
        }

        return $user->account_name
            ?? $user->name
            ?? $user->full_name
            ?? $user->username
            ?? null;
    }
}
