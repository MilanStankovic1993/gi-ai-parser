<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class InquiryDraftMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Inquiry $inquiry,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->inquiry->subject ?: 'Upit';

        if (! preg_match('/^\s*re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        $envelope = new Envelope(subject: $subject);

        if ($this->fromAddress) {
            $addr = new Address($this->fromAddress, $this->fromName ?: config('app.name'));
            $envelope->from = $addr;
            $envelope->replyTo = [$addr];
        }

        return $envelope;
    }

    public function content(): Content
    {
        // šaljemo kao HTML view, a view pretvara Markdown -> HTML
        return new Content(
            html: 'emails.inquiry-draft',
            with: [
                'body' => (string) ($this->inquiry->ai_draft ?: ''),
            ]
        );
    }

    public function headers(): Headers
    {
        $messageId = $this->getOriginalMessageId();

        if ($messageId) {
            return new Headers(
                text: [
                    'In-Reply-To' => $messageId,
                    'References'  => $messageId,
                ]
            );
        }

        return new Headers();
    }

    private function getOriginalMessageId(): ?string
    {
        // 1) Prefer ai_inquiries.message_id (pravi Message-ID originalnog mejla)
        $ai = $this->inquiry->relationLoaded('aiInquiry')
            ? $this->inquiry->aiInquiry
            : $this->inquiry->aiInquiry()->first();

        $mid = $this->normalizeMessageId($ai?->message_id);
        if ($mid) return $mid;

        // 2) Fallback: pokušaj da izvučeš <...> iz external_id
        $ex = trim((string) ($this->inquiry->external_id ?? ''));
        if ($ex !== '' && preg_match('/<[^>]+>/', $ex, $m)) {
            return $this->normalizeMessageId($m[0]);
        }

        return null;
    }

    private function normalizeMessageId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // ako već ima <...>, vrati to
        if (preg_match('/<[^>]+>/', $raw, $m)) {
            return $m[0];
        }

        // minimalna validacija: ne sme imati razmake
        if (str_contains($raw, ' ')) {
            return null;
        }

        return '<' . $raw . '>';
    }
}
