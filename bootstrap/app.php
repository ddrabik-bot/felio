<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('portfolio/imports/*') || $request->is('portfolio/import-batches/*'),
        );
        $exceptions->render(function (InvalidArgumentException $exception, Request $request) {
            if ($request->is('portfolio/imports/*')) {
                $statusHistory = $request->is('portfolio/imports/xtb')
                    ? ['UPLOADED', 'ANALYZING', 'FAILED']
                    : ['CONFIRMED', 'PROCESSING', 'FAILED'];

                return response()->json([
                    'status' => 'FAILED',
                    'statusHistory' => $statusHistory,
                    'errors' => ['workbook' => [$exception->getMessage()]],
                ], 422);
            }
        });
    })->create();
