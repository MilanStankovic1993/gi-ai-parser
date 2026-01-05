<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_inquiries', function (Blueprint $table) {
            $table->id();

            $table->string('source', 50)->default('local');

            // index na 191 da ne puca key length na starijim MySQL/MariaDB setup-ovima
            $table->string('message_id', 191)->nullable()->index();

            // dedupe ključ -> 191 + unique (sigurno za MariaDB 5.5)
            $table->string('message_hash', 191)->unique();

            $table->string('from_email', 191)->nullable()->index();
            $table->string('subject', 255)->nullable();
            $table->timestamp('received_at')->nullable();

            // MariaDB 5.5 nema JSON tip => koristimo longText
            $table->longText('headers')->nullable();
            $table->longText('raw_body')->nullable();

            // Pipeline status
            $table->string('status', 50)->default('new');
            $table->boolean('ai_stopped')->default(false);

            $table->string('intent', 50)->nullable()->index();
            $table->string('out_of_scope_reason', 255)->nullable();

            // Audit payloads (umesto json)
            $table->longText('parsed_payload')->nullable();
            $table->longText('parse_warnings')->nullable();

            // Veza ka poslovnom Inquiry
            $table->foreignId('inquiry_id')
                ->nullable()
                ->index()
                ->constrained('inquiries')
                ->nullOnDelete();

            $table->timestamp('parsed_at')->nullable();

            // Parse meta (umesto json)
            $table->longText('missing_fields')->nullable();

            // Suggestions meta (umesto json)
            $table->longText('suggestions_payload')->nullable();
            $table->timestamp('suggested_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['source', 'message_id']);
            $table->index(['source', 'message_hash']);
            $table->index(['intent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inquiries');
    }
};
