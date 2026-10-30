<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bildirişlər: tam siyahı səhifəsi və yuxarı paneldəki zəng üçün qısa JSON.
 */
class NotificationController extends Controller
{
    public function index(Request $request, AlertService $alerts): View
    {
        return view('notifications.index', ['title' => __('Bildirişlər'), 'alerts' => $alerts->forUser($request->user(), fresh: true)]);
    }

    public function summary(Request $request, AlertService $alerts): JsonResponse
    {
        $list = $alerts->forUser($request->user());

        return response()->json(['count' => count($list), 'danger' => count(array_filter($list, fn ($a) => $a['level'] === 'danger')), 'alerts' => $list]);
    }
}
