@php
    /** @var \App\Models\Inquiry $inquiry */
    $inquiry = $getRecord();

    $ai = $inquiry->aiInquiry;
    $payload = $ai?->suggestions_payload ?? [];

    $primary = collect($payload['primary'] ?? []);
    $alts    = collect($payload['alternatives'] ?? []);
    $log     = $payload['log'] ?? null;
@endphp

<div class="space-y-4">
    @if(! $ai)
        <p class="text-sm text-gray-400">
            Ovaj upit još nema vezan <strong>AiInquiry</strong>.
        </p>

    @elseif(empty($payload))
        <p class="text-sm text-gray-400">
            Još nema sačuvanih sugestija. Pokreni <strong>Generate draft</strong> ili CLI <code>php artisan ai:suggest --limit=...</code>.
        </p>

    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="space-y-2">
                <h3 class="text-base font-semibold text-white">Primary</h3>

                @if($primary->isEmpty())
                    <p class="text-sm text-gray-400">Nema primarnih predloga.</p>
                @else
                    <div class="space-y-3">
                        @foreach($primary as $item)
                            @php
                                $hotel = data_get($item, 'hotel');
                                $room  = data_get($item, 'room');
                                $price = data_get($item, 'price');

                                $name = data_get($hotel, 'hotel_title')
                                    ?? data_get($hotel, 'title')
                                    ?? data_get($hotel, 'hotel_name')
                                    ?? 'Smeštaj';

                                $place = data_get($hotel, 'mesto') ?: (data_get($hotel, 'hotel_map_city') ?: data_get($hotel, 'hotel_city'));
                                $distance = data_get($hotel, 'hotel_udaljenost_plaza') ? ((int) data_get($hotel, 'hotel_udaljenost_plaza') . ' m') : null;

                                $url = data_get($hotel, 'public_url');

                                $capacity = trim(
                                    ((int) (data_get($room, 'room_adults') ?? 0)) . ' odraslih' .
                                    (((int) (data_get($room, 'room_children') ?? 0)) > 0 ? (' + ' . (int) data_get($room, 'room_children') . ' dece') : '')
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
                                            Soba: <strong>{{ data_get($room, 'room_title') ?? data_get($room, 'room_name') ?? ('#' . (data_get($room, 'room_id') ?? '-')) }}</strong>
                                        </p>
                                    </div>

                                    <div class="text-right">
                                        @if($price && data_get($price, 'total'))
                                            <p class="text-lg font-bold text-green-400">
                                                {{ data_get($price, 'total') }} €
                                            </p>
                                            <p class="text-xs text-gray-400">
                                                {{ data_get($price, 'per_night') ?? '-' }} € / noć
                                                @if(data_get($price, 'nights'))
                                                    • {{ data_get($price, 'nights') }} noći
                                                @endif
                                            </p>
                                        @else
                                            <p class="text-sm text-gray-500">Cena nije dostupna</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-2 md:grid-cols-3 gap-2 text-xs text-gray-300">
                                    <div>Kapacitet: <strong>{{ $capacity ?: '-' }}</strong></div>
                                    <div>Min stay: <strong>{{ data_get($room, 'room_min_stay') ?? '-' }}</strong></div>
                                    <div>AI prioritet: <strong>{{ data_get($hotel, 'ai_order') ?? '-' }}</strong></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="space-y-2">
                <h3 class="text-base font-semibold text-white">Alternatives</h3>

                @if($alts->isEmpty())
                    <p class="text-sm text-gray-400">Nema alternativnih predloga.</p>
                @else
                    <pre class="text-xs overflow-auto p-3 rounded bg-gray-900 border border-gray-800">{{ json_encode($alts->values()->all(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </div>
        </div>

        @if($log)
            <details class="rounded-lg border border-gray-800 p-3">
                <summary class="cursor-pointer font-semibold text-white">Log</summary>
                <pre class="mt-2 text-xs overflow-auto">{{ json_encode($log, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif
    @endif
</div>
