Poštovani{{ $guest ? ' ' . $guest : '' }},

Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.

Na osnovu traženih kriterijuma, trenutno nemamo dostupnu ponudu koja u potpunosti odgovara vašem upitu.

Ipak, u nastavku šaljemo 4–5 alternativnih predloga koji bi mogli biti interesantni:

@foreach(($alternatives ?? []) as $idx => $a)
{{ $idx + 1 }}. {{ $a['title'] ?? ($a['name'] ?? 'Smeštaj') }}
@if(!empty($a['price']))
• Cena: {{ $a['price'] }}
@endif
@if(!empty($a['url']))
• Link: {{ $a['url'] }}
@endif

@endforeach

Ukoliko želite, možemo dodatno suziti izbor ako nam potvrdite da li ste fleksibilni u pogledu:
• druge lokacije u blizini
• datuma (± 2–3 dana)
• budžeta / tipa smeštaja

Srdačan pozdrav,
GrckaInfo tim
https://grckainfo.com
