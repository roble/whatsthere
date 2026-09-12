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
        Schema::table('onboarding_states', function (Blueprint $table) {
            $table->string('flow')->default('trip');
            $table->json('property_result_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onboarding_states', function (Blueprint $table) {
            $table->dropColumn(['flow', 'property_result_ids']);
        });
    }
};
