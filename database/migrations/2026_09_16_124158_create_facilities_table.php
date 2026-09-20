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
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('facility_type', ['clinic', 'hospital', 'pharmacy', 'dental', 'diagnostic_lab']);
            $table->string('license_number'); // KMPDC / Pharmacy Board etc.
            $table->enum('ownership_type', ['private', 'public', 'faith_based', 'ngo'])->nullable();
            $table->string('county');
            $table->string('sub_county');
            $table->string('address'); // physical address / landmark
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone');
            $table->string('alt_phone')->nullable();
            $table->string('email');
            $table->string('website')->nullable();
            $table->json('operating_days'); // ["mon","tue",...]
            $table->time('opens_at')->nullable(); // null when is_24hr
            $table->time('closes_at')->nullable();
            $table->boolean('is_24hr')->default(false);
            $table->unsignedSmallInteger('doctors_count')->nullable();
            $table->unsignedSmallInteger('consultation_rooms')->nullable();
            $table->json('notification_channels'); // ["sms","whatsapp","email"]
            $table->string('sms_sender_id')->nullable();
            $table->enum('data_role', ['controller', 'processor', 'both']);
            $table->string('odpc_registration_no')->nullable();

            // Consent evidence captured on Screens 8 and 9.
            $table->timestamp('dpa_accepted_at')->nullable();
            $table->timestamp('patient_consent_confirmed_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('signature_name')->nullable();

            $table->enum('status', ['pending_review', 'active', 'suspended'])->default('pending_review');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
