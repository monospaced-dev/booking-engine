<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Component\HttpFoundation\Response;

class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = config('metrics.token');
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $authorization = $request->header('Authorization', '');
        preg_match('/^Bearer (.*)$/iD', $authorization, $matches);
        if (! hash_equals($token, $matches[1] ?? '')) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $body = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

        return response($body, 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
