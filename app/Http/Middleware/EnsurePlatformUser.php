<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to platform users (users attached to no business).
 * Used for resources shared by every tenant — the role and permission
 * definitions — which no single business may change.
 */
class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! AccessScope::isPlatformUser($user)) {
            return ApiResponse::forbidden();
        }

        return $next($request);
    }
}
