<?php

namespace App\Http\Controllers;

use App\Services\NumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tənzimləmələr → Nömrələmə servisi: hər sənəd və kod növünün şablonu, rejimi, sıfırlanması və növbəti nömrəsi.
 */
class NumberingController extends Controller
{
    public function __construct(private NumberingService $numbering) {}

    public function index(): View
    {
        $sample = $this->numbering->sampleValues();
        $labels = NumberingService::labels();
        $rules = collect(NumberingService::RULES)->map(function ($def, $key) use ($sample, $labels) {
            $rule = $this->numbering->rule($key);

            return (object) [
                'key' => $key, 'group' => $def[0], 'label' => $labels[$key], 'pattern' => $rule->pattern, 'mode' => $rule->mode, 'reset' => $rule->reset,
                'note' => $rule->note, 'default' => $def[3], 'max' => $def[6],
                'preview' => $this->numbering->preview($key, ['date' => now(), 'workplace_id' => DB::table('workplaces')->where('is_default', true)->value('id'),
                    'warehouse_id' => DB::table('warehouses')->where('is_main', true)->value('id')]),
                'parts' => $this->numbering->explain($rule->pattern, $sample),
                'last' => DB::table($def[1])->whereNotNull($def[2])->orderByDesc('id')->value($def[2]),
            ];
        })->values();

        return view('numbering.index', [
            'title' => __('Nömrələmə servisi'),
            'groups' => ['sales' => __('Satış sənədləri'), 'stock' => __('Anbar və stok sənədləri'), 'purchase' => __('Alış sənədləri'), 'finance' => __('Kassa və bank sənədləri'), 'codes' => __('Kodlar')],
            'rules' => $rules, 'tokens' => NumberingService::tokenLabels(), 'sample' => $sample,
            'modes' => self::modes(), 'resets' => self::resets(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, NumberingService::RULES), 404);
        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:64'],
            'mode' => ['required', Rule::in(array_keys(self::modes()))],
            'reset' => ['required', Rule::in(array_keys(self::resets()))],
            'note' => ['nullable', 'string', 'max:255'],
            'next' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ]);
        $errors = $this->numbering->validatePattern($key, $data['pattern'], $data['mode']);
        if ($errors) {
            return back()->withInput()->withErrors(['pattern_'.$key => implode(' ', $errors)])->with('openRule', $key);
        }
        DB::table('numbering_rules')->updateOrInsert(['key' => $key], [
            'pattern' => $data['pattern'], 'mode' => $data['mode'], 'reset' => $data['reset'], 'note' => $data['note'] ?? null,
            'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
        ]);
        if (! empty($data['next'])) {
            $this->numbering->setNext($key, (int) $data['next'], ['date' => now(), 'workplace_id' => DB::table('workplaces')->where('is_default', true)->value('id'),
                'warehouse_id' => DB::table('warehouses')->where('is_main', true)->value('id')]);
        }

        return redirect()->to(route('numbering.index').'#'.$key)->with('status', __('«:name» nömrələmə qaydası yeniləndi. Növbəti nömrə: :no', [
            'name' => NumberingService::labels()[$key], 'no' => $this->numbering->preview($key),
        ]));
    }

    /**
     * Şablonun canlı önizləməsi (JSON).
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        abort_unless(array_key_exists($key, NumberingService::RULES), 404);
        $pattern = (string) $request->query('pattern', '');
        $mode = (string) $request->query('mode', 'auto');
        $sample = $this->numbering->sampleValues();

        return response()->json([
            'number' => $this->numbering->render($pattern, $sample, 1),
            'parts' => $this->numbering->explain($pattern, $sample),
            'errors' => $this->numbering->validatePattern($key, $pattern, $mode),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function modes(): array
    {
        return ['auto' => __('Avtomatik'), 'auto_editable' => __('Avtomatik, dəyişmək olar'), 'manual' => __('Əl ilə')];
    }

    /**
     * @return array<string, string>
     */
    public static function resets(): array
    {
        return [
            'prefix' => __('Nömrənin qalan hissəsi dəyişəndə (məs. yeni gün, başqa iş yeri)'),
            'never' => __('Heç vaxt — davamlı'), 'yearly' => __('Hər il'), 'monthly' => __('Hər ay'), 'daily' => __('Hər gün'),
        ];
    }
}
