<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_product_validations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_product_id')->constrained('umkm_products')->cascadeOnDelete();
            $table->foreignId('consumer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->unique(['umkm_product_id', 'consumer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_product_validations');
    }
};
