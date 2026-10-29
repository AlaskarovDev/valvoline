<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\SalesDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Anbarlar: əsas, səyyar maşın, regional, karantin (tranzit sistemindir). Qalıq və dəyər xülasəsi ilə.
 */
class WarehouseController extends Controller
{
    public function index(SalesDocumentService $documents): View
    {
        $reserved = [];
        foreach ($documents->reserved() as $byWarehouse) {
            foreach ($byWarehouse as $w => $qty) {
                $reserved[$w] = ($reserved[$w] ?? 0) + $qty;
            }
        }
        $stock = DB::table('stock_balances as s')->join('products as p', 'p.id', '=', 's.product_id')->groupBy('s.warehouse_id')
            ->selectRaw('s.warehouse_id, SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS qty, SUM(CASE WHEN s.qty > 0 THEN s.qty * COALESCE(p.current_avg_cost, 0) ELSE 0 END) AS value, SUM(CASE WHEN s.qty > 0.0001 THEN 1 ELSE 0 END) AS skus, SUM(CASE WHEN s.qty < -0.0001 THEN 1 ELSE 0 END) AS negative')
            ->get()->keyBy('warehouse_id');

        return view('warehouses.index', [
            'title' => __('Anbarlar'),
            'warehouses' => Warehouse::query()->orderByDesc('is_main')->orderBy('type')->orderBy('name')->get(),
            'stock' => $stock, 'reserved' => $reserved, 'types' => Warehouse::typeLabels(),
            'managers' => DB::table('managers')->orderBy('name')->get(['id', 'name']),
            'transitOpen' => DB::table('warehouse_transfers')->whereIn('status', ['sent', 'partial'])->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['code'] ??= 'W'.((int) DB::table('warehouses')->max('id') + 1);
        Warehouse::query()->create($data + ['is_main' => false]);

        return back()->with('status', __('«:name» anbarı yaradıldı.', ['name' => $data['name']]));
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        abort_if($warehouse->type === 'transit', 403);
        $data = $this->validated($request, $warehouse);
        if ($warehouse->is_main) {
            $data['type'] = 'main';
            $data['is_active'] = true;
        }
        $warehouse->fill($data)->save();

        return back()->with('status', __('«:name» anbarı yeniləndi.', ['name' => $warehouse->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Warehouse $warehouse): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->ignore($warehouse?->id)],
            'code' => ['nullable', 'string', 'max:16', Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'type' => ['required', Rule::in(['main', 'van', 'regional', 'quarantine'])],
            'address' => ['nullable', 'string', 'max:255'],
            'responsible' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'manager_id' => ['nullable', 'integer', 'exists:managers,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        if ($data['type'] === 'main' && ! $warehouse?->is_main) {
            $data['type'] = 'regional';
        }

        return $data;
    }
}
