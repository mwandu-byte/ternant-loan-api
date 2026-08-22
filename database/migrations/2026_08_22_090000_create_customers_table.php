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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable();
            $table->string('identification_type');
            $table->string('identification_number');
            $table->string('gender', 20)->nullable();
            $table->text('address');
            $table->string('photo')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['identification_type', 'identification_number']);
            $table->index('identification_number');
            $table->index('full_name');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
