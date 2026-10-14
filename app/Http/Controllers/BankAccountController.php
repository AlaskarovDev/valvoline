<?php

namespace App\Http\Controllers;

use App\Services\BankStatementService;
use App\Services\FlowClassifier;
use App\Support\Fmt;
use App\Support\NameNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bank hesabları: hesablar, ay sonu qalıqları (mühasibat faylından) və əməliyyatlar
 * (bank çıxarışından import və ya əl ilə). Əməliyyatlar məlumat xarakterlidir — P&L-ə Cash Flow-dan düşür.
 */
class BankAccountController extends Controller
{
    public const KINDS = ['bank', 'card', 'cash', 'deposit'];

    public function index(Request $request, BankStatementService $service): View
    {
        $service->syncAccounts();
        $years = $this->years();
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : ($years[0] ?? null);

        $balances = DB::table('bank_flow_balances')->when($year, fn ($q) => $q->where('year', $year))->get();
        $months = $balances->pluck('period')->unique()->sort()->values()->all();
        $latest = DB::table('bank_flow_balances')->orderByDesc('period')->get()->groupBy('account')->map(fn (Collection $g) => $g->first());
        $ops = DB::table('bank_transactions')->groupBy('bank_account_id')
            ->selectRaw('bank_account_id, COUNT(*) AS n, MAX(date) AS last_date, SUM(amount_in) AS total_in, SUM(amount_out) AS total_out')
            ->get()->keyBy('bank_account_id');

        $accounts = DB::table('bank_accounts')->orderByDesc('is_active')->orderBy('kind')->orderBy('name')->get()
            ->map(function ($a) use ($latest, $ops, $balances) {
                $key = NameNormalizer::normalize($a->name);
                $a->latest = $latest->first(fn ($b) => NameNormalizer::normalize($b->account) === $key);
                $a->ops = $ops[$a->id] ?? null;
                $a->monthly = $balances->filter(fn ($b) => NameNormalizer::normalize($b->account) === $key)->pluck('amount', 'period')->map(fn ($v) => (float) $v)->all();

                return $a;
            });
        $totals = collect($months)->mapWithKeys(fn ($m) => [$m => (float) $balances->where('period', $m)->sum('amount')])->all();

        return view('bank.index', [
            'title' => __('Bank hesabları'),
            'year' => $year,
            'years' => $years,
            'months' => $months,
            'accounts' => $accounts,
            'totals' => $totals,
            // cəmi yalnız son ayın qalıqları: sonradan fayldan çıxmış (bağlanmış) hesabın köhnə qalığı daxil edilmir
            'latestTotal' => (float) $accounts->filter(fn ($a) => $a->latest && $a->latest->period === $latest->max('period'))->sum(fn ($a) => $a->latest->amount),
            'latestPeriod' => $latest->max('period'),
            'chart' => [
                'type' => 'bar',
                'stacked' => true,
                'categories' => array_map(fn ($m) => Fmt::period($m), $months),
                'series' => $accounts->filter(fn ($a) => array_sum(array_map('abs', $a->monthly)) > 0.5)
                    ->map(fn ($a) => ['name' => $a->name, 'data' => array_map(fn ($m) => round($a->monthly[$m] ?? 0, 2), $months)])->values()->all(),
            ],
            'recent' => DB::table('bank_transactions as t')->join('bank_accounts as a', 'a.id', '=', 't.bank_account_id')
                ->orderByDesc('t.date')->orderByDesc('t.id')->limit(15)->get(['t.*', 'a.name as account_name']),
            'kinds' => self::kindLabels(),
        ]);
    }

