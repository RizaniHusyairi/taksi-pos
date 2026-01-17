<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

     $csp = "img-src 'self' data:; "
            . "font-src 'self';";

        $response->headers->set(
            'Content-Security-Policy',
            $csp
        );

        return $response;
    }
}
