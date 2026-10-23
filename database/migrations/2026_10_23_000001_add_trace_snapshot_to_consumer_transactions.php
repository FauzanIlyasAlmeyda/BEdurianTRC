<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consumer_transactions', 'trace_snapshot')) {
            Schema::table('consumer_transactions', function (Blueprint $table): void {
                $table->json('trace_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consumer_transactions', 'trace_snapshot')) {
            Schema::table('consumer_transactions', function (Blueprint $table): void {
                $table->dropColumn('trace_snapshot');
            });
        }
    }
};
