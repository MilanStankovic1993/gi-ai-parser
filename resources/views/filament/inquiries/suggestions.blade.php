@php
    use App\Services\InquiryAccommodationMatcher;

    /** @var \App\Models\Inquiry $inquiry */
    $inquiry = $getRecord(); // OVDE je fora – ne $record

    /** @var InquiryAccommodationMatcher $matcher */
    $matcher = app(InquiryAccommodationMatcher::class);

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
                    /** @var \App\Models\Accommodation $acc */
                    $acc = $item['accommodation'];
                @endphp

                <div class="p-4 rounded-lg bg-gray-800 border border-gray-700">
                    <div class="flex justify-between items-start gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-white">
                                {{ $acc->name }}
                            </h3>
                            <p class="text-sm text-gray-400">
                                {{ $acc->settlement }}, {{ $acc->region }}
                            </p>
                            @if($acc->availability_note)
                                <p class="text-xs text-gray-400 mt-1">
                                    {{ $acc->availability_note }}
                                </p>
                            @endif
                        </div>

                        <div class="text-right">
                            @if($item['total_price'])
                                <p class="text-lg font-bold text-green-400">
                                    {{ $item['total_price'] }} €
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ $item['price_per_night'] }} € / noć
                                </p>
                            @else
                                <p class="text-sm text-gray-500">
                                    Cena nije dostupna za traženi period
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 md:grid-cols-3 gap-2 text-xs text-gray-300">
                        <div>Kapacitet: <strong>{{ $acc->max_persons }} osoba</strong></div>
                        <div>Plaža: <strong>{{ $acc->distance_to_beach }} m</strong></div>
                        <div>Parking: <strong>{{ $acc->has_parking ? 'Da' : 'Ne' }}</strong></div>
                        <div>Ljubimci: <strong>{{ $acc->accepts_pets ? 'Da' : 'Ne' }}</strong></div>
                        <div>Buka: <strong>{{ $acc->noise_level ?: '-' }}</strong></div>
                        <div>Prioritet: <strong>{{ $acc->priority }}</strong></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
