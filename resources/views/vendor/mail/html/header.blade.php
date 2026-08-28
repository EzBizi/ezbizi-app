@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{--
    The wordmark is 2169x796; the .logo rule keeps that ratio at 120x44.

    The inline style is NOT redundant with the class. Most clients block remote
    images by default on a first message from an unknown sender, and what shows
    instead is the alt text styled by the IMG's own rules — so these declarations
    are what "EzBizi" looks like in the common case, not the rare one.
--}}
<img src="https://www.ezbizi.com/logo.png"
     class="logo"
     alt="{{ config('app.name') }}"
     style="color: #00AEEF; font-size: 20px; font-weight: bold; text-decoration: none;">
</a>
</td>
</tr>
