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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $isProduction = app()->isProduction();
        $isLocal = app()->environment('local');
        $vitePort = config('app.vite_port', 5174);

        if ($isLocal) {
            // Local development: Vite dev server dynamically injects <style> tags for HMR
            $viteOrigin = "http://127.0.0.1:{$vitePort}";
            $viteWs = "ws://127.0.0.1:{$vitePort}";
            $scriptSrc = "'self' 'nonce-{$nonce}' {$viteOrigin}";
            $styleSrc = "'self' 'unsafe-inline' {$viteOrigin}";
            $connectSrc = "'self' {$viteWs} {$viteOrigin}";
        } else {
            // Non-local (production/staging/testing): strictly 'self' for styles without unsafe-inline or nonce
            $scriptSrc = "'self' 'nonce-{$nonce}'";
            $styleSrc = "'self'";
            $connectSrc = "'self'";
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
