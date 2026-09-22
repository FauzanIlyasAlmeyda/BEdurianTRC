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
                foreach (['village', 'district', 'city', 'province'] as $column) {
                    if (! Schema::hasColumn($table->getTable(), $column)) {
                        $table->string($column, 100)->nullable();
                    }
                }
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
                $columns = array_values(array_filter(
                    ['village', 'district', 'city', 'province'],
                    fn (string $column): bool => Schema::hasColumn($table->getTable(), $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
