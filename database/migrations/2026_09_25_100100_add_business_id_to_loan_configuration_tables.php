<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'interest_rules',
        'repayment_frequencies',
        'repayment_terms',
        'penalty_rules',
        'grace_periods',
        'loan_amount_configurations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('business_id')->nullable()->after('id')
                    ->constrained('businesses')->restrictOnDelete();
            });
        }

        // Frequency codes are now unique per business; the two
        // single-row configurations become one row per business.
        Schema::table('repayment_frequencies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['business_id', 'code']);
        });

        foreach (['grace_periods', 'loan_amount_configurations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique('business_id');
            });
        }

        // Existing rows stay as the platform default templates (NULL
        // business). Every existing business gets its own copy so its
        // loans keep resolving exactly the rules they do today.
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            foreach (self::TABLES as $tableName) {
                $rows = DB::table($tableName)->whereNull('business_id')->get()
                    ->map(fn ($row) => collect((array) $row)->except('id')->merge([
                        'business_id' => $businessId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all())
                    ->all();

                if ($rows !== []) {
                    DB::table($tableName)->insert($rows);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            DB::table($tableName)->whereNotNull('business_id')->delete();
        }

        foreach (['grace_periods', 'loan_amount_configurations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['business_id']);
            });
        }

        Schema::table('repayment_frequencies', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->unique('code');
        });

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_id');
            });
        }
    }
};
