<?php

namespace App\Http\Controllers\Reports;

use App\Services\FlowClassifier;
use App\Services\OfficialRevenueService;
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
                ['name' => __('Brüt mənfəət'), 'type' => 'column', 'data' => array_map(fn ($c) => round($report['lines']['gross_profit'][$c] ?? 0, 2), $columns)],
                ['name' => __('Xərclər'), 'type' => 'column', 'data' => array_map(fn ($c) => round($report['lines']['expenses'][$c] ?? 0, 2), $columns)],
                ['name' => __('Xalis mənfəət'), 'type' => 'line', 'data' => array_map(fn ($c) => round($report['lines']['net_profit'][$c] ?? 0, 2), $columns)],
            ],
            'colors' => ['--chart-1', '--chart-6', '--chart-3'],
            'strokeWidths' => [0, 0, 3],
            'markers' => true,
        ];

        return view('reports.pnl', [
            'title' => __('Mənfəət-zərər (P&L)'),
            'filters' => $filters,
            'granularity' => $granularity,
            'columns' => $columns,
            'labels' => $labels,
            'lines' => $lines,
            'chart' => $chart,
            // xanaya klik → hesablama popup-u (eyni filtrlər)
            'detailQuery' => $filters->query(['gran' => $granularity]),
            'official' => app(OfficialRevenueService::class)->cashSummary($filters),
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
        // «name» — popup üçün xam açar (kateqoriya / bank qrupu), «label» — göstərilən ad
        $children = fn (array $set) => array_map(fn ($name, $values) => ['label' => (string) $name, 'name' => (string) $name, 'values' => $values], array_keys($set), array_values($set));

        $net = array_sum($l['net_sales'] ?? []);
        $netWithExport = $net + array_sum($l['export_revenue'] ?? []) + array_sum($l['official_extra'] ?? []);
        $lines = [
            ['key' => 'sales', 'label' => __('Satışlar'), 'values' => $l['sales'] ?? [], 'style' => '', 'type' => 'money', 'children' => []],
            ['key' => 'returns', 'label' => __('− Qaytarma'), 'values' => array_map(fn ($v) => -$v, $l['returns'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['key' => 'net_sales', 'label' => __('Xalis satış'), 'values' => $l['net_sales'] ?? [], 'style' => 'subtotal', 'type' => 'money', 'children' => []],
            ['key' => 'sale_cogs', 'label' => __('− Satılan malın mayası'), 'values' => array_map(fn ($v) => -$v, $l['sale_cogs'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['key' => 'gift_cogs', 'label' => __('− Hədiyyə / daxili istifadə mayası'), 'values' => array_map(fn ($v) => -$v, $l['gift_cogs'] ?? []), 'style' => 'muted', 'type' => 'money', 'children' => []],
            ['key' => 'gross_profit', 'label' => __('Brüt mənfəət'), 'values' => $l['gross_profit'] ?? [], 'style' => 'subtotal', 'type' => 'money', 'children' => []],
            ['key' => 'gross_margin', 'label' => __('Brüt marja'), 'values' => $l['margin'] ?? [], 'style' => 'muted', 'type' => 'pct', 'children' => []],
            ['key' => 'expenses', 'label' => __('− Əməliyyat xərcləri'), 'values' => array_map(fn ($v) => -$v, $l['expenses'] ?? []), 'style' => 'expand', 'type' => 'money',
                'children' => array_map(fn ($c) => ['label' => $c['label'], 'name' => $c['name'], 'values' => array_map(fn ($v) => -$v, $c['values'])], $children($report['expenses']))],
            ['key' => 'bank', 'label' => __('− Rəsmi xərclər (bank)'), 'values' => array_map(fn ($v) => -$v, $l['bank_expenses'] ?? []), 'style' => 'expand', 'type' => 'money',
                'children' => array_map(fn ($c) => ['label' => FlowClassifier::groupLabel($c['label']), 'name' => $c['name'], 'values' => array_map(fn ($v) => -$v, $c['values'])], $children($report['bank']))],
            ['key' => 'other_income', 'label' => __('+ Digər gəlirlər'), 'values' => $l['other_income'] ?? [], 'style' => 'expand', 'type' => 'money', 'children' => $children($report['other_income'])],
            ['key' => 'export', 'label' => __('+ Re-eksport nəticəsi (rəsmi MZ)'), 'values' => $l['export_result'] ?? [], 'style' => 'expand', 'type' => 'money', 'children' => [
                ['label' => __('Gəlir — AR hüdudlarından kənar satış'), 'values' => $l['export_revenue'] ?? []],
                ['label' => __('Xərc — re-eksportla bağlı (maya, daşınma)'), 'values' => array_map(fn ($v) => -$v, $l['export_cost'] ?? [])],
            ]],
            ['key' => 'official_extra', 'label' => __('+ Rəsmi gəlir — sistemdə olmayan satış (bank)'), 'values' => $l['official_extra'] ?? [], 'style' => '', 'type' => 'money', 'children' => []],
            ['key' => 'net_profit', 'label' => __('Xalis mənfəət'), 'values' => $l['net_profit'] ?? [], 'style' => 'total', 'type' => 'money', 'children' => []],
            ['key' => 'net_margin', 'label' => __('Xalis marja'), 'values' => $l['net_margin'] ?? [], 'style' => 'muted', 'type' => 'pct', 'children' => []],
        ];
        if (array_sum($l['founder'] ?? []) != 0.0) {
            $lines[] = ['key' => 'founder', 'label' => __('− Təsisçiyə ödənişlər'), 'values' => array_map(fn ($v) => -$v, $l['founder']), 'style' => 'expand', 'type' => 'money',
                'children' => array_map(fn ($c) => ['label' => $c['label'], 'name' => $c['name'], 'values' => array_map(fn ($v) => -$v, $c['values'])], $children($report['founder']))];
            $lines[] = ['key' => 'retained', 'label' => __('Bölüşdürülməmiş mənfəət'), 'values' => $l['retained'], 'style' => 'subtotal', 'type' => 'money', 'children' => []];
        }
        foreach ($lines as &$line) {
            $line['total'] = $line['type'] === 'money' ? array_sum($line['values']) : null;
        }
        unset($line);
        foreach ($lines as &$line) {
            match ($line['key'] ?? null) {
                'gross_margin' => $line['total'] = abs($net) > 0.005 ? array_sum($l['gross_profit'] ?? []) / $net : null,
                'net_margin' => $line['total'] = abs($netWithExport) > 0.005 ? array_sum($l['net_profit'] ?? []) / $netWithExport : null,
                default => null,
            };
        }
        unset($line);
        // bank / re-eksport məlumatı yoxdursa boş sətri göstərmə
        $lines = array_values(array_filter($lines, fn ($line) => match ($line['key'] ?? null) {
            'bank' => $line['children'] !== [],
            'export' => abs(array_sum($l['export_revenue'] ?? [])) + abs(array_sum($l['export_cost'] ?? [])) > 0.005,
            'official_extra' => abs(array_sum($l['official_extra'] ?? [])) > 0.005,
            default => true,
        }));

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
        $headers = array_merge([__('Göstərici')], $labels, [__('Cəmi')]);
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
