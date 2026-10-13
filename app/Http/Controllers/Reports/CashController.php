<?php

namespace App\Http\Controllers\Reports;

use App\Reports\Table;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kassa: bütün kassa əməliyyatları (mənfəətə təsir etməyənlər daxil), hesablanmış qalıq və
 * cash_checkpoints (fiziki sayım) ilə müqayisə.
 */
class CashController extends ReportController
{
    public function __invoke(Request $request): View|Response
    {
        $filters = $this->filters($request, 'this_year');
        $direction = $filters->direction !== null && $filters->direction !== 'all' ? $filters->direction : null;

        // qalıq bütün tarix üzrə hesablanır (istiqamət filtri yalnız siyahıya aiddir)
        $all = DB::table('cash_transactions')->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'category_name', 'subcategory', 'description', 'income', 'expense', 'op_type', 'affects_profit', 'direction', 'note']);
        $balance = 0.0;
        $byDate = [];
        foreach ($all as $row) {
            $balance += (float) $row->income - (float) $row->expense;
            $row->balance = $balance;
            $byDate[(string) $row->date] = $balance;
        }
        $balanceAt = function (string $date) use ($byDate): float {
            $result = 0.0;
            foreach ($byDate as $d => $b) {
                if ($d > $date) {
                    break;
                }
                $result = $b;
            }

            return $result;
        };

        $checkpoints = DB::table('cash_checkpoints')->orderBy('date')->get()->map(function ($c) use ($balanceAt) {
            $c->computed = $balanceAt((string) $c->date);
            $c->diff = (float) $c->balance - $c->computed;

            return $c;
        });
        $checkpointTable = Table::make('cp', __('Kassa yoxlama nöqtələri'))
            ->column('date', __('Tarix'), 'date')
            ->column('description', __('Təsvir'))
            ->column('balance', __('Sayım qalığı'), 'money')
            ->column('computed', __('Hesablanmış qalıq'), 'money')
            ->column('diff', __('Fərq'), 'money', ['class' => fn ($v) => abs($v) >= 1 ? 'neg font-semibold' : 'pos'])
            ->source($checkpoints)->sortBy('date')->perPage(50)->withoutSearch()
            ->rowClass(fn ($r) => abs($r->diff) >= 1 ? 'row-warn' : '')
            ->subtitle(__('Fərq = sayım − hesablanmış (|fərq| ≥ 1 ₼ sarı)'));

        $inRange = $all->filter(fn ($r) => (string) $r->date >= $filters->fromDate() && (string) $r->date <= $filters->toDate()
            && ($direction === null || $r->direction === $direction))->values();
        $listTable = Table::make('kassa', __('Kassa hərəkətləri'))
            ->column('date', __('Tarix'), 'date')
            ->column('category_name', __('Kateqoriya'))
            ->column('description', __('Təsvir'))
            ->column('income', __('Mədaxil'), 'money', ['class' => fn ($v) => $v > 0 ? 'pos' : ''])
            ->column('expense', __('Məxaric'), 'money', ['class' => fn ($v) => $v > 0 ? 'neg' : ''])
            ->column('balance', __('Qalıq'), 'money', ['class' => fn () => 'font-semibold'])
            ->column('op_type', __('Tip'))
            ->column('affects_profit', __('Mənfəətə təsir'), 'text', ['format' => fn ($v) => $v ? '<span class="badge badge-primary">'.e(__('Bəli')).'</span>' : '<span class="badge">'.e(__('Xeyr')).'</span>'])
            ->column('direction', __('İstiqamət'))
            ->source($inRange)->sortBy('date')->perPage(100)
            ->footer(['income' => $inRange->sum('income'), 'expense' => $inRange->sum('expense')])
            ->subtitle($direction ? __('Qalıq sütunu bütün istiqamətlər üzrə kassa qalığıdır; siyahı «:direction» ilə filtrlənib', ['direction' => $direction]) : null);

        if ($response = $this->exportIfRequested($request, [$checkpointTable, $listTable], __('Kassa'), $filters)) {
            return $response;
        }

        $monthEnd = [];
        foreach ($all as $row) {
            $monthEnd[substr((string) $row->date, 0, 7)] = $row->balance;
        }
        $cpByMonth = [];
        foreach ($checkpoints as $c) {
            $cpByMonth[substr((string) $c->date, 0, 7)] = (float) $c->balance;
        }
        $chart = [
            'type' => 'line',
            'categories' => array_map(fn ($p) => Fmt::period($p), array_keys($monthEnd)),
            'series' => [
                ['name' => __('Hesablanmış qalıq (ay sonu)'), 'type' => 'area', 'data' => array_map(fn ($v) => round($v, 2), array_values($monthEnd))],
                ['name' => __('Sayım qalığı'), 'type' => 'line', 'data' => array_map(fn ($p) => isset($cpByMonth[$p]) ? round($cpByMonth[$p], 2) : null, array_keys($monthEnd))],
            ],
            'strokeWidths' => [2, 0],
            'markers' => true,
            'colors' => ['--chart-2', '--chart-4'],
        ];
        $last = $checkpoints->last();

        return view('reports.cash', [
            'title' => __('Kassa'),
            'filters' => $filters,
            'kpi' => [
                'balance' => $balance,
                'income' => $inRange->sum('income'),
                'expense' => $inRange->sum('expense'),
                'last' => $last,
                'mismatch' => $checkpoints->filter(fn ($c) => abs($c->diff) >= 1)->count(),
            ],
            'chart' => $chart,
            'checkpointTable' => $checkpointTable, 'checkpointRows' => $checkpointTable->resolve($request),
            'listTable' => $listTable, 'listRows' => $listTable->resolve($request),
        ]);
    }
}
