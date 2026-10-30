<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Reports\ReportFilters;
use App\Reports\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hesabat kontrollerləri üçün ortaq köməkçilər: filtrlər və cədvəl exportu.
 */
abstract class ReportController extends Controller
{
    protected function filters(Request $request, string $preset = 'last_12', ?string $direction = null): ReportFilters
    {
        return ReportFilters::fromRequest($request, $preset, $direction);
    }

    /**
     * Sənəd siyahılarında vəziyyət: standart — aktiv sənədlər, ?state=cancelled — yalnız ləğv edilmişlər
     * (cəmlərə ləğv edilmiş sənəd düşməsin deyə qarışdırılmır).
     */
    protected function applyState(Request $request, Builder $query, string $alias): Builder
    {
        return $request->query('state') === 'cancelled' ? $query->whereNotNull($alias.'.cancelled_at') : $query->whereNull($alias.'.cancelled_at');
    }

    /**
     * Sorğuda ?export=xlsx|pdf varsa uyğun cədvəlin faylını qaytarır.
     *
     * @param  list<Table>  $tables
     */
    protected function exportIfRequested(Request $request, array $tables, string $title, ?ReportFilters $filters = null): ?Response
    {
        foreach ($tables as $table) {
            if ($table->wantsExport($request)) {
                $table->resolve($request);

                return $table->export($request, $title, $filters?->label() ?? '');
            }
        }

        return null;
    }
}
