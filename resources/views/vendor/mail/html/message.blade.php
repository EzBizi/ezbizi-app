<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{-- Deliberately NOT "do not reply": config/mail.php leaves reply_to blank, so
     replies go to the From address. Promising nobody reads them would be a
     claim this installation has not actually decided on. --}}
© {{ date('Y') }} {{ config('app.name') }} · [ezbizi.com](https://www.ezbizi.com)
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