    public function show(Request $request, int $account): View
    {
        $acc = DB::table('bank_accounts')->where('id', $account)->first() ?? abort(404);
        $key = NameNormalizer::normalize($acc->name);
        $fileBalances = DB::table('bank_flow_balances')->get()->filter(fn ($b) => NameNormalizer::normalize($b->account) === $key)
            ->pluck('amount', 'period')->map(fn ($v) => (float) $v)->sortKeys();
        $opYears = DB::table('bank_transactions')->where('bank_account_id', $acc->id)->selectRaw('DISTINCT SUBSTR(period, 1, 4) AS y')->pluck('y')->map(fn ($y) => (int) $y);
        $years = $opYears->merge($fileBalances->keys()->map(fn ($p) => (int) substr($p, 0, 4)))->unique()->sortDesc()->values()->all();
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : ($years[0] ?? (int) now()->format('Y'));
        $q = trim((string) $request->query('q', ''));
        $dir = in_array($request->query('dir'), ['in', 'out'], true) ? (string) $request->query('dir') : '';

        $base = DB::table('bank_transactions')->where('bank_account_id', $acc->id)->where('period', 'like', $year.'-%');
        $filtered = (clone $base)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('counterparty', 'like', '%'.$q.'%')->orWhere('description', 'like', '%'.$q.'%')->orWhere('doc_no', 'like', '%'.$q.'%')))
            ->when($dir === 'in', fn ($query) => $query->where('amount_in', '>', 0))
            ->when($dir === 'out', fn ($query) => $query->where('amount_out', '>', 0));
        $transactions = (clone $filtered)->orderByDesc('date')->orderByDesc('id')->paginate(100)->withQueryString();
        $classifier = new FlowClassifier;
        $types = FlowClassifier::typeLabels();
        $transactions->getCollection()->transform(function ($t) use ($classifier, $types) {
            $c = $classifier->classify((string) $t->counterparty, $t->amount_in > 0 ? 'income' : 'payment');
            $t->type = $c['type'];
            $t->type_label = $types[$c['type']] ?? $c['type'];
            $t->group = $c['group'];

            return $t;
        });

        // ay üzrə: mühasibat faylındakı qalıq ↔ əməliyyatlar
        $byMonth = (clone $base)->groupBy('period')->selectRaw('period, SUM(amount_in) AS total_in, SUM(amount_out) AS total_out, COUNT(*) AS n')->get()->keyBy('period');
        $lastBalance = (clone $base)->whereNotNull('balance')->orderBy('date')->orderBy('id')->get(['period', 'balance'])->groupBy('period')->map(fn ($g) => (float) $g->last()->balance);
        $months = collect(range(1, 12))->map(fn ($m) => sprintf('%d-%02d', $year, $m))
            ->filter(fn ($p) => isset($fileBalances[$p]) || isset($byMonth[$p]))->values();
        $monthly = $months->map(fn ($p) => (object) [
            'period' => $p,
            'file' => $fileBalances[$p] ?? null,
            'in' => (float) ($byMonth[$p]->total_in ?? 0),
            'out' => (float) ($byMonth[$p]->total_out ?? 0),
            'n' => (int) ($byMonth[$p]->n ?? 0),
            'statement' => $lastBalance[$p] ?? null,
        ]);

        return view('bank.show', [
            'title' => $acc->name,
            'account' => $acc,
            'year' => $year,
            'years' => $years,
            'q' => $q,
            'dir' => $dir,
            'transactions' => $transactions,
            'monthly' => $monthly,
            'sumIn' => (float) (clone $filtered)->sum('amount_in'),
            'sumOut' => (float) (clone $filtered)->sum('amount_out'),
            'fileLatest' => $fileBalances->isNotEmpty() ? [$fileBalances->keys()->last(), $fileBalances->last()] : null,
            'kinds' => self::kindLabels(),
            'typeLabels' => $types,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $id = DB::table('bank_accounts')->insertGetId(array_merge($data, ['created_at' => now(), 'updated_at' => now()]));

        return redirect()->route('bank.show', $id)->with('status', __('Hesab yaradıldı.'));
    }

    public function update(Request $request, int $account): RedirectResponse
    {
        DB::table('bank_accounts')->where('id', $account)->firstOrFail();
        DB::table('bank_accounts')->where('id', $account)->update(array_merge($this->validated($request, $account), ['updated_at' => now()]));

        return back()->with('status', __('Hesab yeniləndi.'));
    }

    public function storeTransaction(Request $request, int $account): RedirectResponse
    {
        DB::table('bank_accounts')->where('id', $account)->firstOrFail();
        $data = $request->validate([
            'date' => ['required', 'date'],
            'counterparty' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'doc_no' => ['nullable', 'string', 'max:64'],
        ]);
        $date = substr((string) $data['date'], 0, 10);
        DB::table('bank_transactions')->insert([
            'bank_account_id' => $account,
            'date' => $date,
            'period' => substr($date, 0, 7),
            'counterparty' => $data['counterparty'],
            'normalized' => NameNormalizer::normalize($data['counterparty']),
            'description' => $data['description'] ?? null,
            'amount_in' => $data['direction'] === 'in' ? $data['amount'] : 0,
            'amount_out' => $data['direction'] === 'out' ? $data['amount'] : 0,
            'doc_no' => $data['doc_no'] ?? null,
            'source' => 'manual',
            'user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('bank.show', ['account' => $account, 'year' => substr($date, 0, 4)])->with('status', __('Əməliyyat əlavə olundu.'));
    }

    public function import(Request $request, int $account, BankStatementService $service): RedirectResponse
    {
        DB::table('bank_accounts')->where('id', $account)->firstOrFail();
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt']]);
        $file = $request->file('file');
        $parsed = $service->parse($file->getRealPath());
        if ($parsed['errors'] !== [] && $parsed['rows'] === []) {
            return back()->withErrors(['file' => $parsed['errors'][0]]);
        }
        $result = $service->store($account, $parsed['rows'], $file->getClientOriginalName(), $request->user()->id);
        $years = collect($parsed['rows'])->pluck('date')->map(fn ($d) => substr($d, 0, 4))->unique()->sortDesc()->values();

        return redirect()->route('bank.show', ['account' => $account, 'year' => $years->first()])->with('status', __('Çıxarış import edildi: :added yeni əməliyyat, :skipped təkrar ötürüldü. Tanınan sütunlar: :columns.', [
            'added' => $result['added'],
            'skipped' => $result['skipped'],
            'columns' => implode(', ', $parsed['columns']),
        ]));
    }

    public function destroyTransaction(int $transaction): RedirectResponse
    {
        DB::table('bank_transactions')->where('id', $transaction)->firstOrFail();
        DB::table('bank_transactions')->where('id', $transaction)->delete();

        return back()->with('status', __('Əməliyyat silindi.'));
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return ['bank' => __('Cari hesab'), 'card' => __('Kart hesabı'), 'cash' => __('Kassa'), 'deposit' => __('Depozit')];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('bank_accounts', 'name')->ignore($id)],
            'bank' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', Rule::in(['AZN', 'USD', 'EUR', 'RUB', 'GBP', 'TRY'])],
            'kind' => ['required', Rule::in(self::KINDS)],
            'iban' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        return DB::table('bank_flow_balances')->distinct()->pluck('year')
            ->merge(DB::table('bank_transactions')->selectRaw('DISTINCT SUBSTR(period, 1, 4) AS y')->pluck('y'))
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }
}
