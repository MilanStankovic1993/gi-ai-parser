<?php

namespace App\Ai\Email;

interface EmailInboxProviderInterface
{
    /**
     * @return array<int, array{
     *   message_id: string|null,
     *   subject: string|null,
     *   from_email: string|null,
     *   received_at: string|null,
     *   headers: array<string, string>,
     *   body: string
     * }>
     */
    public function fetchNewMessages(): array;
}
