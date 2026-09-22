<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collector_shipments', function (Blueprint $table): void {
            $table->foreignId('destination_user_id')->nullable()->after('destination_type')->constrained('users')->nullOnDelete();
            $table->string('destination_name', 150)->nullable()->after('destination_user_id');
            $table->text('destination_location')->nullable()->after('destination_name');
        });
    }

    public function down(): void
    {
        Schema::table('collector_shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_user_id');
            $table->dropColumn(['destination_name', 'destination_location']);
        });
    }
};
