<?php

namespace App\Imports;

use InvalidArgumentException;

/**
 * Fayl tipləri vacib ardıcıllıqla: mallar → müştərilər → idxal → satış → qaytarma → ödəniş → anbar → kassa.
 */
class ImporterRegistry
{
    /** @var list<class-string<Importer>> */
    public const ORDER = [
        ProductsImporter::class,
        CustomersImporter::class,
        PurchasesImporter::class,
        SalesImporter::class,
        ReturnsImporter::class,
        PaymentsImporter::class,
        StockImporter::class,
        CashImporter::class,
    ];

    /**
     * @return array<string, Importer>
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::ORDER as $class) {
            $importer = new $class;
            $result[$importer->type()] = $importer;
        }

        return $result;
    }

    public static function make(string $type): Importer
    {
        return self::all()[$type] ?? throw new InvalidArgumentException("Naməlum fayl tipi: {$type}");
    }

    /**
     * Fayl adına görə tipi təxmin edir: "04_Satislar.xlsx" → sales.
     */
    public static function guess(string $fileName): ?string
    {
        $prefix = substr(basename($fileName), 0, 2);
        foreach (self::all() as $type => $importer) {
            if (str_starts_with($importer->fileHint(), $prefix) && ctype_digit($prefix)) {
                return $type;
            }
        }

        return null;
    }
}
