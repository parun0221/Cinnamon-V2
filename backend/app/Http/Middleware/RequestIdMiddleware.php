<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // A client-supplied id is echoed into a response header and into the
        // log. Only a well-formed UUID is accepted, so a hostile value cannot
        // pollute the logs or the header.
        $requestId = $this->resolve($request->headers->get('X-Request-Id'));

        $request->attributes->set(RequestId::ATTRIBUTE, $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolve(?string $incoming): string
    {
        if ($incoming !== null && Str::isUuid($incoming)) {
            return $incoming;
        }

        return RequestId::generate();
    }
}
