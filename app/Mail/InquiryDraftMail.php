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
        return new Content(
            text: 'emails.inquiry-draft',
            with: [
                'body' => $this->inquiry->ai_draft ?: '',
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
        // 1) Prefer ai_inquiries.message_id (to je pravi Message-ID originalnog mejla)
        $ai = $this->inquiry->relationLoaded('aiInquiry')
            ? $this->inquiry->aiInquiry
            : $this->inquiry->aiInquiry()->first();

        $mid = $ai?->message_id;
        $mid = $this->normalizeMessageId($mid);
        if ($mid) return $mid;

        // 2) Fallback: pokušaj da izvučeš <...> iz external_id ako ga tamo nosiš
        $ex = (string) ($this->inquiry->external_id ?? '');
        if ($ex !== '') {
            if (preg_match('/<[^>]+>/', $ex, $m)) {
                return $this->normalizeMessageId($m[0]);
            }
        }

        return null;
    }

    private function normalizeMessageId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // ako već ima <...>, izvuci ga i vrati samo to
        if (preg_match('/<[^>]+>/', $raw, $m)) {
            return $m[0];
        }

        // ako je "gola" vrednost (bez uglastih zagrada), uokviri
        // (minimalna validacija: ne sme imati razmake)
        if (str_contains($raw, ' ')) {
            return null;
        }

        return '<' . $raw . '>';
    }
}
