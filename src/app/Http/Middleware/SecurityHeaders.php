<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $isProduction = app()->isProduction();
        $isLocal = app()->environment('local');
        $vitePort = env('VITE_PORT', 5174);

        $scriptSrc = "'self' 'nonce-{$nonce}'";
        $styleSrc = "'self' 'unsafe-inline' 'nonce-{$nonce}'";
        $connectSrc = "'self'";

        if ($isLocal) {
            $viteOrigin = "http://127.0.0.1:{$vitePort}";
            $viteWs = "ws://127.0.0.1:{$vitePort}";
            $scriptSrc .= " {$viteOrigin}";
            $styleSrc .= " {$viteOrigin}";
            $connectSrc .= " {$viteWs} {$viteOrigin}";
        }

        $cspDirectives = [
            "default-src 'self'",
            "script-src {$scriptSrc}",
            "style-src {$styleSrc}",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src {$connectSrc}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if ($isProduction) {
            $cspDirectives[] = 'upgrade-insecure-requests';
        }

        $csp = implode('; ', $cspDirectives);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($isProduction) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
