<?php

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Services\Repayment\OverdueService;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the high-level lending-business summary shown on the
 * dashboard, entirely via count()/sum()/groupBy() queries — no
 * collection is ever loaded into PHP just to total it up.
 *
 * Every query is additionally scoped to the authenticated user's data
 * ownership (see App\Support\AccessScope): a user with `data.view-all`
 * (manager/admin) sees organization-wide totals; anyone else (staff)
 * sees only customers/loans they own and the repayments/payments/
 * penalties/schedules reachable through a loan they own.
 *
 * `from`/`to` scope only EVENT-DATED metrics (loans created, amounts
 * issued/collected/interest/penalties, total repayments) through each
 * table's own natural date column. Point-in-time balance/snapshot
 * metrics (total_outstanding_amount, loans.overdue, repayments.overdue,
 * repayments.upcoming) and the always-"today" metrics (repayments.today,
 * payments.today_disbursements) are never date-ranged. With no from/to
 * supplied, these five original blocks default to all-time totals.
 *
 * The `period`/`loan_performance`/`charts` sections added on top use a
 * separate, always-bounded "effective range" (caller-supplied from/to,
 * else a trailing default window from config('dashboard.default_period_days'))
 * so a monthly trend chart is never literally unbounded — this never
 * narrows the five original blocks' all-time-by-default totals.
 */
class DashboardService
{
    private const MAX_CHART_MONTHS = 24;

    public function __construct(
        private readonly OverdueService $overdueService,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $from, ?string $to): array
    {
        $today = Carbon::today();

        $loanStatusCounts = $this->dateScoped($this->ownedLoans(Loan::query()), 'created_at', $from, $to)
            ->select('status', DB::raw('count(*) as aggregate_count'))
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $activeLoans = (int) ($loanStatusCounts['active'] ?? 0);
        $completedLoans = (int) ($loanStatusCounts['completed'] ?? 0);

        $overdueLoans = $this->ownedLoans(Loan::query())->whereHas(
            'repaymentSchedules',
            fn (Builder $query) => $this->overdueService->applyOverdueScope($query),
        )->count();

        $totalOutstandingAmount = $this->formatAmount(
            $this->viaOwnedLoan(RepaymentSchedule::where('status', '!=', 'paid'))->sum('outstanding_amount'),
        );

        [$periodFrom, $periodTo] = $this->effectiveRange($from, $to);

        return [
            'customers' => [
                'total' => $this->ownedCustomers(Customer::query())->count(),
                'active' => $this->ownedCustomers(Customer::where('status', 'active'))->count(),
            ],
            'loans' => [
                'total' => (int) $loanStatusCounts->sum(),
                'active' => $activeLoans,
                'pending' => (int) ($loanStatusCounts['pending'] ?? 0),
                'completed' => $completedLoans,
                'overdue' => $overdueLoans,
            ],
            'financial' => [
                'total_amount_issued' => $this->formatAmount($this->dateScoped($this->viaOwnedLoan(Payment::query()), 'payment_date', $from, $to)->sum('amount')),
                'total_amount_collected' => $this->formatAmount($this->dateScoped($this->viaOwnedLoan(Repayment::query()), 'repayment_date', $from, $to)->sum('amount')),
                'total_outstanding_amount' => $totalOutstandingAmount,
                'total_interest_generated' => $this->formatAmount($this->dateScoped(
                    $this->ownedLoans(Loan::query()->whereIn('status', ['active', 'completed'])),
                    'start_date',
                    $from,
                    $to,
                )->sum('interest_amount')),
                'total_penalties_generated' => $this->formatAmount($this->dateScoped($this->viaOwnedLoan(Penalty::query()), 'applied_date', $from, $to)->sum('amount')),
            ],
            'repayments' => [
                'total' => $this->dateScoped($this->viaOwnedLoan(Repayment::query()), 'repayment_date', $from, $to)->count(),
                'today' => $this->viaOwnedLoan(Repayment::whereDate('repayment_date', $today))->count(),
                'overdue' => $this->viaOwnedLoan($this->overdueService->applyOverdueScope(RepaymentSchedule::query()))->count(),
                'upcoming' => $this->viaOwnedLoan(RepaymentSchedule::where('status', '!=', 'paid')->where('due_date', '>', $today))->count(),
            ],
            'payments' => [
                'total_disbursements' => $this->dateScoped($this->viaOwnedLoan(Payment::query()), 'payment_date', $from, $to)->count(),
                'today_disbursements' => $this->viaOwnedLoan(Payment::whereDate('payment_date', $today))->count(),
            ],
            'period' => [
                'date_from' => $periodFrom->toDateString(),
                'date_to' => $periodTo->toDateString(),
            ],
            'loan_performance' => $this->loanPerformance($periodFrom, $periodTo, $activeLoans, $completedLoans, $overdueLoans, $totalOutstandingAmount),
            'charts' => $this->charts($periodFrom, $periodTo, $loanStatusCounts),
        ];
    }

