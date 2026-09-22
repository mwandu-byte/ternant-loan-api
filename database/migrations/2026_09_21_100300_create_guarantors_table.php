<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            // When the guarantor is an existing customer, identity data
            // lives on that customer and the inline columns stay NULL.
            $table->foreignId('guarantor_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('full_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('identification_type')->nullable();
            $table->string('identification_number')->nullable();
            $table->text('address')->nullable();
            $table->string('relationship', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'guarantor_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantors');
    }
};
