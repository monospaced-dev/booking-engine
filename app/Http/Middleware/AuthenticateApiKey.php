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

        if (! $apiKey) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'unauthorized'], 401);
        }

        $hashedApiKey = ApiKey::hashKey($apiKey);

        $storedKey = ApiKey::where('key', $hashedApiKey)
            ->where('is_active', true)
            ->first();

        if (! $storedKey) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'unauthorized'], 401);
        }

        $request->attributes->set('api_key_id', $storedKey->id);

        $now = now();
        ApiKey::whereKey($storedKey->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $now->copy()->subSeconds(60)))
            ->update(['last_used_at' => $now]);

        return $next($request);
    }
}
