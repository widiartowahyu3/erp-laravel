<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionTimeouts
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $loginTime = $request->session()->get('login_time');
            $maxLifetimeMinutes = (int) config('session.absolute_lifetime', 480);
            $maxLifetimeSeconds = $maxLifetimeMinutes * 60;

            if ($loginTime !== null && (now()->timestamp - (int) $loginTime) > $maxLifetimeSeconds) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->withErrors([
                    'email' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
                ]);
            }
        }

        return $next($request);
    }
}
