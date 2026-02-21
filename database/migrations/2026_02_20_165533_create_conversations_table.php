<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['dm', 'community'])->default('dm');

            // IMPORTANT: references clients table
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};