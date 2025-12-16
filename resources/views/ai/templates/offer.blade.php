Poštovani,

Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.

Na osnovu informacija iz vašeg upita, u nastavku vam šaljemo nekoliko predloga smeštaja koji bi mogli da odgovaraju vašim željama. Ukoliko smo nešto pogrešno razumeli ili želite izmene, slobodno nas ispravite.

@foreach(($suggestions ?? []) as $i => $s)
{{ $i + 1 }}. {{ $s['name'] ?? 'Smeštaj' }} – {{ $s['place'] ?? '' }}
• Tip: {{ $s['type'] ?? '-' }} • Kapacitet: {{ $s['capacity'] ?? '-' }} • Cena: {{ $s['price'] ?? '-' }} • Plaža: {{ $s['beach'] ?? '-' }}
• Link: {{ $s['link'] ?? '-' }}

@endforeach

Ukoliko vam se neki od predloga dopada, javite nam koji vam je najzanimljiviji kako bismo proverili dostupnost i poslali dalje informacije.

Ako vam je potrebna druga lokacija, drugačiji period ili dodatne opcije, slobodno nam pišite.

Srdačan pozdrav,
GrckaInfo tim
https://grckainfo.com
