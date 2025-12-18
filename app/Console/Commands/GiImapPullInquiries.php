<?php

namespace App\Console\Commands;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webklex\IMAP\Facades\Client;

class GiImapPullInquiries extends Command
{
    protected $signature = 'gi:imap-pull {--limit=50 : Max messages per inbox per run}';
    protected $description = 'Pull new (first-contact) inquiries from IMAP inboxes into the app database';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if (empty(env('IMAP_HOST'))) {
            $this->error('IMAP_HOST is empty in .env.');
            return self::FAILURE;
        }

        $inboxes = [
            'booking' => [
                'username' => env('IMAP_BOOKING_USERNAME'),
                'password' => env('IMAP_BOOKING_PASSWORD'),
            ],
            'info' => [
                'username' => env('IMAP_INFO_USERNAME'),
                'password' => env('IMAP_INFO_PASSWORD'),
            ],
        ];

        foreach ($inboxes as $key => $creds) {
            $this->line("== Inbox: {$key} ==");

            if (empty($creds['username']) || empty($creds['password'])) {
                $this->warn("Missing credentials for {$key} (username/password). Skipping.");
                continue;
            }

            try {
                $this->pullFromInbox(
                    inboxKey: $key,
                    username: (string) $creds['username'],
                    password: (string) $creds['password'],
                    limit: $limit
                );
            } catch (\Throwable $e) {
                $this->error("IMAP error for {$key}: " . $e->getMessage());
                Log::warning('GiImapPullInquiries: inbox error', [
                    'inbox' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function pullFromInbox(string $inboxKey, string $username, string $password, int $limit): void
    {
        $client = Client::make([
            'host'          => env('IMAP_HOST'),
            'port'          => (int) env('IMAP_PORT', 993),
            'encryption'    => env('IMAP_ENCRYPTION', 'ssl'),
            'validate_cert' => filter_var(env('IMAP_VALIDATE_CERT', true), FILTER_VALIDATE_BOOL),
            'username'      => $username,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);

        $client->connect();

        $folderName = env('AI_IMAP_MAILBOX', 'INBOX') ?: 'INBOX';
        $folder = $client->getFolder($folderName);

        $lookbackDays = (int) env('AI_IMAP_LOOKBACK_DAYS', 7);
        $lookbackDays = $lookbackDays > 0 ? $lookbackDays : 7;
        $since = now()->subDays($lookbackDays);

        $query = $folder->query()->unseen();

        // Ako verzija podržava since(), super, ali i dalje imamo ručni cutoff u foreach-u.
        if (method_exists($query, 'since')) {
            try {
                $query->since($since);
            } catch (\Throwable) {
                // ignore, ručni cutoff je svakako tu
            }
        }

        $messages = $query
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($messages as $message) {
            // ----- Received at (moramo GA PRVO, da možemo da presečemo stare unseen) -----
            $receivedAt = null;
            try {
                $date = $message->getDate();
                if ($date) {
                    $receivedAt = Carbon::parse($date);
                }
            } catch (\Throwable) {
                $receivedAt = null;
            }

            // ako ne možemo da parsiramo datum, tretiraj kao "sad" (da ne baciš novi mejl)
            $receivedAt = $receivedAt ?: now();

            // ✅ Lookback cutoff: ako je mejl stariji od lookback prozora -> samo Seen i preskoči
            if ($receivedAt->lt($since)) {
                Log::info('GiImapPullInquiries: skipped old unseen', [
                    'inbox' => $inboxKey,
                    'folder' => $folderName,
                    'received_at' => $receivedAt->toDateTimeString(),
                    'since' => $since->toDateTimeString(),
                ]);

                $message->setFlag('Seen');
                continue;
            }

            // ----- Subject -----
            $subjectRaw = (string) ($message->getSubject() ?? '');
            $subjectTrim = trim($subjectRaw);
            $subjectNorm = $this->normalizeSubject($subjectTrim);

            // ----- From -----
            $from = $message->getFrom();
            $fromEmail = (string) (($from[0]->mail ?? '') ?: '');

            // ----- Headers -----
            $headers = $message->getHeader();
            $messageId  = (string) (($headers->get('message-id')[0] ?? '') ?: '');
            $inReplyTo  = (string) (($headers->get('in-reply-to')[0] ?? '') ?: '');
            $references = (string) (($headers->get('references')[0] ?? '') ?: '');

            // 1) Ignoriši follow-up (reply/forward)
            if (
                ! empty($inReplyTo) ||
                ! empty($references) ||
                preg_match('/^(re:|fw:|fwd:)\s*/i', $subjectNorm)
            ) {
                Log::info('GiImapPullInquiries: ignored follow-up', [
                    'inbox' => $inboxKey,
                    'folder' => $folderName,
                    'from' => $fromEmail,
                    'subject' => $subjectTrim,
                    'subject_norm' => $subjectNorm,
                    'message_id' => $messageId,
                    'in_reply_to' => $inReplyTo,
                    'references' => $references,
                ]);

                $message->setFlag('Seen');
                continue;
            }

            // 2) Ignoriši sistemske “Request from …” (po dogovoru)
            if (str_contains($subjectNorm, 'request from')) {
                Log::info('GiImapPullInquiries: ignored request-from', [
                    'inbox' => $inboxKey,
                    'folder' => $folderName,
                    'from' => $fromEmail,
                    'subject' => $subjectTrim,
                    'subject_norm' => $subjectNorm,
                    'message_id' => $messageId,
                ]);

                $message->setFlag('Seen');
                continue;
            }

            // ----- Body -----
            $body = (string) ($message->getTextBody() ?: $message->getHTMLBody() ?: '');
            $body = trim($body) !== '' ? trim($body) : '[EMPTY_BODY]';

            // 3) Anti-duplicate: message-id ili hash (ako message-id fali)
            $externalId = $messageId !== '' ? $messageId : null;

            $hashSeed = implode('|', [
                strtolower(trim($fromEmail)),
                $subjectNorm,
                (string) $receivedAt->toIso8601String(),
                Str::of($body)->squish()->limit(5000),
            ]);
            $hash = hash('sha256', $hashSeed);

            // ✅ Dedupe: proveri ono što ćeš snimiti u external_id
            $externalToStore = $externalId ?: $hash;

            $exists = Inquiry::query()
                ->where('external_id', $externalToStore)
                ->exists();

            if ($exists) {
                $message->setFlag('Seen');
                continue;
            }

            // Upis
            Inquiry::create([
                'source'      => "email:{$inboxKey}",
                'external_id' => $externalToStore,

                'guest_email' => $fromEmail ?: null,
                'subject'     => $subjectTrim !== '' ? $subjectTrim : null,
                'raw_message' => $body,

                'status'      => 'new',
                'received_at' => $receivedAt,
            ]);

            $message->setFlag('Seen');
            $count++;
        }

        $this->info("Imported: {$count}");
    }

    private function normalizeSubject(string $subjectTrim): string
    {
        $subjectTrim = trim($subjectTrim);

        $norm = mb_strtolower(preg_replace('/\s+/', ' ', $subjectTrim));

        if (str_contains($subjectTrim, '=?')) {
            $decoded = @iconv_mime_decode($subjectTrim, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && trim($decoded) !== '') {
                $norm = mb_strtolower(preg_replace('/\s+/', ' ', trim($decoded)));
            }
        }

        // "re : test" -> "re:test"
        $norm = preg_replace('/\s*:\s*/', ':', $norm);

        return $norm ?: '';
    }
}
