<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'public_account_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('public_account_id', 16)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('harvest_batches', 'recipient_user_id')) {
            Schema::table('harvest_batches', function (Blueprint $table): void {
                $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('harvest_batches', 'recipient_role')) {
            Schema::table('harvest_batches', function (Blueprint $table): void {
                $table->string('recipient_role', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('harvest_batches', 'recipient_role')) {
            Schema::table('harvest_batches', function (Blueprint $table): void {
                $table->dropColumn('recipient_role');
            });
        }

        if (Schema::hasColumn('harvest_batches', 'recipient_user_id')) {
            Schema::table('harvest_batches', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('recipient_user_id');
            });
        }

        if (Schema::hasColumn('users', 'public_account_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('public_account_id');
            });
        }
    }
};
