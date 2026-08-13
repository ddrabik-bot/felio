<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceHttpsForPublicOrigin
{
    /**
     * Ensure redirects that derive their URL from the current request retain
     * HTTPS even when the Cloudflare Tunnel forwards to Nginx over HTTP.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('app.force_https') || ! $response->isRedirection()) {
            return $response;
        }

        $location = $response->headers->get('Location');

        if ($location !== null && str_starts_with($location, 'http://')) {
            $response->headers->set('Location', 'https://'.substr($location, strlen('http://')));
        }

        return $response;
    }
}
