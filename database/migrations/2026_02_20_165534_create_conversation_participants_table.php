<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            // IMPORTANT: still called user_id, but references clients.id
            $table->foreignId('user_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            $table->enum('role', ['member', 'admin'])->default('member');

            $table->unsignedBigInteger('last_read_message_id')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};