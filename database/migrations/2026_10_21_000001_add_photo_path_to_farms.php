<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('farms', 'photo_path')) {
            Schema::table('farms', function (Blueprint $table): void {
                $table->string('photo_path')->nullable()->after('address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('farms', 'photo_path')) {
            Schema::table('farms', function (Blueprint $table): void {
                $table->dropColumn('photo_path');
            });
        }
    }
};
