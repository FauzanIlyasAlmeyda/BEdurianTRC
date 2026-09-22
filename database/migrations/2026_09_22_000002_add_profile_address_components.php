<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'farmer_profiles',
            'collector_profiles',
            'distributor_profiles',
            'umkm_profiles',
            'consumer_profiles',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('village', 100)->nullable();
                $table->string('district', 100)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('province', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'farmer_profiles',
            'collector_profiles',
            'distributor_profiles',
            'umkm_profiles',
            'consumer_profiles',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['village', 'district', 'city', 'province']);
            });
        }
    }
};
