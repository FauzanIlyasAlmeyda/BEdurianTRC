<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_transactions', function (Blueprint $table): void {
            $table->text('buyer_address')->nullable()->after('total_amount');
            $table->string('payment_method', 50)->nullable()->after('buyer_coordinates');
            $table->string('bank_name', 100)->nullable()->after('payment_method');
            $table->string('account_number', 100)->nullable()->after('bank_name');
        });

        Schema::table('distributor_receipts', function (Blueprint $table): void {
            $table->text('destination_location')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_receipts', function (Blueprint $table): void {
            $table->dropColumn('destination_location');
        });

        Schema::table('consumer_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'buyer_address',
                'payment_method',
                'bank_name',
                'account_number',
            ]);
        });
    }
};
