<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoAiTravelDataSeeder extends Seeder
{
    public function run(): void
    {
        // === 1) DEMO SMEŠTAJI ==========================================

        // 1. Vila Maria – Pefkohori, Halkidiki
        $vilaMaria = Accommodation::firstOrCreate(
            ['name' => 'Vila Maria'],
            [
                'region'            => 'Halkidiki',
                'settlement'        => 'Pefkohori',
                'unit_type'         => 'apartman 1 spavaća',
                'bedrooms'          => 1,
                'max_persons'       => 5,
                'distance_to_beach' => 150,
                'beach_type'        => 'sand',
                'has_parking'       => true,
                'accepts_pets'      => false,
                'has_pool'          => false, // nema bazen
                'noise_level'       => 'quiet',
                'availability_note' => 'Većinom slobodno u junu i septembru.',
                'internal_contact'  => 'Nikos, +30..., 10% provizija',
                'is_commission'     => true,
                'priority'          => 5,
            ]
        );

        $vilaMaria->pricePeriods()->delete();
        $vilaMaria->pricePeriods()->createMany([
            [
                'season_name'      => 'Jun 2025',
                'date_from'        => '2025-06-01',
                'date_to'          => '2025-06-30',
                'price_per_night'  => 45,
                'min_nights'       => 5,
                'is_available'     => true,
                'note'             => null,
            ],
            [
                'season_name'      => 'Jul 2025',
                'date_from'        => '2025-07-01',
                'date_to'          => '2025-07-31',
                'price_per_night'  => 60,
                'min_nights'       => 7,
                'is_available'     => true,
                'note'             => 'Veoma tražen period.',
            ],
            [
                'season_name'      => 'Avgust 2025',
                'date_from'        => '2025-08-01',
                'date_to'          => '2025-08-31',
                'price_per_night'  => 70,
                'min_nights'       => 7,
                'is_available'     => true,
                'note'             => null,
            ],
        ]);

        // 2. Stavros House – Stavros
        $stavrosHouse = Accommodation::firstOrCreate(
            ['name' => 'Stavros House'],
            [
                'region'            => 'Stavros',
                'settlement'        => 'Stavros',
                'unit_type'         => 'studio',
                'bedrooms'          => 0,
                'max_persons'       => 3,
                'distance_to_beach' => 80,
                'beach_type'        => 'mixed',
                'has_parking'       => true,
                'accepts_pets'      => true,
                'has_pool'          => false, // nema bazen
                'noise_level'       => 'street',
                'availability_note' => 'Dobar izbor za kraće boravke.',
                'internal_contact'  => 'Giorgos, +30..., 12% provizija',
                'is_commission'     => true,
                'priority'          => 4,
            ]
        );

        $stavrosHouse->pricePeriods()->delete();
        $stavrosHouse->pricePeriods()->createMany([
            [
                'season_name'      => 'Jun 2025',
                'date_from'        => '2025-06-10',
                'date_to'          => '2025-06-30',
                'price_per_night'  => 35,
                'min_nights'       => 4,
                'is_available'     => true,
                'note'             => null,
            ],
            [
                'season_name'      => 'Jul 2025',
                'date_from'        => '2025-07-01',
                'date_to'          => '2025-07-20',
                'price_per_night'  => 50,
                'min_nights'       => 6,
                'is_available'     => true,
                'note'             => null,
            ],
        ]);

        // 3. Sarti View Apartments – Sarti (ovde možemo da imamo bazen)
        $sartiView = Accommodation::firstOrCreate(
            ['name' => 'Sarti View Apartments'],
            [
                'region'            => 'Sithonia',
                'settlement'        => 'Sarti',
                'unit_type'         => 'apartman 2 spavaće',
                'bedrooms'          => 2,
                'max_persons'       => 6,
                'distance_to_beach' => 300,
                'beach_type'        => 'sand',
                'has_parking'       => false,
                'accepts_pets'      => false,
                'has_pool'          => true, // ima bazen
                'noise_level'       => 'street',
                'availability_note' => 'Dostupno u junu i delu septembra.',
                'internal_contact'  => 'Eleni, +30..., 15% provizija',
                'is_commission'     => true,
                'priority'          => 3,
            ]
        );

        $sartiView->pricePeriods()->delete();
        $sartiView->pricePeriods()->createMany([
            [
                'season_name'      => 'Jun 2025',
                'date_from'        => '2025-06-01',
                'date_to'          => '2025-06-25',
                'price_per_night'  => 55,
                'min_nights'       => 5,
                'is_available'     => true,
                'note'             => null,
            ],
            [
                'season_name'      => 'Avgust 2025',
                'date_from'        => '2025-08-10',
                'date_to'          => '2025-08-31',
                'price_per_night'  => 85,
                'min_nights'       => 7,
                'is_available'     => false,
                'note'             => 'Skoro popunjeno.',
            ],
        ]);

        // 4. Thassos Beach Hotel – Tasos (hotel sa bazenom)
        $thassosHotel = Accommodation::firstOrCreate(
            ['name' => 'Thassos Beach Hotel'],
            [
                'region'            => 'Thassos',
                'settlement'        => 'Potos',
                'unit_type'         => 'hotel soba',
                'bedrooms'          => 1,
                'max_persons'       => 3,
                'distance_to_beach' => 30,
                'beach_type'        => 'sand',
                'has_parking'       => true,
                'accepts_pets'      => false,
                'has_pool'          => true, // hotel sa bazenom
                'noise_level'       => 'main_road',
                'availability_note' => 'Najbolje radi u julu i avgustu.',
                'internal_contact'  => 'Hotel recepcija, +30..., 12% provizija',
                'is_commission'     => true,
                'priority'          => 2,
            ]
        );

        $thassosHotel->pricePeriods()->delete();
        $thassosHotel->pricePeriods()->createMany([
            [
                'season_name'      => 'Jul 2025',
                'date_from'        => '2025-07-05',
                'date_to'          => '2025-07-31',
                'price_per_night'  => 70,
                'min_nights'       => 5,
                'is_available'     => true,
                'note'             => null,
            ],
            [
                'season_name'      => 'Avgust 2025',
                'date_from'        => '2025-08-01',
                'date_to'          => '2025-08-25',
                'price_per_night'  => 90,
                'min_nights'       => 7,
                'is_available'     => true,
                'note'             => null,
            ],
        ]);

        // === 2) DEMO UPITI (INQUIRIES) =================================

        $receivedBase = Carbon::parse('2025-03-10 10:00:00');

        // 1) Pefkohori – blizu plaže, parking, mirno, budžet 800
        Inquiry::create([
            'guest_name'   => 'Petar Petrović',
            'guest_email'  => 'petar@example.com',
            'raw_message'  => "Pozdrav,\n\nTražimo smeštaj u Pefkohoriju za 2 odrasle osobe i 1 dete od 15.08. do 22.08. 2025.\nBitno nam je da je što bliže plaži, da ima parking i da je lokacija mirna zbog deteta.\nBudžet nam je oko 800 eur za ceo boravak.\n\nHvala!",
            'received_at'  => $receivedBase->copy()->addMinutes(5),
            'status'       => 'new',
            'reply_mode'   => 'ai_draft',
        ]);

        // 2) Stavros – ljubimci + parking + blizu plaže, budžet 500
        Inquiry::create([
            'guest_name'   => 'Jelena Jelić',
            'guest_email'  => 'jelena@example.com',
            'raw_message'  => "Dobar dan,\n\nMolim vas ponudu za Stavros, termin 18.06 - 25.06, za 2 odrasle osobe.\nVoleli bismo smeštaj koji prima ljubimce i ima parking, blizu plaže.\nBudžet je do 500 eura.\n\nPozdrav!",
            'received_at'  => $receivedBase->copy()->addMinutes(15),
            'status'       => 'new',
            'reply_mode'   => 'ai_draft',
        ]);

        // 3) Sarti – 2+2, fleksibilan termin, bez posebnih uslova, budžet 1000
        Inquiry::create([
            'guest_name'   => 'Marko Marković',
            'guest_email'  => 'marko@example.com',
            'raw_message'  => "Zdravo,\n\nInteresuje nas Sarti ili okolna mesta, 2 odrasla i 2 dece.\nTermin je fleksibilan, druga polovina juna ili početak jula, 10-12 noćenja.\nNisu nam bitni ljubimci, može i bez parkinga, ali da plaža bude pristojna.\nBudžet je oko 1000 eur.\n\nHvala unapred.",
            'received_at'  => $receivedBase->copy()->addMinutes(25),
            'status'       => 'new',
            'reply_mode'   => 'ai_draft',
        ]);
    }
}
