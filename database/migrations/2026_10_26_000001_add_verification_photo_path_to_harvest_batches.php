<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_batches', function (Blueprint $table): void {
            $table->string('verification_photo_path')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('harvest_batches', function (Blueprint $table): void {
            $table->dropColumn('verification_photo_path');
        });
    }
};
