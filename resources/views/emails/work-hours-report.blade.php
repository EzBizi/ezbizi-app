@component('mail::message')
# {{ $name }}

{{ $range }}

@if (count($rows) === 0)
Nobody in this report clocked any time this week.
@else
@foreach ($sections as $section)
**{{ $section['label'] }}**

@component('mail::table')
| Employee | Hours worked |
|:---------|-------------:|
@foreach ($section['rows'] as $row)
@if ($section['key'] === 'overtime')
| <span style="color:#c62828">{{ str_replace('|', '/', $row['name']) }}</span> | <span style="color:#c62828">**{{ $row['hours'] }}**</span> |
@else
| {{ str_replace('|', '/', $row['name']) }} | {{ $row['hours'] }} |
@endif
@endforeach
@endcomponent

@endforeach
**Total ({{ count($rows) === 1 ? '1 employee' : count($rows).' employees' }}): {{ $total }}**
@endif

@component('mail::subcopy')
@if ($fromAutomation)
Sent by {{ $business !== '' ? $business : 'your organization' }} through EzBizi, from an automation rule. Somebody who can edit automations can change or stop it.
@else
Sent from Work Hours in EzBizi{{ $business !== '' ? ' by '.$business : '' }}.
@endif
@endcomponent
@endcomponent
