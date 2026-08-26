<?php

namespace Tests\Unit\Services;

use App\Models\PenaltyRule;
use App\Services\LoanConfiguration\PenaltyRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyRuleServiceResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_rule_covering_a_bounded_range(): void
    {
        PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 499999.99, 'penalty_value' => 50000]);
        $upper = PenaltyRule::factory()->create(['minimum_amount' => 500000, 'maximum_amount' => 2000000, 'penalty_value' => 100000]);

        $rule = (new PenaltyRuleService)->resolveApplicableRule(750000);

        $this->assertNotNull($rule);
        $this->assertSame($upper->id, $rule->id);
    }

    public function test_resolves_the_unbounded_upper_rule(): void
    {
        PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000]);
        $unbounded = PenaltyRule::factory()->create(['minimum_amount' => 3000000, 'maximum_amount' => null, 'penalty_value' => 100000]);

        $rule = (new PenaltyRuleService)->resolveApplicableRule(10000000);

        $this->assertNotNull($rule);
        $this->assertSame($unbounded->id, $rule->id);
    }

    public function test_returns_null_when_no_active_rule_matches_the_principal(): void
    {
        PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 100000, 'penalty_value' => 50000]);

        $rule = (new PenaltyRuleService)->resolveApplicableRule(500000);

        $this->assertNull($rule);
    }

    public function test_returns_null_rather_than_throwing_when_no_rules_exist_at_all(): void
    {
        $rule = (new PenaltyRuleService)->resolveApplicableRule(1000000);

        $this->assertNull($rule);
    }

    public function test_ignores_inactive_rules(): void
    {
        PenaltyRule::factory()->inactive()->create(['minimum_amount' => 0, 'maximum_amount' => 1000000, 'penalty_value' => 50000]);

        $rule = (new PenaltyRuleService)->resolveApplicableRule(500000);

        $this->assertNull($rule);
    }

    public function test_boundary_amounts_are_inclusive(): void
    {
        $rule = PenaltyRule::factory()->create(['minimum_amount' => 100000, 'maximum_amount' => 200000, 'penalty_value' => 50000]);

        $atMinimum = (new PenaltyRuleService)->resolveApplicableRule(100000);
        $atMaximum = (new PenaltyRuleService)->resolveApplicableRule(200000);

        $this->assertSame($rule->id, $atMinimum->id);
        $this->assertSame($rule->id, $atMaximum->id);
    }
}
