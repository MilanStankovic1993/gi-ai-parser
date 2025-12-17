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
            $table->string('source')->default('email');
            $table->string('external_id')->nullable(); // npr email message-id

            // Podaci o gostu
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();

            // Subject
            $table->string('subject')->nullable();

            // Originalni tekst upita
            $table->text('raw_message');

            // AI draft (edit/copy/send)
            $table->longText('ai_draft')->nullable();

            // Summary polja (AI popunjava)
            $table->string('region')->nullable();
            $table->string('location')->nullable(); // mesto/naselje (Pefkohori, Stavros...)
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('month_hint')->nullable(); // "druga polovina juna" itd.

            $table->unsignedInteger('nights')->nullable();
            $table->unsignedInteger('adults')->nullable();
            $table->unsignedInteger('children')->nullable();
            $table->string('children_ages')->nullable(); // JSON string npr "[5,3]"

            // Budžet
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();

            // Wants / flags
            $table->boolean('wants_near_beach')->nullable();
            $table->boolean('wants_parking')->nullable();
            $table->boolean('wants_quiet')->nullable();
            $table->boolean('wants_pets')->nullable();
            $table->boolean('wants_pool')->nullable();
            $table->text('special_requirements')->nullable();

            // Language (sr/en/...)
            $table->string('language')->nullable();

            // Status obrade (business status)
            $table->enum('status', [
                'new',
                'extracted',
                'suggested',
                'replied',
                'closed',
            ])->default('new');

            // Način odgovora
            $table->enum('reply_mode', [
                'ai_draft',
                'manual',
            ])->default('ai_draft');

            // Extraction meta (AI vs fallback) + debug
            $table->string('extraction_mode')->nullable(); // 'ai' | 'fallback' | 'openai' | 'local' (kako već koristiš)
            $table->text('extraction_debug')->nullable();

            $table->boolean('is_priority')->default(false);

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // (opciono) korisni indexi
            $table->index(['status', 'received_at']);
            $table->index(['guest_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
