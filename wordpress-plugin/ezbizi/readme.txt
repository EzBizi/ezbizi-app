=== EzBizi ===
Contributors: YOUR-WORDPRESS-ORG-USERNAME
Tags: shortcode, business info, contact details, opening hours, local business
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show your company's phone, address, licence and hours from EzBizi as shortcodes, so one change updates every page of your site.

== Description ==

Your phone number is probably written into your website in a dozen places — the
header, the footer, three service pages, a contact form, an old landing page
somebody forgot. Changing it means finding all twelve.

This plugin lets EzBizi hold the answer instead. You set your details once in
EzBizi, put a shortcode where the value belongs, and the page shows whatever
EzBizi currently says.

**Each website gets its own answers.** A subdomain is a separate site in EzBizi
with its own token, so `nj.example.com` can publish a different phone number
from `example.com` without a second theme, a second plugin, or a second copy of
your pages.

= What you can publish =

Every field you fill in inside EzBizi becomes a shortcode here:

* `[ezbizi_company_name]`
* `[ezbizi_phone]`
* `[ezbizi_email]`
* `[ezbizi_address]`
* `[ezbizi_hours]`
* `[ezbizi_license]`
* `[ezbizi_service_area]`
* `[ezbizi_booking_url]`

There is also a general form, `[ezbizi key="phone"]`, which accepts a fallback
for a value you have not filled in yet: `[ezbizi key="phone" default="Call us"]`.

The list is not hardcoded. It comes from your EzBizi account, so a field added
there starts working here without updating this plugin.

= Your site keeps working when ours does not =

The values are cached, and the last successful read is kept indefinitely. If
EzBizi is slow or unreachable, your pages carry on showing the details they last
received rather than going blank. Refreshing happens in the background, so a
visitor never waits on a request to us.

= You need an EzBizi account =

This plugin is a client for the EzBizi platform and does nothing on its own. You
will need an account, and a website registered under Web Management, to get the
token it asks for.

== External services ==

This plugin connects to the EzBizi API to fetch the business details you entered
in your EzBizi account. It does not work without that connection — retrieving
those values is the plugin's entire purpose.

**What is sent, and when.** On each refresh the plugin sends:

* your site token, which identifies which website is asking;
* your site's hostname, taken from your WordPress address, so EzBizi returns the
  values for this site rather than another one you own;
* this plugin's version number.

A refresh happens at most once every five minutes while your site is being
visited, and additionally when an administrator saves the settings or presses
"Refresh now".

**What is not sent.** No information about your visitors is transmitted — no IP
addresses, no page views, no analytics, no personal data of any kind. The page a
visitor is on is never sent either: per-page values are selected on your own
server from data already received.

**What is received.** The business details you entered in EzBizi for this site,
such as your company name, phone number, address, licence number and opening
hours. These are values you have chosen to publish on your own public website.

Service provider: EzBizi — https://www.ezbizi.com/
Terms of service: https://www.ezbizi.com/terms/
Privacy policy: https://www.ezbizi.com/privacy/

== Installation ==

1. In EzBizi, open **Web Management → SEO** and add your website's address. A
   subdomain is added separately from its parent domain.
2. Copy the token EzBizi shows you. It is displayed once and cannot be looked up
   again — if you lose it, issue a new one, which takes a moment.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the file,
   and activate it.
4. Go to **Settings → EzBizi** and paste the token.
5. Put the shortcodes wherever the values belong.

== Frequently Asked Questions ==

= Do I need an EzBizi account? =

Yes. The plugin displays values held in EzBizi and does nothing without an
account and a registered website.

= I changed a value in EzBizi and my site still shows the old one. =

Values are cached for about five minutes. Press "Refresh now" on the
**Settings → EzBizi** screen if you do not want to wait.

= Can my subdomains show different details? =

Yes, and that is what the plugin is for. Register each host in EzBizi
separately. Each gets its own token and its own set of values, so a regional
site can answer its own phone number.

= I lost my token. =

Issue a new one in EzBizi under Web Management. The old one stops working
immediately, and the site keeps showing its last known details until you paste
the new token in.

= What happens if EzBizi is down? =

Your pages keep showing the values they last received. Nothing goes blank, and
visitors are never made to wait on a request to us.

= Does this send anything about my visitors? =

No. See the "External services" section above for exactly what is transmitted.

= What happens when I delete the plugin? =

Its settings, cached values and stored token are removed from your database.
Deleting the plugin does not revoke the token at the EzBizi end — use "Issue a
new token" in EzBizi for that.

== Changelog ==

= 1.0.0 =
* First release. Shortcodes for the business details held in EzBizi, with
  per-site and per-page values, background refreshing, and last-known-good
  values retained when the API cannot be reached.
