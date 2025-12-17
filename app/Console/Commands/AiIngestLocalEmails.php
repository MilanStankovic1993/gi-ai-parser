<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Ai\Email\EmailInboxProviderInterface;
use App\Models\AiInquiry;

class AiIngestLocalEmails extends Command
{
    protected $signature = 'ai:ingest-local {--limit=50}';
    protected $description = 'Ingest local .eml/.txt messages into ai_inquiries (dedupe by message_hash).';

    public function handle(EmailInboxProviderInterface $provider): int
    {
        $limit = (int) $this->option('limit');

        $messages = $provider->fetchNewMessages();
        if ($limit > 0) {
            $messages = array_slice($messages, 0, $limit);
        }

        $created = 0;
        $skipped = 0;

        foreach ($messages as $m) {
            $hash = $this->makeHash($m);

            if (AiInquiry::query()->where('message_hash', $hash)->exists()) {
                $skipped++;
                continue;
            }

            AiInquiry::create([
                'source'       => 'local',
                'message_id'   => $m['message_id'] ?? null,
                'message_hash' => $hash,
                'from_email'   => $m['from_email'] ?? null,
                'subject'      => $m['subject'] ?? null,
                'received_at'  => $m['received_at'] ?? null,
                'headers'      => $m['headers'] ?? [],
                'raw_body'     => $m['body'] ?? null,
                'status'       => 'new',
                'ai_stopped'   => false,
            ]);

            $created++;
        }

        $this->info("Done. Created: {$created}, Skipped: {$skipped}");
        return self::SUCCESS;
    }

    private function makeHash(array $m): string
    {
        $seed = implode('|', [
            (string) ($m['message_id'] ?? ''),
            (string) ($m['from_email'] ?? ''),
            (string) ($m['subject'] ?? ''),
            (string) ($m['received_at'] ?? ''),
            Str::of((string) ($m['body'] ?? ''))->squish()->limit(5000),
        ]);

        return hash('sha256', $seed);
    }
}
