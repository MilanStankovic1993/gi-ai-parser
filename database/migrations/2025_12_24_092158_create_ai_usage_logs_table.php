<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 50)->default('openai'); // openai
            $table->string('model', 50);                       // gpt-4.1 / gpt-4o-mini
            $table->string('action', 50);                      // extract / suggest / draft

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            // money: držimo decimal (precizno), ne float
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('cost_eur', 12, 6)->nullable();

            $table->foreignId('ai_inquiry_id')
                ->nullable()
                ->constrained('ai_inquiries')
                ->nullOnDelete();

            $table->timestamp('used_at')->useCurrent();

            $table->timestamps();

            // indeksi za tipične izveštaje (mesec + filtriranje po action/model/provider)
            $table->index(['used_at', 'action']);
            $table->index(['used_at', 'model']);
            $table->index(['used_at', 'provider']);
            $table->index(['ai_inquiry_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
