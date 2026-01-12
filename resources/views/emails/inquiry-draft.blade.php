{{-- resources/views/emails/inquiry-draft.blade.php --}}
@php
    use Illuminate\Support\Str;

    $body = (string) ($body ?? '');

    // Pretvori Markdown u HTML (da [Naziv](url) bude klikabilno)
    $html = Str::markdown($body);
@endphp

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; padding:0; background:#ffffff;">
    <div style="max-width:720px; margin:0 auto; padding:16px; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:#111827;">
        {!! $html !!}
    </div>
</body>
</html>
