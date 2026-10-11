<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $missingRoute = $request->route() === null;

            return response()->json([
                'message' => $missingRoute ? 'Route not found.' : 'Resource not found.',
                'code' => $missingRoute ? 'route_not_found' : 'not_found',
            ], 404);
        });
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if (! request()->is('api/*') || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $payload = json_decode($response->getContent(), true) ?? [];
            $payload['message'] ??= \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Request failed.';
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
