<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {

            $table->id();

            $table->string('email', 120)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('username', 40)->nullable()->unique();
            $table->string('profile_image', 255)->nullable();
            $table->string('pin')->nullable();

            $table->string('email_verification_code', 6)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->unsignedTinyInteger('email_verification_resend_count')->default(0);
            $table->timestamp('email_verified_at')->nullable();

            $table->string('phone_verification_code', 6)->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->boolean('verified')->default(false);
            $table->boolean('account_completed')->default(false);
            $table->unsignedTinyInteger('onboarding_step')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
