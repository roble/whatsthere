<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // headers->add() rather than withHeaders(): the latter comes from
        // Illuminate\Http\ResponseTrait, so it exists on a normal Response but
        // not on the Symfony StreamedResponse the chat replies with. Calling it
        // there is fatal, and this middleware only runs in production -- which
        // is how streaming worked locally and 500'd on every deploy.
        $response->headers->add([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => 'upgrade-insecure-requests',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $response;
    }
}
