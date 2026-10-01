@props(['url'])
<tr>
<td class="header">
{{--
    The navy band of the sign-in page (UI queue 39, Jose's 1, 2026-10-01: "ezbizi
    colors, etc, not just text").

    The wordmark is TEXT, not the old logo image: most clients block remote
    images on a first message from an unknown sender, and text is what still
    says EzBizi then. The icon beside it is decoration with an empty alt, so a
    blocked icon leaves nothing behind but the word.

    The colour is set twice, a solid navy and then the gradient, because
    Outlook drops background-image and keeps background-color.
--}}
<table class="band" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="band-cell">
<a href="{{ $url }}" class="wordmark"><img src="{{ rtrim((string) config('ezbizi.hosts.customer'), '/') }}/icons/Icon-192.png" width="26" height="26" alt="" class="band-icon"><span style="color: #00B4D8;">Ez</span><span style="color: #ffffff;">Bizi</span></a>
</td>
</tr>
</table>
</td>
</tr>
