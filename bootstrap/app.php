<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 'rbac.context' MUST run AFTER 'auth:sanctum' (it reads the resolved
        // Shop + current access token), so it is applied per route-group in
        // routes/api.php, never appended globally to the api group.
        $middleware->alias([
            'rbac.context' => \App\Http\Middleware\SetRbacContext::class,
            'can.perm' => \App\Http\Middleware\EnsurePermission::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscribed::class,
            'module' => \App\Http\Middleware\EnsureShopModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
