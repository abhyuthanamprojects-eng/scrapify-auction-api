<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** Backs the admin Reports screen and its CSV export. */
    public function auctions(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)->with(['category', 'lots'])->get();

        $totalValue = $rows->sum(fn (Auction $a) => (float) ($a->final_price ?? $a->current_highest ?? 0));

        $savings = $rows->isEmpty() ? 0 : $rows->avg(function (Auction $a) {
            $reserve = (float) $a->reserve_price;
            if ($reserve <= 0) {
                return 0;
            }
            $final = (float) ($a->final_price ?? $a->current_highest ?? $reserve);

            return ($final - $reserve) / $reserve;
        });

        return response()->json([
            'summary' => [
                'total_auctions' => $rows->count(),
                'total_value_inr' => $totalValue,
                'avg_uplift_pct' => round($savings * 100, 2),
                'top_category' => $rows->groupBy(fn ($a) => $a->category?->name ?? '—')
                    ->sortByDesc->count()->keys()->first() ?? '—',
            ],
            'data' => $rows->map(fn (Auction $a) => [
                'auction_id' => $a->code,
                'title' => $a->title,
                'company' => $a->company,
                'category' => $a->category?->name,
                'location' => $a->location,
                'starting_price_inr' => (float) $a->starting_price,
                'reserve_price_inr' => (float) $a->reserve_price,
                'final_price_inr' => (float) ($a->final_price ?? $a->current_highest ?? 0),
                'bidders' => $a->bidders_count,
                'winner' => $a->winner_name,
                'status' => $a->status,
                'closed_at' => $a->closed_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * H1 Summary — the per-lot commercial breakdown the admin panel renders
     * as a PDF. Tax rates are configurable in config/scrapify.php.
     */
    public function h1(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->with(['lots', 'bids.vendor', 'category'])->firstOrFail();

        $gstPct = config('scrapify.gst_pct');
        $tcsPct = config('scrapify.tcs_pct');
        $staPct = config('scrapify.sta_pct');

        $lots = $auction->lots->isNotEmpty()
            ? $auction->lots
            : collect([(object) [
                'code' => $auction->code.'-L1',
                'name' => $auction->title,
                'quantity' => $auction->quantity ?? '1 Lot',
                'reserve_price' => $auction->reserve_price,
                'current_bid' => $auction->final_price ?? $auction->current_highest,
                'id' => null,
            ]]);

        $rows = $lots->values()->map(function ($lot, $i) use ($auction, $gstPct, $tcsPct, $staPct) {
            $closing = (float) ($lot->current_bid ?? $auction->final_price ?? $auction->current_highest ?? 0);

            $top = $auction->bids
                ->filter(fn ($b) => $lot->id === null || $b->lot_id === $lot->id)
                ->sortByDesc('amount')
                ->first();

            $emd = (float) $auction->emd_amount;
            $gst = $closing * $gstPct / 100;
            $tcs = $closing * $tcsPct / 100;
            $total = $closing + $gst + $tcs;

            return [
                'sr_no' => $i + 1,
                'lot_no' => $lot->code,
                'item_name' => $lot->name,
                'location' => $auction->location,
                'qty_uom' => $lot->quantity,
                'starting_price_inr' => (float) $auction->starting_price,
                'reserve_price_inr' => (float) $lot->reserve_price,
                'sta_pct' => $staPct,
                'h1_bid_inr' => $closing,
                'h1_bidder_name' => $top?->vendor_name ?? $auction->winner_name,
                'h1_bidder_email' => $top?->vendor?->email,
                'status' => $closing > 0 && $closing >= (float) $lot->reserve_price ? 'Sold' : 'Unsold',
                'closing_price_inr' => $closing,
                'emd_hold_inr' => $emd,
                'gst_pct' => $gstPct,
                'gst_amount_inr' => round($gst, 2),
                'tcs_pct' => $tcsPct,
                'tcs_amount_inr' => round($tcs, 2),
                'total_with_taxes_inr' => round($total, 2),
                'balance_after_emd_inr' => round($total - $emd, 2),
            ];
        });

        return response()->json([
            'auction' => [
                'id' => $auction->code,
                'company' => $auction->company,
                'title' => $auction->title,
                'department' => trim("{$auction->plant} — {$auction->warehouse}", ' —'),
                'start' => $auction->schedule_start?->toIso8601String(),
                'end' => $auction->schedule_end?->toIso8601String(),
            ],
            'rows' => $rows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** All Bid Report — every bid on an auction, newest first. */
    public function allBids(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        return response()->json([
            'auction' => ['id' => $auction->code, 'company' => $auction->company, 'title' => $auction->title],
            'rows' => $auction->bids()->with('vendor')->latest('id')->get()->values()->map(fn (Bid $b, $i) => [
                'sr_no' => $i + 1,
                'company_name' => $b->vendor_name,
                'vendor_id' => $b->vendor?->code,
                'mobile' => $b->vendor?->phone,
                'email' => $b->vendor?->email,
                'bid_date' => $b->created_at?->toDateString(),
                'bid_time' => $b->created_at?->format('H:i:s'),
                'bid_price_inr' => (float) $b->amount,
                'ip' => $b->ip,
                'location' => $auction->location,
            ]),
        ]);
    }

    /** All Bidder Report — one row per distinct bidder with KYC identifiers. */
    public function allBidders(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        $vendorIds = $auction->bids()->distinct('vendor_id')->pluck('vendor_id');

        return response()->json([
            'auction' => ['id' => $auction->code, 'company' => $auction->company, 'title' => $auction->title],
            'rows' => Vendor::whereIn('id', $vendorIds)->get()->values()->map(fn (Vendor $v, $i) => [
                'sr_no' => $i + 1,
                'company_name' => $v->company_name,
                'vendor_id' => $v->code,
                'mobile' => $v->phone,
                'email' => $v->email,
                'gst_no' => $v->gst_number,
                'gst_address' => $v->address ?? $v->location,
                'location' => $v->location,
            ]),
        ]);
    }

    /** The admin dashboard's KPI tiles, activity feed and charts. */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'kpis' => [
                'pending_vendors' => Vendor::where('status', 'pending')->count(),
                'pending_organizations' => Organization::where('status', 'pending_super_admin_approval')->count(),
                'live_auctions' => Auction::where('status', 'live')->count(),
                'auctions_awaiting_publish' => Auction::where('status', 'approved')->count(),
                'total_vendors' => Vendor::count(),
            ],
            'needs_attention' => Auction::where('status', 'pending_approval')
                ->latest('submitted_at')
                ->limit(5)
                ->get()
                ->map(fn (Auction $a) => [
                    'id' => $a->code,
                    'type' => 'Auction',
                    'name' => $a->title,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'hours_waiting' => $a->submitted_at ? (int) $a->submitted_at->diffInHours(now()) : null,
                ])
                ->concat(
                    Vendor::where('status', 'pending')->latest()->limit(5)->get()->map(fn (Vendor $v) => [
                        'id' => $v->code,
                        'type' => 'Vendor',
                        'name' => $v->company_name,
                        'submitted_at' => $v->created_at?->toIso8601String(),
                        'hours_waiting' => (int) $v->created_at->diffInHours(now()),
                    ]),
                )
                ->sortByDesc('hours_waiting')
                ->take(6)
                ->values(),
            'activity' => \App\Models\AuditLog::latest('created_at')->latest('id')->limit(10)->get()->map(fn ($l) => [
                'id' => $l->code,
                'actor' => $l->user_name,
                'action' => $l->action,
                'at' => $l->created_at?->toIso8601String(),
            ]),
            'category_mix' => Auction::selectRaw('category_id, count(*) as total')
                ->groupBy('category_id')
                ->with('category')
                ->get()
                ->map(fn ($r) => ['name' => $r->category?->name ?? '—', 'value' => $r->total]),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $totalAuctions = Auction::count();
        $liveAuctions = Auction::where('status', 'live')->count();
        $closedAuctions = Auction::where('status', 'closed')->count();
        $cancelledAuctions = Auction::where('status', 'cancelled')->count();
        $pendingApproval = Auction::where('status', 'pending_approval')->count();

        $totalRevenue = (float) Auction::where('status', 'closed')->sum('final_price');
        $avgBidders = Auction::where('status', 'closed')->avg('bidders_count') ?? 0;

        $totalVendors = Vendor::count();
        $activeVendors = Vendor::where('status', 'approved')->count();
        $pendingVendors = Vendor::where('status', 'pending')->count();

        $totalOrgs = Organization::count();

        $monthlyRevenue = Auction::where('status', 'closed')
            ->where('closed_at', '>=', now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(closed_at, '%Y-%m') as month, SUM(final_price) as total, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'revenue_inr' => (float) $r->total,
                'auctions' => $r->count,
            ]);

        $topCategories = Auction::whereIn('status', ['closed', 'live'])
            ->selectRaw('category_id, COUNT(*) as total, SUM(COALESCE(final_price, current_highest, 0)) as value')
            ->groupBy('category_id')
            ->with('category')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->category?->name ?? '—',
                'auctions' => $r->total,
                'value_inr' => (float) $r->value,
            ]);

        return response()->json([
            'auctions' => [
                'total' => $totalAuctions,
                'live' => $liveAuctions,
                'closed' => $closedAuctions,
                'cancelled' => $cancelledAuctions,
                'pending_approval' => $pendingApproval,
            ],
            'revenue' => [
                'total_inr' => $totalRevenue,
                'avg_bidders_per_auction' => round($avgBidders, 1),
            ],
            'vendors' => [
                'total' => $totalVendors,
                'active' => $activeVendors,
                'pending' => $pendingVendors,
            ],
            'organizations' => [
                'total' => $totalOrgs,
            ],
            'monthly_revenue' => $monthlyRevenue,
            'top_categories' => $topCategories,
        ]);
    }

    private function filtered(Request $request)
    {
        $q = Auction::query()->whereIn('status', ['live', 'closed', 'cancelled']);

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($category = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $category)->orWhere('name', $category));
        }

        if ($company = $request->query('company')) {
            $q->where('company', $company);
        }

        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        return $q->orderByDesc('created_at');
    }
}
