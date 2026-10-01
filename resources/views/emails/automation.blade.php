{{--
    The frame every automation email goes out in: UI queue 39, Jose's 1
    (2026-10-01: "ezbizi colors, etc, not just text ... any and all automations
    that send emails from ezbizi").

    $bodyHtml is the ONE unescaped echo, and it may be: it is CommonMark output
    with HTML input escaped and unsafe links dropped (AutomationMessage::markdownHtml),
    and a stranger's fields were escaped before that (ActionRunner::interpolate).
    Everything else here is escaped by Blade.

    Styles are inline because mail apps drop <style>; AutomationMessage inlines
    the body's own rules (.md) after this renders. Tables, because Outlook.

    The band says who is speaking. With a $kind, EzBizi composed the substance
    (the overbooking warning) and the navy EzBizi band carries that kind. Without
    one, a business wrote it: its name takes the band and EzBizi is "via".

    The navy band is justified ONLY because a trigger that declares
    composedFields puts EzBizi's own words at the substance of the message. Do
    not widen "declares composedFields" to "any trigger": an event EzBizi raises
    still carries a business's words when the author wrote them (Automations,
    2026-10-01).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F5F8FA;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #F5F8FA;">
<tr>
<td align="center" style="padding: 24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
@if ($kind !== null)
<tr>
<td style="background-color: #0A2233; background-image: linear-gradient(135deg, #071018, #0A2233 55%, #06384E); border-radius: 12px 12px 0 0; padding: 16px 22px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="font-size: 20px; font-weight: 800; letter-spacing: -0.5px; line-height: 26px;"><img src="{{ $icon }}" width="26" height="26" alt="" style="vertical-align: middle; border-radius: 7px; margin-right: 8px;"><span style="color: #00B4D8;">Ez</span><span style="color: #ffffff;">Bizi</span></td>
<td align="right" style="font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #9FD6E6;">{{ $kind }}</td>
</tr>
</table>
</td>
</tr>
@else
<tr>
<td style="background-color: #ffffff; border: 1px solid #E3E9EC; border-bottom: 3px solid #00B4D8; border-radius: 12px 12px 0 0; padding: 16px 22px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="font-size: 18px; font-weight: 800; color: #060808;">{{ $business }}</td>
<td align="right" style="font-size: 11px; color: #5B6672; white-space: nowrap;"><img src="{{ $icon }}" width="16" height="16" alt="" style="vertical-align: middle; border-radius: 4px; margin-right: 4px;">via EzBizi</td>
</tr>
</table>
</td>
</tr>
@endif
<tr>
<td style="background-color: #ffffff; border: 1px solid #E3E9EC; border-top: 0; border-radius: 0 0 12px 12px; padding: 22px; font-size: 15px; line-height: 1.55; color: #1D2A33;">
<div class="md{{ $kind !== null ? ' composed' : '' }}">{!! $bodyHtml !!}</div>
@if ($kind !== null)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 8px;">
<tr>
<td style="background-color: #00708F; border-radius: 10px;"><a href="{{ $open }}" style="display: inline-block; padding: 11px 18px; color: #ffffff; font-weight: 700; text-decoration: none;">Open EzBizi</a></td>
</tr>
</table>
@endif
</td>
</tr>
<tr>
<td style="padding: 14px 22px 0; font-size: 12px; line-height: 1.5; color: #5B6672;">
Sent by {{ $business }} through EzBizi{{ $automation !== null ? ', from the automation “'.$automation.'”' : '' }}.
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
