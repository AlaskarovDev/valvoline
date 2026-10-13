<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Paylaşılan hostinq provayderin CDN / proksisi arxasındadır.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [SetLocale::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

/*
 * Paylaşılan hostinq quruluşu (deploy/agent.php yazır), hamısı public_html daxilində:
 *   public_html/            veb kök: index.php, .htaccess, build/
 *   public_html/_app/       tətbiq (veb girişi bağlıdır)
 *   public_html/_app_shared/.env, public_html/_app_storage/ — buraxılışlar arasında qalır
 */
if (is_file(dirname(__DIR__).'/.hosting-layout')) {
    $root = dirname(__DIR__, 2);
    $app->usePublicPath($root);
    $app->useEnvironmentPath($root.'/_app_shared');
    $app->useStoragePath($root.'/_app_storage');
}

return $app;
