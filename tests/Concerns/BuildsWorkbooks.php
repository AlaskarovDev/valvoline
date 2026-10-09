<?php

namespace Tests\Concerns;

use App\Imports\ImporterRegistry;
use App\Models\ImportBatch;
use App\Services\AnalyticsRebuilder;
use App\Support\Spreadsheet;

/**
 * Testlərdə promptdakı sütun strukturu ilə kiçik Excel fikstürləri yaradır və import edir.
 */
trait BuildsWorkbooks
{
    protected string $fixtureDir;

    protected function fixtureDir(): string
    {
        return $this->fixtureDir ??= tap(sys_get_temp_dir().DIRECTORY_SEPARATOR.'valvo-test-'.uniqid(), fn ($d) => mkdir($d));
    }

    /**
     * @param  array<string, array{0: list<string>, 1: list<list<mixed>>}>  $sheets
     */
    protected function workbook(string $file, array $sheets): string
    {
        $payload = [];
        foreach ($sheets as $name => [$headers, $rows]) {
            $payload[$name] = ['headers' => $headers, 'rows' => $rows];
        }
        $payload['Izah'] = ['headers' => ['Vərəq', 'İzah'], 'rows' => [['test', 'fikstür']]];
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.$file;
        Spreadsheet::write($path, $payload);

        return $path;
    }

    protected function importFile(string $type, string $path, string $mode = 'replace'): ImportBatch
    {
        $batch = ImportBatch::query()->create(['file_type' => $type, 'file_name' => basename($path), 'status' => 'preview']);

        return ImporterRegistry::make($type)->import($path, $batch, $mode);
    }

    protected function rebuild(): array
    {
        return app(AnalyticsRebuilder::class)->run();
    }

    /**
     * @param  list<list<mixed>>  $rows  [kod, ad, qrup, kateqoriya, litr, qutuda, qiymət]
     */
    protected function productsFile(array $rows): string
    {
        return $this->workbook('01_Mallar.xlsx', ['Mallar' => [
            ['Mal_Kodu', 'Mal_Adi', 'Mehsul_Qrupu', 'Qrupda_Kod_Sayi', 'Seriya', 'Ozluluk', 'Kateqoriya', 'Hecm_Litr', 'Qutuda_Eded', 'Qablasdirma_Tipi', 'Satis_Qiymeti', 'Son_Maya_Deyeri', 'Orta_Maya_Deyeri', 'Kohne_Kod_Yazilislari'],
            array_map(fn ($r) => [$r[0], $r[1], $r[2], 1, 'TEST', null, $r[3], $r[4], $r[5], null, $r[6], null, null, $r[7] ?? null], $rows),
        ]]);
    }

