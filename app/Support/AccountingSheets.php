<?php

namespace App\Support;

use App\Services\ChartLedgerService;
use App\Services\FlowClassifier;
use App\Services\OfficialRevenueService;
use App\Services\ReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Mühasibat səhifələrinin Excel exportu: hər səhifənin view-a verdiyi məlumat vərəqlərə çevrilir
 * (ekranda nə görünürsə — eyni süzgəc və il ilə).
 */
class AccountingSheets
{
    /**
     * @param  array<string, array{headers: list<string>, rows: iterable<list<mixed>>}>  $sheets
     */
    public static function download(string $file, array $sheets): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'acc');
        Spreadsheet::write($path, $sheets);

        return response()->download($path, $file.'-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, array{headers: list<string>, rows: list<list<mixed>>}>
     */
    public static function chart(array $d): array
    {
        $natures = ['A' => __('Aktiv'), 'P' => __('Passiv'), 'K' => __('Kontr-aktiv'), 'G' => __('Gəlir'), 'X' => __('Xərc'), 'N' => __('Nəticə'), 'B' => __('Balansdankənar')];
        $signed = fn (string $code, $v) => $v === null ? null : (in_array($code, ChartLedgerService::CONTRA_INCOME, true) ? -1 : 1) * (float) $v;
        $rows = [];
        $totals = [];
        foreach ($d['accounts'] as $section => $list) {
            $sectionOfficial = 0.0;
            $sectionManagement = 0.0;
            foreach ($list->groupBy('group_code') as $group => $items) {
                $groupOfficial = (float) $items->sum(fn ($a) => $signed($a->code, $d['balances'][$a->code] ?? null) ?? 0.0);
                $groupManagement = (float) $items->sum(fn ($a) => $signed($a->code, $d['management'][$a->code] ?? null) ?? 0.0);
                $totals[] = [(string) $section, $group.' · '.($d['groups'][$group] ?? ''), $groupOfficial, $groupManagement];
                $sectionOfficial += $groupOfficial;
                $sectionManagement += $groupManagement;
                foreach ($items as $a) {
                    $rows[] = [(string) $section, (string) $group, $a->code, $a->name, $natures[$a->nature] ?? $a->nature,
                        isset($d['balances'][$a->code]) ? (float) $d['balances'][$a->code] : null,
                        isset($d['management'][$a->code]) ? (float) $d['management'][$a->code] : null];
                }
            }
            $totals[] = [(string) $section, __('Bölmə :n cəmi', ['n' => $section]), $sectionOfficial, $sectionManagement];
        }
        $p = $d['profit'];
        $totals[] = ['', __('Bölmə 6 — Gəlirlər'), $p['official']['income'], $p['management']['income']];
        $totals[] = ['', __('− Bölmə 7 — Xərclər'), -$p['official']['expense'], -$p['management']['expense']];
        $totals[] = ['', __('= Mənfəət (zərər)'), $p['official']['result'], $p['management']['result']];

        return [
            __('Hesablar planı') => ['headers' => [__('Bölmə'), __('Qrup'), __('Hesab'), __('Ad'), __('Xarakter'), __('Rəsmi'), __('İdarəetmə')], 'rows' => $rows],
            __('Cəmlər') => ['headers' => [__('Bölmə'), __('Qrup'), __('Rəsmi'), __('İdarəetmə')], 'rows' => $totals],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function balance(array $d): array
    {
        $rows = [];
        foreach ([__('Aktivlər') => $d['assets'], __('Öhdəliklər və kapital') => $d['liabilities']] as $side => $list) {
            foreach ($list as $l) {
                $rows[] = [$side, $l->legacy_code, $l->name, $l->chart_code, $l->chart_name, (float) $l->amount];
            }
            $rows[] = [$side, '', __('Cəmi'), '', '', (float) $list->sum('amount')];
        }

        return [__('Balans').' '.($d['year'] ?? '') => ['headers' => [__('Tərəf'), __('Köhnə'), __('Hesab'), __('Yeni hesab'), __('Ad'), __('Məbləğ')], 'rows' => $rows]];
    }

    /**
     * İdarəetmə balansı + hər sətrin detalı ayrıca vərəqdə (mallar, müştərilər, kassa, mənfəət).
     *
     * @param  array<string, mixed>  $d
     */
    public static function balanceManagement(array $d, ChartLedgerService $ledger): array
    {
        $rows = [];
        foreach ([__('Aktivlər') => $d['assets'], __('Öhdəliklər və kapital') => $d['liabilities']] as $side => $list) {
            foreach ($list as $l) {
                $rows[] = [$side, $l->code, $l->name, $l->amount, $l->official];
            }
            $rows[] = [$side, '', __('Cəmi'), (float) $list->sum('amount'), null];
        }
        $sheets = [__('İdarəetmə balansı') => ['headers' => [__('Tərəf'), __('Hesab'), __('Ad'), __('İdarəetmə'), __('Rəsmi')], 'rows' => $rows]];
        foreach (['205', '211', '542', '221', '341'] as $code) {
            $detail = $ledger->detail($code, $d['date']);
            if ($detail !== null) {
                $sheets[$code.' '.$detail['title']] = ['headers' => $detail['columns'], 'rows' => array_merge($detail['rows'], $detail['foot'] ? [$detail['foot']] : [])];
            }
        }
        $parts = $ledger->managementBalance($d['date']);
        $equity = [];
        foreach (['343', '344', '301'] as $code) {
            foreach ($parts[$code] ?? [] as $part) {
                $equity[] = [$code, $part['label'], $part['amount']];
            }
        }
        $sheets[__('Kapital')] = ['headers' => [__('Hesab'), __('Komponent'), __('Məbləğ')], 'rows' => $equity];

        return $sheets;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function profitLossTax(array $d): array
    {
        $statement = $d['statement'];
        $headers = array_merge([__('Göstərici'), __('Kod')], array_map(fn ($y) => $statement['labels'][$y], $statement['columns']));
        $rows = [];
        foreach ($statement['lines'] as $line) {
            $rows[] = array_merge([$line['label'], ''], array_map(fn ($y) => (float) ($line['values'][$y] ?? 0), $statement['columns']));
            foreach ($line['children'] as $child) {
                $rows[] = array_merge(['    '.($child['name'] ?? $child['label']), $child['code'] ?? ''], array_map(fn ($y) => (float) ($child['values'][$y] ?? 0), $statement['columns']));
            }
        }
        $original = [];
        foreach ([__('Gəlirlər') => $d['income'], __('Xərclər') => $d['expenses']] as $section => $list) {
            foreach ($list as $l) {
                $original[] = [$section, $l->tax_code, $l->name, (float) $l->amount];
            }
        }
        if ($d['result'] ?? null) {
            $original[] = ['', '', $d['result']->name, (float) $d['result']->amount];
        }

        return [
            __('MZ illər üzrə') => ['headers' => $headers, 'rows' => $rows],
            __('MZ').' '.($d['year'] ?? '') => ['headers' => [__('Bölmə'), __('Kod'), __('Maddə'), __('Məbləğ')], 'rows' => $original],
        ];
    }

    /**
     * Cash Flow — mühasibat faylındakı düzülüşlə (aylar sütunlarda) + təsnifat və hesablar üzrə qalıqlar.
     *
     * @param  array<string, mixed>  $d
     */
    public static function cashFlow(array $d): array
    {
        $months = $d['months'];
        $headers = array_merge([__('Kontragent'), __('Bölmə'), __('Növ'), __('Xərc qrupu')], array_map(fn ($m) => Fmt::period($m), $months), [__('Cəmi')]);
        $sections = ['income' => __('Gəlirlər'), 'payment' => __('Ödənişlər'), 'other' => __('Digər')];
        $rows = [array_merge([__('Dövrün əvvəlinə qalıq'), '', '', ''], array_map(fn ($m) => (float) ($d['openingByMonth'][$m] ?? 0), $months), [null])];
        foreach ($sections as $key => $label) {
            $list = $d['sheetRows'][$key] ?? collect();
            foreach ($list as $r) {
                $rows[] = array_merge([$r->counterparty, $label, $d['typeLabels'][$r->type] ?? $r->type, $r->type === FlowClassifier::EXPENSE ? FlowClassifier::groupLabel($r->group) : ''],
                    array_map(fn ($m) => isset($r->months[$m]) ? (float) $r->months[$m] : null, $months), [(float) $r->total]);
            }
            $rows[] = array_merge([__('Cəmi').': '.$label, '', '', ''], array_map(fn ($m) => (float) $list->sum(fn ($r) => $r->months[$m] ?? 0), $months), [(float) $list->sum('total')]);
        }
        $rows[] = array_merge([__('Dövrün sonuna qalıq'), '', '', ''], array_map(fn ($m) => (float) ($d['closingByMonth'][$m] ?? 0), $months), [null]);

        $balances = [];
        foreach ($d['balances'] as $account => $list) {
            $byPeriod = $list->keyBy('period');
            $balances[] = array_merge([$account], array_map(fn ($m) => isset($byPeriod[$m]) ? (float) $byPeriod[$m]->amount : null, $months));
        }

        return [
            'Cash Flow '.$d['year'] => ['headers' => $headers, 'rows' => $rows],
            __('Hesablar üzrə qalıq') => ['headers' => array_merge([__('Hesab')], array_map(fn ($m) => Fmt::period($m), $months)), 'rows' => $balances],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function overview(array $d): array
    {
        $years = $d['years'];
        $yearHeaders = array_map(fn ($y) => (string) ($d['yearLabels'][$y] ?? $y), $years);
        $metrics = [];
        foreach ($d['groups'] as $heading => $list) {
            foreach ($list as $m) {
                $metrics[] = array_merge([$heading, $m->label], array_map(fn ($y) => (float) ($m->values[$y] ?? 0), $years));
            }
        }
        $lines = fn (Collection $rows) => $rows->map(fn ($r) => array_merge([$r->code, $r->name, $r->section], array_map(fn ($y) => isset($r->values[$y]) ? (float) $r->values[$y] : null, $years)))->all();

        return [
            __('İllər üzrə') => ['headers' => array_merge([__('Bölmə'), __('Göstərici')], $yearHeaders), 'rows' => $metrics],
            __('Balans sətirləri') => ['headers' => array_merge([__('Kod'), __('Ad'), __('Tərəf')], $yearHeaders), 'rows' => $lines($d['balanceRows'])],
            __('MZ maddələri') => ['headers' => array_merge([__('Kod'), __('Maddə'), __('Bölmə')], $yearHeaders), 'rows' => $lines($d['plRows'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function reconcile(array $d): array
    {
        $status = [
            ReconciliationService::STATUS_ADDED => __('Sistemdə yox idi → rəsmi kimi əlavə olundu'),
            ReconciliationService::STATUS_BOTH => __('Həm kassada, həm bankda — təkrar olmadığını yoxlayın'),
            ReconciliationService::STATUS_CASH_ONLY => __('Yalnız kassada (rəsmi uçotda yoxdur)'),
            ReconciliationService::STATUS_OK => __('Uyğundur'),
            ReconciliationService::STATUS_INFO => __('Məlumat'),
        ];
        $labels = OfficialRevenueService::labels();

        return [
            __('Gəlirlər') => ['headers' => [__('Göstərici'), __('Sistem'), __('Rəsmi'), __('Fərq'), __('Şərh')],
                'rows' => array_map(fn ($r) => [$r->label, $r->system, $r->official, $r->official - $r->system, $r->note], $d['revenue'])],
            __('Xərclər') => ['headers' => [__('Qrup'), __('Kassa'), __('Rəsmi (bank)'), __('Sistemdə indi'), __('MZ (hesablanmış)'), __('Vəziyyət'), __('Kassa kateqoriyaları')],
                'rows' => array_map(fn ($e) => [$e->label, $e->cash, $e->bank, $e->system_after, $e->mz, $status[$e->status] ?? $e->status, implode(', ', $e->categories)], $d['expenses'])],
            __('Alışlar') => ['headers' => [__('Göstərici'), __('Məbləğ'), __('Şərh')],
                'rows' => array_map(fn ($p) => [$p->label, $p->amount, $p->note], $d['purchases'])],
            __('Rəsmi gəlirlər') => ['headers' => [__('Bankda ödəyici'), __('Cəmi'), __('Sistemdə müştəri'), __('Satış bu il'), __('Satış əvvəl'), __('P&L-ə münasibət')],
                'rows' => collect($d['payers'])->map(fn ($p) => [$p->counterparty, $p->total, $p->customer->name ?? '', $p->year_sales, $p->earlier_sales, $labels[$p->decision] ?? $p->decision])->all()],
            __('Açılış qalıqları') => ['headers' => [__('Tarix'), __('Açılış'), __('Əvvəlki ilin sonu'), __('Sistemdəki kassa')],
                'rows' => array_map(fn ($o) => ['01.01.'.$o->year, $o->opening, $o->previous_closing, $o->system_cash], $d['openings'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function bankAccounts(array $d): array
    {
        $months = $d['months'];
        $rows = $d['accounts']->map(fn ($a) => array_merge([$a->name, $a->bank, $a->currency, $d['kinds'][$a->kind] ?? $a->kind,
            $a->latest ? (float) $a->latest->amount : null, $a->latest ? Fmt::period($a->latest->period) : null],
            array_map(fn ($m) => $a->monthly[$m] ?? null, $months)))->all();

        return [__('Bank hesabları') => ['headers' => array_merge([__('Hesab'), __('Bank'), __('Valyuta'), __('Növ'), __('Son qalıq'), __('Ay')], array_map(fn ($m) => Fmt::period($m), $months)), 'rows' => $rows]];
    }

    /**
     * @param  iterable<object>  $transactions
     */
    public static function bankTransactions(string $account, iterable $transactions, array $typeLabels): array
    {
        $classifier = new FlowClassifier;
        $rows = [];
        foreach ($transactions as $t) {
            $type = $classifier->classify((string) $t->counterparty, $t->amount_in > 0 ? 'income' : 'payment')['type'];
            $rows[] = [Carbon::parse($t->date)->format('d.m.Y'), $t->counterparty, $t->description, $t->doc_no, $typeLabels[$type] ?? $type,
                (float) $t->amount_in, (float) $t->amount_out, $t->balance !== null ? (float) $t->balance : null, $t->source === 'statement' ? __('çıxarış') : __('əl ilə')];
        }

        return [mb_substr($account, 0, 31) => ['headers' => [__('Tarix'), __('Kontragent'), __('Təyinat'), __('Sənəd №'), __('Növ'), __('Mədaxil'), __('Məxaric'), __('Qalıq'), __('Mənbə')], 'rows' => $rows]];
    }
}
