<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next, ?string $flag = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->active) {
            return $this->deny($request, 401, 'signed-out', 'Sign in with your wiki account to continue.');
        }

        if (! $user->hasFlag(User::FLAG_TS)) {
            return $this->deny($request, 403, 'no-access',
                'Your account is not yet marked as Trust & Safety. Someone holding the user-manager flag can grant it.');
        }

        if ($flag !== null && ! $user->hasFlag($flag)) {
            return $this->deny($request, 403, 'missing-flag', "This needs the '{$flag}' flag, which your account does not hold.");
        }

        return $next($request);
    }

    private function deny(Request $request, int $status, string $error, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $error, 'message' => $message], $status);
        }

        return response()->view('errors.no-access', ['message' => $message], $status);
    }
}
