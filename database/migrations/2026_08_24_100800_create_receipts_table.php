<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no')->unique();
            $table->decimal('amount', 15, 2);
            $table->date('receipt_date');
            $table->string('payment_method', 30);
            $table->string('reference_no')->nullable()->unique();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('receipt_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
