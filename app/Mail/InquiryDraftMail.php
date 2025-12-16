<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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

        // Ako već nije "Re:", dodaj "Re:"
        if (!preg_match('/^\s*re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        $envelope = new Envelope(
            subject: $subject,
        );

        // From ćemo setovati kasnije (kad dobijemo kredencijale),
        // ali omogućavamo da se prosledi ovde.
        if ($this->fromAddress) {
            $envelope->from = new \Illuminate\Mail\Mailables\Address(
                $this->fromAddress,
                $this->fromName ?: config('app.name')
            );
        }

        // Reply-To (da odgovor ide na pravi inbox)
        if ($this->fromAddress) {
            $envelope->replyTo = [
                new \Illuminate\Mail\Mailables\Address(
                    $this->fromAddress,
                    $this->fromName ?: config('app.name')
                ),
            ];
        }

        return $envelope;
    }

    public function content(): Content
    {
        // U Fazi 1 šaljemo plain text (najsigurnije za deliverability).
        // Body je AI draft koji agent može izmeniti pre slanja.
        $body = $this->inquiry->ai_draft ?: '';

        return new Content(
            text: 'emails.inquiry-draft',
            with: [
                'body' => $body,
            ]
        );
    }

    public function headers(): Headers
    {
        $messageId = $this->normalizeMessageId($this->inquiry->external_id);

        // Ako imamo message-id originalnog mejla, šaljemo reply header-e
        // (ovo je 1:1 za “reply na upit” ponašanje)
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

    private function normalizeMessageId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // U bazi može biti sa ili bez < >
        if (!str_starts_with($raw, '<')) {
            $raw = '<' . $raw;
        }
        if (!str_ends_with($raw, '>')) {
            $raw = $raw . '>';
        }

        return $raw;
    }
}
