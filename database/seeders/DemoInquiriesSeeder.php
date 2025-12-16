<?php

namespace Database\Seeders;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoInquiriesSeeder extends Seeder
{
    public function run(): void
    {
        // Očisti stare demo upite (opciono, ali praktično)
        Inquiry::query()->whereIn('guest_email', [
            'petar@example.com',
            'jelena@example.com',
            'marko@example.com',
        ])->delete();

        $receivedBase = Carbon::parse('2025-03-10 10:00:00');

        Inquiry::create([
            'source'      => 'email',
            'guest_name'  => 'Petar Petrović',
            'guest_email' => 'petar@example.com',
            'subject'     => 'Upit za Pefkohori',
            'raw_message' => "Pozdrav,\n\nTražimo smeštaj u Pefkohoriju za 2 odrasle osobe i 1 dete od 15.08. do 22.08. 2025.\nBitno nam je da je što bliže plaži, da ima parking i da je lokacija mirna zbog deteta.\nBudžet nam je oko 800 eur za ceo boravak.\n\nHvala!",
            'received_at' => $receivedBase->copy()->addMinutes(5),
            'status'      => 'new',
            'reply_mode'  => 'ai_draft',
        ]);

        Inquiry::create([
            'source'      => 'email',
            'guest_name'  => 'Jelena Jelić',
            'guest_email' => 'jelena@example.com',
            'subject'     => 'Upit za Stavros',
            'raw_message' => "Dobar dan,\n\nMolim vas ponudu za Stavros, termin 18.06 - 25.06, za 2 odrasle osobe.\nVoleli bismo smeštaj koji prima ljubimce i ima parking, blizu plaže.\nBudžet je do 500 eura.\n\nPozdrav!",
            'received_at' => $receivedBase->copy()->addMinutes(15),
            'status'      => 'new',
            'reply_mode'  => 'ai_draft',
        ]);

        Inquiry::create([
            'source'      => 'email',
            'guest_name'  => 'Marko Marković',
            'guest_email' => 'marko@example.com',
            'subject'     => 'Upit za Sarti / Sitonija',
            'raw_message' => "Zdravo,\n\nInteresuje nas Sarti ili okolna mesta, 2 odrasla i 2 dece.\nTermin je fleksibilan, druga polovina juna ili početak jula, 10-12 noćenja.\nNisu nam bitni ljubimci, može i bez parkinga, ali da plaža bude pristojna.\nBudžet je oko 1000 eur.\n\nHvala unapred.",
            'received_at' => $receivedBase->copy()->addMinutes(25),
            'status'      => 'new',
            'reply_mode'  => 'ai_draft',
        ]);
    }
}
