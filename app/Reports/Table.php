<?php

namespace App\Reports;

use App\Support\Fmt;
use App\Support\Spreadsheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hesabat cədvəli: server tərəfli sıralama, axtarış, səhifələmə, Excel / PDF export.
 * Mənbə ya Query Builder-dir (böyük siyahılar), ya da hazır kolleksiya (aqreqat hesabatlar).
 * Sorğu parametrləri cədvəl adı ilə prefikslənir ki, bir səhifədə bir neçə cədvəl olsun: {ad}_sort, {ad}_dir, {ad}_q, {ad}_page.
 */
class Table
{
    /** @var list<array{key: string, label: string, type: string, sortable: bool, searchable: bool, sql: string|null, link: Closure|null, format: Closure|null, class: Closure|null, export: bool, wrap: bool}> */
    public array $columns = [];

    private Builder|Collection|null $source = null;

    private ?string $defaultSort = null;

    private string $defaultDir = 'desc';

    private int $perPage = 50;

    private ?Closure $rowClass = null;

    /** @var array<string, mixed>|null */
    private ?array $footer = null;

    private bool $autoFooter = false;

    private ?LengthAwarePaginator $resolved = null;

    private ?Request $request = null;

    public ?string $subtitle = null;

    public bool $searchable = true;

    public function __construct(public string $name, public string $title = '') {}

    public static function make(string $name, string $title = ''): self
    {
        return new self($name, $title);
    }

    /**
     * @param  array{sortable?: bool, searchable?: bool, sql?: string, link?: Closure, format?: Closure, class?: Closure, export?: bool, wrap?: bool}  $options
     */
    public function column(string $key, string $label, string $type = 'text', array $options = []): self
    {
        $this->columns[] = [
            'key' => $key,
            'label' => $label,
            'type' => $type, // text | money | qty | pct | date | int | badge
            'sortable' => $options['sortable'] ?? true,
            'searchable' => $options['searchable'] ?? ($type === 'text'),
            'sql' => $options['sql'] ?? null,
            'link' => $options['link'] ?? null,
            'format' => $options['format'] ?? null,
            'class' => $options['class'] ?? null,
            'export' => $options['export'] ?? true,
            'wrap' => $options['wrap'] ?? in_array($key, ['description', 'note', 'reason'], true),
        ];

        return $this;
    }

    public function source(Builder|Collection|array $source): self
    {
        $this->source = is_array($source) ? collect($source) : $source;

        return $this;
    }

    public function sortBy(string $key, string $dir = 'desc'): self
    {
        $this->defaultSort = $key;
        $this->defaultDir = $dir;

        return $this;
    }

