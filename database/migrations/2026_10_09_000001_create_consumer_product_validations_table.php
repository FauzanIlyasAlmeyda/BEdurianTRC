<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consumer_product_validations')) {
            Schema::create('consumer_product_validations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('umkm_product_id')->constrained('umkm_products')->cascadeOnDelete();
                $table->foreignId('consumer_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('validated_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('consumer_product_validations', 'cpv_product_consumer_uq')) {
            Schema::table('consumer_product_validations', function (Blueprint $table): void {
                $table->unique(
                    ['umkm_product_id', 'consumer_user_id'],
                    'cpv_product_consumer_uq',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_product_validations');
    }
};