    /**
     * @return array<int, Carbon>
     */
    private function effectiveRange(?string $from, ?string $to): array
    {
        $end = $to !== null ? Carbon::parse($to) : Carbon::today();
        $days = max(1, (int) config('dashboard.default_period_days', 30));
        $start = $from !== null ? Carbon::parse($from) : $end->copy()->subDays($days - 1);

        return [$start, $end];
    }

    /**
     * loans/active/completed/overdue are point-in-time status counts —
     * reused verbatim from the snapshot already computed above (matches
     * the "never date-ranged" convention for status/balance metrics
     * documented on the class); only total_loans (an event count) and the
     * financial figures are scoped to the effective period.
     *
     * @return array<string, mixed>
     */
    private function loanPerformance(
        Carbon $periodFrom,
        Carbon $periodTo,
        int $activeLoans,
        int $completedLoans,
        int $overdueLoans,
        string $totalOutstandingAmount,
    ): array {
        $totalLoans = $this->ownedLoans(Loan::query())
            ->whereDate('start_date', '>=', $periodFrom)
            ->whereDate('start_date', '<=', $periodTo)
            ->count();

        $totalDisbursed = $this->viaOwnedLoan(Payment::query())
            ->whereDate('payment_date', '>=', $periodFrom)
            ->whereDate('payment_date', '<=', $periodTo)
            ->sum('amount');

        $totalCollected = $this->viaOwnedLoan(Repayment::query())
            ->whereDate('repayment_date', '>=', $periodFrom)
            ->whereDate('repayment_date', '<=', $periodTo)
            ->sum('amount');

        $totalInterest = $this->ownedLoans(Loan::query())
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('start_date', '>=', $periodFrom)
            ->whereDate('start_date', '<=', $periodTo)
            ->sum('interest_amount');

        $totalPenalties = $this->viaOwnedLoan(Penalty::query())
            ->whereDate('applied_date', '>=', $periodFrom)
            ->whereDate('applied_date', '<=', $periodTo)
            ->sum('amount');

        return [
            'total_loans' => $totalLoans,
            'active_loans' => $activeLoans,
            'completed_loans' => $completedLoans,
            'overdue_loans' => $overdueLoans,
            // The system only supports "overdue" — there is no separate
            // "default" classification anywhere, so this is the only
            // risk-ratio offered, never an invented default rate.
            'overdue_percentage' => $activeLoans > 0 ? round($overdueLoans / $activeLoans * 100, 2) : 0.0,
            'total_disbursed' => $this->formatAmount($totalDisbursed),
            'total_collected' => $this->formatAmount($totalCollected),
            'total_outstanding' => $totalOutstandingAmount,
            'total_interest_generated' => $this->formatAmount($totalInterest),
            'total_penalties_accrued' => $this->formatAmount($totalPenalties),
        ];
    }

