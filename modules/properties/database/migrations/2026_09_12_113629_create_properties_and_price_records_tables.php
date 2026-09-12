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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('address');
            $table->string('town');
            $table->string('county');
            $table->string('country', 2)->default('IE');
            $table->string('eircode')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('property_type');
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('floor_area_sqm', 8, 2)->nullable();
            $table->string('ber_rating', 3)->nullable();
            $table->text('description')->nullable();
            $table->string('normalized_address')->nullable()->index();
            $table->enum('status', ['for_sale', 'sold', 'withdrawn']);
            $table->timestamps();
        });

        Schema::create('property_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_listing_id');
            $table->text('url')->nullable();
            $table->string('title');
            $table->enum('status', ['active', 'sold', 'withdrawn']);
            $table->date('listed_on')->nullable();
            $table->date('last_seen_on')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_listing_id']);
            $table->index(['property_id', 'status', 'last_seen_on']);
        });

        Schema::create('property_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_listing_id')->constrained()->cascadeOnDelete();
            $table->enum('media_type', ['image', 'floorplan'])->default('image');
            $table->text('url');
            $table->string('alt_text')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['property_listing_id', 'url']);
            $table->index(['property_listing_id', 'position']);
        });

        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('source_path');
            $table->string('checksum', 64);
            $table->enum('status', ['running', 'completed', 'failed']);
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('imported_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'checksum']);
        });

        Schema::create('property_price_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('record_type', ['asking_price', 'sale']);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('EUR');
            $table->date('effective_date');
            $table->text('source_url')->nullable();
            $table->string('source_reference')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'record_type', 'effective_date']);
            $table->index(['property_listing_id', 'record_type', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_price_records');
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('property_media');
        Schema::dropIfExists('property_listings');
        Schema::dropIfExists('properties');
    }
};
