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
        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('repayment_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('penalty_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('period_start_date');
            $table->date('applied_date');
            $table->string('status', 20)->default('applied');
            $table->text('reason')->nullable();
            $table->timestamps();

            // Idempotency backstop: one penalty per schedule per accrual
            // period. See App\Services\Penalty\PenaltyService for how
            // period_start_date is resolved per application_frequency.
            $table->unique(['repayment_schedule_id', 'period_start_date']);
            $table->index('loan_id');
            $table->index('applied_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
