<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEMO: 02–08 fayllarını promptdakı sütun strukturu ilə sintetik məlumatla yaradır
 * (mallar bazadakı real 01_Mallar kataloqundan götürülür). Yalnız sınaq və nümayiş üçündür —
 * rəqəmlər real biznesi əks etdirmir.
 */
class DemoWorkbooks
{
    private const MANAGERS = ['Elvin Məmmədov', 'Rauf Həsənov', 'Nigar Quliyeva', 'Tural Əliyev', 'Səbinə Kərimova'];

    private const NAME_PARTS = ['Avto', 'Motor', 'Yağ', 'Servis', 'Texnika', 'Kapital', 'Sürət', 'Şimal', 'Cənub', 'Qərb', 'Xəzər', 'Abşeron',
        'Gəncə', 'Lənkəran', 'Şəki', 'Quba', 'Sumqayıt', 'Mərkəz', 'Ulduz', 'Ekspress', 'Partnyor', 'Diesel', 'Trans', 'Ağır Texnika'];

    private const NAME_SUFFIX = ['MMC', 'avtoservis', 'mağaza', 'FHN', 'ASC', 'yağdəyişmə', 'ehtiyat hissələri', 'TİCARƏT MMC'];

    private const RETURN_REASONS = ['Müştəri imtina etdi', 'Qablaşdırma zədəli', 'Səhv mal göndərilib', 'Satılmadı, geri qaytarıldı', 'Son istifadə tarixi'];

    /** @var array<int, array{code: string, name: string, manager: string, type: string, aliases: list<string>}> */
    private array $customers = [];

    /** @var list<object> */
    private array $products = [];

    public function __construct(private int $seed = 20261009) {}

