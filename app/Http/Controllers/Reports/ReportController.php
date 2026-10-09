<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Reports\ReportFilters;
use App\Reports\Table;
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
