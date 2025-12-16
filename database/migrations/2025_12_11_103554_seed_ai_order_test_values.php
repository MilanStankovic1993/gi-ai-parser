<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ovo je samo za testiranje lokalno – ravnomerno raspoređujemo vrednosti 1–10
        $hotels = DB::connection('grcka')
            ->table('pt_hotels')
            ->orderBy('hotel_id')
            ->get(['hotel_id']);

        $priority = 1;

        foreach ($hotels as $hotel) {
            DB::connection('grcka')
                ->table('pt_hotels')
                ->where('hotel_id', $hotel->hotel_id)
                ->update(['ai_order' => $priority]);

            $priority++;
            if ($priority > 10) {
                $priority = 1; // restartujemo 1–10
            }
        }
    }

    public function down(): void
    {
        // Vraćamo sve na NULL
        DB::connection('grcka')
            ->table('pt_hotels')
            ->update(['ai_order' => null]);
    }
};
