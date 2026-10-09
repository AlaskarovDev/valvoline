<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\Manager;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * İmport zamanı kod → id axtarışları üçün yaddaşda saxlanılan xəritələr.
 * Mal: kod, sonra köhnə kod yazılışları. Müştəri: kod, sonra alias / əsas ad / digər ad yazılışları.
 */
class ImportContext
{
    /** @var array<string, int> */
    private array $productByCode = [];

    /** @var array<string, int> */
    private array $customerByCode = [];

    /** @var array<string, int> */
    private array $customerByName = [];

    /** @var array<string, int> */
    private array $managerByName = [];

    /** @var array<string, int> */
    private array $warehouseByName = [];

    public function __construct()
    {
        $this->reload();
    }

    public function reload(): void
    {
        $this->productByCode = [];
        foreach (Product::query()->get(['id', 'code', 'old_codes']) as $product) {
            foreach (self::splitCodes($product->old_codes) as $old) {
                $this->productByCode[self::codeKey($old)] ??= $product->id;
            }
        }
        foreach (Product::query()->pluck('id', 'code') as $code => $id) {
            $this->productByCode[self::codeKey((string) $code)] = $id; // əsas kod köhnə yazılışdan üstündür
        }

        $this->customerByCode = [];
        $this->customerByName = [];
        foreach (Customer::query()->get(['id', 'code', 'name', 'other_names']) as $customer) {
            $this->customerByCode[self::codeKey($customer->code)] = $customer->id;
            foreach (preg_split('/\s*[|;]\s*/u', (string) $customer->other_names) ?: [] as $other) {
                if ($other !== '') {
                    $this->customerByName[NameNormalizer::normalize($other)] ??= $customer->id;
                }
            }
        }
        foreach (Customer::query()->get(['id', 'name']) as $customer) {
            $this->customerByName[NameNormalizer::normalize($customer->name)] = $customer->id;
        }
        foreach (CustomerAlias::query()->whereNotNull('customer_id')->get(['normalized', 'original_name', 'customer_id']) as $alias) {
            $this->customerByName[$alias->normalized ?: NameNormalizer::normalize($alias->original_name)] = $alias->customer_id;
        }

        $this->managerByName = [];
        foreach (Manager::query()->pluck('id', 'name') as $name => $id) {
            $this->managerByName[self::nameKey($name)] = $id;
        }

        $this->warehouseByName = [];
        foreach (Warehouse::query()->get() as $warehouse) {
            $this->warehouseByName[NameNormalizer::normalize($warehouse->name)] = $warehouse->id;
            $this->warehouseByName[NameNormalizer::normalize($warehouse->code)] = $warehouse->id;
        }
    }

    public function productId(?string $code): ?int
    {
        return $code === null ? null : ($this->productByCode[self::codeKey($code)] ?? null);
    }

    public function hasProduct(?string $code): bool
    {
        return $this->productId($code) !== null;
    }

    /**
     * Kod tanınırsa kodla, deyilsə adların (Musteri_Adi, Orijinal_Musteri_Adi) alias xəritəsi ilə.
     *
     * @param  list<string|null>  $names
     */
    public function customerId(?string $code, array $names = []): ?int
    {
        if ($code !== null && isset($this->customerByCode[self::codeKey($code)])) {
            return $this->customerByCode[self::codeKey($code)];
        }
        foreach ($names as $name) {
            $key = NameNormalizer::normalize($name);
            if ($key !== '' && isset($this->customerByName[$key])) {
                return $this->customerByName[$key];
            }
        }

        return null;
    }

    public function managerId(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $key = self::nameKey($name);
        if (! isset($this->managerByName[$key])) {
            $this->managerByName[$key] = Manager::query()->firstOrCreate(['name' => self::cleanName($name)])->id;
        }

        return $this->managerByName[$key];
    }

    public function mainWarehouseId(): int
    {
        return (int) Warehouse::query()->where('is_main', true)->value('id');
    }

    public function warehouseId(?string $name, ?int $fallback = null): ?int
    {
        if ($name === null || trim($name) === '') {
            return $fallback;
        }
        $key = NameNormalizer::normalize($name);
        if (! isset($this->warehouseByName[$key])) {
            foreach ($this->warehouseByName as $known => $id) {
                if ($known !== '' && (str_contains($key, $known) || str_contains($known, $key))) {
                    return $this->warehouseByName[$key] = $id;
                }
            }
            $code = 'W'.(DB::table('warehouses')->max('id') + 1);
            $this->warehouseByName[$key] = Warehouse::query()->create(['name' => self::cleanName($name), 'code' => $code])->id;
        }

        return $this->warehouseByName[$key];
    }

    public static function codeKey(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $code) ?? $code);
    }

    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * @return list<string>
     */
    public static function splitCodes(?string $codes): array
    {
        if ($codes === null || trim($codes) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;|]+/u', $codes) ?: []), fn ($c) => $c !== ''));
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(self::cleanName($name));
    }
}
