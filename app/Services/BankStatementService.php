<?php

namespace App\Services;

use App\Support\NameNormalizer;
use DateTime;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Bank hesabları: mühasibat faylındakı hesab adlarından hesabların yaradılması və bank çıxarışının
 * (Excel / CSV) oxunması. Çıxarışın başlıq sətri avtomatik tapılır — Azərbaycan, rus və ingilis başlıqları tanınır.
 */
class BankStatementService
{
    /** Başlıq sözləri (normallaşdırılmış) → sütun rolu. Sıra vacibdir: ilk uyğun gələn rol götürülür. */
    private const HEADERS = [
        'date' => '/^(tarix|emeliyyat tarixi|odenis tarixi|valyutalasma tarixi|date|transaction date|value date|дата)/',
        'in' => '/(kredit|credit|medaxil|daxilolma|gelen|incoming|приход|поступлен|кредит)/',
        'out' => '/(debet|debit|mexaric|cixis|xaric|outgoing|расход|списан|дебет)/',
        'amount' => '/^(mebleg|amount|сумма)/',
        'balance' => '/(qaliq|balance|остаток)/',
        'counterparty' => '/(kontragent|qarsi teref|odeyici|alan|gonderen|benefisiar|counterparty|payer|payee|beneficiary|контрагент|получатель|плательщик)/',
        'description' => '/(teyinat|tesvir|aciqlama|purpose|description|details|narrative|назначение|описание)/',
        'doc_no' => '/(sened|senedin|doc|reference|№|nomre|номер)/',
    ];

    /**
     * Mühasibat faylındakı hesab adlarından (ay sonu qalıqları) bank hesablarını yaradır; mövcudlara toxunmur.
     */
    public function syncAccounts(): void
    {
        $existing = DB::table('bank_accounts')->pluck('name')->map(fn ($n) => NameNormalizer::normalize($n))->flip();
        foreach (DB::table('bank_flow_balances')->distinct()->pluck('account') as $name) {
            if (isset($existing[NameNormalizer::normalize($name)])) {
                continue;
            }
            DB::table('bank_accounts')->insert(array_merge(self::describe($name), ['name' => $name, 'created_at' => now(), 'updated_at' => now()]));
            $existing[NameNormalizer::normalize($name)] = true;
        }
    }

    /**
     * Hesab adından bank, valyuta və növ: «Turan Bank AZN Kart» → Turan Bank, AZN, card.
     *
     * @return array{bank: string|null, currency: string, kind: string}
     */
    public static function describe(string $name): array
    {
        $key = NameNormalizer::normalize($name);
        $currency = match (true) {
            (bool) preg_match('/\b(euro|eur)\b/', $key) => 'EUR',
            (bool) preg_match('/\b(usd|dollar)\b/', $key) => 'USD',
            (bool) preg_match('/\b(rubl|rub)\b/', $key) => 'RUB',
            default => 'AZN',
        };
        $kind = match (true) {
            str_contains($key, 'kassa') => 'cash',
            str_contains($key, 'depozit') => 'deposit',
            (bool) preg_match('/\bkart\b|\bcard\b/', $key) => 'card',
            default => 'bank',
        };
        $bank = match (true) {
            str_starts_with($key, 'rb ') || str_contains($key, 'rabite') => 'Rabitəbank',
            str_contains($key, 'turan') => 'Turan Bank',
            str_contains($key, 'kapital') => 'Kapital Bank',
            str_contains($key, 'pasha') => 'PAŞA Bank',
            str_contains($key, 'abb') => 'ABB',
            str_contains($key, 'leo') => 'Leobank',
            str_contains($key, 'edv') => __('ƏDV depozit hesabı'),
            $kind === 'cash' => null,
            default => null,
        };

        return ['bank' => $bank, 'currency' => $currency, 'kind' => $kind];
    }

    /**
     * Çıxarışı oxuyur.
     *
     * @return array{rows: list<array{date: string, counterparty: string|null, description: string|null, amount_in: float, amount_out: float, balance: float|null, doc_no: string|null}>, columns: array<string, string>, errors: list<string>}
     */
    public function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (Throwable) {
            return ['rows' => [], 'columns' => [], 'errors' => [__('Fayl oxunmadı — Excel (.xlsx, .xls) və ya CSV olmalıdır.')]];
        }
        $data = $sheet->toArray(null, true, false, false);

        [$headerIndex, $map, $labels] = $this->findHeader($data);
        if ($headerIndex === null) {
            return ['rows' => [], 'columns' => [], 'errors' => [__('Başlıq sətri tapılmadı: «Tarix» və «Mədaxil / Məxaric» (və ya «Debet / Kredit», «Məbləğ») sütunları olmalıdır.')]];
        }