    /**
     * @return list<string> yaradılan fayllar
     */
    public function generate(string $dir, int $orders = 3900, string $from = '2024-02-13', string $to = '2026-10-01'): array
    {
        mt_srand($this->seed);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->products = DB::table('products')->orderBy('code')->get()->all();
        if ($this->products === []) {
            throw new \RuntimeException('Əvvəlcə 01_Mallar.xlsx import edilməlidir.');
        }
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $this->makeCustomers(360);
        $purchases = $this->makePurchases($start, $end);
        [$sales, $orderMeta] = $this->makeSales($start->copy()->addDay(), $end, $orders);
        $returns = $this->makeReturns($sales, $end);
        $payments = $this->makePayments($orderMeta, $returns, $end);
        [$adjustments, $transfers] = $this->makeStock($start, $end);
        [$cash, $categories, $checkpoints] = $this->makeCash($start, $end, $payments);

        $files = [];
        $files[] = $this->write($dir, '02_Musteriler.xlsx', [
            'Musteriler' => [
                ['Musteri_Kodu', 'Musteri_Adi', 'Menecer', 'VOEN', 'Musteri_Tipi', 'Ilk_Satis', 'Son_Satis', 'Diger_Ad_Yazilislari'],
                array_map(fn ($c) => [$c['code'], $c['name'], $c['manager'], (string) (1400000000 + mt_rand(1000, 999999)),
                    $c['type'] === 'internal' ? 'Daxili (müştəri deyil)' : 'Müştəri', null, null, implode(' | ', $c['aliases'])], $this->customers),
            ],
            'Ad_Uygunlasdirma' => [
                ['Orijinal_Ad', 'Normallasdirilmis', 'Musteri_Kodu', 'Esas_Ad', 'Uygunlasdirma_Usulu'],
                $this->aliasRows(),
            ],
        ]);
        $files[] = $this->write($dir, '03_Idxal_Alislar.xlsx', [
            'Partiyalar' => [['Partiya_No', 'Partiya_Adi', 'Tarix', 'Tarix_Menbeyi', 'Mal_Cesidi', 'Cem_Eded', 'Cem_Maya_AZN'], $purchases['batches']],
            'Idxal_Setirleri' => [['Partiya_No', 'Partiya_Adi', 'Tarix', 'Mal_Kodu', 'Mal_Adi', 'Miqdar_Eded', 'Maya_Deyeri_Vahid', 'Cem_Maya', 'Orijinal_Miqdar', 'Orijinal_Vahid', 'Qeyd'], $purchases['lines']],
        ]);
        $files[] = $this->write($dir, '04_Satislar.xlsx', [
            'Satis_Setirleri' => [['Tarix', 'Sifaris_No', 'Qaime_No', 'Menecer', 'Musteri_Kodu', 'Musteri_Adi', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qiymet', 'Mebleg', 'Endirim_Faizi', 'Endirimli_Qiymet', 'Yekun_Mebleg', 'Emeliyyat_Tipi', 'Qeyd', 'Orijinal_Musteri_Adi'], $sales],
        ]);
        $files[] = $this->write($dir, '05_Qaytarmalar.xlsx', [
            'Qaytarmalar' => [['Tarix', 'Qaytarma_No', 'Menecer', 'Musteri_Kodu', 'Musteri_Adi', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qiymet', 'Endirim_Faizi', 'Yekun_Mebleg', 'Emeliyyat_Tipi', 'Sebeb', 'Qeyd', 'Orijinal_Musteri_Adi'], $returns],
        ]);
        $files[] = $this->write($dir, '06_Odenisler.xlsx', [
            'Odenisler' => [['Tarix', 'Musteri_Kodu', 'Musteri_Adi', 'Menecer', 'Nagd', 'Bank', 'Cem_Odenis', 'Tehvil_Alan', 'Qeyd', 'Orijinal_Musteri_Adi'], $payments],
        ]);
        $files[] = $this->write($dir, '07_Anbar.xlsx', [
            'Sayim_Duzelisleri' => [['Tarix', 'Mal_Kodu', 'Mal_Adi', 'Hesablanan_Qaliq', 'Sayim_Qaligi', 'Duzelis_Miqdari', 'Sebeb'], $adjustments],
            'Anbar_Transferleri' => [['Tarix', 'Sened_No', 'Istiqamet', 'Haradan', 'Haraya', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qeyd'], $transfers],
            'Cari_Qaliq_Yoxlama' => [['Mal_Kodu', 'Qeyd'], [['—', 'DEMO: bu vərəq import edilmir']]],
        ]);
        $files[] = $this->write($dir, '08_Xercler.xlsx', [
            'Kassa_Emeliyyatlari' => [['Tarix', 'Kateqoriya', 'Alt_Kateqoriya', 'Tesvir', 'Medaxil', 'Mexaric', 'Emeliyyat_Tipi', 'Menfeete_Tesir', 'Istiqamet', 'Kateqoriya_Menbeyi', 'Qeyd', 'Orijinal_Setir'], $cash],
            'Kateqoriyalar' => [['Kateqoriya', 'Emeliyyat_Tipi', 'Menfeete_Tesir', 'Setir_Sayi', 'Izah'], $categories],
            'Kassa_Qaliqlari' => [['Tarix', 'Tesvir', 'Kassa_Qaligi'], $checkpoints],
        ]);

        return $files;
    }

    private function makeCustomers(int $count): void
    {
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            do {
                $name = '«'.self::NAME_PARTS[mt_rand(0, count(self::NAME_PARTS) - 1)].' '.self::NAME_PARTS[mt_rand(0, count(self::NAME_PARTS) - 1)].'» '
                    .self::NAME_SUFFIX[mt_rand(0, count(self::NAME_SUFFIX) - 1)];
            } while (isset($used[$name]));
            $used[$name] = true;
            $aliases = [];
            if (mt_rand(1, 100) <= 18) {
                $aliases[] = mb_strtoupper(str_replace(['«', '»'], '', $name));
            }
            if (mt_rand(1, 100) <= 10) {
                $aliases[] = strtr(str_replace(['«', '»'], '"', $name), ['ə' => 'e', 'Ə' => 'E', 'ş' => 's', 'ı' => 'i', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u']);
            }
            $this->customers[] = [
                'code' => (string) (880000 + $i * 7 + mt_rand(0, 6)),
                'name' => $name,
                'manager' => self::MANAGERS[$i % count(self::MANAGERS)],
                'type' => $i < 3 ? 'internal' : 'customer',
                'aliases' => $aliases,
                'weight' => $i < 3 ? 0.2 : (mt_rand(1, 100) <= 15 ? mt_rand(30, 80) / 10 : mt_rand(3, 15) / 10),
            ];
        }
        $this->customers[0]['name'] = 'Daxili istifadə (ofis)';
        $this->customers[1]['name'] = 'Səyyar satış maşını';
        $this->customers[2]['name'] = 'Reklam və marketinq';
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function aliasRows(): array
    {
        $rows = [];
        foreach ($this->customers as $c) {
            foreach ($c['aliases'] as $alias) {
                $rows[] = [$alias, NameNormalizer::normalize($alias), $c['code'], $c['name'], mt_rand(0, 1) ? 'Avtomatik (oxşarlıq)' : 'Əl ilə'];
            }
        }

        return $rows;
    }

    /**
     * @return array{batches: list<array<int, mixed>>, lines: list<array<int, mixed>>}
     */
    private function makePurchases(Carbon $start, Carbon $end): array
    {
        $batches = [];
        $lines = [];
        $batchNo = 0;
        $date = $start->copy();
        while ($date->lessThanOrEqualTo($end->copy()->subMonth())) {
            $name = $batchNo === 0 ? 'Açılış qalığı' : "{$batchNo}-ci maşın";
            $picked = $batchNo === 0 ? $this->products : array_filter($this->products, fn () => mt_rand(1, 100) <= 55);
            $sumQty = 0;
            $sumCost = 0.0;
            foreach ($picked as $p) {
                $base = (float) ($p->file_avg_cost ?: max((float) $p->sale_price * 0.62, 1));
                $cost = round($base * (1 + (mt_rand(-60, 60) / 1000)) * (1 + $batchNo * 0.004), 4);
                $pack = max((int) $p->pack_qty, 1);
                $qty = $pack * ((float) $p->volume_l >= 200 ? mt_rand(1, 4) : mt_rand(4, 24)) * ($batchNo === 0 ? 2 : 1);
                $note = null;
                if ($batchNo === 0 && mt_rand(1, 100) <= 12) {
                    $note = 'TƏXMİNİ maya — açılış qalığı üçün sənəd yoxdur';
                }
                $lines[] = [$batchNo, $name, $date->format('d.m.Y'), $p->code, $p->name, $qty, $cost, round($qty * $cost, 2),
                    $qty / $pack, 'qutu', $note];
                $sumQty += $qty;
                $sumCost += $qty * $cost;
            }
            $batches[] = [$batchNo, $name, $date->format('d.m.Y'), $batchNo === 0 ? 'Sayım aktı' : 'Gömrük bəyannaməsi', count($picked), $sumQty, round($sumCost, 2)];
            $batchNo++;
            $date = $batchNo === 1 ? $start->copy()->addDays(20) : $date->copy()->addDays(mt_rand(55, 75));
        }

        return ['batches' => $batches, 'lines' => $lines];
    }

    /**
     * @return array{0: list<array<int, mixed>>, 1: array<string, array{date: Carbon, customer: array<string, mixed>, total: float}>}
     */
    private function makeSales(Carbon $start, Carbon $end, int $orderCount): array
    {
        $rows = [];
        $meta = [];
        $days = $start->diffInDays($end);
        $weights = array_map(fn ($c) => $c['weight'], $this->customers);
        $totalWeight = array_sum($weights);
        $popular = array_slice($this->products, 0, (int) (count($this->products) * 0.6));
        $dates = [];
        for ($i = 0; $i < $orderCount; $i++) {
            // satışlar ilbəil artır və yayda bir az yüksəlir
            $t = sqrt(mt_rand(0, 1000000) / 1000000);
            $dates[] = $start->copy()->addDays((int) round($t * $days));
        }
        usort($dates, fn ($a, $b) => $a <=> $b);
        $orderNo = 24000;
        foreach ($dates as $date) {
            if ($date->isSunday()) {
                $date = $date->copy()->addDay();
            }
            $orderNo++;
            $customer = $this->pickWeighted($weights, $totalWeight);
            $c = $this->customers[$customer];
            $lineCount = min(1 + (int) floor(-log(max(mt_rand(1, 1000) / 1000, 0.001)) * 2.6), 14);
            $opType = $c['type'] === 'internal' ? (mt_rand(0, 1) ? 'Daxili istifadə' : 'Hədiyyə / pulsuz') : (mt_rand(1, 1000) <= 12 ? 'Hədiyyə / pulsuz' : 'Satış');
            $discount = $opType === 'Satış' ? [0, 0, 0.05, 0.1, 0.15, 0.2, 0.25, 0.3][mt_rand(0, 7)] : 0;
            $total = 0.0;
            $invoice = 'Q-'.($orderNo + 100000);
            $picked = [];
            for ($l = 0; $l < $lineCount; $l++) {
                $p = mt_rand(1, 100) <= 75 ? $popular[mt_rand(0, count($popular) - 1)] : $this->products[mt_rand(0, count($this->products) - 1)];
                if (isset($picked[$p->code])) {
                    continue;
                }
                $picked[$p->code] = true;
                $qty = (float) $p->volume_l >= 200 ? 1 : max((int) $p->pack_qty, 1) * mt_rand(1, 3) / (mt_rand(1, 3) === 1 ? max((int) $p->pack_qty, 1) : 1);
                $qty = max(1, (int) round($qty));
                $price = (float) $p->sale_price;
                $amount = round($qty * $price, 2);
                $discounted = round($price * (1 - $discount), 4);
                $net = $opType === 'Satış' ? round($qty * $discounted, 2) : 0.0;
                $customerName = $c['name'];
                $originalName = $c['aliases'] !== [] && mt_rand(1, 100) <= 40 ? $c['aliases'][0] : $customerName;
                $code = $c['code'];
                $rows[] = [$date->format('d.m.Y'), (string) $orderNo, $invoice, $c['manager'], $code, $customerName, $p->code, $p->name,
                    $qty, $price, $amount, $discount, $discounted, $net, $opType, null, $originalName];
                $total += $net;
            }
            $meta[(string) $orderNo] = ['date' => $date, 'customer' => $c, 'total' => $total];
        }
        // gözləmədə nümunələri: tanınmayan mal kodu və kodsuz/tanınmayan müştəri adı
        $c = $this->customers[10];
        $rows[] = [$end->copy()->subDays(5)->format('d.m.Y'), '99901', 'Q-199901', $c['manager'], $c['code'], $c['name'], 'XX-0001', 'NAMƏLUM MAL (DEMO)', 2, 10, 20, 0, 10, 20, 'Satış', 'DEMO: tanınmayan kod', $c['name']];
        $rows[] = [$end->copy()->subDays(4)->format('d.m.Y'), '99902', 'Q-199902', self::MANAGERS[0], null, 'Yeni Müştəri Avtoservis (DEMO)', $this->products[0]->code, $this->products[0]->name, 1, (float) $this->products[0]->sale_price, (float) $this->products[0]->sale_price, 0, (float) $this->products[0]->sale_price, (float) $this->products[0]->sale_price, 'Satış', 'DEMO: kodsuz müştəri', 'Yeni Musteri Avtoservis'];

        return [$rows, $meta];
    }

    /**
     * @param  list<array<int, mixed>>  $sales
     * @return list<array<int, mixed>>
     */
    private function makeReturns(array $sales, Carbon $end): array
    {
        $rows = [];
        $n = 0;
        foreach ($sales as $s) {
            if ($s[14] !== 'Satış' || mt_rand(1, 1000) > 45) {
                continue;
            }
            $date = Carbon::createFromFormat('d.m.Y', $s[0])->addDays(mt_rand(2, 40));
            if ($date->greaterThan($end)) {
                continue;
            }
            $qty = max(1, (int) floor($s[8] * mt_rand(30, 100) / 100));
            $n++;
            $rows[] = [$date->format('d.m.Y'), 'QY-'.(5000 + $n), $s[3], $s[4], $s[5], $s[6], $s[7], $qty, $s[9], $s[11], round($qty * $s[12], 2),
                'Müştəri qaytarması', self::RETURN_REASONS[mt_rand(0, count(self::RETURN_REASONS) - 1)], "Sifariş {$s[1]}", $s[16]];
        }
        // daxili qaytarma nümunəsi
        $p = $this->products[3];
        $rows[] = [$end->copy()->subDays(30)->format('d.m.Y'), 'QY-D1', self::MANAGERS[0], $this->customers[1]['code'], $this->customers[1]['name'], $p->code, $p->name, 2, 0, 0, 0, 'Daxili', 'Səyyar maşından geri', null, null];

        return $rows;
    }

    /**
     * @param  array<string, array{date: Carbon, customer: array<string, mixed>, total: float}>  $orders
     * @param  list<array<int, mixed>>  $returns
     * @return list<array<int, mixed>>
     */
    private function makePayments(array $orders, array $returns, Carbon $end): array
    {
        $rows = [];
        $receivers = ['Kassir — Leyla', 'Kassir — Orxan', 'Menecer', 'Bank köçürməsi'];
        foreach ($orders as $order) {
            if ($order['total'] <= 0 || $order['customer']['type'] === 'internal') {
                continue;
            }
            $paidShare = mt_rand(1, 100) <= 80 ? 1.0 : mt_rand(0, 90) / 100;
            $remaining = round($order['total'] * $paidShare, 2);
            $parts = mt_rand(1, 100) <= 70 ? 1 : 2;
            for ($k = 0; $k < $parts && $remaining > 0; $k++) {
                $amount = $k === $parts - 1 ? $remaining : round($remaining * mt_rand(30, 70) / 100, 2);
                $remaining = round($remaining - $amount, 2);
                $date = $order['date']->copy()->addDays(mt_rand(0, 75));
                if ($date->greaterThan($end)) {
                    continue;
                }
                $isBank = mt_rand(1, 100) <= 45;
                $c = $order['customer'];
                $rows[] = [$date->format('d.m.Y'), $c['code'], $c['name'], $c['manager'], $isBank ? 0 : $amount, $isBank ? $amount : 0, $amount,
                    $isBank ? 'Bank köçürməsi' : $receivers[mt_rand(0, 2)], null, $c['name']];
            }
        }
        // geri ödəniş nümunəsi (mənfi məbləğ)
        $c = $this->customers[20];
        $rows[] = [$end->copy()->subDays(12)->format('d.m.Y'), $c['code'], $c['name'], $c['manager'], -150, 0, -150, 'Kassir — Leyla', 'Artıq ödənişin qaytarılması', $c['name']];
        usort($rows, fn ($a, $b) => Carbon::createFromFormat('d.m.Y', $a[0]) <=> Carbon::createFromFormat('d.m.Y', $b[0]));

        return $rows;
    }

    /**
     * @return array{0: list<array<int, mixed>>, 1: list<array<int, mixed>>}
     */
    private function makeStock(Carbon $start, Carbon $end): array
    {
        $adjustments = [];
        foreach ([$start->copy()->addMonths(10), $start->copy()->addMonths(22)] as $date) {
            foreach (array_slice($this->products, mt_rand(0, 20), 12) as $p) {
                $diff = mt_rand(-6, 3);
                if ($diff === 0) {
                    continue;
                }
                $computed = mt_rand(10, 80);
                $adjustments[] = [$date->format('d.m.Y'), $p->code, $p->name, $computed, $computed + $diff, $diff, $diff < 0 ? 'Sayımda əskik çıxdı' : 'Sayımda artıq çıxdı'];
            }
        }
        $transfers = [];
        $doc = 0;
        for ($date = $start->copy()->addMonths(6); $date->lessThan($end); $date->addDays(mt_rand(25, 40))) {
            $doc++;
            $items = array_slice($this->products, mt_rand(0, 60), 6);
            foreach ($items as $p) {
                $qty = max((int) $p->pack_qty, 1) * mt_rand(1, 2);
                $transfers[] = [$date->format('d.m.Y'), 'TR-'.$doc, 'Çıxış', 'Əsas anbar', 'Cənub bölgəsi — Anbar 2', $p->code, $p->name, $qty, 'Səyyar satış'];
                $back = (int) floor($qty * mt_rand(0, 60) / 100);
                if ($back > 0) {
                    $transfers[] = [$date->copy()->addDays(6)->format('d.m.Y'), 'TR-'.$doc.'G', 'Giriş (geri qayıtdı)', 'Cənub bölgəsi — Anbar 2', 'Əsas anbar', $p->code, $p->name, $back, 'Satılmayan qalıq'];
                }
            }
        }

        return [$adjustments, $transfers];
    }

    /**
     * @param  list<array<int, mixed>>  $payments
     * @return array{0: list<array<int, mixed>>, 1: list<array<int, mixed>>, 2: list<array<int, mixed>>}
     */
    private function makeCash(Carbon $start, Carbon $end, array $payments): array
    {
        $categories = [
            ['Əmək haqqı', 'Xərc', 'Bəli', 'İşçilərin aylıq maaşı'],
            ['Anbar icarəsi', 'Xərc', 'Bəli', 'Anbar və ofis icarəsi'],
            ['Yanacaq', 'Xərc', 'Bəli', 'Çatdırılma maşınlarının yanacağı'],
            ['Kommunal', 'Xərc', 'Bəli', 'İşıq, su, qaz, internet'],
            ['Nəqliyyat və logistika', 'Xərc', 'Bəli', 'Daşınma xərcləri'],
            ['Reklam', 'Xərc', 'Bəli', 'Marketinq'],
            ['Bank xidməti', 'Xərc', 'Bəli', 'Bank komissiyaları'],
            ['Satış pulunun kassaya təhvili', 'Mədaxil', 'Xeyr', 'Müştəri ödənişləri — mənfəəti dəyişmir'],
            ['Bank hesabına köçürmə', 'Köçürmə', 'Xeyr', 'Kassadan banka'],
            ['İdxal mal ödənişi', 'Xərc', 'Xeyr', 'Mal mayası — COGS ilə hesablanır'],
            ['Avans', 'Xərc', 'Xeyr', 'İşçilərə avans'],
            ['Depozit faizi', 'Mədaxil', 'Bəli', 'Bank depoziti faizi'],
            ['TIR mədaxil', 'Mədaxil', 'Bəli', 'TIR biznes xətti'],
            ['TIR xərcləri', 'Xərc', 'Bəli', 'TIR biznes xətti'],
        ];
        $rows = [];
        $add = function (Carbon $d, string $cat, ?string $sub, string $desc, float $in, float $out, string $type, string $affects, string $dir) use (&$rows) {
            $rows[] = [$d->format('d.m.Y'), $cat, $sub, $desc, $in ?: null, $out ?: null, $type, $affects, $dir, 'Avtomatik', null, $desc];
        };
        for ($month = $start->copy()->startOfMonth(); $month->lessThanOrEqualTo($end); $month->addMonthNoOverflow()) {
            $growth = 1 + $month->diffInMonths($start) * 0.01;
            $add($month->copy()->addDays(4), 'Əmək haqqı', 'Satış şöbəsi', 'Maaş '.$month->format('m.Y'), 0, round(9800 * $growth, 2), 'Xərc', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(5), 'Anbar icarəsi', null, 'İcarə '.$month->format('m.Y'), 0, 2500, 'Xərc', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(9), 'Yanacaq', null, 'Yanacaq kartları', 0, round(mt_rand(900, 1600) * $growth, 2), 'Xərc', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(12), 'Kommunal', null, 'Kommunal ödənişlər', 0, mt_rand(300, 650), 'Xərc', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(15), 'Nəqliyyat və logistika', 'Regionlar', 'Regionlara daşınma', 0, mt_rand(600, 1800), 'Xərc', 'Bəli', 'VALVO');
            if (mt_rand(1, 3) === 1) {
                $add($month->copy()->addDays(18), 'Reklam', null, 'Sosial media reklamı', 0, mt_rand(200, 900), 'Xərc', 'Bəli', 'VALVO');
            }
            $add($month->copy()->addDays(20), 'Bank xidməti', null, 'Komissiya', 0, mt_rand(40, 120), 'Xərc', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(22), 'Avans', null, 'İşçiyə avans', 0, mt_rand(200, 600), 'Xərc', 'Xeyr', 'VALVO');
            $add($month->copy()->addDays(25), 'Depozit faizi', null, 'Depozit faizi', mt_rand(150, 400), 0, 'Mədaxil', 'Bəli', 'VALVO');
            $add($month->copy()->addDays(26), 'TIR mədaxil', null, 'TIR daşıma', mt_rand(3000, 7000), 0, 'Mədaxil', 'Bəli', 'TIR');
            $add($month->copy()->addDays(27), 'TIR xərcləri', null, 'TIR yanacaq', 0, mt_rand(1500, 4000), 'Xərc', 'Bəli', 'TIR');
            if ($month->month % 2 === 0) {
                $add($month->copy()->addDays(14), 'İdxal mal ödənişi', null, 'Valvoline invoys', 0, mt_rand(40000, 90000), 'Xərc', 'Xeyr', 'VALVO');
            }
        }
        // ödənişlərin nağd hissəsi kassaya təhvil kimi (mənfəətə təsirsiz)
        $byMonth = [];
        foreach ($payments as $p) {
            if ((float) $p[4] > 0) {
                $key = substr($p[0], 3);
                $byMonth[$key] = ($byMonth[$key] ?? 0) + (float) $p[4];
            }
        }
        foreach ($byMonth as $key => $sum) {
            $d = Carbon::createFromFormat('d.m.Y', '28.'.$key);
            $add($d, 'Satış pulunun kassaya təhvili', null, 'Nağd satış pulu', round($sum, 2), 0, 'Mədaxil', 'Xeyr', 'VALVO');
            $add($d->copy()->addDay(), 'Bank hesabına köçürmə', null, 'Kassadan banka', 0, round($sum * 0.7, 2), 'Köçürmə', 'Xeyr', 'VALVO');
        }
        usort($rows, fn ($a, $b) => Carbon::createFromFormat('d.m.Y', $a[0]) <=> Carbon::createFromFormat('d.m.Y', $b[0]));

        $catRows = [];
        foreach ($categories as $c) {
            $count = count(array_filter($rows, fn ($r) => $r[1] === $c[0]));
            $catRows[] = [$c[0], $c[1], $c[2], $count, $c[3]];
        }

        $checkpoints = [];
        $balance = 50000.0;
        $lastMonth = null;
        foreach ($rows as $r) {
            $balance += (float) $r[4] - (float) $r[5];
            $month = substr($r[0], 3);
            if ($lastMonth !== null && $month !== $lastMonth && mt_rand(1, 4) === 1) {
                $checkpoints[] = [$r[0], 'Kassa sayımı', round($balance - (float) $r[4] + (float) $r[5] + (mt_rand(1, 5) === 1 ? mt_rand(-80, 80) : 0), 2)];
            }
            $lastMonth = $month;
        }
        array_unshift($rows, [$start->format('d.m.Y'), 'Açılış qalığı', null, 'Kassa açılış qalığı', 50000, null, 'Mədaxil', 'Xeyr', 'DİGƏR (2023 ilk dövr)', 'Əl ilə', null, null]);
        $catRows[] = ['Açılış qalığı', 'Mədaxil', 'Xeyr', 1, 'Kassanın ilkin qalığı'];

        return [$rows, $catRows, $checkpoints];
    }

    /**
     * @param  array<int, float>  $weights
     */
    private function pickWeighted(array $weights, float $total): int
    {
        $r = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $i => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $i;
            }
        }

        return array_key_last($weights);
    }

    /**
     * @param  array<string, array{0: list<string>, 1: list<array<int, mixed>>}>  $sheets
     */
    private function write(string $dir, string $file, array $sheets): string
    {
        $payload = [];
        foreach ($sheets as $name => [$headers, $rows]) {
            $payload[$name] = ['headers' => $headers, 'rows' => $rows];
        }
        $payload['Izah'] = ['headers' => ['Vərəq', 'İzah'], 'rows' => [['DEMO', 'Sintetik sınaq məlumatı — real rəqəmlər deyil.']]];
        $path = $dir.DIRECTORY_SEPARATOR.$file;
        Spreadsheet::write($path, $payload);

        return $path;
    }
}
