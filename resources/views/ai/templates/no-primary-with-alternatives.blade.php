Poštovani{{ $guest ? ' ' . $guest : '' }},

Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.

Na osnovu kriterijuma iz vašeg upita, trenutno **nemamo dostupne provizijske smeštaje koji u potpunosti odgovaraju traženim uslovima** (period, struktura gostiju, budžet ili lokacija).

Ipak, kako bismo vam pomogli u izboru, u nastavku vam šaljemo **nekoliko alternativnih predloga smeštaja** koji bi mogli biti interesantni:

@foreach($alternatives as $idx => $c)
{{ $idx + 1 }}. {{ $c['title'] ?? 'Smeštaj' }}
@if(!empty($c['details']))
• {{ $c['details'] }}
@endif
@if(!empty($c['price']))
• Cena: {{ $c['price'] }}
@endif
@if(!empty($c['url']))
• Link: {{ $c['url'] }}
@endif

@endforeach

Ukoliko vam se neki od ovih predloga dopada, ili želite da proverimo **drugu lokaciju, fleksibilnije datume ili drugačiji budžet**, slobodno nam pišite.

Rado ćemo pokušati da pronađemo najbolje moguće opcije za vas.

Srdačan pozdrav,  
GrckaInfo tim  
https://grckainfo.com
