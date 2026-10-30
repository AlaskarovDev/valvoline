<?php

namespace App\Http\Controllers;

use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Təsdiqlər: limit / endirim / minimum qiymət aşan sifarişlər rəhbərin qərarını gözləyir.
 */
class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['approved', 'rejected'], true) ? $request->query('status') : 'pending';
        $rows = DB::table('approval_requests as a')->join('sales_orders as o', 'o.id', '=', 'a.subject_id')->where('a.subject_type', 'sales_order')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')->leftJoin('users as r', 'r.id', '=', 'a.requested_by')->leftJoin('users as d', 'd.id', '=', 'a.decided_by')
            ->where('a.status', $status)->orderByDesc('a.id')->limit(200)
            ->get(['a.*', 'o.order_no', 'o.date as order_date', 'c.name as customer_name', 'r.name as requester', 'd.name as decider']);

        return view('approvals.index', ['title' => __('Təsdiqlər'), 'rows' => $rows, 'status' => $status, 'labels' => ApprovalService::statusLabels()]);
    }

    public function decide(Request $request, int $approval, ApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $order = $approvals->decide($approval, $data['decision'] === 'approve', $data['note'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $data['decision'] === 'approve'
            ? __('Sifariş № :no təsdiqləndi.', ['no' => $order->order_no]) : __('Sifariş № :no rədd edildi.', ['no' => $order->order_no]));
    }
}
