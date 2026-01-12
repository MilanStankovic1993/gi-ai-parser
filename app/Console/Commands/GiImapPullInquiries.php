<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webklex\IMAP\Facades\Client;

class GiImapPullInquiries extends Command
{
    protected $signature = 'gi:imap-pull {--limit=50 : Max messages per inbox per run}';
    protected $description = 'Pull first-contact inquiries from IMAP into ai_inquiries (dedupe via message_hash)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // ✅ Works with config:cache (env() in runtime would be null)
        if (empty(config('imap.accounts.default.host'))) {
            $this->error("IMAP host is empty (config('imap.accounts.default.host')).");
            return self::FAILURE;
        }

        $inboxes = [
            'booking' => [
                'username' => config('imap.accounts.booking.username'),
                'password' => config('imap.accounts.booking.password'),
            ],
            'info' => [
                'username' => config('imap.accounts.info.username'),
                'password' => config('imap.accounts.info.password'),
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
        // ✅ Use predefined accounts from config/imap.php (booking/info)
        $client = Client::account($inboxKey);
        $client->connect();

        $folderName   = (string) (config('ai.imap.mailbox', 'INBOX') ?: 'INBOX');
        $folder = $client->getFolder($folderName);

        $lookbackDays = max(1, (int) config('ai.imap.lookback_days', 7));
        $since = now()->subDays($lookbackDays);

        // unseen (prod) / all (test)
        $mode         = strtolower((string) config('ai.imap.fetch_mode', 'unseen'));

        $query = $folder->query();

        if ($mode === 'all') {
            $query->all();
        } else {
            $query->unseen();
        }

        // newest first (ako postoji)
        if (method_exists($query, 'setFetchOrder')) {
            try {
                $query->setFetchOrder('desc');
            } catch (\Throwable) {
            }
        }

        // since() ako postoji (i dalje imamo ručni cutoff)
        if (method_exists($query, 'since')) {
            try {
                $query->since($since);
            } catch (\Throwable) {
            }
        }

        $messages = $query->limit($limit)->get();

        $countNew = 0;
        $countUpdated = 0;
        $countSkipped = 0;

        foreach ($messages as $message) {
            // ----- received_at (prvo zbog lookback)
            $receivedAt = null;
            try {
                $date = $message->getDate();
                if ($date) {
                    $receivedAt = Carbon::parse($date);
                }
            } catch (\Throwable) {
                $receivedAt = null;
            }
            $receivedAt = $receivedAt ?: now();

            // ----- subject (hard limit da ne pukne DB)
            $subjectRaw = (string) ($message->getSubject() ?? '');
            $subjectTrim = trim($subjectRaw);
            if ($subjectTrim !== '') {
                $subjectTrim = Str::of($subjectTrim)->limit(250, '…')->toString();
            }
            $subjectNorm = $this->normalizeSubject($subjectTrim);

            // ----- from
            $from = $message->getFrom();
            $fromEmail = (string) (($from[0]->mail ?? '') ?: '');
            $fromLower = strtolower(trim($fromEmail));

            // ----- headers
            $headersObj = $message->getHeader();
            $messageId  = $this->normalizeHeaderMessageId((string) (($headersObj->get('message-id')[0] ?? '') ?: ''));
            $inReplyTo  = $this->normalizeHeaderMessageId((string) (($headersObj->get('in-reply-to')[0] ?? '') ?: ''));
            $references = trim((string) (($headersObj->get('references')[0] ?? '') ?: ''));
            $references = Str::of($references)->limit(2000)->toString();

            // ----- body
            $body = (string) ($message->getTextBody() ?: $message->getHTMLBody() ?: '');
            $body = trim($body) !== '' ? trim($body) : '[EMPTY_BODY]';

            $bodySquished = Str::of($body)->squish()->toString();
            $bodyNorm = mb_strtolower($bodySquished);

            /**
             * --- message_hash (dedupe stabilno)
             * 1) Ako imamo Message-ID -> to je najbolji key
             * 2) Fallback: from + subject + početak body-ja (bez received_at jer zna da varira)
             */
            $hashKey = $messageId !== ''
                ? implode('|', ['imap', $inboxKey, 'mid', $messageId])
                : implode('|', [
                    'imap', $inboxKey, 'fallback',
                    $fromLower,
                    $subjectNorm,
                    Str::of($bodySquished)->limit(2000)->toString(),
                ]);

            $messageHash = hash('sha256', $hashKey);

            // default: new
            $finalStatus = 'new';
            $skipReason = null;

            // 0) lookback cutoff
            if ($receivedAt->lt($since)) {
                $finalStatus = 'skipped';
                $skipReason = 'lookback_old';
            }

            // 1) follow-up / replies
            if ($finalStatus === 'new' && (
                $inReplyTo !== '' ||
                $references !== '' ||
                preg_match('/^(re(\[\d+\])?:|fw:|fwd:|aw:)\s*/i', $subjectNorm)
            )) {
                $finalStatus = 'skipped';
                $skipReason = 'follow_up';
            }

            // 2) system “Request from …”
            if ($finalStatus === 'new' && str_contains($subjectNorm, 'request from')) {
                $finalStatus = 'skipped';
                $skipReason = 'request_from';
            }

            // 3) system/spam (konzervativno)
            if ($finalStatus === 'new' && $this->isSystemOrSpam($fromLower, $subjectNorm, $bodyNorm)) {
                $finalStatus = 'skipped';
                $skipReason = 'system_or_spam';
            }

            // 4) vlasnici smeštaja / listing requests
            if ($finalStatus === 'new' && $this->isListingRequest($subjectNorm, $bodyNorm)) {
                $finalStatus = 'skipped';
                $skipReason = 'listing_request';
            }

            // ---- upsert
            $payload = [
                'source'      => "imap:{$inboxKey}",
                'message_id'  => $messageId !== '' ? $messageId : null,
                'from_email'  => $fromEmail ?: null,
                'subject'     => $subjectTrim !== '' ? $subjectTrim : null,
                'received_at' => $receivedAt,
                'headers'     => [
                    'message-id'   => $messageId,
                    'in-reply-to'  => $inReplyTo,
                    'references'   => $references,
                    'skip_reason'  => $skipReason,
                    'fetch_mode'   => $mode,
                    'folder'       => $folderName,
                    'inbox'        => $inboxKey,
                ],
                'raw_body'    => $body,
                'status'      => $finalStatus,
                'ai_stopped'  => false,
                'inquiry_id'  => null, // popunjava ai:sync-inquiries
            ];

            $existing = AiInquiry::query()->where('message_hash', $messageHash)->first();

            if ($existing) {
                $existing->fill($payload)->save();
                $countUpdated++;

                if ($finalStatus !== 'new') {
                    $countSkipped++; // ✅ da statistika bude tačna
                }
            } else {
                AiInquiry::create(array_merge(['message_hash' => $messageHash], $payload));
                if ($finalStatus === 'new') {
                    $countNew++;
                } else {
                    $countSkipped++;
                }
            }

            // Seen flag: samo u unseen modu i samo za NEW (da ne "pojede" skipped)
            if ($mode !== 'all' && $finalStatus === 'new') {
                try {
                    $message->setFlag('Seen');
                } catch (\Throwable) {
                }
            }

            // ✅ PRIVREMENI LOG: svaka poruka (i new i skipped)
            Log::info('GiImapPullInquiries: message', [
                'final'       => $finalStatus,
                'reason'      => $skipReason,
                'inbox'       => $inboxKey,
                'folder'      => $folderName,
                'mode'        => $mode,
                'from'        => $fromEmail,
                'subject'     => $subjectTrim !== '' ? $subjectTrim : null,
                'message_id'  => $messageId,
                'in_reply_to' => $inReplyTo,
                'references'  => $references,
                'received_at' => $receivedAt->toDateTimeString(),
                'hash'        => $messageHash,
            ]);

            // stari skipped log (ostaje)
            if ($finalStatus !== 'new') {
                Log::info('GiImapPullInquiries: skipped', [
                    'reason' => $skipReason,
                    'inbox' => $inboxKey,
                    'folder' => $folderName,
                    'mode' => $mode,
                    'from' => $fromEmail,
                    'subject' => $subjectTrim !== '' ? $subjectTrim : null,
                    'message_id' => $messageId,
                    'received_at' => $receivedAt->toDateTimeString(),
                ]);
            }
        }

        $this->info("Imported into ai_inquiries: new={$countNew}, updated={$countUpdated}, skipped={$countSkipped} (mode={$mode})");
    }

    private function normalizeHeaderMessageId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // izvuci prvi <...> ako postoji (nekad dođe listom ili sa dodatnim tekstom)
        if (preg_match('/<[^>]+>/', $raw, $m)) {
            return $m[0];
        }

        // ako je "gola" vrednost bez razmaka, uokviri
        if (! str_contains($raw, ' ') && ! str_contains($raw, "\t")) {
            return '<' . $raw . '>';
        }

        return $raw;
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

    private function isSystemOrSpam(string $fromLower, string $subjectNorm, string $bodyNorm): bool
    {
        $senderBad = [
            'no-reply@',
            'noreply@',
            'postmaster@',
            '@notifications.google.com',
            '@accounts.google.com',
            '@webhotelier',
            '@mailer-daemon',
            '@flippa.com',
            '@mailchimp',
        ];

        foreach ($senderBad as $needle) {
            if ($needle !== '' && str_contains($fromLower, $needle)) {
                return true;
            }
        }

        $subjectBad = [
            'invoice',
            'payment',
            'security alert',
            'new device',
            'password',
            'affiliate',
            'b2b',
            'webhotelier',
            'want to sell',
            'domain',
            'newsletter',
            'unsubscribe',
            'verification',
            'confirm your',
            'receipt',
            'order confirmation',
            'confirmation',
        ];

        foreach ($subjectBad as $needle) {
            if ($needle !== '' && str_contains($subjectNorm, $needle)) {
                return true;
            }
        }

        $bodyBad = [
            'unsubscribe',
            'view in browser',
            'marketing',
            'campaign',
            'promo code',
            'limited offer',
            'follow us on',
        ];

        $hits = 0;
        foreach ($bodyBad as $needle) {
            if ($needle !== '' && str_contains($bodyNorm, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function isListingRequest(string $subjectNorm, string $bodyNorm): bool
    {
        $needles = [
            'new listing',
            'list my property',
            'add my property',
            'how can i list',
            'i want to list',
            'advertise my',
            'property owner',
            'vlasnik smeštaja',
            'vlasnik smestaja',
            'oglašavanje smeštaja',
            'oglasavanje smestaja',
            'kako da oglasim',
            'dodam smeštaj',
            'dodam smestaj',
            'how can i advertise',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && (str_contains($subjectNorm, $needle) || str_contains($bodyNorm, $needle))) {
                return true;
            }
        }

        return false;
    }
}
