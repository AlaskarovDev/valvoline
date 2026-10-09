<?php

namespace App\Http\Controllers\Reports;

use App\Services\ProfitLossService;
use App\Support\Fmt;
use App\Support\Spreadsheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProfitLossController extends ReportController
{
    public function __invoke(Request $request, ProfitLossService $pnl): View|Response
    {
        $filters = $this->filters($request, 'this_year', 'VALVO');
        $granularity = $request->query('gran') === 'year' ? 'year' : 'month';
        $report = $pnl->build($filters, $granularity);
        $columns = $report['columns'];
        $labels = array_map(fn ($c) => $granularity === 'year' ? (string) $c : Fmt::period((string) $c), $columns);

        $lines = $this->lines($report);
        if (in_array($request->query('export'), ['xlsx', 'pdf'], true)) {
            return $this->export($request->query('export'), $lines, $columns, $labels, $filters->label());
        }

        $chart = [
            'type' => 'line',
            'categories' => $labels,
            'series' => [
                ['name' => 'Brüt mənfəət', 'type' => 'column', 'data' => array_map(fn ($c) => round($report['lines']['gross_profit'][$c] ?? 0, 2), $columns)],
                ['name' => 'Xərclər', 'type' => 'column', 'data' => array_map(fn ($c) => round($report['lines']['expenses'][$c] ?? 0, 2), $columns)],
                ['name' => 'Xalis mənfəət', 'type' => 'line', 'data' => array_map(fn ($c) => round($report['lines']['net_profit'][$c] ?? 0, 2), $columns)],
            ],
            'colors' => ['--chart-1', '--chart-6', '--chart-3'],
            'strokeWidths' => [0, 0, 3],
            'markers' => true,
        ];

        return view('reports.pnl', [
            'title' => 'Mənfəət-zərər (P&L)',
            'filters' => $filters,
            'granularity' => $granularity,
            'columns' => $columns,
            'labels' => $labels,
            'lines' => $lines,
            'chart' => $chart,
        ]);
    }

    /**
     * P&L sətirləri: [açar, ad, dəyərlər, üslub, tip, alt sətirlər].
     *
     * @param  array{columns: list<string>, lines: array<string, array<string, float>>, expenses: array<string, array<string, float>>, other_income: array<string, array<string, float>>}  $report
     * @return list<array{label: string, values: array<string, float>, style: string, type: string, total: float|null, children: list<array{label: string, values: array<string, float>}>}>
     */
    private function lines(array $report): array
    {
        $l = $report['lines'];
        $children = fn (array $set) => array_map(fn ($name, $values) => ['label' => $name, 'values' => $values], array_keys($set), array_values($set));

        $net = array_sum($l['net_sales'] ?? []);
        $lines = [
            ['label' => 'Satış', 'values' => $l['sales'] ?? [], 'style' => '', 'type' => 'money', 'children' => []],
            ['label' => '− Qaytarma', 'values' => array_map(fn ($v) => -$v, $l['returns'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['label' => 'Xalis satış', 'values' => $l['net_sales'] ?? [], 'style' => 'subtotal', 'type' => 'money', 'children' => []],
            ['label' => '− Satılan malın mayası', 'values' => array_map(fn ($v) => -$v, $l['sale_cogs'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['label' => '− Hədiyyə / daxili istifadə mayası', 'values' => array_map(fn ($v) => -$v, $l['gift_cogs'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['label' => 'Brüt mənfəət', 'values' => $l['gross_profit'] ?? [], 'style' => 'subtotal', 'type' => 'money', 'children' => []],
            ['label' => 'Brüt marja', 'values' => $l['margin'] ?? [], 'style' => 'muted', 'type' => 'pct', 'children' => []],
            ['label' => '− Əməliyyat xərcləri', 'values' => array_map(fn ($v) => -$v, $l['expenses'] ?? []), 'style' => 'expand', 'type' => 'money',
                'children' => array_map(fn ($c) => ['label' => $c['label'], 'values' => array_map(fn ($v) => -$v, $c['values'])], $children($report['expenses']))],
            ['label' => '+ Digər gəlirlər', 'values' => $l['other_income'] ?? [], 'style' => 'expand', 'type' => 'money', 'children' => $children($report['other_income'])],
            ['label' => 'Xalis mənfəət', 'values' => $l['net_profit'] ?? [], 'style' => 'total', 'type' => 'money', 'children' => []],
            ['label' => 'Xalis marja', 'values' => $l['net_margin'] ?? [], 'style' => 'muted', 'type' => 'pct', 'children' => []],
        ];
        foreach ($lines as &$line) {
            $line['total'] = $line['type'] === 'money' ? array_sum($line['values']) : null;
        }
        unset($line);
        $lines[6]['total'] = abs($net) > 0.005 ? array_sum($l['gross_profit'] ?? []) / $net : null;
        $lines[10]['total'] = abs($net) > 0.005 ? array_sum($l['net_profit'] ?? []) / $net : null;

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $columns
     * @param  list<string>  $labels
     */
    private function export(string $format, array $lines, array $columns, array $labels, string $filterLabel): Response
    {
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->exportRow($line['label'], $line['values'], $columns, $line['type'], $line['total']);
            foreach ($line['children'] as $child) {
                $rows[] = $this->exportRow('    '.$child['label'], $child['values'], $columns, $line['type'], array_sum($child['values']));
            }
        }
        $headers = array_merge(['Göstərici'], $labels, ['Cəmi']);
        $file = 'menfeet-zerer-'.now()->format('Y-m-d');

        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'pnl');
            Spreadsheet::write($path, ['P&L' => ['headers' => $headers, 'rows' => $rows]]);

            return response()->download($path, $file.'.xlsx')->deleteFileAfterSend();
        }

        return Pdf::loadView('exports.pnl', ['headers' => $headers, 'lines' => $lines, 'columns' => $columns, 'filterLabel' => $filterLabel])
            ->setPaper('a4', 'landscape')->download(Str::slug($file).'.pdf');
    }

    /**
     * @param  array<string, float>  $values
     * @param  list<string>  $columns
     * @return list<mixed>
     */
    private function exportRow(string $label, array $values, array $columns, string $type, ?float $total): array
    {
        $row = [$label];
        foreach ($columns as $c) {
            $v = $values[$c] ?? 0.0;
            $row[] = $type === 'pct' ? round($v * 100, 2) : round($v, 2);
        }
        $row[] = $total === null ? null : ($type === 'pct' ? round($total * 100, 2) : round($total, 2));

        return $row;
    }
}
