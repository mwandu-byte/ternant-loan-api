<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loan_amount_configurations', function (Blueprint $table) {
            $table->id();
            $table->decimal('minimum_amount', 15, 2)->default(0);
            $table->decimal('maximum_amount', 15, 2)->nullable();
            $table->timestamps();
        });

        // Singleton table: no store/destroy routes exist for this resource,
        // so one row must always exist. Seeded permissive (no maximum) so
        // the app is usable out of the box.
        DB::table('loan_amount_configurations')->insert([
            'minimum_amount' => 0,
            'maximum_amount' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_amount_configurations');
    }
};
