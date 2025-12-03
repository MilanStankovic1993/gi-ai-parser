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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();

            // Odakle je došao upit (email, web_form, manual...)
            $table->string('source')->default('email');
            $table->string('external_id')->nullable(); // ID iz eksternog sistema (npr. email message-id)

            // Podaci o gostu (koliko imamo)
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();

            // Kratak subject / naslov upita
            $table->string('subject')->nullable();

            // Ceo originalni tekst upita (raw body)
            $table->text('raw_message');

            // Summary polja koja će AI popunjavati (za brzi pregled)
            $table->string('region')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('nights')->nullable();
            $table->unsignedInteger('adults')->nullable();
            $table->unsignedInteger('children')->nullable();

            // Budžet – ostavljam kao int, kasnije se može proširiti
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();

            $table->boolean('wants_near_beach')->nullable();  // blizu plaže
            $table->boolean('wants_parking')->nullable();     // parking
            $table->boolean('wants_quiet')->nullable();       // mirna lokacija
            $table->boolean('wants_pets')->nullable();        // smeštaj za ljubimce
            $table->boolean('wants_pool')->nullable();        // bazen
            $table->text('special_requirements')->nullable(); // itd. (slobodan tekst)

            // Status obrade upita
            $table->enum('status', [
                'new',        // novi, AI nije još radio
                'extracted',  // AI je izvukao podatke
                'suggested',  // AI predlozi generisani
                'replied',    // odgovor je poslat
                'closed',     // zatvoreno
            ])->default('new');

            // Način odgovora
            $table->enum('reply_mode', [
                'ai_draft',   // AI generiše draft
                'manual',     // ručni odgovor
            ])->default('ai_draft');

            $table->boolean('is_priority')->default(false);

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
