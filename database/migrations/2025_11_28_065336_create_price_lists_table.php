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
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();

            // osnovne stvari o fajlu
            $table->string('original_filename');      // npr. cenovnik_2026_vila_X.pdf
            $table->string('original_path');          // storage path fajla

            // posle OCR-a (za skenirane) - za sada može da ostane null
            $table->string('processed_path')->nullable();

            // status obrade: pending, processing, processed, failed
            $table->string('status')->default('pending');

            // odakle je došao: email, upload, API...
            $table->string('source')->nullable();

            // poruka o grešci ako parsiranje padne
            $table->text('error_message')->nullable();

            // kada je uspešno kompletiran
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
