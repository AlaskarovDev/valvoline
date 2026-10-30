<?php

namespace App\Http\Middleware;

use App\Services\BackupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Paylaşılan hostinqdə cron olmadığı üçün: daxil olmuş istifadəçinin sorğusundan sonra (cavab göndəriləndən sonra)
 * son nüsxə 24 saatdan köhnədirsə yeni ehtiyat nüsxəsi yaradılır. Yoxlama saatda bir dəfə aparılır.
 */
class DailyBackup
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null && ! app()->runningUnitTests() && Cache::add('backup:checked', 1, 3600)) {
            app()->terminating(function () {
                try {
                    Cache::lock('backup:running', 900)->get(function () {
                        $backups = app(BackupService::class);
                        if ($backups->isDue()) {
                            $backups->create();
                        }
                    });
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $response;
    }
}
