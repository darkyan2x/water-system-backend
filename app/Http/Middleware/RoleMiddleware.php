<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $userRole = strtolower(trim((string) $user->role));

        $allowedRoles = array_map(function ($role) {
            return strtolower(trim($role));
        }, $roles);

        if (!in_array($userRole, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Unauthorized. Only master or admin accounts can access this resource.',
            ], 403);
        }

        return $next($request);
    }
}