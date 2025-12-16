@php
    use App\Services\InquiryAccommodationMatcher;

    /** @var \App\Models\Inquiry $inquiry */
    $inquiry = $getRecord();

    /** @var InquiryAccommodationMatcher $matcher */
    $matcher = app(InquiryAccommodationMatcher::class);

    // Predloge prikazujemo tek kad je extraction urađen
    $suggestions = $inquiry->status === 'extracted'
        ? $matcher->match($inquiry, 5)
        : collect();
@endphp

<div class="space-y-4">
    @if($inquiry->status !== 'extracted')
        <p class="text-sm text-gray-400">
            Prvo pokreni <strong>Run AI extraction</strong> da bi se prikazali predlozi smeštaja.
        </p>
    @elseif($suggestions->isEmpty())
        <p class="text-sm text-gray-400">
            Trenutno nema kandidata koji odgovaraju kriterijumima iz upita i dostupnim periodima.
        </p>
    @else
        <div class="space-y-3">
            @foreach($suggestions as $item)
                @php
                    /** @var \App\Models\Grcka\Hotel $hotel */
                    $hotel = $item['hotel'];

                    /** @var \App\Models\Grcka\Room $room */
                    $room = $item['room'];

                    $price = $item['price'] ?? null;

                    $name = $hotel->hotel_title ?? $hotel->hotel_name ?? 'Smeštaj';
                    $place = $hotel->mesto ?: ($hotel->hotel_map_city ?: ($hotel->hotel_city ?: null));
                    $distance = $hotel->hotel_udaljenost_plaza ? ((int) $hotel->hotel_udaljenost_plaza . ' m') : null;

                    // URL ako postoji (iz accessora public_url)
                    $url = $hotel->public_url ?? null;

                    $capacity = trim(
                        ((int) ($room->room_adults ?? 0)) . ' odraslih' .
                        ((int) ($room->room_children ?? 0) > 0 ? (' + ' . (int) $room->room_children . ' dece') : '')
                    );
                @endphp

                <div class="p-4 rounded-lg bg-gray-800 border border-gray-700">
                    <div class="flex justify-between items-start gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-white">
                                @if($url)
                                    <a href="{{ $url }}" target="_blank" class="underline decoration-gray-600 hover:decoration-gray-300">
                                        {{ $name }}
                                    </a>
                                @else
                                    {{ $name }}
                                @endif
                            </h3>

                            <p class="text-sm text-gray-400">
                                {{ $place ?: '-' }}
                                @if($distance)
                                    • {{ $distance }} do plaže
                                @endif
                            </p>

                            <p class="text-xs text-gray-400 mt-1">
                                Soba: <strong>{{ $room->room_title ?? $room->room_name ?? ('#' . $room->room_id) }}</strong>
                            </p>
                        </div>

                        <div class="text-right">
                            @if($price && ($price['total'] ?? null))
                                <p class="text-lg font-bold text-green-400">
                                    {{ $price['total'] }} €
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ $price['per_night'] ?? '-' }} € / noć
                                    @if(!empty($price['nights']))
                                        • {{ $price['nights'] }} noći
                                    @endif
                                </p>
                            @else
                                <p class="text-sm text-gray-500">
                                    Cena nije dostupna za traženi period
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 md:grid-cols-3 gap-2 text-xs text-gray-300">
                        <div>Kapacitet: <strong>{{ $capacity ?: '-' }}</strong></div>
                        <div>Min stay: <strong>{{ $room->room_min_stay ?? '-' }}</strong></div>
                        <div>AI prioritet: <strong>{{ $hotel->ai_order ?? '-' }}</strong></div>

                        <div>Parking: <strong>{{ ($hotel->hotel_parking ?? null) ? 'Da' : '—' }}</strong></div>
                        <div>Ljubimci: <strong>{{ ($hotel->hotel_pets ?? null) ? 'Da' : '—' }}</strong></div>
                        <div>Bazen: <strong>{{ ($hotel->hotel_pool ?? null) ? 'Da' : '—' }}</strong></div>
                    </div>

                    @if($hotel->ai_order === null)
                        <p class="text-xs text-amber-400 mt-3">
                            Napomena: Ovaj smeštaj nema podešen ai_order (biće rangiran niže).
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
