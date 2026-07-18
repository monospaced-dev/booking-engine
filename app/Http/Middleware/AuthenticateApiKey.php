<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('x-api-key');

        if (!$apiKey) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $hashedApiKey = ApiKey::hashKey($apiKey);

        $storedKey = ApiKey::where('key', $hashedApiKey)
            ->where('is_active', true)
            ->first();

        if (!$storedKey) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $storedKey->last_used_at = now();
        $storedKey->save();

        return $next($request);
    }
}
