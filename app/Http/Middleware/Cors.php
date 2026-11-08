<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowed = array_filter(array_map('trim', explode(',', (string) config('app.frontend_origins', 'http://localhost:4200'))));
        $isAllowed = $origin !== null && in_array($origin, $allowed, true);

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
        $response = $next($request);
        }

        if ($isAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS, PUT, DELETE');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Auth-Token, Origin, Authorization');
        $response->headers->set('Accept-Language', 'en');

        return $response;
    }
}
