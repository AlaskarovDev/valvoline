<?php

namespace App\Http\Controllers;

use App\Services\PeriodLock;
use App\Support\AppSettings;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tənzimləmələr → Dövr bağlama: hesabatı verilmiş dövrə sənəd yazılmasın, dəyişdirilməsin, ləğv edilməsin.
 */
class PeriodLockController extends Controller
{
    public function index(): View
    {
        $setting = DB::table('app_settings as s')->leftJoin('users as u', 'u.id', '=', 's.updated_by')
            ->where('s.key', PeriodLock::KEY)->first(['s.value', 's.updated_at', 'u.name as user_name']);

        return view('settings.period-lock', ['title' => __('Dövr bağlama'), 'lockedUntil' => PeriodLock::lockedUntil(), 'setting' => $setting]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locked_until' => ['nullable', 'date', 'before_or_equal:today']], [], ['locked_until' => __('Bağlanma tarixi')]);
        $date = $request->boolean('unlock') ? null : ($data['locked_until'] ?? null);
        PeriodLock::set($date, $request->user()->id);
        AppSettings::flush();

        return redirect()->route('period-lock.index')->with('popup', $date
            ? ['title' => __('Dövr bağlandı'), 'message' => __(':date daxil olmaqla əvvəlki tarixlərə sənəd yazılmayacaq.', ['date' => Fmt::date($date)])]
            : ['title' => __('Dövr açıldı'), 'message' => __('Bütün tarixlərə sənəd yazmaq olar.')]);
    }
}
