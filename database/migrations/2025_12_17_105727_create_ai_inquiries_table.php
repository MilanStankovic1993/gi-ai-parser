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
            $table->string('message_id', 255)->nullable()->index();
            $table->string('message_hash', 255)->unique();

            $table->string('from_email', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->json('headers')->nullable();
            $table->longText('raw_body')->nullable();

            // Pipeline status (string da ne puca kad dodaš nove statuse)
            $table->string('status', 50)->default('new');
            $table->boolean('ai_stopped')->default(false);

            /**
             * ŠIRA SLIKA (kanonski meta podaci za pipeline)
             */
            $table->string('intent', 50)->nullable()->index();
            $table->string('out_of_scope_reason')->nullable();

            // Audit: šta je extractor vratio (full payload + warningi)
            $table->json('parsed_payload')->nullable();
            $table->json('parse_warnings')->nullable();

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

            // korisno za listanje po intent-u u pipeline-u
            $table->index(['intent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inquiries');
    }
};
