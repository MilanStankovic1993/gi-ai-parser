@php
    $r = $getRecord();
    $subject = (string) ($getState() ?? '');

    $fallback = '';
    if ($r && is_object($r)) {
        $fallback = (string) ($r->raw_message ?? '');
    }

    $text = trim($subject) !== '' ? $subject : $fallback;
    $text = trim($text);

    // ✅ skrati (promeni 70 po želji)
    $short = \Illuminate\Support\Str::limit($text, 20);

    $tooltip = $text;
    if ($r && is_object($r)) {
        $tooltip = (string) (($r->raw_message ?? '') ?: ($r->subject ?? ''));
    }
@endphp

<div class="w-full whitespace-normal break-words leading-snug" title="{{ $tooltip }}">
    <div class="line-clamp-2">
        {{ $short }}
    </div>
</div>
