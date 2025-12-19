<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiSyncInquiries extends Command
{
    protected $signature = 'ai:sync-inquiries {--limit=50}';
    protected $description = 'Create/attach Inquiry records for ai_inquiries (status=new) that do not have inquiry_id yet.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // IDs pa lock u transakciji (izbegavamo trku)
        $ids = AiInquiry::query()
            ->whereNull('inquiry_id')
            ->where('status', 'new')
            ->where('ai_stopped', false) // 1:1 "AI stop" - ne diraj
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $created = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$created, &$skipped) {

                    /** @var AiInquiry|null $ai */
                    $ai = AiInquiry::query()
                        ->whereKey($id)
                        ->lockForUpdate()
                        ->first();

                    if (! $ai) {
                        $skipped++;
                        return;
                    }

                    // Ako je u međuvremenu neko drugi syncovao / promenio status / stopovao AI
                    if (
                        $ai->ai_stopped ||
                        $ai->inquiry_id !== null ||
                        $ai->status !== 'new'
                    ) {
                        $skipped++;
                        return;
                    }

                    $source = (string) ($ai->source ?: 'unknown');

                    $baseExternal = $ai->message_id ?: $ai->message_hash ?: null;

                    // edge-case fallback (stabilan hash)
                    if (! $baseExternal) {
                        $body = (string) ($ai->raw_body ?? '');
                        $baseExternal = hash('sha256', implode('|', [
                            $source,
                            (string) ($ai->from_email ?? ''),
                            (string) ($ai->subject ?? ''),
                            (string) ($ai->received_at ?? ''),
                            Str::of($body)->squish()->limit(5000, ''), // stabilno + kontrola
                        ]));
                    }

                    // External ID mora biti kratak i stabilan (pretpostavka: inquiries.external_id VARCHAR(255))
                    $external = 'ai:' . $source . ':' . $baseExternal;
                    $external = Str::limit($external, 255, '');

                    // Dedupe (soft) na Inquiry nivou
                    $existing = Inquiry::query()
                        ->where('external_id', $external)
                        ->first();

                    if ($existing) {
                        $ai->inquiry_id = $existing->id;
                        $ai->status = 'synced';
                        $ai->save();

                        $skipped++;
                        return;
                    }

                    $subject = (string) ($ai->subject ?? '');
                    $subject = Str::limit($subject, 255, ''); // hard limit

                    $receivedAt = $ai->received_at
                        ? Carbon::parse($ai->received_at)
                        : Carbon::now();

                    // Kreiraj Inquiry
                    $inquiry = Inquiry::create([
                        'source'      => Str::limit('ai_inquiries:' . $source, 255, ''),
                        'external_id' => $external,

                        'guest_name'  => null,
                        'guest_email' => $ai->from_email, // može i null
                        'subject'     => $subject,
                        'raw_message' => (string) ($ai->raw_body ?? ''),
                        'received_at' => $receivedAt,

                        'status'      => Inquiry::STATUS_NEW,
                        'reply_mode'  => 'ai_draft',
                    ]);

                    $ai->inquiry_id = $inquiry->id;
                    $ai->status = 'synced';
                    $ai->save();

                    $created++;
                });
            } catch (QueryException $e) {
                // Ako dodaš UNIQUE na inquiries.external_id, ovde hvataš duplikat iz trke
                $failed++;

                try {
                    DB::transaction(function () use ($id) {
                        $ai = AiInquiry::query()->whereKey($id)->lockForUpdate()->first();
                        if (! $ai || $ai->inquiry_id) {
                            return;
                        }

                        // pokušaj da nađeš Inquiry koji je pobedio u trci
                        $source = (string) ($ai->source ?: 'unknown');
                        $baseExternal = $ai->message_id ?: $ai->message_hash ?: null;

                        if (! $baseExternal) {
                            $body = (string) ($ai->raw_body ?? '');
                            $baseExternal = hash('sha256', implode('|', [
                                $source,
                                (string) ($ai->from_email ?? ''),
                                (string) ($ai->subject ?? ''),
                                (string) ($ai->received_at ?? ''),
                                Str::of($body)->squish()->limit(5000, ''),
                            ]));
                        }

                        $external = Str::limit('ai:' . $source . ':' . $baseExternal, 255, '');

                        $existing = Inquiry::query()->where('external_id', $external)->first();
                        if ($existing) {
                            $ai->inquiry_id = $existing->id;
                            $ai->status = 'synced';
                            $ai->save();
                        }
                    });
                } catch (\Throwable) {
                    // ignore
                }
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->info("Done. Created: {$created}, Skipped: {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }
}
