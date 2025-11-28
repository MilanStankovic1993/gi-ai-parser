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
        Schema::create('price_list_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained()
                ->cascadeOnDelete();

            // faza obrade: ocr, parsing, validation, saving...
            $table->string('step');

            // raw input / output – JSON, tekst, prompt, odgovor...
            $table->longText('raw_input')->nullable();
            $table->longText('raw_output')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_logs');
    }
};
