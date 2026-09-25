<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards self-registration against the same business signing up
        // twice. The column stays nullable: platform-created businesses
        // may have no email, and NULLs never collide on a unique index.
        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
