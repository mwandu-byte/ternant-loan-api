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
        Schema::create('collateral_loan', function (Blueprint $table) {
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collateral_id')->constrained()->cascadeOnDelete();

            $table->primary(['loan_id', 'collateral_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collateral_loan');
    }
};
