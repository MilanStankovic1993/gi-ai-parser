<?php

namespace App\Console\Commands;

use App\Models\Inquiry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webklex\IMAP\Facades\Client;

class GiImapPullInquiries extends Command
{
    protected $signature = 'gi:imap-pull {--limit=50 : Max messages per inbox per run}';
    protected $description = 'Pull new (first-contact) inquiries from IMAP inboxes into the app database';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // Dva inboxa (booking + info)
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

            if (empty(env('IMAP_HOST'))) {
                $this->error('IMAP_HOST is empty in .env (set host when you get it from client).');
                continue;
            }

            if (empty($creds['username']) || empty($creds['password'])) {
                $this->warn("Missing credentials for {$key} (username/password). Skipping.");
                continue;
            }

            try {
                $this->pullFromInbox(
                    inboxKey: $key,
                    username: $creds['username'],
                    password: $creds['password'],
                    limit: $limit
                );
            } catch (\Throwable $e) {
                $this->error("IMAP error for {$key}: " . $e->getMessage());
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

        $folder = $client->getFolder('INBOX');

        // Samo nepročitani, limitirano (da cron ne “pojede” previše odjednom)
        $messages = $folder->query()
            ->unseen()
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($messages as $message) {
            $subject = (string) ($message->getSubject() ?? '');
            $from = $message->getFrom();
            $fromEmail = (string) (($from[0]->mail ?? '') ?: '');

            // headers
            $headers = $message->getHeader();
            $messageId = (string) (($headers->get('message-id')[0] ?? '') ?: '');
            $inReplyTo = (string) (($headers->get('in-reply-to')[0] ?? '') ?: '');
            $references = (string) (($headers->get('references')[0] ?? '') ?: '');

            // 1) Ignoriši follow-up / reply / fwd
            if (!empty($inReplyTo) || !empty($references) || preg_match('/^(re:|fw:|fwd:)/i', trim($subject))) {
                $message->setFlag('Seen');
                continue;
            }

            // 2) Ignoriši “Request from …” šablone / sistemske template (po dogovoru)
            if (Str::contains(Str::lower($subject), 'request from')) {
                $message->setFlag('Seen');
                continue;
            }

            // 3) Anti-duplicate preko message-id (external_id)
            if (!empty($messageId) && Inquiry::query()->where('external_id', $messageId)->exists()) {
                $message->setFlag('Seen');
                continue;
            }

            // body: prefer text, fallback html
            $body = (string) ($message->getTextBody() ?: $message->getHTMLBody() ?: '');

            // minimalna normalizacija (da ne upisujemo baš prazan)
            $body = trim(strip_tags($body)) ?: '[EMPTY_BODY]';

            // upis u DB
            Inquiry::create([
                'source'       => "email:{$inboxKey}",
                'external_id'  => $messageId ?: null,

                'guest_email'  => $fromEmail ?: null,
                'subject'      => $subject ?: null,
                'raw_message'  => $body,

                'status'       => 'new',
                'received_at'  => now(),
            ]);

            // mark seen tek kad smo uspešno snimili
            $message->setFlag('Seen');
            $count++;
        }

        $this->info("Imported: {$count}");
    }
}
