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
        Schema::create('accommodations', function (Blueprint $table) {
            $table->id();

            // Osnovno
            $table->string('name');                    // Naziv objekta
            $table->string('region');                  // Regija (Halkidiki, Tasos...)
            $table->string('settlement')->nullable();  // Naselje (Pefkohori, Stavros...)

            // Tip jedinice i kapacitet
            $table->string('unit_type');               // studio, apartman, duplex...
            $table->unsignedTinyInteger('bedrooms')->nullable();   // broj spavaćih (0 = studio)
            $table->unsignedTinyInteger('max_persons');            // max broj osoba

            // Plaža i okruženje
            $table->unsignedInteger('distance_to_beach')->nullable(); // u metrima
            $table->string('beach_type')->nullable();                 // pesak, šljunak, mešano...
            $table->boolean('has_parking')->default(false);
            $table->boolean('accepts_pets')->default(false);
            $table->string('noise_level')->nullable();                // quiet, street, main_road...

            // Dostupnost i interni podaci
            $table->string('availability_note')->nullable();          // okvirna dostupnost (npr. "slobodno u junu i septembru")
            $table->string('internal_contact')->nullable();           // interni kontakt vlasnika (tel, email, napomena)

            // 🔹 Bazen:
            $table->boolean('has_pool')->default(false);

            // Provizija / prioritet
            $table->boolean('is_commission')->default(true);          // da li je provizijski objekat
            $table->unsignedTinyInteger('priority')->default(0);      // 0 = normalno, 1+ = prioritetno prikazivanje

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accommodations');
    }
};