        $rows = [];
        $errors = [];
        foreach (array_slice($data, $headerIndex + 1, null, true) as $i => $row) {
            $date = self::date($row[$map['date']] ?? null);
            if ($date === null) {
                continue; // boş, cəm və ya qeyd sətri
            }
            $in = isset($map['in']) ? abs(self::number($row[$map['in']] ?? null)) : 0.0;
            $out = isset($map['out']) ? abs(self::number($row[$map['out']] ?? null)) : 0.0;
            if (! isset($map['in']) && ! isset($map['out']) && isset($map['amount'])) {
                $amount = self::number($row[$map['amount']] ?? null);
                [$in, $out] = $amount >= 0 ? [$amount, 0.0] : [0.0, -$amount];
            }
            if ($in < 0.005 && $out < 0.005) {
                continue;
            }
            $text = fn (string $role) => isset($map[$role]) && trim((string) ($row[$map[$role]] ?? '')) !== '' ? trim((string) $row[$map[$role]]) : null;
            $description = $text('description');
            $rows[] = [
                'date' => $date,
                'counterparty' => $text('counterparty') ?? ($description !== null ? mb_substr($description, 0, 120) : null),
                'description' => $description,
                'amount_in' => round($in, 4),
                'amount_out' => round($out, 4),
                'balance' => isset($map['balance']) && ($row[$map['balance']] ?? null) !== null && $row[$map['balance']] !== '' ? self::number($row[$map['balance']]) : null,
                'doc_no' => $text('doc_no') !== null ? mb_substr((string) $text('doc_no'), 0, 64) : null,
            ];
        }
        if ($rows === []) {
            $errors[] = __('Çıxarışda əməliyyat sətri tapılmadı.');
        }

        return ['rows' => $rows, 'columns' => $labels, 'errors' => $errors];
    }

    /**
     * Çıxarış sətirlərini hesaba yazır; eyni sətir (tarix + məbləğ + təyinat) ikinci dəfə yazılmır.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{added: int, skipped: int}
     */
    public function store(int $accountId, array $rows, string $fileName, ?int $userId): array
    {
        $seen = [];
        $existing = DB::table('bank_transactions')->where('bank_account_id', $accountId)->whereNotNull('row_hash')->pluck('row_hash')->flip();
        $insert = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $base = implode('|', [$accountId, $row['date'], $row['amount_in'], $row['amount_out'], NameNormalizer::normalize(($row['counterparty'] ?? '').' '.($row['description'] ?? '')), $row['doc_no'] ?? '']);
            $n = $seen[$base] = ($seen[$base] ?? 0) + 1;   // eyni gün eyni məbləğli bir neçə əməliyyat ola bilər
            $hash = sha1($base.'#'.$n);
            if (isset($existing[$hash])) {
                $skipped++;

                continue;
            }
            $insert[] = [
                'bank_account_id' => $accountId,
                'date' => $row['date'],
                'period' => substr($row['date'], 0, 7),
                'counterparty' => $row['counterparty'] !== null ? mb_substr($row['counterparty'], 0, 255) : null,
                'normalized' => $row['counterparty'] !== null ? mb_substr(NameNormalizer::normalize($row['counterparty']), 0, 255) : null,
                'description' => $row['description'],
                'amount_in' => $row['amount_in'],
                'amount_out' => $row['amount_out'],
                'balance' => $row['balance'],
                'doc_no' => $row['doc_no'],
                'source' => 'statement',
                'file_name' => mb_substr($fileName, 0, 255),
                'row_hash' => $hash,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('bank_transactions')->insert($chunk);
        }

        return ['added' => count($insert), 'skipped' => $skipped];
    }

    /**
     * @param  list<array<int, mixed>>  $data
     * @return array{0: int|null, 1: array<string, int>, 2: array<string, string>}
     */
    private function findHeader(array $data): array
    {
        foreach (array_slice($data, 0, 40, true) as $index => $row) {
            $map = [];
            $labels = [];
            foreach ($row as $col => $value) {
                $key = NameNormalizer::normalize((string) $value);
                if ($key === '' || is_numeric($value)) {
                    continue;
                }
                foreach (self::HEADERS as $role => $pattern) {
                    if (! isset($map[$role]) && preg_match($pattern.'u', $key)) {
                        $map[$role] = $col;
                        $labels[$role] = trim((string) $value);
                        break;
                    }
                }
            }
            if (isset($map['date']) && (isset($map['in']) || isset($map['out']) || isset($map['amount']))) {
                return [$index, $map, $labels];
            }
        }

        return [null, [], []];
    }

    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.y'] as $format) {
            $date = DateTime::createFromFormat('!'.$format, $text);
            $errors = DateTime::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $year = (int) $date->format('Y');

                return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d') : null;
            }
        }

        return null;
    }

    public static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = str_replace(["\u{00A0}", "\u{202F}", ' ', '₼', 'AZN', 'azn'], '', trim((string) $value));
        if ($text === '' || $text === '-') {
            return 0.0;
        }
        // «1.234,56» və «1,234.56»: sonuncu ayırıcı onluq hissədir
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }

        return is_numeric($text) ? (float) $text : 0.0;
    }
}
