<?php

namespace Database\Seeders;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

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

        if (! empty($oldInquiryIds)) {
            AiInquiry::query()->whereIn('inquiry_id', $oldInquiryIds)->delete();
            Inquiry::query()->whereIn('id', $oldInquiryIds)->delete();
        }

        $receivedBase = Carbon::now()->subDays(2)->setTime(10, 0, 0);

        $items = [
            [
                'guest_name'  => 'Petar Petrović',
                'guest_email' => 'petar@example.com',
                'subject'     => 'Upit (demo) - primarni match',
                'raw_message' => <<<TXT
Pozdrav,

Tražimo smeštaj u Kriopigi (Halkidiki / Kasandra) za 2 odrasle osobe i 1 dete (5 godina),
u terminu od 01.09. do 08.09. (7 noćenja).

Bitno nam je:
- blizu plaže
- parking
- mirna lokacija

Budžet do 1.250 EUR za ceo boravak.

Hvala!
TXT,
                'received_at' => $receivedBase->copy()->addMinutes(5),
            ],
            [
                'guest_name'  => 'Jelena Jelić',
                'guest_email' => 'jelena@example.com',
                'subject'     => 'Upit (demo) - alternative match',
                'raw_message' => <<<TXT
Dobar dan,

Zanima nas smeštaj u Pefkohori za 2 odrasle osobe
od 01.09. do 08.09. (7 noćenja).

Budžet do 1.200 EUR.
Važno: parking i da prima ljubimce.

Ako nema ništa u Pefkohoriju, molim vas pošaljite alternative u regiji Halkidiki.

Pozdrav!
TXT,
                'received_at' => $receivedBase->copy()->addMinutes(15),
            ],
            [
                'guest_name'  => 'Marko Marković',
                'guest_email' => 'marko@example.com',
                'subject'     => 'Upit (demo) - fleksibilan period',
                'raw_message' => <<<TXT
Zdravo,

Tražimo smeštaj za 2 odrasle osobe u okolini Neos Marmaras / Nikiti (Sitonija).
Period nam je fleksibilan: druga polovina juna ili početak jula, 7-10 noćenja.

Budžet oko 700-900 EUR ukupno.
Nije obavezno parking, ali da je okej plaža.

Hvala unapred!
TXT,
                'received_at' => $receivedBase->copy()->addMinutes(25),
            ],
        ];

        foreach ($items as $idx => $data) {
            $inquiry = Inquiry::create([
                'source'      => 'email',
                'external_id' => '<demo-' . ($idx + 1) . '@example.com>',
                'guest_name'  => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'subject'     => $data['subject'],
                'raw_message' => $data['raw_message'],
                'received_at' => $data['received_at'],

                // važno: ostavi kao NEW jer u realnom toku prvo ide extraction
                'status'      => 'new',
                'reply_mode'  => 'ai_draft',

                // ništa od extracted polja ne punimo ovde!
                // region/location/dates/adults/children/budget... popuniće extraction
            ]);

            AiInquiry::create([
                'source'       => 'local',
                'message_id'   => '<demo-' . ($idx + 1) . '@example.com>',
                'message_hash' => hash('sha256', 'demo|' . $inquiry->id . '|' . $data['raw_message']),
                'from_email'   => $data['guest_email'],
                'subject'      => $data['subject'],
                'received_at'  => $data['received_at'],
                'headers'      => [
                    'from' => $data['guest_name'] . ' <' . $data['guest_email'] . '>',
                    'subject' => $data['subject'],
                    'message-id' => '<demo-' . ($idx + 1) . '@example.com>',
                    'date' => $data['received_at']->format('r'),
                ],
                'raw_body'     => $data['raw_message'],

                // tvoj pipeline očekuje synced za “stiglo iz inboxa”
                'status'       => 'synced',
                'ai_stopped'   => false,
                'inquiry_id'   => $inquiry->id,
            ]);
        }

        $this->command?->info('DemoInquiriesSeeder: Seeded 3 inquiries with MAIL-ONLY payload (run extraction then generate draft).');
    }
}
