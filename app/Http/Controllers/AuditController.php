<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\ReportController;
use App\Models\AuditLog;
use App\Reports\Table;
use App\Support\AuditLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tənzimləmələr → Audit jurnalı: kim, nə vaxt, nəyi yaratdı / dəyişdi / ləğv etdi (yalnız admin).
 */
class AuditController extends ReportController
{
    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_month');
        $search = trim((string) $request->query('q'));
        $userId = is_numeric($request->query('user')) ? (int) $request->query('user') : null;
        $outcome = in_array($request->query('outcome'), ['ok', 'error', 'denied'], true) ? $request->query('outcome') : null;
        $outcomes = ['ok' => __('Uğurlu'), 'error' => __('Alınmadı'), 'denied' => __('İcazə yoxdur')];

        $query = DB::table('audit_logs as a')
            ->whereBetween('a.created_at', [$filters->fromDate().' 00:00:00', $filters->toDate().' 23:59:59'])
            ->when($userId, fn ($q) => $q->where('a.user_id', $userId))
            ->when($outcome, fn ($q) => $q->where('a.outcome', $outcome))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('a.doc_nos', 'like', '%'.$search.'%')
                ->orWhere('a.summary', 'like', '%'.$search.'%')->orWhere('a.action', 'like', '%'.$search.'%')
                ->orWhere('a.user_name', 'like', '%'.$search.'%')->orWhere('a.ip', $search)))
            ->select('a.id', 'a.created_at', 'a.user_name', 'a.action', 'a.subject_type', 'a.subject_id', 'a.doc_nos', 'a.summary', 'a.outcome', 'a.ip');

        $table = Table::make('audit', __('Audit jurnalı'))
            ->column('created_at', __('Vaxt'), 'text', ['sql' => 'a.created_at', 'format' => fn ($v) => e(substr((string) $v, 8, 2).'.'.substr((string) $v, 5, 2).'.'.substr((string) $v, 0, 4).' '.substr((string) $v, 11, 5)),
                'link' => fn ($r) => route('audit.show', $r->id)])
            ->column('user_name', __('İstifadəçi'), 'text', ['sql' => 'a.user_name'])
            ->column('action', __('Əməliyyat'), 'text', ['sql' => 'a.action', 'format' => fn ($v) => e(AuditLabels::label((string) $v))])
            ->column('doc_nos', __('Sənəd / obyekt'), 'text', ['sql' => 'a.doc_nos', 'sortable' => false,
                'format' => fn ($v, $r) => e(trim(str_replace('|', ' ', (string) $v)) ?: ($r->subject_type ? $r->subject_type.' #'.$r->subject_id : '—')),
                'link' => fn ($r) => AuditLabels::subjectUrl($r->subject_type, $r->subject_id)])
            ->column('summary', __('Nəticə'), 'text', ['sql' => 'a.summary', 'sortable' => false, 'mobile' => false,
                'format' => fn ($v) => e(mb_strimwidth((string) $v, 0, 140, '…'))])
            ->column('outcome', __('Vəziyyət'), 'text', ['sql' => 'a.outcome',
                'format' => fn ($v) => '<span class="badge '.($v === 'ok' ? '' : 'badge-neg').'">'.e($outcomes[$v] ?? $v).'</span>'])
            ->column('ip', 'IP', 'text', ['sql' => 'a.ip', 'mobile' => false])
            ->source($query)->sortBy('created_at', 'desc')
            ->subtitle(__('Hər yaratma, dəyişiklik, ləğv, giriş və import avtomatik yazılır. Parollar saxlanılmır.'));

        if ($response = $this->exportIfRequested($request, [$table], __('Audit jurnalı'), $filters)) {
            return $response;
        }

        return view('audit.index', [
            'title' => __('Audit jurnalı'), 'filters' => $filters, 'table' => $table, 'rows' => $table->resolve($request),
            'users' => DB::table('audit_logs')->whereNotNull('user_id')->distinct()->orderBy('user_name')->pluck('user_name', 'user_id'),
            'outcomes' => $outcomes, 'search' => $search,
        ]);
    }

    public function show(AuditLog $log): View
    {
        return view('audit.show', ['title' => __('Audit qeydi').' #'.$log->id, 'log' => $log, 'url' => AuditLabels::subjectUrl($log->subject_type, $log->subject_id)]);
    }
}
