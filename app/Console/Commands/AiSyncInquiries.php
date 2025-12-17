<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AiInquiry;
use App\Models\Inquiry;

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

        foreach ($items as $ai) {
            // (Opcionalno) ako želiš extra dedupe na poslovnom nivou:
            // probaj naći existing Inquiry po guest_email+subject+received_at.
            // Za sada pravimo novi i vezujemo.

            $inquiry = Inquiry::create([
                'source'      => 'email',
                'guest_name'  => null,
                'guest_email' => $ai->from_email,
                'subject'     => $ai->subject,
                'raw_message' => $ai->raw_body,
                'received_at' => $ai->received_at,
                'status'      => 'new',
                'reply_mode'  => 'ai_draft',
            ]);

            $ai->inquiry_id = $inquiry->id;
            $ai->status = 'synced'; // da znamo da je prošao u poslovni sloj
            $ai->save();

            $created++;
        }

        $this->info("Done. Synced inquiries: {$created}");
        return self::SUCCESS;
    }
}
