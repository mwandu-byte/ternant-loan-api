<?php

namespace Database\Seeders;

use App\Models\GracePeriod;
use App\Models\InterestRule;
use App\Models\LoanAmountConfiguration;
use App\Models\PenaltyRule;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use Illuminate\Database\Seeder;

class LoanConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        InterestRule::updateOrCreate(
            ['minimum_amount' => 0],
            ['maximum_amount' => 499999.99, 'interest_rate' => 30.00, 'calculation_method' => 'percentage', 'status' => 'active'],
        );

        // No upper limit — mirrors the open-ended top tier already used for
        // PenaltyRule below. Without this, any principal above the old
        // 4,000,000 ceiling has no interest rate to resolve to and loan
        // creation fails, regardless of what LoanAmountConfiguration allows.
        InterestRule::updateOrCreate(
            ['minimum_amount' => 500000],
            ['maximum_amount' => null, 'interest_rate' => 22.00, 'calculation_method' => 'percentage', 'status' => 'active'],
        );

        LoanAmountConfiguration::query()->first()?->update([
            'minimum_amount' => 0,
            'maximum_amount' => 4000000,
        ]);

        RepaymentFrequency::updateOrCreate(
            ['code' => 'monthly'],
            ['name' => 'Monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active'],
        );

        RepaymentFrequency::updateOrCreate(
            ['code' => 'every_2_months'],
            ['name' => 'Every 2 Months', 'interval_value' => 2, 'interval_unit' => 'month', 'status' => 'active'],
        );

        RepaymentFrequency::updateOrCreate(
            ['code' => 'every_4_months'],
            ['name' => 'Every 4 Months', 'interval_value' => 4, 'interval_unit' => 'month', 'status' => 'active'],
        );

        foreach ([1, 3, 4, 6] as $months) {
            RepaymentTerm::updateOrCreate(
                ['value' => $months, 'unit' => 'months'],
                ['name' => "{$months} Month".($months > 1 ? 's' : ''), 'status' => 'active'],
            );
        }

        GracePeriod::query()->first()?->update([
            'duration' => 7,
            'unit' => 'days',
            'status' => 'active',
        ]);

        PenaltyRule::updateOrCreate(
            ['minimum_amount' => 0, 'maximum_amount' => 2999999.99],
            ['penalty_type' => 'fixed', 'penalty_value' => 50000, 'application_frequency' => 'once', 'status' => 'active'],
        );

        PenaltyRule::updateOrCreate(
            ['minimum_amount' => 3000000, 'maximum_amount' => null],
            ['penalty_type' => 'fixed', 'penalty_value' => 100000, 'application_frequency' => 'once', 'status' => 'active'],
        );
    }
}
