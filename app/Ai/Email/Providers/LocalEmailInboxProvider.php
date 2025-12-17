<?php

namespace App\Ai\Email\Providers;

use App\Ai\Email\EmailInboxProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LocalEmailInboxProvider implements EmailInboxProviderInterface
{
    public function fetchNewMessages(): array
    {
        $basePath = (string) config('ai_tools.local_inbox_path', storage_path('app/ai-inbox'));
        $processedPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_processed';

        if (! is_dir($basePath)) {
            @mkdir($basePath, 0775, true);
        }
        if (! is_dir($processedPath)) {
            @mkdir($processedPath, 0775, true);
        }

        $files = glob(rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.{eml,txt}', GLOB_BRACE) ?: [];

        // Sort po vremenu (stari prvo) da bude predvidivo
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        $messages = [];

        foreach ($files as $file) {
            // preskoči sve što je u _processed folderu (glob ne bi trebalo, ali da smo mirni)
            if (Str::contains($file, DIRECTORY_SEPARATOR . '_processed' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $raw = @file_get_contents($file);
            if (! is_string($raw) || trim($raw) === '') {
                $this->markProcessed($file, $processedPath);
                continue;
            }

            [$headers, $body] = $this->parseEmlLike($raw);

            $subject   = $headers['subject'] ?? null;
            $from      = $headers['from'] ?? null;
            $date      = $headers['date'] ?? null;
            $messageId = $headers['message-id'] ?? null;

            $fromEmail = $this->extractEmail($from);
            $receivedAt = $this->parseDateToIso($date) ?? Carbon::createFromTimestamp(filemtime($file))->toIso8601String();

            $messages[] = [
                'message_id'   => $messageId,
                'subject'      => $subject,
                'from_email'   => $fromEmail,
                'received_at'  => $receivedAt,
                'headers'      => $headers,
                'body'         => $body,
            ];

            $this->markProcessed($file, $processedPath);
        }

        return $messages;
    }

    private function parseEmlLike(string $raw): array
    {
        // Standardni EML: headeri pa prazna linija pa body.
        // Normalizujemo line endings.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $parts = preg_split("/\n\s*\n/", $raw, 2);
        $headerText = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerText);

        // Ako je .txt bez headera, tretiramo sve kao body
        if ($headers === []) {
            $body = $raw;
        }

        return [$headers, trim($body)];
    }

    private function parseHeaders(string $headerText): array
    {
        $lines = explode("\n", $headerText);

        // Folded headers: linije koje počinju space/tab pripadaju prethodnom headeru
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (! empty($unfolded) && preg_match('/^[ \t]+/', $line)) {
                $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
            } else {
                $unfolded[] = trim($line);
            }
        }

        $headers = [];
        foreach ($unfolded as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $key = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            // Zadržimo samo prve vrednosti; ako treba multi-value kasnije, proširićemo.
            if (! array_key_exists($key, $headers)) {
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    private function extractEmail(?string $fromHeader): ?string
    {
        if (! $fromHeader) {
            return null;
        }

        // hvata mail iz "Name <mail@domain>" ili samo "mail@domain"
        if (preg_match('/<([^>]+@[^>]+)>/', $fromHeader, $m)) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $fromHeader, $m)) {
            return strtolower(trim($m[1]));
        }

        return null;
    }

    private function parseDateToIso(?string $dateHeader): ?string
    {
        if (! $dateHeader) {
            return null;
        }

        try {
            return Carbon::parse($dateHeader)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function markProcessed(string $file, string $processedPath): void
    {
        $baseName = basename($file);

        // da izbegnemo overwrite: dodamo timestamp prefiks ako već postoji
        $target = rtrim($processedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName;
        if (file_exists($target)) {
            $target = rtrim($processedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . time() . '_' . $baseName;
        }

        @rename($file, $target);
    }
}
