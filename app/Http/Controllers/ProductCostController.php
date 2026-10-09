<?php

namespace App\Http\Controllers;

use App\Jobs\RebuildAnalytics;
use App\Models\Product;
use App\Services\AnalyticsRebuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Təxmini maya (Qeyd: TƏXMİNİ) olan alış sətrinin vahid mayasını dəyişir və bütün mayanı yenidən qurur.
 */
class ProductCostController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $line = DB::table('purchase_lines')->where('id', $data['line_id'])->where('product_id', $product->id)->where('is_estimated', true)->first();
        abort_if($line === null, 404);

        DB::table('purchase_lines')->where('id', $line->id)->update([
            'unit_cost' => $data['unit_cost'],
            'total_cost' => round((float) $line->qty * (float) $data['unit_cost'], 4),
            'note' => trim(($line->note ?? '').' · maya əl ilə dəyişdirilib '.now()->format('d.m.Y').' ('.$request->user()->name.')'),
            'updated_at' => now(),
        ]);
        AnalyticsRebuilder::markQueued();
        RebuildAnalytics::dispatchAfterResponse();

        return back()->with('status', 'Maya yeniləndi. Bütün maya, mənfəət və anbar dəyəri yenidən hesablanır.');
    }
}
