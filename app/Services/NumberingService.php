<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nömrələmə servisi — proqramdakı bütün sənəd nömrələri və kodlar buradan verilir.
 *
 * Şablon hissələri: sabit mətn, {YYYY} {YY} {MM} {DD} (sənəd tarixi), {IY}/{IYN} (iş yeri kodu / onun rəqəmləri),
 * {ANB}/{ANBN} (anbar), {SN}/{SNN} (satış nümayəndəsi), {SEQ} və ya {SEQ:4} (sıra nömrəsi, 4 rəqəmə qədər sıfırla).
 * Rejim: auto (sistem verir), auto_editable (sistem təklif edir, dəyişmək olar), manual (istifadəçi yazır).
 * Sıranın yenidən başlaması: prefix (nömrənin qalan hissəsi dəyişəndə), never, yearly, monthly, daily.
 */
class NumberingService
{
    /** açar => [qrup, cədvəl, sütun, susmaya görə şablon, rejim, sıfırlanma, maks. uzunluq] */
    public const RULES = [
        'sales_order' => ['sales', 'sales_orders', 'order_no', '{SEQ}', 'auto_editable', 'never', 64],
        'delivery_note' => ['sales', 'delivery_notes', 'doc_no', 'TT-{SEQ:6}', 'auto', 'never', 32],
        'sales_invoice' => ['sales', 'sales_invoices', 'doc_no', 'SF-{SEQ:6}', 'auto', 'never', 32],
        'campaign' => ['sales', 'campaigns', 'code', 'KMP.{YYYY}{MM}.{SEQ:4}', 'auto_editable', 'prefix', 30],
        'warehouse_transfer' => ['stock', 'warehouse_transfers', 'doc_no', 'AT-{SEQ:6}', 'auto', 'never', 32],
        'stock_write_off' => ['stock', 'stock_write_offs', 'doc_no', 'SA-{SEQ:6}', 'auto', 'never', 32],
        'stock_doc_opening' => ['stock', 'stock_documents', 'doc_no', 'AC-{SEQ:6}', 'auto', 'never', 32],
        'stock_doc_count_surplus' => ['stock', 'stock_documents', 'doc_no', 'SAR-{SEQ:6}', 'auto', 'never', 32],
        'stock_doc_count_shortage' => ['stock', 'stock_documents', 'doc_no', 'SAS-{SEQ:6}', 'auto', 'never', 32],
        'stock_doc_production_loss' => ['stock', 'stock_documents', 'doc_no', 'IIT-{SEQ:6}', 'auto', 'never', 32],
        'stock_doc_consumption' => ['stock', 'stock_documents', 'doc_no', 'SRF-{SEQ:6}', 'auto', 'never', 32],
        'fin_cash_opening' => ['finance', 'finance_documents', 'doc_no', 'KA-{SEQ:6}', 'auto', 'never', 32],
        'fin_cash_receipt' => ['finance', 'finance_documents', 'doc_no', 'KM-{SEQ:6}', 'auto', 'never', 32],
        'fin_cash_transfer' => ['finance', 'finance_documents', 'doc_no', 'KT-{SEQ:6}', 'auto', 'never', 32],
        'fin_bank_opening' => ['finance', 'finance_documents', 'doc_no', 'BA-{SEQ:6}', 'auto', 'never', 32],
        'fin_bank_transfer' => ['finance', 'finance_documents', 'doc_no', 'BT-{SEQ:6}', 'auto', 'never', 32],
        'product' => ['codes', 'products', 'code', 'M{SEQ:5}', 'manual', 'never', 64],
        'customer' => ['codes', 'customers', 'code', 'C{SEQ:5}', 'manual', 'never', 64],
        'sales_rep' => ['codes', 'managers', 'code', 'SN-{SEQ:3}', 'auto_editable', 'never', 16],
        'cash_register' => ['codes', 'cash_registers', 'code', 'KS-{SEQ:3}', 'auto_editable', 'never', 16],
    ];

