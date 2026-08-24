<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('repayment_schedule_id')->constrained('repayment_schedules')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('receipts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('repayment_date');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('repayment_schedule_id');
            $table->index('repayment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repayments');
    }
};
