<?php

use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiKey::class);
        $middleware->alias([
            'auth.apikey' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }

            $missingRoute = $request->route() === null;

            return response()->json([
                'message' => $missingRoute ? 'Route not found.' : 'Resource not found.',
                'code' => $missingRoute ? 'route_not_found' : 'not_found',
            ], 404);
        });
        $exceptions->respond(function (Response $response) {
            if (! request()->is('api/*') || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $payload = json_decode($response->getContent(), true) ?? [];
            $payload['message'] ??= Response::$statusTexts[$status] ?? 'Request failed.';
            $payload['code'] ??= match ($status) {
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                422 => 'validation_failed',
                429 => 'too_many_requests',
                default => $status >= 500 ? 'internal_error' : 'request_failed',
            };

            $response->setContent(json_encode($payload));

            return $response;
        });
    })->create();
