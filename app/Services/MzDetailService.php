<?php

namespace App\Services;

use App\Reports\ReportFilters;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MZ (vergi bəyannaməsi) maddəsinin detalı. MZ vərəqində yalnız illik məbləğ var — popup maddənin qarşılığını göstərir:
 * eyni xərc qrupuna düşən bank ödənişləri (Cash Flow), sistemdəki kassa xərcləri / satış / maya və fərq.
 */
class MzDetailService
{
    public function __construct(private ProfitLossService $pnl) {}

    /**
     * MZ sətrinin növü: income | cogs (221.4, re-eksport 232.1) | depreciation (227 / 228) | opex.
     */
    public static function kind(object $line): string
    {
        $code = (string) $line->tax_code;

        return match (true) {
            $line->section === 'income' => 'income',
            str_starts_with($code, '221.4') || ProfitLossService::isExportLine($line) => 'cogs',
            str_starts_with($code, '227') || str_starts_with($code, '228') => 'depreciation',
            default => 'opex',
        };
    }

    /**
     * @return array{line: object|null, kind: string, amount: float, bank: Collection<int, object>, bankTitle: string|null, system: list<array{label: string, amount: float, url: string|null}>, systemTitle: string|null, note: string}
     */
    public function line(int $year, string $code, string $name): array
    {
        $line = DB::table('acc_pl_lines')->where('year', $year)->whereIn('section', ['income', 'expense'])->get()
            ->first(fn ($l) => (string) $l->tax_code === $code && NameNormalizer::normalize($l->name) === NameNormalizer::normalize($name))
            ?? DB::table('acc_pl_lines')->where('year', $year)->where('tax_code', $code)->first();
        $kind = $line ? self::kind($line) : 'opex';
        $filters = new ReportFilters(Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear(), 'custom', direction: 'VALVO');
        $range = ['from' => $year.'-01-01', 'to' => $year.'-12-31', 'gran' => 'year'];
        $classifier = new FlowClassifier;
        $flows = DB::table('bank_flow_lines')->where('year', $year)->where('amount', '!=', 0)->orderBy('row_no')->orderBy('period')->get()
            ->map(function ($f) use ($classifier) {
                $c = $f->section === 'other' ? ['type' => 'fx', 'group' => null] : $classifier->classify((string) $f->counterparty, (string) $f->section);
                $f->type = $c['type'];
                $f->group = $c['group'] ?: 'Sair xərclər';

                return $f;
            });
        $system = [];
        $note = '';

        if ($kind === 'income') {
            $isExport = $line && ProfitLossService::isExportLine($line);
            $isFx = in_array(substr($code, 0, 3), ['214', '219'], true);
            $bank = match (true) {
                $isExport => $flows->where('section', 'income')->filter(fn ($f) => OfficialRevenueService::isExportBuyer((string) $f->normalized)),
                $isFx => $flows->where('section', 'other'),
                default => $flows->where('section', 'income')->where('type', 'revenue')->reject(fn ($f) => OfficialRevenueService::isExportBuyer((string) $f->normalized)),
            };
            $bankTitle = $isExport ? __('Xarici alıcının bank ödənişləri') : ($isFx ? __('Valyutanın yenidən qiymətləndirilməsi (Cash Flow)') : __('Müştərilərdən bank daxilolmaları'));
            if (! $isExport && ! $isFx) {
                $lines = $this->pnl->build($filters, 'year', false)['lines'];
                $system[] = ['label' => __('Satışlar (04 faylı)'), 'amount' => (float) array_sum($lines['sales'] ?? []), 'url' => route('pnl.detail', $range + ['line' => 'sales', 'col' => 'total'])];
                $system[] = ['label' => __('− Qaytarma (05 faylı)'), 'amount' => -(float) array_sum($lines['returns'] ?? []), 'url' => route('pnl.detail', $range + ['line' => 'returns', 'col' => 'total'])];
                $note = __('MZ-də yalnız rəsmiləşdirilmiş satış var; sistemdə nağd satışlar da daxildir. Fərq nağd satışlardan və ƏDV-dən yaranır.');
            }
        } elseif ($kind === 'cogs') {
            $bank = $flows->where('section', 'payment')->where('type', 'cogs');
            $bankTitle = __('Mal alışı / gömrük ödənişləri (Cash Flow)');
            if ($line && ! ProfitLossService::isExportLine($line)) {
                $lines = $this->pnl->build($filters, 'year', false)['lines'];
                $system[] = ['label' => __('Satılan malın mayası (:method)', ['method' => CostingService::methodLabel()]), 'amount' => (float) array_sum($lines['sale_cogs'] ?? []), 'url' => route('pnl.detail', $range + ['line' => 'sale_cogs', 'col' => 'total'])];
                $system[] = ['label' => __('Hədiyyə / daxili istifadə mayası'), 'amount' => (float) array_sum($lines['gift_cogs'] ?? []), 'url' => route('pnl.detail', $range + ['line' => 'gift_cogs', 'col' => 'total'])];
            }
            $note = __('Ödəniş tarixi malın satıldığı dövrdən fərqlənir — bank ödənişi maya dəyərinə bərabər olmaya bilər.');
        } else {
            $group = ReconciliationService::mzGroup($code, $line->name ?? $name);
            // sosial sığortaya icbari tibbi sığorta da daxildir
            $groups = $group === 'Sosial sığorta (DSMF)' ? [$group, 'İcbari tibbi sığorta'] : [$group];
            $bank = $flows->where('section', 'payment')->where('type', FlowClassifier::EXPENSE)->filter(fn ($f) => in_array($f->group, $groups, true));
            $bankTitle = __('Bank ödənişləri — «:group» qrupu (Cash Flow)', ['group' => FlowClassifier::groupLabel($group)]);
            foreach ($this->pnl->cashByCategory($filters, 'year', 'expense') as $category => $values) {
                if (in_array(ReconciliationService::cashGroup((string) $category), $groups, true)) {
                    $system[] = ['label' => (string) $category, 'amount' => (float) array_sum($values), 'url' => route('pnl.detail', $range + ['line' => 'expenses', 'col' => 'total', 'child' => (string) $category])];
                }
            }
            $note = $kind === 'depreciation'
                ? __('Amortizasiya pul hərəkəti deyil — bankda və kassada qarşılığı yoxdur.')
                : __('MZ hesablanmış xərcdir (hesablama metodu), bank — ödənilmiş məbləğ; fərq ödəmə vaxtından və vergi tutulmalarından yaranır.');
        }

        return [
            'line' => $line,
            'kind' => $kind,
            'amount' => (float) ($line->amount ?? 0),
            'bank' => $bank->values(),
            'bankTitle' => $bankTitle,
            'system' => $system,
            'systemTitle' => $system === [] ? null : ($kind === 'opex' ? __('Sistemdə kassa xərcləri (08 faylı)') : __('Sistemdə (importlar)')),
            'note' => $note,
        ];
    }

    /**
     * Qrup sətri (Gəlirlər, Malın dəyəri, Əməliyyat xərcləri, Amortizasiya): maddələrin tərkibi.
     *
     * @return Collection<int, object>
     */
    public function group(int $year, string $kind): Collection
    {
        $lines = DB::table('acc_pl_lines')->where('year', $year)->whereIn('section', ['income', 'expense'])->orderBy('row_no')->get()
            ->filter(fn ($l) => self::kind($l) === $kind)->values();
        $total = (float) $lines->sum('amount');

        return $lines->map(fn ($l) => (object) [
            'code' => (string) $l->tax_code, 'name' => $l->name, 'amount' => (float) $l->amount,
            'share' => abs($total) > 0.005 ? (float) $l->amount / $total : null,
        ]);
    }
}
