<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Minlərlə sətri `UPDATE … SET col = CASE id WHEN … END WHERE id IN (…)` ilə hissə-hissə yeniləyir.
 * Həm MySQL, həm SQLite-da eyni SQL işləyir.
 */
class BulkUpdater
{
    /**
     * @param  array<int, array<string, mixed>>  $updates  id => [sütun => dəyər]
     */
    public static function update(string $table, array $updates, int $chunkSize = 400): void
    {
        foreach (array_chunk($updates, $chunkSize, true) as $chunk) {
            $columns = array_keys(reset($chunk));
            $sets = [];
            $bindings = [];
            foreach ($columns as $column) {
                $cases = [];
                foreach ($chunk as $id => $values) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = $values[$column];
                }
                $sets[] = DB::getQueryGrammar()->wrap($column).' = CASE id '.implode(' ', $cases).' END';
            }
            $ids = array_keys($chunk);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::update(
                'UPDATE '.DB::getQueryGrammar()->wrapTable($table).' SET '.implode(', ', $sets)." WHERE id IN ({$placeholders})",
                array_merge($bindings, $ids),
            );
        }
    }
}
