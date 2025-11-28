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
        Schema::create('price_list_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('sezona_od')->nullable();
            $table->date('sezona_do')->nullable();

            $table->string('tip_jedinice')->nullable(); // studio, 1/2, 1/3...

            $table->decimal('cena_noc', 10, 2)->nullable();
            $table->unsignedInteger('min_noci')->nullable();

            $table->string('doplate')->nullable();   // tekstualno za sad
            $table->string('promo')->nullable();     // npr. 7=6, -10%
            $table->text('napomena')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_rows');
    }
};
