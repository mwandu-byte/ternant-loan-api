<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('has_discount')->default(false)->after('total_amount');
            $table->decimal('discount_rate', 5, 2)->nullable()->after('has_discount');

            // Not nullable, no default: the loans table has no production
            // data yet at the point this migration ships. If ever run
            // against a pre-populated dev/staging database, backfill first:
            // UPDATE loans SET applied_interest_rate = interest_rate
            // WHERE applied_interest_rate IS NULL
            $table->decimal('applied_interest_rate', 5, 2)->after('discount_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['has_discount', 'discount_rate', 'applied_interest_rate']);
        });
    }
};
