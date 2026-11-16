<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $loginKey = $guard->getName();
        if (! $request->session()->has($loginKey) && ! $guard->viaRemember()) {
            return $next($request);
        }

        if (! $request->session()->has('auth_version_web') && $guard->viaRemember()) {
            $request->session()->put('auth_version_web', $user->auth_version);
        }

        if ((int) $request->session()->get('auth_version_web', -1) !== (int) $user->auth_version) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Phiên đăng nhập đã hết hiệu lực. Vui lòng đăng nhập lại.'], 401)
                : redirect('/#login');
        }

        return $next($request);
    }
}
