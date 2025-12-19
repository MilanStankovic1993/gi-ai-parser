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

            $table->string('source', 50)->default('local'); // local|imap|manual
            $table->string('message_id', 255)->nullable()->index();
            $table->string('message_hash', 255)->unique();   // dedupe ključ

            $table->string('from_email', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->json('headers')->nullable();
            $table->longText('raw_body')->nullable();

            // Pipeline status (string da ne puca kad dodaš nove statuse)
            $table->string('status', 50)->default('new');
            $table->boolean('ai_stopped')->default(false);

            // Veza ka poslovnom Inquiry
            $table->foreignId('inquiry_id')->nullable()->index()
                ->constrained('inquiries')->nullOnDelete();

            // Parse meta
            $table->timestamp('parsed_at')->nullable();
            $table->json('missing_fields')->nullable();

            // Suggestions meta
            $table->json('suggestions_payload')->nullable();
            $table->timestamp('suggested_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['from_email']);

            // praktični indexi za lookup/dedupe/debug
            $table->index(['source', 'message_id']);
            $table->index(['source', 'message_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inquiries');
    }
};
