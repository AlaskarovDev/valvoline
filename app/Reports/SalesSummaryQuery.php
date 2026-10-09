<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * monthly_sales_summary üzərində filtrlənmiş sorğu və standart metrikalar.
 *
 * Xalis satış = Σ satış yekun − Σ müştəri qaytarması yekun.
 * COGS        = Σ cogs (satış + hədiyyə + daxili) − Σ müştəri qaytarması cogs.
 */
class SalesSummaryQuery
{
    public const METRICS = "
        SUM(CASE WHEN s.kind = 'sale' THEN s.net_amount ELSE 0 END) AS sales,
        SUM(CASE WHEN s.kind = 'sale' THEN s.gross_amount ELSE 0 END) AS sales_gross,
        SUM(CASE WHEN s.kind = 'return_customer' THEN s.net_amount ELSE 0 END) AS returns,
        SUM(CASE WHEN s.kind = 'sale' THEN s.cogs ELSE 0 END) AS sale_cogs,
        SUM(CASE WHEN s.kind IN ('gift', 'internal') THEN s.cogs ELSE 0 END) AS gift_cogs,
        SUM(CASE WHEN s.kind = 'return_customer' THEN s.cogs ELSE 0 END) AS return_cogs,
        SUM(CASE WHEN s.kind = 'sale' THEN s.qty ELSE 0 END) AS qty,
        SUM(CASE WHEN s.kind = 'return_customer' THEN s.qty ELSE 0 END) AS return_qty,
        SUM(CASE WHEN s.kind = 'sale' THEN s.liters ELSE 0 END) - SUM(CASE WHEN s.kind = 'return_customer' THEN s.liters ELSE 0 END) AS liters,
        SUM(CASE WHEN s.kind = 'sale' THEN s.line_count ELSE 0 END) AS line_count";

    /**
     * Ay sərhədlərinə uyğun aralıqda aqreqat cədvəl, ixtiyari tarix aralığında isə xam sətirlər
     * (eyni sütunlarla) istifadə olunur — nəticə gün dəqiqliyində düzgündür.
     */
    public static function base(ReportFilters $filters, bool $withDates = true): Builder
    {
        $monthAligned = $filters->from->isSameDay($filters->from->copy()->startOfMonth())
            && $filters->to->isSameDay($filters->to->copy()->endOfMonth());

        if (! $withDates || $monthAligned) {
            $query = DB::table('monthly_sales_summary as s');
            if ($withDates) {
                $query->whereBetween('s.period', [$filters->fromPeriod(), $filters->toPeriod()]);
            }
        } else {
            $query = DB::query()->fromSub(self::rawRows($filters->fromDate(), $filters->toDate()), 's');
        }
        $query->leftJoin('products as p', 'p.id', '=', 's.product_id');
        self::applyDimensions($query, $filters, 's', 'p');

        return $query;
    }

    /**
     * monthly_sales_summary ilə eyni sütunlu xam sətirlər (gün aralığı üçün).
     */
    public static function rawRows(string $from, string $to): Builder
    {
        $sales = DB::table('sales_lines as l')
            ->leftJoin('products as lp', 'lp.id', '=', 'l.product_id')
            ->whereBetween('l.date', [$from, $to])
            ->selectRaw('l.period, l.op_type AS kind, l.product_id, l.customer_id, l.manager_id, l.qty,
                l.qty * COALESCE(lp.volume_l, 0) AS liters, l.amount AS gross_amount, l.net_amount,
                COALESCE(l.cogs, 0) AS cogs, 1 AS line_count');
        $returns = DB::table('sales_returns as r')
            ->leftJoin('products as rp', 'rp.id', '=', 'r.product_id')
            ->whereBetween('r.date', [$from, $to])
            ->selectRaw("r.period, CASE WHEN r.op_type = 'customer' THEN 'return_customer' ELSE 'return_internal' END AS kind,
                r.product_id, r.customer_id, r.manager_id, r.qty, r.qty * COALESCE(rp.volume_l, 0) AS liters,
                r.qty * r.price AS gross_amount, r.net_amount, COALESCE(r.cogs, 0) AS cogs, 1 AS line_count");

        return $sales->unionAll($returns);
    }

    /**
     * Menecer / müştəri / mal / qrup / kateqoriya / seriya filtrləri.
     */
    public static function applyDimensions(Builder $query, ReportFilters $filters, string $alias, ?string $productAlias): void
    {
        if ($filters->managerId !== null) {
            $query->where("{$alias}.manager_id", $filters->managerId);
        }
        if ($filters->customerId !== null) {
            $query->where("{$alias}.customer_id", $filters->customerId);
        }
        if ($filters->productId !== null) {
            $query->where("{$alias}.product_id", $filters->productId);
        }
        if ($productAlias !== null) {
            if ($filters->groupId !== null) {
                $query->where("{$productAlias}.product_group_id", $filters->groupId);
            }
            if ($filters->categoryId !== null) {
                $query->where("{$productAlias}.category_id", $filters->categoryId);
            }
            if ($filters->series !== null) {
                $query->where("{$productAlias}.series", $filters->series);
            }
        }
    }

    /**
     * Sətir obyektinə hesablanmış sahələri əlavə edir: net_sales, cogs, gross_profit, margin, discount.
     */
    public static function derive(object $row): object
    {
        $row->sales = (float) ($row->sales ?? 0);
        $row->returns = (float) ($row->returns ?? 0);
        $row->net_sales = $row->sales - $row->returns;
        $row->cogs = (float) ($row->sale_cogs ?? 0) + (float) ($row->gift_cogs ?? 0) - (float) ($row->return_cogs ?? 0);
        $row->gross_profit = $row->net_sales - $row->cogs;
        $row->margin = abs($row->net_sales) > 0.005 ? $row->gross_profit / $row->net_sales : null;
        $gross = (float) ($row->sales_gross ?? 0);
        $row->discount = $gross > 0.005 ? 1 - $row->sales / $gross : null;
        $row->return_rate = $row->sales > 0.005 ? $row->returns / $row->sales : null;

        return $row;
    }

    /**
     * Filtr aralığı üçün cəmi metrikalar.
     */
    public static function totals(ReportFilters $filters): object
    {
        return self::derive(self::base($filters)->selectRaw(self::METRICS)->first() ?? (object) []);
    }

    /**
     * Eyni filtr, bir il əvvəlki dövr (müqayisə üçün).
     */
    public static function previousYear(ReportFilters $filters): ReportFilters
    {
        $previous = clone $filters;
        $previous->from = $filters->from->copy()->subYearNoOverflow();
        $previous->to = $filters->to->copy()->subYearNoOverflow();
        if ($filters->to->isSameDay($filters->to->copy()->endOfMonth())) {
            $previous->to = $previous->to->endOfMonth();
        }

        return $previous;
    }

    /**
     * @return array{min: string|null, max: string|null}
     */
    public static function dataPeriods(): array
    {
        $row = DB::table('monthly_sales_summary')->selectRaw('MIN(period) AS min_p, MAX(period) AS max_p')->first();

        return ['min' => $row->min_p ?? null, 'max' => $row->max_p ?? null];
    }
}
