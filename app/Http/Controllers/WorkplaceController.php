<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workplace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * İş yerləri: hər sənəd (sifariş, təhvil-təslim, faktura, transfer, silinmə, kassa/bank sənədi) bir iş yerinə yazılır.
 */
class WorkplaceController extends Controller
{
    public function index(): View
    {
        $docs = [];
        foreach (['sales_orders', 'delivery_notes', 'sales_invoices', 'warehouse_transfers', 'stock_write_offs', 'finance_documents'] as $table) {
            foreach (DB::table($table)->whereNotNull('workplace_id')->groupBy('workplace_id')->selectRaw('workplace_id, COUNT(*) AS n')->pluck('n', 'workplace_id') as $id => $n) {
                $docs[$id] = ($docs[$id] ?? 0) + $n;
            }
        }

        return view('workplaces.index', [
            'title' => __('İş yerləri'),
            'workplaces' => Workplace::query()->orderBy('code')->get(),
            'docs' => $docs,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'workplace_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $workplace = Workplace::query()->create($data);
        $this->singleDefault($workplace);

        return back()->with('status', __('«:name» iş yeri əlavə olundu.', ['name' => $workplace->name]));
    }

    public function update(Request $request, Workplace $workplace): RedirectResponse
    {
        $workplace->fill($this->validated($request, $workplace))->save();
        $this->singleDefault($workplace);

        return back()->with('status', __('«:name» iş yeri yeniləndi.', ['name' => $workplace->name]));
    }

    /**
     * İstifadəçilərin iş yeri (sənəddə susmaya görə).
     */
    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate(['users' => ['array'], 'users.*' => ['nullable', 'integer', 'exists:workplaces,id']]);
        foreach ($data['users'] ?? [] as $userId => $workplaceId) {
            User::query()->whereKey((int) $userId)->update(['workplace_id' => $workplaceId ?: null]);
        }

        return back()->with('status', __('İstifadəçilərin iş yerləri yeniləndi.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Workplace $workplace): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('workplaces', 'code')->ignore($workplace?->id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('workplaces', 'name')->ignore($workplace?->id)],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function singleDefault(Workplace $workplace): void
    {
        if ($workplace->is_default) {
            Workplace::query()->whereKeyNot($workplace->id)->update(['is_default' => false]);
        } elseif (! Workplace::query()->where('is_default', true)->exists()) {
            $workplace->forceFill(['is_default' => true])->save();
        }
    }
}
