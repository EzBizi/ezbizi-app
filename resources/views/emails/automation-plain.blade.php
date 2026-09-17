{{-- UNESCAPED ON PURPOSE. This view is rendered ONLY by
     App\Mail\AutomationMessage via ->text(), so the output is a text/plain
     body with no markup context at all — there is nothing for an escape to
     protect, and Blade's default turns every quote and ampersand a rule author
     types into &quot; and &amp; in the delivered message.

     Measured before changing it:
       typed      Your "sweep" is booked. Don't forget & keys.
       delivered  Your &quot;sweep&quot; is booked. Don&#039;t forget &amp; keys.

     Found by the Automations session from the far side of the envelope.

     WHAT MAKES THIS SAFE IS THE ABSENCE OF AN HTML PART, not this file. Add an
     ->html() alternative to AutomationMessage and a tenant-authored body
     becomes markup in a rendering context — which is the injection the escape
     would genuinely have been for. PlatformMailTest pins that there is no HTML
     part, so adding one goes red and whoever adds it has to decide about
     escaping rather than inherit this decision. --}}
{!! $body !!}
