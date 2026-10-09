<?php

namespace App\Reports;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bütün hesabat səhifələrinin ortaq filtr paneli.
 * Menecer rolunda olan istifadəçi üçün manager_id məcburi olaraq öz id-si ilə əvəzlənir.
 */
class ReportFilters
{
    public const PRESETS = [
        'this_month' => 'Bu ay',
        'last_month' => 'Keçən ay',
        'this_year' => 'Bu il',
        'last_year' => 'Keçən il',
        'last_12' => 'Son 12 ay',
        'all' => 'Bütün dövr',
    ];

    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $preset = 'custom',
        public ?int $managerId = null,
        public ?int $customerId = null,
        public ?int $productId = null,
        public ?int $groupId = null,
        public ?int $categoryId = null,
        public ?string $series = null,
        public ?string $direction = null,
        public bool $managerLocked = false,
        public string $groupBy = 'code',
    ) {}

    public static function fromRequest(Request $request, string $defaultPreset = 'last_12', ?string $defaultDirection = null): self
    {
        $preset = (string) $request->query('preset', $request->filled('from') ? 'custom' : $defaultPreset);
        [$from, $to] = self::range($preset);
        if ($preset === 'custom') {
            $from = self::parseDate($request->query('from')) ?? $from;
            $to = self::parseDate($request->query('to')) ?? $to;
        }
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        /** @var User|null $user */
        $user = $request->user();
        $restricted = $user?->restrictedManagerId();

        return new self(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            preset: array_key_exists($preset, self::PRESETS) ? $preset : 'custom',
            managerId: $restricted ?? self::intOrNull($request->query('manager')),
            customerId: self::intOrNull($request->query('customer')),
            productId: self::intOrNull($request->query('product')),
            groupId: self::intOrNull($request->query('group')),
            categoryId: self::intOrNull($request->query('category')),
            series: self::stringOrNull($request->query('series')),
            direction: $request->has('direction') ? self::stringOrNull($request->query('direction')) : $defaultDirection,
            managerLocked: $restricted !== null,
            groupBy: $request->query('by') === 'group' ? 'group' : 'code',
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $preset): array
    {
        $today = now();

        return match ($preset) {
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            'all' => [Carbon::create(2000, 1, 1), $today->copy()->addYears(5)->endOfYear()],
            default => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today->copy()->endOfMonth()],
        };
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function fromPeriod(): string
    {
        return $this->from->format('Y-m');
    }

    public function toPeriod(): string
    {
        return $this->to->format('Y-m');
    }

    /**
     * Aralıqdakı aylar (YYYY-MM), məlumat olan ən erkən/son aya qədər sıxılmış.
     *
     * @return list<string>
     */
    public function periods(?string $minData = null, ?string $maxData = null): array
    {
        $start = $this->from->copy()->startOfMonth();
        $end = $this->to->copy()->startOfMonth();
        if ($minData !== null && $start->format('Y-m') < $minData) {
            $start = Carbon::createFromFormat('Y-m-d', $minData.'-01')->startOfMonth();
        }
        if ($maxData !== null && $end->format('Y-m') > $maxData) {
            $end = Carbon::createFromFormat('Y-m-d', $maxData.'-01')->startOfMonth();
        }
        $periods = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addMonthNoOverflow()) {
            $periods[] = $cursor->format('Y-m');
        }

        return $periods;
    }

    /**
     * Linklər üçün query parametrləri (drill-down zamanı filtr saxlanılır).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public function query(array $override = []): array
    {
        $query = $this->preset === 'custom'
            ? ['from' => $this->fromDate(), 'to' => $this->toDate()]
            : ['preset' => $this->preset];
        $query += array_filter([
            'manager' => $this->managerLocked ? null : $this->managerId,
            'customer' => $this->customerId,
            'product' => $this->productId,
            'group' => $this->groupId,
            'category' => $this->categoryId,
            'series' => $this->series,
            'direction' => $this->direction,
            'by' => $this->groupBy === 'group' ? 'group' : null,
        ], fn ($v) => $v !== null && $v !== '');

        return array_filter(array_merge($query, $override), fn ($v) => $v !== null && $v !== '');
    }

    public function label(): string
    {
        return $this->preset !== 'custom' && isset(self::PRESETS[$this->preset])
            ? self::PRESETS[$this->preset].' ('.$this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y').')'
            : $this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y');
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date instanceof Carbon && $date->format($format) === $value) {
                    return $date;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
