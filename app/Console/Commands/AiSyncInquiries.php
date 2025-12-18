<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiSyncInquiries extends Command
{
    protected $signature = 'ai:sync-inquiries {--limit=50}';
    protected $description = 'Create/attach Inquiry records for ai_inquiries that do not have inquiry_id yet.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $items = AiInquiry::query()
            ->whereNull('inquiry_id')
            ->where('status', 'new')
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($items as $ai) {
            DB::transaction(function () use ($ai, &$created, &$skipped) {

                // Dedupe: ako već postoji Inquiry sa external_id = message_id ili message_hash
                $external = $ai->message_id ?: $ai->message_hash ?: null;

                if ($external) {
                    $existing = Inquiry::query()
                        ->where('external_id', $external)
                        ->first();

                    if ($existing) {
                        $ai->inquiry_id = $existing->id;
                        $ai->status = 'synced';
                        $ai->save();

                        $skipped++;
                        return;
                    }
                }

                $inquiry = Inquiry::create([
                    'source'      => 'ai_inquiries:' . ((string) ($ai->source ?? 'email')),
                    'external_id' => $external,

                    'guest_name'  => null,
                    'guest_email' => $ai->from_email,
                    'subject'     => $ai->subject,
                    'raw_message' => $ai->raw_body,
                    'received_at' => $ai->received_at,

                    'status'      => 'new',
                    'reply_mode'  => 'ai_draft',
                ]);

                $ai->inquiry_id = $inquiry->id;
                $ai->status = 'synced';
                $ai->save();

                $created++;
            });
        }

        $this->info("Done. Created: {$created}, Skipped: {$skipped}");
        return self::SUCCESS;
    }
}
