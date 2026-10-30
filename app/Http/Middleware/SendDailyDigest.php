<?php

namespace App\Http\Middleware;

use App\Services\DigestService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Paylaşılan hostinqdə cron olmadığı üçün (DailyBackup kimi): daxil olmuş istifadəçinin sorğusundan sonra
 * yarım saatda bir yoxlanılır — 08:00-dan sonra bu gün göndərilməyən xülasələr göndərilir.
 */
class SendDailyDigest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null && ! app()->runningUnitTests() && Cache::add('digest:checked', 1, 1800)) {
            app()->terminating(function () {
                try {
                    Cache::lock('digest:running', 600)->get(fn () => app(DigestService::class)->sendDue());
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $response;
    }
}
