<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('onboarding_states', function (Blueprint $table) {
            $table->string('conversation_id', 36)->primary();
            $table->string('phase')->default('interviewing');
            $table->unsignedTinyInteger('question_count')->default(0);
            $table->json('answers')->nullable();
            $table->json('current_question')->nullable();
            $table->json('plan')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_states');
    }
};
