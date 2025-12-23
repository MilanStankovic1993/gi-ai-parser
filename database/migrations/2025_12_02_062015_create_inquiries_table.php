<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();

            // Odakle je došao upit (email, web_form, manual...)
            $table->string('source', 255)->default('email');

            // Stabilan ID za dedupe (ai:source:message_id/hash)
            // Napomena: nullable+unique je OK u MySQL/Postgres; ingest mora obavezno da popuni external_id za email source.
            $table->string('external_id', 255)->nullable()->unique();

            // Podaci o gostu
            $table->string('guest_name', 255)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->string('guest_phone', 255)->nullable();

            // Subject
            $table->string('subject', 255)->nullable();

            // Originalni tekst upita
            $table->text('raw_message');

            // AI draft (edit/copy/send)
            $table->longText('ai_draft')->nullable();

            /**
             * KANONSKI CONTRACT (Faza 1)
             */
            $table->string('intent', 50)->nullable()->index();
            $table->json('entities')->nullable();
            $table->json('travel_time')->nullable();
            $table->json('party')->nullable();
            $table->json('location_json')->nullable();
            $table->json('units')->nullable();
            $table->json('wishes')->nullable();        // tri-state flags: true/false/null
            $table->json('questions')->nullable();
            $table->json('tags')->nullable();
            $table->json('why_no_offer')->nullable();

            // Summary polja (AI popunjava) – ostavljamo za UI/filtere
            $table->string('region', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('month_hint', 255)->nullable();

            $table->unsignedInteger('nights')->nullable();
            $table->unsignedInteger('adults')->nullable();
            $table->unsignedInteger('children')->nullable();

            // JSON: npr [5,3]
            $table->json('children_ages')->nullable();

            // Budžet
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();

            /**
             * Wants / flags – tri-state (true/false/null). Ne upisivati false ako nije eksplicitno pomenuto.
             */
            $table->boolean('wants_near_beach')->nullable();
            $table->boolean('wants_parking')->nullable();
            $table->boolean('wants_quiet')->nullable();
            $table->boolean('wants_pets')->nullable();
            $table->boolean('wants_pool')->nullable();
            $table->text('special_requirements')->nullable();

            // Language (sr/en/...)
            $table->string('language', 10)->nullable();

            // Status obrade (business status)
            $table->enum('status', [
                'new',
                'needs_info',
                'extracted',
                'suggested',
                'replied',
                'closed',
                'no_ai',
            ])->default('new');

            // Način odgovora
            $table->enum('reply_mode', [
                'ai_draft',
                'manual',
            ])->default('ai_draft');

            // Extraction meta + debug
            $table->string('extraction_mode', 50)->nullable(); // ai|fallback|...
            $table->json('extraction_debug')->nullable();

            $table->boolean('is_priority')->default(false);

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // Indexi
            $table->index(['status', 'received_at']);
            $table->index('guest_email');
            $table->index(['status', 'intent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