    /**
     * @param  Collection<string, int>  $loanStatusCounts
     * @return array<string, mixed>
     */
    private function charts(Carbon $periodFrom, Carbon $periodTo, $loanStatusCounts): array
    {
        $buckets = $this->monthlyBuckets($periodFrom, $periodTo);

        $loanBuckets = collect($buckets)->map(fn (array $bucket) => [
            'period' => $bucket['label'],
            'loans' => $this->ownedLoans(Loan::query())
                ->whereDate('start_date', '>=', $bucket['start'])
                ->whereDate('start_date', '<=', $bucket['end'])
                ->count(),
        ]);

        $disbursementBuckets = collect($buckets)->map(function (array $bucket) {
            $agg = $this->viaOwnedLoan(Payment::query())
                ->whereDate('payment_date', '>=', $bucket['start'])
                ->whereDate('payment_date', '<=', $bucket['end'])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
                ->first();

            return [
                'period' => $bucket['label'],
                'count' => (int) ($agg->cnt ?? 0),
                'amount' => $this->formatAmount($agg->total ?? 0),
            ];
        });

        $collectionBuckets = collect($buckets)->map(function (array $bucket) {
            $agg = $this->viaOwnedLoan(Repayment::query())
                ->whereDate('repayment_date', '>=', $bucket['start'])
                ->whereDate('repayment_date', '<=', $bucket['end'])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
                ->first();

            return [
                'period' => $bucket['label'],
                'amount' => $this->formatAmount($agg->total ?? 0),
                'repayments' => (int) ($agg->cnt ?? 0),
            ];
        });

        $penaltyBuckets = collect($buckets)->map(function (array $bucket) {
            $agg = $this->viaOwnedLoan(Penalty::query())
                ->whereDate('applied_date', '>=', $bucket['start'])
                ->whereDate('applied_date', '<=', $bucket['end'])
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->first();

            return [
                'period' => $bucket['label'],
                'accrued' => $this->formatAmount($agg->total ?? 0),
            ];
        });

        $loanPerformanceTrend = $loanBuckets->map(fn (array $row, int $index) => [
            'period' => $row['period'],
            'loans' => $row['loans'],
            'disbursed' => $disbursementBuckets[$index]['amount'],
        ])->values()->all();

        $paidVsOutstanding = $this->viaOwnedLoan(RepaymentSchedule::query())
            ->selectRaw('COALESCE(SUM(total_amount - outstanding_amount), 0) as paid, COALESCE(SUM(outstanding_amount), 0) as outstanding')
            ->first();

        $statuses = ['pending', 'active', 'completed', 'cancelled'];

        return [
            'loan_status' => collect($statuses)->map(fn (string $status) => [
                'status' => $status,
                'count' => (int) ($loanStatusCounts[$status] ?? 0),
            ])->all(),
            'loan_performance_trend' => $loanPerformanceTrend,
            'collection_trend' => $collectionBuckets->values()->all(),
            'disbursement_trend' => $disbursementBuckets->map(fn (array $row) => [
                'period' => $row['period'],
                'amount' => $row['amount'],
                'disbursements' => $row['count'],
            ])->values()->all(),
            'paid_vs_outstanding' => [
                ['category' => 'paid', 'amount' => $this->formatAmount($paidVsOutstanding->paid ?? 0)],
                ['category' => 'outstanding', 'amount' => $this->formatAmount($paidVsOutstanding->outstanding ?? 0)],
            ],
            // No paid/outstanding keys: nothing in the schema ever marks a
            // penalty as paid (see PenaltyReportService docblock) — only
            // the real accrued amount is reported here.
            'penalty_trend' => $penaltyBuckets->values()->all(),
        ];
    }

    /**
     * @return array<int, array{label: string, start: Carbon, end: Carbon}>
     */
    private function monthlyBuckets(Carbon $from, Carbon $to): array
    {
        $buckets = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to) && count($buckets) < self::MAX_CHART_MONTHS) {
            $bucketEnd = $cursor->copy()->endOfMonth();

            $buckets[] = [
                'label' => $cursor->format('Y-m'),
                'start' => $cursor->copy()->max($from),
                'end' => $bucketEnd->min($to),
            ];

            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return $buckets;
    }

    /**
     * SUM() aggregates come back as plain SQL results (e.g. '1500000',
     * not '1500000.00') — they never pass through a model's decimal
     * cast the way a fetched attribute would, so the 2-decimal-place
     * formatting other amounts in this API always carry has to be
     * applied explicitly here.
     */
    private function formatAmount(mixed $sum): string
    {
        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function dateScoped(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    private function ownedLoans(Builder $query): Builder
    {
        return AccessScope::restrictToOwner($query, auth()->user());
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function ownedCustomers(Builder $query): Builder
    {
        return AccessScope::restrictToOwner($query, auth()->user());
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function viaOwnedLoan(Builder $query, string $relation = 'loan'): Builder
    {
        return AccessScope::restrictViaLoan($query, auth()->user(), $relation);
    }
}