    /**
     * @param  list<list<mixed>>  $rows  [kod, ad, menecer, tip]
     * @param  list<list<mixed>>  $aliases  [orijinal ad, kod]
     */
    protected function customersFile(array $rows, array $aliases = []): string
    {
        return $this->workbook('02_Musteriler.xlsx', [
            'Musteriler' => [
                ['Musteri_Kodu', 'Musteri_Adi', 'Menecer', 'VOEN', 'Musteri_Tipi', 'Ilk_Satis', 'Son_Satis', 'Diger_Ad_Yazilislari'],
                array_map(fn ($r) => [$r[0], $r[1], $r[2], null, $r[3] ?? 'Müştəri', null, null, null], $rows),
            ],
            'Ad_Uygunlasdirma' => [
                ['Orijinal_Ad', 'Normallasdirilmis', 'Musteri_Kodu', 'Esas_Ad', 'Uygunlasdirma_Usulu'],
                array_map(fn ($a) => [$a[0], null, $a[1], null, 'test'], $aliases),
            ],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $lines  [partiya, tarix, kod, miqdar, vahid maya, qeyd]
     */
    protected function purchasesFile(array $lines): string
    {
        $batches = [];
        foreach ($lines as $l) {
            $batches[$l[0]] ??= [$l[0], $l[0] == 0 ? 'Açılış' : "Maşın {$l[0]}", $l[1], 'test', 0, 0, 0];
        }

        return $this->workbook('03_Idxal_Alislar.xlsx', [
            'Partiyalar' => [['Partiya_No', 'Partiya_Adi', 'Tarix', 'Tarix_Menbeyi', 'Mal_Cesidi', 'Cem_Eded', 'Cem_Maya_AZN'], array_values($batches)],
            'Idxal_Setirleri' => [
                ['Partiya_No', 'Partiya_Adi', 'Tarix', 'Mal_Kodu', 'Mal_Adi', 'Miqdar_Eded', 'Maya_Deyeri_Vahid', 'Cem_Maya', 'Orijinal_Miqdar', 'Orijinal_Vahid', 'Qeyd'],
                array_map(fn ($l) => [$l[0], null, $l[1], $l[2], null, $l[3], $l[4], $l[3] * $l[4], null, null, $l[5] ?? null], $lines),
            ],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $lines  [tarix, sifariş, menecer, müştəri kodu, müştəri adı, mal kodu, miqdar, qiymət, endirim, yekun, tip]
     */
    protected function salesFile(array $lines): string
    {
        return $this->workbook('04_Satislar.xlsx', ['Satis_Setirleri' => [
            ['Tarix', 'Sifaris_No', 'Qaime_No', 'Menecer', 'Musteri_Kodu', 'Musteri_Adi', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qiymet', 'Mebleg', 'Endirim_Faizi', 'Endirimli_Qiymet', 'Yekun_Mebleg', 'Emeliyyat_Tipi', 'Qeyd', 'Orijinal_Musteri_Adi'],
            array_map(fn ($l) => [$l[0], $l[1], null, $l[2], $l[3], $l[4], $l[5], null, $l[6], $l[7], $l[6] * $l[7], $l[8], $l[7] * (1 - $l[8]), $l[9], $l[10] ?? 'Satış', null, $l[4]], $lines),
        ]]);
    }

    /**
     * @param  list<list<mixed>>  $lines  [tarix, no, müştəri kodu, mal kodu, miqdar, qiymət, yekun, tip, səbəb]
     */
    protected function returnsFile(array $lines): string
    {
        return $this->workbook('05_Qaytarmalar.xlsx', ['Qaytarmalar' => [
            ['Tarix', 'Qaytarma_No', 'Menecer', 'Musteri_Kodu', 'Musteri_Adi', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qiymet', 'Endirim_Faizi', 'Yekun_Mebleg', 'Emeliyyat_Tipi', 'Sebeb', 'Qeyd', 'Orijinal_Musteri_Adi'],
            array_map(fn ($l) => [$l[0], $l[1], 'Menecer A', $l[2], null, $l[3], null, $l[4], $l[5], 0, $l[6], $l[7] ?? 'Müştəri qaytarması', $l[8] ?? null, null, null], $lines),
        ]]);
    }

    /**
     * @param  list<list<mixed>>  $rows  [tarix, müştəri kodu, nağd, bank, qeyd]
     */
    protected function paymentsFile(array $rows): string
    {
        return $this->workbook('06_Odenisler.xlsx', ['Odenisler' => [
            ['Tarix', 'Musteri_Kodu', 'Musteri_Adi', 'Menecer', 'Nagd', 'Bank', 'Cem_Odenis', 'Tehvil_Alan', 'Qeyd', 'Orijinal_Musteri_Adi'],
            array_map(fn ($r) => [$r[0], $r[1], null, 'Menecer A', $r[2], $r[3], $r[2] + $r[3], 'Kassir', $r[4] ?? null, null], $rows),
        ]]);
    }

    /**
     * @param  list<list<mixed>>  $adjustments  [tarix, kod, düzəliş]
     * @param  list<list<mixed>>  $transfers  [tarix, sənəd, istiqamət, haradan, haraya, kod, miqdar]
     */
    protected function stockFile(array $adjustments, array $transfers): string
    {
        return $this->workbook('07_Anbar.xlsx', [
            'Sayim_Duzelisleri' => [['Tarix', 'Mal_Kodu', 'Mal_Adi', 'Hesablanan_Qaliq', 'Sayim_Qaligi', 'Duzelis_Miqdari', 'Sebeb'],
                array_map(fn ($a) => [$a[0], $a[1], null, null, null, $a[2], 'test'], $adjustments)],
            'Anbar_Transferleri' => [['Tarix', 'Sened_No', 'Istiqamet', 'Haradan', 'Haraya', 'Mal_Kodu', 'Mal_Adi', 'Miqdar', 'Qeyd'],
                array_map(fn ($t) => [$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], null, $t[6], null], $transfers)],
            'Cari_Qaliq_Yoxlama' => [['Mal_Kodu'], []],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $rows  [tarix, kateqoriya, mədaxil, məxaric, tip, təsir, istiqamət]
     * @param  list<list<mixed>>  $categories  [kateqoriya, tip, təsir]
     * @param  list<list<mixed>>  $checkpoints  [tarix, qalıq]
     */
    protected function cashFile(array $rows, array $categories, array $checkpoints = []): string
    {
        return $this->workbook('08_Xercler.xlsx', [
            'Kassa_Emeliyyatlari' => [['Tarix', 'Kateqoriya', 'Alt_Kateqoriya', 'Tesvir', 'Medaxil', 'Mexaric', 'Emeliyyat_Tipi', 'Menfeete_Tesir', 'Istiqamet', 'Kateqoriya_Menbeyi', 'Qeyd', 'Orijinal_Setir'],
                array_map(fn ($r) => [$r[0], $r[1], null, 'test', $r[2], $r[3], $r[4], $r[5], $r[6], null, null, null], $rows)],
            'Kateqoriyalar' => [['Kateqoriya', 'Emeliyyat_Tipi', 'Menfeete_Tesir', 'Setir_Sayi', 'Izah'], array_map(fn ($c) => [$c[0], $c[1], $c[2], null, null], $categories)],
            'Kassa_Qaliqlari' => [['Tarix', 'Tesvir', 'Kassa_Qaligi'], array_map(fn ($c) => [$c[0], 'sayım', $c[1]], $checkpoints)],
        ]);
    }
}
