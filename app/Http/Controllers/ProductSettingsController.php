<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Məhsul kitabçaları: markalar, istehsalçılar, siniflər, ölçü vahidləri, qiymət növləri.
 */
class ProductSettingsController extends Controller
{
    /**
     * kitabça => [cədvəl, məhsuldakı sahə]
     */
    private const KINDS = [
        'brands' => ['brands', 'brand_id'],
        'manufacturers' => ['manufacturers', 'manufacturer_id'],
        'classes' => ['product_classes', 'product_class_id'],
        'units' => ['units', 'base_unit_id'],
        'price-lists' => ['price_lists', null],
    ];

    public function index(Request $request): View
    {
        $counts = fn (string $field) => DB::table('products')->whereNotNull($field)->groupBy($field)->selectRaw("{$field} AS id, COUNT(*) AS n")->pluck('n', 'id');

        return view('products.settings', [
            'title' => __('Məhsul parametrləri'),
            'tab' => array_key_exists($request->query('tab', ''), self::KINDS) ? $request->query('tab') : 'brands',
            'brands' => DB::table('brands')->orderBy('name')->get(), 'brandCounts' => $counts('brand_id'),
            'manufacturers' => DB::table('manufacturers')->orderBy('name')->get(), 'manufacturerCounts' => $counts('manufacturer_id'),
            'classes' => DB::table('product_classes')->orderBy('name')->get(), 'classCounts' => $counts('product_class_id'),
            'units' => DB::table('units')->orderBy('name')->get(), 'unitCounts' => $counts('base_unit_id'),
            'priceLists' => DB::table('price_lists')->orderBy('type')->orderByDesc('is_default')->orderBy('name')->get(),
            'listCounts' => DB::table('price_cards')->whereNotNull('price_list_id')->groupBy('price_list_id')->selectRaw('price_list_id AS id, COUNT(*) AS n')->pluck('n', 'id'),
            'listCustomers' => DB::table('customers')->whereNotNull('price_list_id')->groupBy('price_list_id')->selectRaw('price_list_id AS id, COUNT(*) AS n')->pluck('n', 'id'),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        [$table] = self::KINDS[$kind] ?? abort(404);
        $data = $this->validated($request, $kind, $table, null);
        DB::table($table)->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        $this->singleDefault($kind, $data, (int) DB::getPdo()->lastInsertId());

        return redirect()->route('product-settings.index', ['tab' => $kind])->with('status', __('«:name» əlavə olundu.', ['name' => $data['name']]));
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        [$table] = self::KINDS[$kind] ?? abort(404);
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
        $data = $this->validated($request, $kind, $table, $id);
        DB::table($table)->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->singleDefault($kind, $data, $id);

        return redirect()->route('product-settings.index', ['tab' => $kind])->with('status', __('«:name» yeniləndi.', ['name' => $data['name']]));
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        [$table, $field] = self::KINDS[$kind] ?? abort(404);
        $used = $field ? DB::table('products')->where($field, $id)->exists()
            : DB::table('price_cards')->where('price_list_id', $id)->exists() || DB::table('customers')->where('price_list_id', $id)->exists();
        if ($kind === 'units') {
            $used = $used || DB::table('product_units')->where('unit_id', $id)->exists();
        }
        if ($used) {
            return back()->with('error', __('İstifadə olunan qeyd silinmir.'));
        }
        DB::table($table)->where('id', $id)->delete();

        return redirect()->route('product-settings.index', ['tab' => $kind])->with('status', __('Silindi.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $kind, string $table, ?int $id): array
    {
        $rules = ['name' => ['required', 'string', 'max:'.($kind === 'units' ? 32 : 255), Rule::unique($table, 'name')->ignore($id)]];
        $rules += match ($kind) {
            'manufacturers' => ['country' => ['nullable', 'string', 'max:64']],
            'units' => ['code' => ['nullable', 'string', 'max:16']],
            'price-lists' => ['type' => ['required', Rule::in(['sale', 'purchase'])], 'vat_included' => ['nullable', 'boolean'], 'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']],
            default => [],
        };
        $data = $request->validate($rules);
        if ($kind === 'price-lists') {
            $data['vat_included'] = $request->boolean('vat_included');
            $data['is_default'] = $request->boolean('is_default');
            $data['is_active'] = $request->boolean('is_active', true);
        }

        return $data;
    }

    /**
     * Hər növdən (satış / alış) yalnız bir əsas qiymət növü.
     *
     * @param  array<string, mixed>  $data
     */
    private function singleDefault(string $kind, array $data, int $id): void
    {
        if ($kind === 'price-lists' && ! empty($data['is_default'])) {
            DB::table('price_lists')->where('type', $data['type'])->where('id', '<>', $id)->update(['is_default' => false]);
        }
    }
}
