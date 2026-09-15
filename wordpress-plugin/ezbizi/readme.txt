=== EzBizi ===
Contributors: ezbizi
Tags: shortcode, business info, contact details, opening hours, local business
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
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

== External Services ==

This plugin relies on a third-party service to retrieve the business details
(company name, phone, address, licence, hours) that you configure in your
EzBizi account. It does not work without that connection — retrieving those
values is the plugin's entire purpose.

= EzBizi API =

**What it is:** EzBizi is a business management platform. It holds a company's
business details centrally so the same phone number or address can be published
across every website that company owns, with each site — including each
subdomain — able to publish its own answers.

**What data is sent:** Your site token, which identifies which registered
website is asking; your site's hostname, taken from your WordPress address, so
EzBizi returns the values for this site rather than another one you own; and
this plugin's version number.

**When data is sent:**
- At most once every five minutes while your site is being visited, refreshed in
  the background rather than while a visitor waits
- When an administrator saves the settings page or presses "Refresh now"

**What data is NOT sent by the shortcodes:** No information about your
visitors — no IP addresses, no page views, no analytics, no personal data of any
kind. The page a visitor is on is never sent either: per-page values are
selected on your own server from data already received.

**The chat and contact form widgets are different, and this is the important
paragraph on this page.** Both are OFF unless you switch them on in EzBizi, and
neither sends anything until a visitor uses one. When a visitor does:

- Their browser sends what they typed — which may include their name, email
  address, telephone number, and the text of their message or their answers to
  your form's questions — directly to EzBizi, where it becomes an enquiry you
  can reply to.
- The address of the page they were on is sent with it, so you can see where
  they were when they got in touch.
- Because their browser makes that request itself, EzBizi receives their IP
  address the way any website they visit would. It is not stored as part of the
  enquiry.
- Live chat stores a token in that browser's sessionStorage so the visitor can
  read replies to their own conversation and nobody else's. It is cleared when
  they close the tab.

If you switch both widgets off, none of the above happens and this plugin sends
nothing about your visitors at all.

**What data is received:** Only the business details you entered in EzBizi for
this site — company name, phone number, email, address, opening hours, licence
number, service area and booking link. These are values you have chosen to
publish on your own public website.

**The publishing account (optional capability, added in 1.1.0):** Once your
token has been accepted — on the next content refresh, or the next time an
administrator opens wp-admin — the plugin creates a WordPress user named
"ezbizi-service" on a restricted role, generates an application password for it,
and sends that application password to EzBizi so EzBizi can publish and edit
content on this site on your behalf. The role can publish and edit posts and
pages and upload files; it cannot change settings, manage users, install
plugins, edit themes, post unfiltered HTML, or delete anything. The application
password is sent once, over HTTPS only, and is stored encrypted by EzBizi. If
the handover fails the application password is deleted immediately rather than
left active.

**What EzBizi reads with that account (added in 1.3.0):** the list of your
pages and posts — their titles, web addresses, url slugs, status (published,
draft, and so on) and when each was last changed — so that you can see them in
EzBizi under Web Management. It is read on demand when somebody opens that
screen, nothing is copied or stored, and the text of a page is never requested.

You can revoke it at any time: delete the "EzBizi" application password under
Users, delete the ezbizi-service user, or deactivate this plugin — deactivation
revokes it for you. Changing your site token revokes it too. If EzBizi drops the
pairing at its end, this plugin deletes its own application password at the next
refresh.

**Service Provider:** EzBizi
**API Endpoints:**
- https://app.ezbizi.com/api/v1/web/plugin/values (retrieving your details)
- https://app.ezbizi.com/api/v1/web/plugin/service-account (handing over the
  publishing credential described above)
**Terms of Service:** https://www.ezbizi.com/terms/
**Privacy Policy:** https://www.ezbizi.com/privacy/

== Installation ==

1. In EzBizi, open **Web Management → SEO** and add your website's address. A
   subdomain is added separately from its parent domain.
2. Copy the token EzBizi shows you. It is displayed once and cannot be looked up
   again — if you lose it, issue a new one, which takes a moment.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the file,
   and activate it.
4. Click **EzBizi** in the WordPress sidebar and paste the token.
5. Put the shortcodes wherever the values belong.

== Frequently Asked Questions ==

= Do I need an EzBizi account? =

Yes. The plugin displays values held in EzBizi and does nothing without an
account and a registered website.

= I changed a value in EzBizi and my site still shows the old one. =

Values are cached for about five minutes. Press "Refresh now" on the
**EzBizi** screen if you do not want to wait.

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

No. See the "External Services" section above for exactly what is transmitted.

= What happens when I delete the plugin? =

Its settings, cached values and stored token are removed from your database.
Deleting the plugin does not revoke the token at the EzBizi end — use "Issue a
new token" in EzBizi for that.

== Changelog ==

= 1.6.0 =
* The live chat bubble now appears on every page on its own. It floats in the
  corner, so it was never something to paste onto each page by hand. Set its
  position to "inline" if you would rather place it yourself with the shortcode
  or the Elementor widget.
* The contact form is unchanged: it appears where you put it.

= 1.5.0 =
* The live chat and contact form are now Elementor widgets. Search "EzBizi" in
  the Elements panel and drag either one onto a page. The shortcodes
  [ezbizi_chat] and [ezbizi_form] still work exactly as before.
* The widgets no longer have an on/off switch in EzBizi. Putting one on a page
  is what shows it, and taking it off the page is what hides it.

= 1.4.0 =
* EzBizi now has its own item in the WordPress sidebar instead of sitting
  inside Settings, which is where people were looking for it.

= 1.3.0 =
* Your pages and posts are visible in EzBizi under Web Management, read from
  this site when you open the screen. Nothing is copied or stored.
* Fixes the publishing account never being created on most sites. It was only
  attempted while a shortcode was being rendered, and the check it made was
  about the request rather than about the site — so on an ordinary HTTPS site
  it silently never happened. It is now also attempted when an administrator
  opens wp-admin.

= 1.2.0 =
* Live chat and a contact form for your website. Both are off until you turn
  them on in EzBizi, and enquiries arrive there beside your other leads, where
  you can reply.
* Everything about how they look and what the form asks — colours, wording,
  the questions — is set in EzBizi under Web Management › Widgets, so
  [ezbizi_chat] and [ezbizi_form] take no settings of their own and one change
  reaches every site you run.
* "External Services" above now describes what a visitor's browser sends when
  they use one of these widgets. Read it before switching them on.

= 1.1.0 =
* EzBizi can now publish and edit content on this site. On connecting, the
  plugin creates a restricted "ezbizi-service" user and hands EzBizi an
  application password for it. See "External Services" above for exactly what
  it may do and every way to revoke it.
* Deactivating the plugin, or changing your site token, now revokes that
  application password.

= 1.0.2 =
* The plugin now says when it is installed but not yet connected, instead
  of sitting silent with every shortcode empty.
* Added a Settings link on the plugin's own row, which is where people
  look for it.

= 1.0.1 =
* Maintenance release. No changes to the plugin itself — published to
  confirm the update path works before a real fix has to travel it.

= 1.0.0 =
* First release. Shortcodes for the business details held in EzBizi, with
  per-site and per-page values, background refreshing, and last-known-good
  values retained when the API cannot be reached.
