<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Sanctum token when one is present, without requiring it.
 *
 * The read-only project routes are public so the demo can be opened without an
 * account (spec 6). But Sanctum only resolves a bearer token inside the
 * `auth:sanctum` middleware, so taking those routes out of it did not merely
 * widen guest access — it stopped authenticating anyone at all on them, and a
 * signed-in user was silently treated as a guest on their own project.
 *
 * Switching the default guard to `sanctum` makes the token resolve when it is
 * sent and leaves the user null when it is not, which is exactly what the
 * policies already expect.
 */
final class ResolveOptionalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
