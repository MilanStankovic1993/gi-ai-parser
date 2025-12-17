<?php

namespace Database\Seeders;

use App\Models\Inquiry;
use App\Models\AiInquiry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoInquiriesSeeder extends Seeder
{
    public function run(): void
    {
        // Očisti stare demo upite
        $emails = [
            'petar@example.com',
            'jelena@example.com',
            'marko@example.com',
        ];

        $oldInquiryIds = Inquiry::query()
            ->whereIn('guest_email', $emails)
            ->pluck('id')
            ->all();

        if (!empty($oldInquiryIds)) {
            AiInquiry::query()->whereIn('inquiry_id', $oldInquiryIds)->delete();
            Inquiry::query()->whereIn('id', $oldInquiryIds)->delete();
        }

        $receivedBase = Carbon::parse('2025-03-10 10:00:00');

        $items = [
            [
                'guest_name'  => 'Petar Petrović',
                'guest_email' => 'petar@example.com',
                'subject'     => 'Upit za Pefkohori',
                'raw_message' => "Pozdrav,\n\nTražimo smeštaj u Pefkohoriju za 2 odrasle osobe i 1 dete od 15.08. do 22.08. 2025.\nBitno nam je da je što bliže plaži, da ima parking i da je lokacija mirna zbog deteta.\nBudžet nam je oko 800 eur za ceo boravak.\n\nHvala!",
                'received_at' => $receivedBase->copy()->addMinutes(5),
            ],
            [
                'guest_name'  => 'Jelena Jelić',
                'guest_email' => 'jelena@example.com',
                'subject'     => 'Upit za Stavros',
                'raw_message' => "Dobar dan,\n\nMolim vas ponudu za Stavros, termin 18.06 - 25.06, za 2 odrasle osobe.\nVoleli bismo smeštaj koji prima ljubimce i ima parking, blizu plaže.\nBudžet je do 500 eura.\n\nPozdrav!",
                'received_at' => $receivedBase->copy()->addMinutes(15),
            ],
            [
                'guest_name'  => 'Marko Marković',
                'guest_email' => 'marko@example.com',
                'subject'     => 'Upit za Sarti / Sitonija',
                'raw_message' => "Zdravo,\n\nInteresuje nas Sarti ili okolna mesta, 2 odrasla i 2 dece.\nTermin je fleksibilan, druga polovina juna ili početak jula, 10-12 noćenja.\nNisu nam bitni ljubimci, može i bez parkinga, ali da plaža bude pristojna.\nBudžet je oko 1000 eur.\n\nHvala unapred.",
                'received_at' => $receivedBase->copy()->addMinutes(25),
            ],
        ];

        foreach ($items as $idx => $data) {
            // 1) business Inquiry (ono što vidi agent u app)
            $inquiry = Inquiry::create([
                'source'      => 'email',
                'external_id' => '<demo-' . ($idx + 1) . '@example.com>',
                'guest_name'  => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'subject'     => $data['subject'],
                'raw_message' => $data['raw_message'],
                'received_at' => $data['received_at'],
                'status'      => 'new',
                'reply_mode'  => 'ai_draft',
            ]);

            // 2) pipeline AiInquiry (kao da je mejl sync-ovan)
            $rawBody = $data['raw_message'];

            AiInquiry::create([
                'source'       => 'local',
                'message_id'   => '<demo-' . ($idx + 1) . '@example.com>',
                'message_hash' => hash('sha256', 'demo|' . $inquiry->id . '|' . $rawBody),
                'from_email'   => $data['guest_email'],
                'subject'      => $data['subject'],
                'received_at'  => $data['received_at'],

                'headers'      => [
                    'from' => $data['guest_name'] . ' <' . $data['guest_email'] . '>',
                    'subject' => $data['subject'],
                    'message-id' => '<demo-' . ($idx + 1) . '@example.com>',
                    'date' => $data['received_at']->format('r'),
                ],
                'raw_body'     => $rawBody,

                // BITNO: parse komanda kod tebe čita status='synced'
                'status'       => 'synced',
                'ai_stopped'   => false,
                'inquiry_id'   => $inquiry->id,
            ]);
        }
    }
}
