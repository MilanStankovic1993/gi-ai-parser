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

            $table->string('source')->default('local'); // local|imap|manual
            $table->string('message_id')->nullable();
            $table->string('message_hash')->unique();   // dedupe ključ

            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->json('headers')->nullable();
            $table->longText('raw_body')->nullable();

            // Pipeline status (string da ne puca kad dodaš nove statuse)
            // npr: new|synced|parsed|needs_info|suggested|no_availability|drafted|done|error|stopped
            $table->string('status')->default('new');
            $table->boolean('ai_stopped')->default(false);

            // Veza ka poslovnom Inquiry
            $table->unsignedBigInteger('inquiry_id')->nullable()->index();

            // Parse meta
            $table->timestamp('parsed_at')->nullable();
            $table->json('missing_fields')->nullable();

            // Suggestions meta
            $table->json('suggestions_payload')->nullable();
            $table->timestamp('suggested_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['from_email']);

            // (opciono) FK ako želiš (samo ako ti je ok cascade)
            // $table->foreign('inquiry_id')->references('id')->on('inquiries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inquiries');
    }
};
