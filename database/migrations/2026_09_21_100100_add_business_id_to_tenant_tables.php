<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'customers', 'loans'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('business_id')->nullable()->after('id')
                    ->constrained('businesses')->restrictOnDelete();
            });
        }

        // Existing single-tenant data moves into one default business.
        // Users stay platform-level (business_id NULL) so existing
        // administrators keep working across tenants.
        if (DB::table('customers')->exists() || DB::table('loans')->exists()) {
            $businessId = DB::table('businesses')->insertGetId([
                'name' => 'Default Business',
                'status' => 'active',
                'requires_application_fee' => false,
                'requires_guarantor' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('customers')->update(['business_id' => $businessId]);
            DB::table('loans')->update(['business_id' => $businessId]);
        }

        // Uniqueness is now per business: two tenants may register the same
        // phone / identification number independently.
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropUnique(['identification_type', 'identification_number']);

            $table->unique(['business_id', 'phone']);
            $table->unique(['business_id', 'identification_type', 'identification_number'], 'customers_business_identification_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'phone']);
            $table->dropUnique('customers_business_identification_unique');
            $table->unique('phone');
            $table->unique(['identification_type', 'identification_number']);
        });

        foreach (['users', 'customers', 'loans'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_id');
            });
        }
    }
};