    public function perPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    public function rowClass(Closure $callback): self
    {
        $this->rowClass = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $footer  açıq dəyərlər; null + $auto = true → bütün rəqəm sütunlarının cəmi
     */
    public function footer(?array $footer = null, bool $auto = false): self
    {
        $this->footer = $footer;
        $this->autoFooter = $auto;

        return $this;
    }

    public function subtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function withoutSearch(): self
    {
        $this->searchable = false;

        return $this;
    }

    public function param(string $suffix): string
    {
        return $this->name.'_'.$suffix;
    }

    public function sortKey(): ?string
    {
        $key = $this->request?->query($this->param('sort'));
        foreach ($this->columns as $column) {
            if ($column['key'] === $key && $column['sortable']) {
                return $key;
            }
        }

        return $this->defaultSort;
    }

    public function sortDir(): string
    {
        $dir = $this->request?->query($this->param('dir'));

        return in_array($dir, ['asc', 'desc'], true) ? $dir : $this->defaultDir;
    }

    public function search(): string
    {
        return trim((string) $this->request?->query($this->param('q'), ''));
    }

    public function resolve(Request $request): LengthAwarePaginator
    {
        $this->request = $request;
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $page = max(1, (int) $request->query($this->param('page'), 1));
        $options = ['path' => $request->url(), 'pageName' => $this->param('page'), 'query' => $request->query()];

        if ($this->source instanceof Builder) {
            $query = $this->filteredQuery();
            $total = (clone $query)->getCountForPagination();
            $items = $query->forPage($page, $this->perPage)->get();
            if ($this->autoFooter) {
                $this->footer = $this->sumColumns($this->filteredQuery(false)->get());
            }

            return $this->resolved = new LengthAwarePaginator($items, $total, $this->perPage, $page, $options);
        }

        $rows = $this->filteredCollection();
        if ($this->autoFooter) {
            $this->footer = $this->sumColumns($rows);
        }

        return $this->resolved = new LengthAwarePaginator($rows->forPage($page, $this->perPage)->values(), $rows->count(), $this->perPage, $page, $options);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function footerValues(): ?array
    {
        return $this->footer;
    }

    public function wantsExport(Request $request): bool
    {
        return in_array($request->query('export'), ['xlsx', 'pdf'], true)
            && ($request->query('table', $this->name) === $this->name);
    }

    public function export(Request $request, string $pageTitle, string $filterLabel = ''): Response
    {
        $this->request = $request;
        $rows = $this->source instanceof Builder ? $this->filteredQuery()->get() : $this->filteredCollection();
        $columns = array_values(array_filter($this->columns, fn ($c) => $c['export']));
        $fileBase = Str::slug(Str::ascii($pageTitle.' '.$this->title)).'-'.now()->format('Y-m-d');

        if ($request->query('export') === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'xls');
            $data = [];
            foreach ($rows as $row) {
                $data[] = array_map(fn ($c) => $this->rawValue($row, $c), $columns);
            }
            if ($this->footer !== null || $this->autoFooter) {
                $footer = $this->autoFooter ? $this->sumColumns($rows) : $this->footer;
                $data[] = array_map(fn ($c) => $footer[$c['key']] ?? null, $columns);
            }
            Spreadsheet::write($path, [mb_substr($this->title ?: $pageTitle, 0, 31) => ['headers' => array_column($columns, 'label'), 'rows' => $data]]);

            return response()->download($path, $fileBase.'.xlsx')->deleteFileAfterSend();
        }

        $footer = $this->autoFooter ? $this->sumColumns($rows) : $this->footer;
        $pdf = Pdf::loadView('exports.table', [
            'title' => $pageTitle.($this->title ? ' — '.$this->title : ''),
            'filterLabel' => $filterLabel,
            'columns' => $columns,
            'rows' => $rows,
            'footer' => $footer,
            'table' => $this,
        ])->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait');

        return $pdf->download($fileBase.'.pdf');
    }

    /**
     * @param  array{key: string, type: string, format: Closure|null}  $column
     */
    public function display(object|array $row, array $column): string
    {
        $value = data_get($row, $column['key']);
        if ($column['format'] !== null) {
            return (string) ($column['format'])($value, $row);
        }

        return self::formatValue($value, $column['type']);
    }

    public static function formatValue(mixed $value, string $type): string
    {
        return match ($type) {
            'money' => Fmt::money($value),
            'qty' => Fmt::qty($value),
            'int' => Fmt::number($value),
            'pct' => Fmt::pct($value),
            'date' => Fmt::date($value),
            default => $value === null || $value === '' ? '—' : (string) $value,
        };
    }

    /**
     * @param  array{key: string, link: Closure|null}  $column
     */
    public function link(object|array $row, array $column): ?string
    {
        return $column['link'] !== null ? ($column['link'])($row) : null;
    }

    public function cellClass(object|array $row, array $column): string
    {
        $classes = in_array($column['type'], ['money', 'qty', 'int', 'pct'], true) ? 'num' : ($column['wrap'] ? 'wrap' : '');
        if ($column['class'] !== null) {
            $classes .= ' '.($column['class'])(data_get($row, $column['key']), $row);
        }

        return trim($classes);
    }

    public function rowCss(object|array $row): string
    {
        return $this->rowClass !== null ? (string) ($this->rowClass)($row) : '';
    }

    /**
     * @param  array<string, mixed>  $override
     */
    public function url(array $override): string
    {
        $query = array_merge($this->request?->query() ?? [], $override);

        return ($this->request?->url() ?? '').'?'.http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== ''));
    }

    public function sortUrl(string $key): string
    {
        $dir = $this->sortKey() === $key && $this->sortDir() === 'desc' ? 'asc' : 'desc';

        return $this->url([$this->param('sort') => $key, $this->param('dir') => $dir, $this->param('page') => null]);
    }

    public function exportUrl(string $format): string
    {
        return $this->url(['export' => $format, 'table' => $this->name]);
    }

    private function filteredQuery(bool $sorted = true): Builder
    {
        /** @var Builder $query */
        $query = clone $this->source;
        $search = $this->search();
        if ($search !== '') {
            $searchColumns = array_filter($this->columns, fn ($c) => $c['searchable']);
            $query->where(function (Builder $q) use ($searchColumns, $search) {
                foreach ($searchColumns as $column) {
                    $q->orWhere(DB::raw($column['sql'] ?? $column['key']), 'like', '%'.$search.'%');
                }
            });
        }
        if ($sorted && ($key = $this->sortKey()) !== null) {
            $column = collect($this->columns)->firstWhere('key', $key);
            $query->reorder()->orderByRaw(($column['sql'] ?? $key).' '.$this->sortDir());
        }

        return $query;
    }

    /**
     * @return Collection<int, mixed>
     */
    private function filteredCollection(): Collection
    {
        $rows = $this->source ?? collect();
        $search = mb_strtolower($this->search());
        if ($search !== '') {
            $searchColumns = array_filter($this->columns, fn ($c) => $c['searchable']);
            $rows = $rows->filter(function ($row) use ($searchColumns, $search) {
                foreach ($searchColumns as $column) {
                    if (str_contains(mb_strtolower((string) data_get($row, $column['key'])), $search)) {
                        return true;
                    }
                }

                return false;
            });
        }
        if (($key = $this->sortKey()) !== null) {
            $rows = $this->sortDir() === 'desc'
                ? $rows->sortByDesc(fn ($r) => data_get($r, $key) ?? -INF)
                : $rows->sortBy(fn ($r) => data_get($r, $key) ?? INF);
        }

        return $rows->values();
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return array<string, mixed>
     */
    private function sumColumns(iterable $rows): array
    {
        $sums = [];
        foreach ($this->columns as $column) {
            if (in_array($column['type'], ['money', 'qty', 'int'], true)) {
                $sums[$column['key']] = 0.0;
            }
        }
        foreach ($rows as $row) {
            foreach ($sums as $key => $sum) {
                $sums[$key] = $sum + (float) data_get($row, $key);
            }
        }

        return $sums;
    }

    /**
     * @param  array{key: string, type: string, format: Closure|null}  $column
     */
    private function rawValue(object|array $row, array $column): mixed
    {
        $value = data_get($row, $column['key']);

        return match ($column['type']) {
            'money' => $value === null ? null : round((float) $value, 2),
            'qty', 'int' => $value === null ? null : (float) $value,
            'pct' => $value === null ? null : round((float) $value * 100, 2),
            'date' => $value ? Fmt::date($value) : null,
            default => $column['format'] !== null ? strip_tags((string) ($column['format'])($value, $row)) : $value,
        };
    }
}
