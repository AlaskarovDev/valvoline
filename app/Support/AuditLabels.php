<?php

namespace App\Support;

use App\Models\DeliveryNote;
use App\Models\FinanceDocument;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockDocument;
use App\Models\StockWriteOff;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Str;

/**
 * Audit jurnalı üçün insan dilində adlar: marşrut adı → «Satış fakturası — ləğv edildi».
 */
class AuditLabels
{
    private const DOCUMENTS = ['orders', 'deliveries', 'invoices', 'stock-docs', 'write-offs', 'finance.documents'];

    /**
     * Marşrut prefiksi → obyekt (ən uzun prefiks qalib gəlir).
     *
     * @return array<string, string>
     */
    public static function objects(): array
    {
        return [
            'auth' => __('Sistem'), 'logout' => __('Sistem'),
            'orders' => __('Sifariş'), 'deliveries' => __('Təhvil-təslim sənədi'), 'invoices' => __('Satış fakturası'),
            'campaigns' => __('Kampaniya'), 'reps' => __('Satış nümayəndəsi'),
            'products' => __('Mal'), 'prices' => __('Qiymət kartı'), 'product-settings' => __('Məhsul parametri'),
            'stock-docs' => __('Stok sənədi'), 'write-offs' => __('Silinmə aktı'), 'transfers' => __('Anbar transferi'),
            'batches' => __('Partiya / son istifadə tarixi'), 'warehouses' => __('Anbar'),
            'customers' => __('Müştəri'), 'aliases' => __('Uyğunlaşdırma'),
            'finance.documents' => __('Maliyyə sənədi'), 'finance.registers' => __('Kassa'), 'finance.banks' => __('Bank'), 'finance.accounts' => __('Bank hesabı'),
            'bank' => __('Bank hesabı'), 'bank.tx' => __('Bank əməliyyatı'),
            'accounting' => __('Mühasibat'), 'pnl' => __('Mənfəət və zərər'), 'categories' => __('Xərc kateqoriyası'),
            'import' => __('İmport'), 'users' => __('İstifadəçi'), 'workplaces' => __('İş yeri'), 'numbering' => __('Nömrələmə servisi'),
            'period-lock' => __('Dövr bağlama'),
        ];
    }

    /**
     * Marşrutun son hissəsi → hərəkət.
     *
     * @return array<string, string>
     */
    public static function verbs(): array
    {
        return [
            'store' => __('yaradıldı'), 'update' => __('dəyişdirildi'),
            'cancel' => __('ləğv edildi'), 'send' => __('göndərildi'), 'invoice' => __('fakturalandı'), 'receive' => __('qəbul edildi'),
            'shortage' => __('kəsir silindi'), 'decide' => __('qərar verildi'), 'import' => __('Excel-dən yükləndi'), 'bulk' => __('toplu dəyişiklik'),
            'toggle' => __('status dəyişdi'), 'assign' => __('istifadəçilər təyin edildi'), 'confirm' => __('təsdiqləndi'), 'rollback' => __('geri qaytarıldı'),
            'upload' => __('fayl yükləndi'), 'rebuild' => __('yenidən hesablandı'), 'write-off' => __('silinmə'), 'cost' => __('maya dəyişdi'),
            'pricelist' => __('qiymət növü dəyişdi'), 'classify' => __('təsnifat'), 'openings' => __('qalıqlar yazıldı'), 'revenue.decide' => __('gəlir qərarı'),
            'login' => __('giriş'), 'logout' => __('çıxış'),
        ];
    }

    public static function label(string $action): string
    {
        if ($action === 'logout') {
            return __('Sistem').' — '.__('çıxış');
        }
        $objects = self::objects();
        $prefix = collect(array_keys($objects))->filter(fn ($p) => $action === $p || str_starts_with($action, $p.'.'))->sortByDesc(fn ($p) => strlen($p))->first();
        $rest = $prefix ? Str::after($action, $prefix.'.') : $action;
        $verb = match (true) {
            // sənəd silinmir, ləğv edilir; məlumat kartları (qiymət, uyğunlaşdırma …) isə silinir
            $rest === 'destroy' => in_array($prefix, self::DOCUMENTS, true) ? __('ləğv edildi') : __('silindi'),
            default => self::verbs()[$rest] ?? self::verbs()[Str::afterLast($rest, '.')] ?? $rest,
        };

        return ($prefix ? $objects[$prefix].' — ' : '').$verb;
    }

    /**
     * Audit qeydinin obyektinə keçid (sənəd səhifəsi).
     */
    public static function subjectUrl(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }
        $routes = [
            class_basename(SalesOrder::class) => 'orders.show', class_basename(DeliveryNote::class) => 'deliveries.show',
            class_basename(SalesInvoice::class) => 'invoices.show', class_basename(StockDocument::class) => 'stock-docs.show',
            class_basename(StockWriteOff::class) => 'write-offs.show', class_basename(FinanceDocument::class) => 'finance.documents.show',
            class_basename(WarehouseTransfer::class) => 'transfers.show', 'Product' => 'products.show', 'Customer' => 'customers.show', 'Campaign' => 'campaigns.show',
        ];

        return isset($routes[$type]) ? route($routes[$type], $id) : null;
    }
}
