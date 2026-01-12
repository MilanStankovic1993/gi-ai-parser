{{-- resources/views/ai/templates/offer.blade.php --}}
Poštovani,

Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.

Na osnovu informacija iz vašeg upita, u nastavku vam šaljemo nekoliko predloga smeštaja koji bi mogli da odgovaraju vašim željama. Ukoliko smo nešto pogrešno razumeli ili želite izmene, slobodno nas ispravite.

@foreach(($suggestions ?? []) as $i => $s)
{{ $i + 1 }}.
@if(!empty($s['link']))
<a href="{{ $s['link'] }}">{{ $s['name'] ?? 'Smeštaj' }}</a>@if(!empty($s['place'])) – {{ $s['place'] }}@endif
@else
{{ $s['name'] ?? 'Smeštaj' }}@if(!empty($s['place'])) – {{ $s['place'] }}@endif
@endif
• Tip: {{ $s['type'] ?? '-' }} • Kapacitet: {{ $s['capacity'] ?? '-' }} • Cena: {{ $s['price'] ?? '-' }}@if(!empty($s['beach'])) • Plaža: {{ $s['beach'] }}@endif

@endforeach

Ukoliko vam se neki od predloga dopada, javite nam koji vam je najzanimljiviji kako bismo proverili dostupnost i poslali dalje informacije.

Ako vam je potrebna druga lokacija, drugačiji period ili dodatne opcije, slobodno nam pišite.

Srdačan pozdrav,
GrckaInfo tim
https://grckainfo.com
