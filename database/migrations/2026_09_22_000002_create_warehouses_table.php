<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('owner_role', 30);
            $table->string('name', 150);
            $table->text('location');
            $table->text('note')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['owner_user_id', 'owner_role']);
        });

        Schema::table('harvest_batches', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('received_fruit_count')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('harvest_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::dropIfExists('warehouses');
    }
};
