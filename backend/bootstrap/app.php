<?php

use App\Http\Middleware\AttackDetectionMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register attack detection middleware alias
        $middleware->alias([
            'attack.detection' => AttackDetectionMiddleware::class,
        ]);

        // Add attack detection to API middleware group
        $middleware->api(prepend: [
            AttackDetectionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle database/SQL exceptions - never expose SQL to frontend
        $exceptions->render(function (QueryException $e, Request $request) {
            // Log the full error for debugging
            Log::error('Database error', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                // Check for specific constraint violations to provide helpful messages
                $message = 'A database error occurred. Please try again.';
                $code = 500;

                // Check for constraint violations (e.g., negative balance)
                if (str_contains($e->getMessage(), 'Check constraint') ||
                    str_contains($e->getMessage(), 'chk_')) {
                    $message = 'Operation failed due to validation constraints. Please check your request.';
                    $code = 422;
                }

                // Check for duplicate entry
                if (str_contains($e->getMessage(), 'Duplicate entry') ||
                    $e->getCode() === '23000') {
                    $message = 'A record with this information already exists.';
                    $code = 409;
                }

                return response()->json([
                    'message' => $message,
                    'error' => 'database_error',
                ], $code);
            }
        });

        // Handle generic exceptions for API routes
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // Don't override HTTP exceptions (they already have proper messages)
                if ($e instanceof HttpException) {
                    return null;
                }

                // Log unexpected errors
                if (!config('app.debug')) {
                    Log::error('Unexpected error', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'message' => 'An unexpected error occurred. Please try again.',
                        'error' => 'server_error',
                    ], 500);
                }
            }
        });
    })->create();
