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
        Schema::create('accommodation_price_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('accommodation_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('season_name')->nullable();       // npr. "Predsezona", "Glavna sezona"
            $table->date('date_from');                       // od kog datuma važi
            $table->date('date_to');                         // do kog datuma važi

            $table->unsignedInteger('price_per_night');      // cena po noći u EUR
            $table->unsignedTinyInteger('min_nights')->default(1); // minimalan broj noći

            $table->boolean('is_available')->default(true);  // okvirno: da li je uopšte raspoloživo
            $table->string('note')->nullable();              // napomena (npr. "popunjeno u avgustu")

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accommodation_price_periods');
    }
};
