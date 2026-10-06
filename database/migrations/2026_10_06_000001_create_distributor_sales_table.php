<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_role', 30);
            $table->foreignId('harvest_batch_id')->constrained('harvest_batches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->decimal('expected_weight_kg', 12, 2);
            $table->unsignedInteger('expected_fruit_count');
            $table->decimal('received_weight_kg', 12, 2)->nullable();
            $table->unsignedInteger('received_fruit_count')->nullable();
            $table->string('condition', 50)->nullable();
            $table->text('quality_note')->nullable();
            $table->text('discrepancy_note')->nullable();
            $table->text('rejection_note')->nullable();
            $table->string('status', 30)->default('initiated');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'status']);
            $table->index(['seller_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_sales');
    }
};
