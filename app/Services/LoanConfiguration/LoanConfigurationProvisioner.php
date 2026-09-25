<?php

namespace App\Services\LoanConfiguration;

use App\Models\Business;
use App\Models\GracePeriod;
use App\Models\InterestRule;
use App\Models\LoanAmountConfiguration;
use App\Models\PenaltyRule;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a new business its own copy of the platform default loan
 * configuration (the business_id NULL template rows), so it can lend
 * immediately and later tune its rules without affecting any other
 * business.
 */
class LoanConfigurationProvisioner
{
    /** @var array<int, class-string<Model>> */
    private const COPIED_MODELS = [
        InterestRule::class,
        RepaymentFrequency::class,
        RepaymentTerm::class,
        PenaltyRule::class,
    ];

    public function provisionFor(Business $business): void
    {
        foreach (self::COPIED_MODELS as $model) {
            $model::query()->forBusiness(null)->get()
                ->each(fn (Model $template) => $this->copy($template, $business));
        }

        // The two single-row configurations must always exist for a
        // business, otherwise it could not create loans or evaluate
        // overdue schedules. Fall back to the column defaults (7-day grace
        // period, no amount limit) when no template exists.
        foreach ([GracePeriod::class, LoanAmountConfiguration::class] as $model) {
            $template = $model::query()->forBusiness(null)->first();

            $template !== null
                ? $this->copy($template, $business)
                : $this->saveFor(new $model, $business);
        }
    }

    private function copy(Model $template, Business $business): void
    {
        $this->saveFor($template->replicate(), $business);
    }

    /**
     * Saved quietly so BelongsToBusiness's creating hook cannot re-pin the
     * row to whoever is authenticated (e.g. a signed-in user of another
     * business who calls the public registration endpoint).
     */
    private function saveFor(Model $model, Business $business): void
    {
        $model->business_id = $business->id;
        $model->saveQuietly();
    }
}
