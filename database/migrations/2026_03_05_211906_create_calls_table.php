<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->uuid('id')->primary(); // call_id

            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');

            $table->enum('type', ['voice', 'video'])->default('voice');

            // ringing = started but not accepted, in_call = connected
            $table->enum('status', ['ringing', 'connecting', 'in_call', 'ended'])
                ->default('ringing');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->string('end_reason', 50)->nullable();

            $table->timestamps();

            $table->index(['from_user_id', 'status']);
            $table->index(['to_user_id', 'status']);
            $table->index(['conversation_id', 'status']);

            // optional foreign keys (safe if your tables exist)
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('from_user_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('to_user_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }

};
