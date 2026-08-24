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
        Schema::create('grace_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('duration')->default(7);
            $table->string('unit', 20)->default('days');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        // Singleton table: no store/destroy routes exist for this resource,
        // so one row must always exist.
        DB::table('grace_periods')->insert([
            'duration' => 7,
            'unit' => 'days',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grace_periods');
    }
};
