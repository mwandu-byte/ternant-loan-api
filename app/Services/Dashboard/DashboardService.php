<?php

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Services\Repayment\OverdueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the high-level lending-business summary shown on the
 * dashboard, entirely via count()/sum()/groupBy() queries — no
 * collection is ever loaded into PHP just to total it up.
 *
 * `from`/`to` scope only EVENT-DATED metrics (loans created, amounts
 * issued/collected/interest/penalties, total repayments) through each
 * table's own natural date column. Point-in-time balance/snapshot
 * metrics (total_outstanding_amount, loans.overdue, repayments.overdue,
 * repayments.upcoming) and the always-"today" metrics (repayments.today,
 * payments.today_disbursements) are never date-ranged — "overdue as of
 * Aug 1-15" or "today's repayments filtered to a past range" have no
 * coherent meaning. With no from/to supplied, event-dated metrics
 * default to all-time totals.
 */
class DashboardService
{
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

        $loanStatusCounts = $this->dateScoped(Loan::query(), 'created_at', $from, $to)
            ->select('status', DB::raw('count(*) as aggregate_count'))
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        return [
            'customers' => [
                'total' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
            ],
            'loans' => [
                'total' => (int) $loanStatusCounts->sum(),
                'active' => (int) ($loanStatusCounts['active'] ?? 0),
                'pending' => (int) ($loanStatusCounts['pending'] ?? 0),
                'completed' => (int) ($loanStatusCounts['completed'] ?? 0),
                'overdue' => Loan::whereHas(
                    'repaymentSchedules',
                    fn (Builder $query) => $this->overdueService->applyOverdueScope($query),
                )->count(),
            ],
            'financial' => [
                'total_amount_issued' => $this->formatAmount($this->dateScoped(Payment::query(), 'payment_date', $from, $to)->sum('amount')),
                'total_amount_collected' => $this->formatAmount($this->dateScoped(Repayment::query(), 'repayment_date', $from, $to)->sum('amount')),
                'total_outstanding_amount' => $this->formatAmount(RepaymentSchedule::where('status', '!=', 'paid')->sum('outstanding_amount')),
                'total_interest_generated' => $this->formatAmount($this->dateScoped(
                    Loan::query()->whereIn('status', ['active', 'completed']),
                    'start_date',
                    $from,
                    $to,
                )->sum('interest_amount')),
                'total_penalties_generated' => $this->formatAmount($this->dateScoped(Penalty::query(), 'applied_date', $from, $to)->sum('amount')),
            ],
            'repayments' => [
                'total' => $this->dateScoped(Repayment::query(), 'repayment_date', $from, $to)->count(),
                'today' => Repayment::whereDate('repayment_date', $today)->count(),
                'overdue' => $this->overdueService->applyOverdueScope(RepaymentSchedule::query())->count(),
                'upcoming' => RepaymentSchedule::where('status', '!=', 'paid')->where('due_date', '>', $today)->count(),
            ],
            'payments' => [
                'total_disbursements' => $this->dateScoped(Payment::query(), 'payment_date', $from, $to)->count(),
                'today_disbursements' => Payment::whereDate('payment_date', $today)->count(),
            ],
        ];
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
}
