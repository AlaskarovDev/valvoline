<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mərkəzi Bankın rəsmi məzənnəsi (1 vahid = X AZN), currency_rates cədvəlində keşlənir.
 * cbar.az istənilən tarix üçün (həftəsonu, hətta gələcək) son dərc olunmuş bülleteni qaytarır —
 * ona görə bülletenin öz tarixi saxlanılır və gələcək tarix soruşulmur.
 */
class CurrencyRates
{
    public const URL = 'https://www.cbar.az/currencies/%s.xml';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return ['AZN', 'USD', 'EUR', 'RUB', 'TRY', 'GBP', 'CNY', 'AED'];
    }

    public function rate(string $code, Carbon|string $date): ?float
    {
        $code = strtoupper($code);
        if ($code === 'AZN') {
            return 1.0;
        }
        $day = Carbon::parse($date)->min(now())->toDateString();
        $exact = DB::table('currency_rates')->where('code', $code)->where('date', $day)->value('rate');
        if ($exact !== null) {
            return (float) $exact;
        }
        $this->fetch($day);

        $found = DB::table('currency_rates')->where('code', $code)->where('date', '<=', $day)->orderByDesc('date')->value('rate');

        return $found !== null ? (float) $found : null;
    }

    /**
     * Bülleteni yükləyib saxlayır; alınmasa səssizcə heç nə etmir (istifadəçi məzənnəni əl ilə yazır).
     */
    public function fetch(string $day): void
    {
        try {
            $response = Http::timeout(8)->accept('application/xml')->get(sprintf(self::URL, Carbon::parse($day)->format('d.m.Y')));
            if (! $response->ok()) {
                return;
            }
            $xml = @simplexml_load_string($response->body());
            if ($xml === false) {
                return;
            }
            $bulletin = Carbon::createFromFormat('!d.m.Y', trim((string) $xml['Date']));
            $bulletinDay = $bulletin ? $bulletin->toDateString() : $day;
            $now = now();
            foreach ($xml->ValType as $type) {
                foreach ($type->Valute as $valute) {
                    $nominal = (float) str_replace(',', '.', (string) $valute->Nominal);
                    $value = (float) str_replace(',', '.', (string) $valute->Value);
                    $code = strtoupper(trim((string) $valute['Code']));
                    if ($code !== '' && $nominal > 0 && $value > 0) {
                        DB::table('currency_rates')->updateOrInsert(['date' => $bulletinDay, 'code' => $code], ['rate' => round($value / $nominal, 8), 'updated_at' => $now, 'created_at' => $now]);
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
