<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminUser
{
    public function __construct(
        private readonly AdminAccess $adminAccess,
    ) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! ($user instanceof User) || ! $this->adminAccess->canAccess($user)) {
            abort(403);
        }

        return $next($request);
    }
}
