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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('reference_no')->unique();
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('repayment_frequency');
            $table->unsignedInteger('repayment_term');
            $table->date('start_date');
            $table->date('due_date');
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
