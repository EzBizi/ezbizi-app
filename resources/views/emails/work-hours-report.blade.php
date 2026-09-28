@component('mail::message')
# {{ $name }}

{{ $range }}

@if (count($rows) === 0)
Nobody in this report clocked any time this week.
@else
@component('mail::table')
| Employee | Hours worked |
|:---------|-------------:|
@foreach ($rows as $row)
| {{ str_replace('|', '/', $row['name']) }} | {{ $row['hours'] }} |
@endforeach
| **Total ({{ count($rows) === 1 ? '1 employee' : count($rows).' employees' }})** | **{{ $total }}** |
@endcomponent
@endif

@component('mail::subcopy')
@if ($fromAutomation)
Sent by {{ $business !== '' ? $business : 'your organization' }} through EzBizi, from an automation rule. Somebody who can edit automations can change or stop it.
@else
Sent from Work Hours in EzBizi{{ $business !== '' ? ' by '.$business : '' }}.
@endif
@endcomponent
@endcomponent