    public const TOKENS = ['YYYY', 'YY', 'MM', 'DD', 'IY', 'IYN', 'ANB', 'ANBN', 'SN', 'SNN', 'SEQ'];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'sales_order' => __('Sifariş'), 'delivery_note' => __('Təhvil-təslim sənədi'), 'sales_invoice' => __('Satış fakturası'),
            'campaign' => __('Kampaniya kodu'), 'warehouse_transfer' => __('Anbar transferi'), 'stock_write_off' => __('Silinmə aktı'),
            'stock_doc_opening' => __('Açılış sənədi'), 'stock_doc_count_surplus' => __('Sayım artığı sənədi'), 'stock_doc_count_shortage' => __('Sayım əskiyi sənədi'),
            'stock_doc_production_loss' => __('İstehsal itkisi sənədi'), 'stock_doc_consumption' => __('Sərf sənədi'),
            'fin_cash_opening' => __('Kassa açılış sənədi'), 'fin_cash_receipt' => __('Kassa mədaxili (müştəri ödənişi)'), 'fin_cash_transfer' => __('Kassa transfer sənədi'),
            'fin_bank_opening' => __('Bank açılış sənədi'), 'fin_bank_transfer' => __('Banklar arası transfer sənədi'),
            'product' => __('Mal kodu'), 'customer' => __('Müştəri kodu'), 'sales_rep' => __('Satış nümayəndəsi kodu'), 'cash_register' => __('Kassa kodu'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tokenLabels(): array
    {
        return [
            'YYYY' => __('il (4 rəqəm)'), 'YY' => __('il (2 rəqəm)'), 'MM' => __('ay'), 'DD' => __('gün'),
            'IY' => __('iş yerinin kodu'), 'IYN' => __('iş yeri kodunun rəqəmləri'), 'ANB' => __('anbarın kodu'), 'ANBN' => __('anbar kodunun rəqəmləri'),
            'SN' => __('satış nümayəndəsinin kodu'), 'SNN' => __('nümayəndə kodunun rəqəmləri'), 'SEQ' => __('sıra nömrəsi'),
        ];
    }

    /**
     * @return object{key: string, pattern: string, mode: string, reset: string, note: string|null}
     */
    public function rule(string $key): object
    {
        $def = self::RULES[$key] ?? throw new RuntimeException('Unknown numbering rule '.$key);
        $row = DB::table('numbering_rules')->where('key', $key)->first();

        return $row ?? (object) ['key' => $key, 'pattern' => $def[3], 'mode' => $def[4], 'reset' => $def[5], 'note' => null];
    }

    public function mode(string $key): string
    {
        return $this->rule($key)->mode;
    }

    /**
     * Növbəti nömrəni verir və sayğacı artırır.
     *
     * @param  array{date?: mixed, workplace_id?: int|null, warehouse_id?: int|null, manager_id?: int|null}  $ctx
     */
    public function next(string $key, array $ctx = []): string
    {
        return DB::transaction(function () use ($key, $ctx) {
            $rule = $this->rule($key);
            $values = $this->contextValues($ctx);
            $scope = $this->scope($rule, $values);
            DB::table('numbering_counters')->insertOrIgnore(['rule_key' => $key, 'scope' => $scope, 'last_value' => $this->scanMax($key, $rule, $values), 'created_at' => now(), 'updated_at' => now()]);
            $counter = DB::table('numbering_counters')->where('rule_key', $key)->where('scope', $scope)->lockForUpdate()->first();
            [$value, $number] = $this->firstFree($key, $rule, $values, (int) $counter->last_value + 1);
            DB::table('numbering_counters')->where('id', $counter->id)->update(['last_value' => $value, 'updated_at' => now()]);

            return $number;
        });
    }

    /**
     * Növbəti nömrənin önizləməsi (sayğac dəyişmir).
     *
     * @param  array<string, mixed>  $ctx
     */
    public function preview(string $key, array $ctx = []): string
    {
        $rule = $this->rule($key);
        $values = $this->contextValues($ctx);
        $last = DB::table('numbering_counters')->where('rule_key', $key)->where('scope', $this->scope($rule, $values))->value('last_value');

        return $this->firstFree($key, $rule, $values, (int) ($last ?? $this->scanMax($key, $rule, $values)) + 1)[1];
    }

    /**
     * Formadan gələn dəyərə görə nömrə: manual — yazılan; auto — sistem; auto_editable — dəyişdirilibsə yazılan, yoxsa sistem.
     *
     * @param  array<string, mixed>  $ctx
     */
    public function resolve(string $key, ?string $submitted, array $ctx = [], ?string $offered = null): string
    {
        $submitted = $submitted !== null ? trim($submitted) : null;
        $mode = $this->mode($key);
        if ($mode === 'manual' && $submitted) {
            return $submitted;
        }
        if ($mode === 'auto_editable' && $submitted && $submitted !== $offered && $submitted !== $this->preview($key, $ctx)) {
            return $submitted;
        }

        return $this->next($key, $ctx);
    }

    /**
     * Cari sahə üçün növbəti nömrəni təyin edir (məs. 500-dən davam etsin).
     *
     * @param  array<string, mixed>  $ctx
     */
    public function setNext(string $key, int $next, array $ctx = []): void
    {
        $rule = $this->rule($key);
        $scope = $this->scope($rule, $this->contextValues($ctx));
        DB::table('numbering_counters')->updateOrInsert(['rule_key' => $key, 'scope' => $scope], ['last_value' => max(0, $next - 1), 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * Şablonu nümunə dəyərlərlə yazır.
     *
     * @param  array<string, string>  $values
     */
    public function render(string $pattern, array $values, string|int|null $seq): string
    {
        return preg_replace_callback('/\{([A-Z]+)(?::(\d+))?\}/', function ($m) use ($values, $seq) {
            if ($m[1] === 'SEQ') {
                if ($seq === null) {
                    return "\x00SEQ\x00";
                }

                return isset($m[2]) && is_int($seq) ? str_pad((string) $seq, (int) $m[2], '0', STR_PAD_LEFT) : (string) $seq;
            }

            return $values[$m[1]] ?? '';
        }, $pattern) ?? $pattern;
    }

    /**
     * Şablonun hissələrinin izahı: [[mətn nümunəsi, mənası], …].
     *
     * @param  array<string, string>  $values
     * @return list<array{0: string, 1: string}>
     */
    public function explain(string $pattern, array $values, int $seq = 1): array
    {
        $labels = self::tokenLabels();
        $parts = preg_split('/(\{[A-Z]+(?::\d+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(function ($part) use ($labels, $values, $seq) {
            if (preg_match('/^\{([A-Z]+)(?::(\d+))?\}$/', $part, $m)) {
                $text = $this->render($part, $values, $seq);
                $label = $labels[$m[1]] ?? __('naməlum hissə');
                if ($m[1] === 'SEQ' && isset($m[2])) {
                    $label .= ' '.__('(:n rəqəm)', ['n' => $m[2]]);
                }

                return [$text, $label];
            }

            return [$part, __('sabit mətn')];
        }, $parts);
    }

    /**
     * Şablonun yoxlanması.
     *
     * @return list<string> xətalar
     */
    public function validatePattern(string $key, string $pattern, string $mode): array
    {
        $errors = [];
        preg_match_all('/\{([A-Z]+)(?::(\d+))?\}/', $pattern, $m);
        foreach ($m[1] as $token) {
            if (! in_array($token, self::TOKENS, true)) {
                $errors[] = __('Naməlum hissə: {:t}', ['t' => $token]);
            }
        }
        if ($mode !== 'manual' && ! in_array('SEQ', $m[1], true)) {
            $errors[] = __('Avtomatik nömrə üçün şablonda {SEQ} (sıra nömrəsi) olmalıdır.');
        }
        if (preg_match('/[\s\/\\\\?#%]/', $this->render($pattern, $this->sampleValues(), 1))) {
            $errors[] = __('Nömrədə boşluq və / \\ ? # % simvolları olmamalıdır.');
        }
        $length = mb_strlen($this->render($pattern, $this->sampleValues(), 999999));
        if ($length > self::RULES[$key][6]) {
            $errors[] = __('Nömrə çox uzundur (:n simvol, maksimum :max).', ['n' => $length, 'max' => self::RULES[$key][6]]);
        }

        return $errors;
    }

    /**
     * Önizləmə üçün nümunə kontekst: bu gün, əsas iş yeri, əsas anbar, ilk nümayəndə.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        return $this->contextValues([
            'date' => now(),
            'workplace_id' => DB::table('workplaces')->where('is_default', true)->value('id'),
            'warehouse_id' => DB::table('warehouses')->where('is_main', true)->value('id'),
            'manager_id' => DB::table('managers')->whereNotNull('code')->orderBy('id')->value('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    public function contextValues(array $ctx): array
    {
        $date = isset($ctx['date']) && $ctx['date'] ? Carbon::parse($ctx['date']) : now();
        $digits = fn (?string $s) => $s === null ? '' : (preg_replace('/\D+/', '', $s) ?? '');
        $workplace = ! empty($ctx['workplace_id']) ? DB::table('workplaces')->where('id', $ctx['workplace_id'])->value('code') : null;
        $warehouse = ! empty($ctx['warehouse_id']) ? DB::table('warehouses')->where('id', $ctx['warehouse_id'])->value('code') : null;
        $rep = ! empty($ctx['manager_id']) ? DB::table('managers')->where('id', $ctx['manager_id'])->value('code') : null;

        return [
            'YYYY' => $date->format('Y'), 'YY' => $date->format('y'), 'MM' => $date->format('m'), 'DD' => $date->format('d'),
            'IY' => (string) $workplace, 'IYN' => $digits($workplace), 'ANB' => (string) $warehouse, 'ANBN' => $digits($warehouse),
            'SN' => (string) $rep, 'SNN' => $digits($rep), '_date' => $date->toDateString(),
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function scope(object $rule, array $values): string
    {
        $date = Carbon::parse($values['_date']);

        return match ($rule->reset) {
            'never' => '',
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Y-m'),
            'daily' => $date->format('Y-m-d'),
            default => mb_substr(str_replace("\x00SEQ\x00", '#', $this->render($rule->pattern, $values, null)), 0, 96),
        };
    }

    /**
     * Sayğac olmayanda: mövcud nömrələrdən ən böyük sıra (proqrama keçid və şablon dəyişəndə toqquşmasın).
     *
     * @param  array<string, string>  $values
     */
    private function scanMax(string $key, object $rule, array $values): int
    {
        [, $table, $column] = self::RULES[$key];
        $regex = $rule->reset === 'prefix'
            ? '/^'.str_replace(preg_quote("\x00SEQ\x00", '/'), '(\d+)', preg_quote($this->render($rule->pattern, $values, null), '/')).'$/u'
            : $this->genericRegex($rule->pattern);
        // səhvən sıra yerinə yazılmış uzun rəqəmlər (məs. barkod «№8710941033518») sayılmır: şablonun enindən və 8 rəqəmdən uzun olanlar
        preg_match('/\{SEQ(?::(\d+))?\}/', $rule->pattern, $w);
        $maxDigits = max(8, (int) ($w[1] ?? 0));
        $max = 0;
        foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $existing) {
            if (preg_match($regex, (string) $existing, $m) && isset($m[1]) && strlen(ltrim($m[1], '0')) <= $maxDigits) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    private function genericRegex(string $pattern): string
    {
        $parts = preg_split('/(\{[A-Z]+(?::\d+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Z]+)(?::\d+)?\}$/', $part, $m)) {
                $regex .= match ($m[1]) {
                    'SEQ' => '(\d+)', 'YYYY' => '\d{4}', 'YY', 'MM', 'DD' => '\d{2}', 'IYN', 'ANBN', 'SNN' => '\d*', default => '.*?',
                };
            } else {
                $regex .= preg_quote($part, '/');
            }
        }

        return '/^'.$regex.'$/u';
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: int, 1: string}
     */
    private function firstFree(string $key, object $rule, array $values, int $value): array
    {
        [, $table, $column] = self::RULES[$key];
        if (! str_contains($rule->pattern, '{SEQ')) {
            return [$value, $this->render($rule->pattern, $values, $value)];
        }
        for ($i = 0; $i < 10000; $i++, $value++) {
            $number = $this->render($rule->pattern, $values, $value);
            if (! DB::table($table)->where($column, $number)->exists()) {
                return [$value, $number];
            }
        }
        throw new RuntimeException(__('Boş nömrə tapılmadı.'));
    }
}
