<?php

namespace App\Http\Controllers;

use App\Models\Manager;
use App\Models\Warehouse;
use App\Models\Workplace;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Satış nümayəndələri / ekspeditorlar: kod, telefon, iş yeri, səyyar anbar və ayrıca kassa (kassa kodu ilə).
 */
class SalesRepController extends Controller
{
    public function index(FinanceService $finance): View
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $balances = $finance->cashBalances();
        $registers = DB::table('cash_registers')->whereNotNull('manager_id')->get(['id', 'code', 'manager_id'])->keyBy('manager_id');
        $customers = DB::table('customers')->whereNotNull('manager_id')->groupBy('manager_id')->selectRaw('manager_id, COUNT(*) AS n')->pluck('n', 'manager_id');
        $sales = DB::table('sales_lines')->where('op_type', 'sale')->where('date', '>=', $monthStart)->whereNotNull('manager_id')->groupBy('manager_id')->selectRaw('manager_id, SUM(net_amount) AS s')->pluck('s', 'manager_id');
        $debts = DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')->whereNotNull('c.manager_id')->where('b.balance', '>', 0)
            ->groupBy('c.manager_id')->selectRaw('c.manager_id, SUM(b.balance) AS d')->pluck('d', 'c.manager_id');
        $reps = DB::table('managers as m')->leftJoin('workplaces as w', 'w.id', '=', 'm.workplace_id')->leftJoin('warehouses as wh', 'wh.id', '=', 'm.warehouse_id')
            ->orderBy('m.name')->get(['m.*', 'w.name as workplace_name', 'wh.name as warehouse_name'])
            ->map(function ($m) use ($registers, $balances, $customers, $sales, $debts) {
                $reg = $registers[$m->id] ?? null;
                $m->register_id = $reg?->id;
                $m->register_code = $reg?->code;
                $m->cash = $reg ? ($balances[$reg->id] ?? 0.0) : null;
                $m->customers = (int) ($customers[$m->id] ?? 0);
                $m->month_sales = (float) ($sales[$m->id] ?? 0);
                $m->debt = (float) ($debts[$m->id] ?? 0);
                $m->plan = $m->monthly_target ? $m->month_sales / (float) $m->monthly_target : null;

                return $m;
            });

        return view('reps.index', [
            'title' => __('Satış nümayəndələri'), 'reps' => $reps,
            'workplaces' => Workplace::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->sellable()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        DB::transaction(function () use ($data, $request) {
            $data['code'] ??= $this->nextCode();
            $manager = Manager::query()->create(collect($data)->except('create_register')->all());
            if ($request->boolean('create_register', true)) {
                $this->ensureRegister($manager);
            }
        });

        return back()->with('status', __('«:name» satış nümayəndəsi əlavə olundu.', ['name' => $data['name']]));
    }

    public function update(Request $request, Manager $manager): RedirectResponse
    {
        $data = $this->validated($request, $manager);
        $manager->fill(collect($data)->except('create_register')->all())->save();
        if ($request->boolean('create_register')) {
            $this->ensureRegister($manager);
        }

        return back()->with('status', __('«:name» yeniləndi.', ['name' => $manager->name]));
    }

    private function ensureRegister(Manager $manager): void
    {
        if (DB::table('cash_registers')->where('manager_id', $manager->id)->exists()) {
            return;
        }
        $last = DB::table('cash_registers')->where('code', 'like', 'KS-%')->pluck('code')->map(fn ($c) => (int) substr($c, 3))->max() ?? 0;
        DB::table('cash_registers')->insert([
            'code' => 'KS-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Kassa — '.$manager->name, 'type' => 'rep',
            'manager_id' => $manager->id, 'workplace_id' => $manager->workplace_id, 'currency' => 'AZN', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Manager $manager): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('managers', 'name')->ignore($manager?->id)],
            'code' => ['nullable', 'string', 'max:16', Rule::unique('managers', 'code')->ignore($manager?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(['rep', 'forwarder'])],
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'monthly_target' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'create_register' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function nextCode(): string
    {
        $last = DB::table('managers')->where('code', 'like', 'SN-%')->pluck('code')->map(fn ($c) => (int) substr($c, 3))->max() ?? 0;

        return 'SN-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
