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
        Schema::create('interest_rules', function (Blueprint $table) {
            $table->id();
            $table->decimal('minimum_amount', 15, 2);
            $table->decimal('maximum_amount', 15, 2)->nullable();
            $table->decimal('interest_rate', 5, 2);
            $table->string('calculation_method', 30)->default('percentage');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_rules');
    }
};
