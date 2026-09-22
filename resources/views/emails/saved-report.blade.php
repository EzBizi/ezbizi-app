@component('mail::message')
# {{ $name }}

@if ($summary)
{{ $summary }}
@endif

@if ($count === 0)
This report found nothing. No rows matched, for the records the report can see —
somebody with a wider reach may get results from the same report.
@else
{{ $count === 1 ? '1 row' : number_format($count).' rows' }}, attached as a CSV.
@endif

@if ($capped)
Showing the first {{ number_format($count) }} rows. This report matched more.
@endif

@component('mail::subcopy')
@if ($business !== '')
Sent by {{ $business }} through EzBizi, from an automation rule. Somebody at
{{ $business }} who can edit automations can change or stop it.
@else
This was sent by an automation rule in EzBizi. Somebody who can edit automations
can change or stop it.
@endif
@endcomponent
@endcomponent
