<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Set once the fee has been consumed by a loan application.
            $table->foreignId('loan_id')->nullable()->unique()->constrained('loans')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('paid');
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('reference_no')->nullable();
            $table->string('receipt_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_fees');
    }
};
