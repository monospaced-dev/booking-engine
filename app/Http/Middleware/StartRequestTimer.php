<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StartRequestTimer
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started_at', hrtime(true));

        return $next($request);
    }
}
