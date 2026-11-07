<?php

use App\Console\Commands\SetupHrdUser;
use App\Http\Middleware\EnsureHrdUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        SetupHrdUser::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', EnsureHrdUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
