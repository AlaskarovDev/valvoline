<?php

namespace App\Livewire;

use App\Jobs\RebuildAnalytics;
use App\Models\CustomerAlias;
use App\Services\AnalyticsRebuilder;
use App\Services\ReferenceResolver;
use App\Support\NameNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tanınmayan müştəri adlarının uyğunlaşdırılması: oxşar adlar təklif edilir
 * (Azərbaycan hərfləri ə→e, ı→i … çevrilərək), seçim alias kimi yadda saxlanılır.
 */
class AliasMatcher extends Component
{
    #[Url(as: 'name')]
    public ?string $selected = null;

    public string $search = '';

    public ?string $message = null;

    public function select(string $name): void
    {
        $this->selected = $name;
        $this->search = '';
        $this->message = null;
    }

    public function match(int $customerId): void
    {
        abort_unless(auth()->user()?->can('data'), 403);
        if ($this->selected === null || trim($this->selected) === '') {
            return;
        }
        $customer = DB::table('customers')->where('id', $customerId)->first(['id', 'code', 'name']);
        if ($customer === null) {
            return;
        }
        CustomerAlias::query()->updateOrCreate(
            ['normalized' => NameNormalizer::normalize($this->selected), 'import_batch_id' => null],
            ['original_name' => $this->selected, 'customer_id' => $customer->id, 'customer_code' => $customer->code, 'main_name' => $customer->name, 'method' => 'Əl ilə'],
        );
        app(ReferenceResolver::class)->run();
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        $this->message = __('«:name» → :customer (:code) uyğunlaşdırıldı. Hesablamalar yenilənir.', ['name' => $this->selected, 'customer' => $customer->name, 'code' => $customer->code]);
        $this->selected = null;
    }

    /**
     * @return Collection<int, object{name: string, code: string|null, cnt: int, amount: float}>
     */
    private function unmatched(): Collection
    {
        $rows = collect();
        foreach (['sales_lines' => 'net_amount', 'sales_returns' => 'net_amount', 'payments' => 'total'] as $table => $amount) {
            $query = DB::table($table)->whereNull('customer_id');
            if ($table === 'sales_returns') {
                $query->where('op_type', 'customer');
            }
            $rows = $rows->concat($query->groupBy('customer_name', 'customer_code', 'original_customer_name')
                ->selectRaw("COALESCE(original_customer_name, customer_name, customer_code) AS name, customer_code AS code, COUNT(*) AS cnt, SUM({$amount}) AS amount")
                ->get());
        }

        return $rows->filter(fn ($r) => $r->name !== null)
            ->groupBy('name')
            ->map(fn ($g, $name) => (object) ['name' => (string) $name, 'code' => $g->first()->code, 'cnt' => (int) $g->sum('cnt'), 'amount' => (float) $g->sum('amount')])
            ->sortByDesc('cnt')->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function suggestions(string $name): Collection
    {
        $candidates = DB::table('customers')->get(['id', 'code', 'name', 'other_names']);
        $aliases = DB::table('customer_aliases')->whereNotNull('customer_id')->get(['customer_id', 'original_name'])->groupBy('customer_id');

        return $candidates->map(function ($c) use ($name, $aliases) {
            $score = NameNormalizer::similarity($name, $c->name);
            foreach ($aliases[$c->id] ?? [] as $alias) {
                $score = max($score, NameNormalizer::similarity($name, $alias->original_name));
            }
            $c->score = $score;

            return $c;
        })->sortByDesc('score')->take(6)->values();
    }

    public function render(): View
    {
        $unmatched = $this->unmatched();
        $results = collect();
        if ($this->selected !== null) {
            $results = trim($this->search) !== ''
                ? DB::table('customers')->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('code', 'like', '%'.$this->search.'%'))
                    ->orderBy('name')->limit(15)->get(['id', 'code', 'name'])
                : $this->suggestions($this->selected);
        }

        return view('livewire.alias-matcher', [
            'unmatched' => $unmatched,
            'results' => $results,
            'manual' => CustomerAlias::query()->whereNull('import_batch_id')->with('customer:id,name,code')->latest()->limit(50)->get(),
        ]);
    }
}
