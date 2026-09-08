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
{{-- Deliberately NOT "do not reply". MAIL_REPLY_TO_ADDRESS points at a mailbox
     somebody reads, so a reply reaches a person; saying nobody does would be
     false. (This comment said reply_to was blank until it stopped being true.)

     Privacy and Terms are here because SES's own best-practice guide asks for
     it in as many words: "It's a good practice to link to these documents from
     each email you send." Hardcoded rather than read from config on purpose —
     unlike the reset and invitation links, which are per-installation and are
     built from ezbizi.hosts, these two are EzBizi's own legal documents and are
     the same wherever the software runs. --}}
© {{ date('Y') }} {{ config('app.name') }} · [ezbizi.com](https://www.ezbizi.com) · [Privacy](https://www.ezbizi.com/privacy/) · [Terms](https://www.ezbizi.com/terms/)
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
