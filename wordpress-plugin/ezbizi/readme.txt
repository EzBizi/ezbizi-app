=== EzBizi ===
Contributors: ezbizi
Tags: shortcode, business info, contact details, opening hours, local business
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.26.23
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

= Five that render more than a line of text =

These take your values and lay them out for you:

* `[ezbizi_social_links]` — every social and directory link you have filled in,
  as a row of icons. Networks you have not filled in are left out.
* `[ezbizi_google_map]` — a map of where the business is, drawn from the
  latitude and longitude in EzBizi. No Google API key is needed. If you have
  not set coordinates it shows a link to your Google listing instead.
* `[ezbizi_hours_table]` — your opening hours as a table, one row per day, with
  today marked. If your hours are written as a sentence rather than set on the
  grid, it prints the sentence, exactly as `[ezbizi_hours]` does.
* `[ezbizi_city_list]` — the towns you have pages for, linked, grouped by the
  group label you gave each page.
* `[ezbizi_post_title]` — the title of the page it is on, so one template can
  head every town page with the name of that town.

Each of them accepts one setting, the name of a preset:

    [ezbizi_social_links preset="footer"]

A preset is a named bundle of sizes and spacing, set up in EzBizi under **Web
Management → Marketing → Widgets**, so a company with several websites sets its
icon size once. Leave the name out for the default. A name that does not exist
falls back to the default, so a typo in a footer never blanks the footer.

All five are also Elementor widgets. Search "EzBizi" in the Elements panel.

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

**The map shortcode loads a map from Google, and only where you place it
(added in 1.12.0).** `[ezbizi_google_map]` and the EzBizi Map Elementor widget
embed Google Maps in a frame, so a page carrying one asks a visitor's browser
to load that frame from Google — which means Google receives their IP address
and whatever cookies they already hold for it, as it would on any site with a
map. It uses the keyless embed, so no account or API key of ours is involved,
and nothing about your visitor is sent to us. The only information in the
address is the latitude and longitude you entered in EzBizi. A page without
that shortcode on it loads nothing from Google.

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

= 1.26.23 =
* NEW: Tells EzBizi whether any published page carries the booking widget.

= 1.26.22 =
* CHANGE: [ezbizi_booking chat="keep"] leaves the chat bubble on its page; the
  ezbizi_booking_hides_chat filter sets it for a whole site. Hidden by default.

= 1.26.21 =
* CHANGE: The green face reads "Share your experience with us".

= 1.26.20 =
* CHANGE: The review page's green face reads "Tap to share it", without naming Google.

= 1.26.19 =
* NEW: /b/<code>, the short booking link, forwards to the site's booking page.

= 1.26.18 =
* CHANGE: The booking widget takes a request for a ZIP with no online times:
  name, phone and address arrive as a lead the office calls back.

= 1.26.17 =
* NEW: The review faces tell EzBizi what a customer did with their review
  link - opened it, which face, the complaint sent, on to Google - for the
  Customer Experience report. Only for a visit that came by a link.

= 1.26.16 =
* FIX: The booking, form and chat widgets print their script in the page
  footer. Content filters on some pages turned its && into &#038;, so typing
  a ZIP in the booking widget did nothing.

= 1.26.15 =
* NEW: The booking widget reads a lead's own link (?l=): the booking lands on
  that lead, and the ZIP, name and phone it already holds are not asked again.
* CHANGE: The booking widget lists first, in its day, the window Scheduling
  routes best; nothing says so to the customer.

= 1.26.14 =
* CHANGE: The review faces' green face goes straight to the Google review
  page, with no step between. A site with no Google review link keeps the
  old path.

= 1.26.13 =
* CHANGE: The review faces' complaint form asks for detail under "What
  happened?", and after it is sent the page apologises and stops: no Google
  review offer by default.

= 1.26.12 =
* FIX: The SEO Helper reads X's own title, description and image, and what
  every network prints; a page whose SEO record fell behind its saved
  description is rebuilt when read; a page that does not exist answers 404
  instead of 403; the social title templates can be corrected.

= 1.26.11 =
* CHANGE: The booking widget shows where you are in the steps (ZIP code, Day,
  Time, Your details) instead of a title bar, a larger ZIP box, what happens
  next under the first step, the windows each day has, and a foot line.

= 1.26.10 =
* CHANGE: No chat bubble on a page with the booking widget or the review
  faces; it sat on the booking card on phones.

= 1.26.9 =
* FIX: The EzBizi form no longer sends the Turnstile token as one of its
  answers.

= 1.26.8 =
* CHANGE: Booking widget steps have no numbers and read as sentences
  ("Please enter your ZIP code"), sit at the top of the fixed card, and a
  ZIP no area serves says so instead of "no openings".

= 1.26.7 =
* CHANGE: The review faces no longer offer the membership; it is sent as
  its own text after a happy review.
* CHANGE: No scrollbar on phones.

= 1.26.6 =
* CHANGE: A short booking step sits centred in the fixed-size card.

= 1.26.5 =
* CHANGE: The booking widget keeps one size through every step; a taller
  step scrolls inside it.

= 1.26.4 =
* NEW: /r/<code> on every site forwards a short review link to the site's
  reviews page.
* CHANGE: No membership offer after a "not so great" review; that
  thank-you is centred. The Google button stays on both paths.

= 1.26.3 =
* NEW: A customer who came by their review link is not asked for their
  name, phone or email on the "not so great" form: it says "Sending as
  <first name>" and EzBizi fills in the rest. Nothing personal is shown.

= 1.26.2 =
* IMPROVED: The review faces are made for a phone. Two full-width buttons
  to tap, with Great first, and one button shape for Continue, the review and
  Join. The membership sits in its own card, and the bottom stays clear of
  the chat bubble, which shows only its icon on that page.

= 1.26.1 =
* FIX: The booking widget keeps its own input and button look on themes
  that style every input dark or every button in capitals.

= 1.26.0 =
* NEW: [ezbizi_booking title="..."], an appointment widget: ZIP, day, arrival
  window, details. Real openings from Scheduling; requests wait in Leads >
  Online Booking for a dispatcher. Robot check and Turnstile box.
* NEW: Every EzBizi and Elementor form asks for a ZIP code when it has no
  ZIP question of its own, so leads can be checked against availability.
* NEW: The review faces show a customer who came by their link their
  membership, or the plans with a one-tap Join.

= 1.25.1 =
* FIX: Cloudflare's Turnstile script loads only on pages with an Elementor
  form, not on every page.

= 1.25.0 =
* NEW: A "verify you are human" box (Cloudflare Turnstile) on every EzBizi
  form, the chat's start, the review faces' form and Elementor forms, on
  domains EzBizi has a Turnstile widget for. The proof of work stays too.

= 1.24.5 =
* FIX: EzBizi's SEO Helper can save the search title and description of pages
  and posts somebody else wrote (the service account may edit them; never
  delete).

= 1.24.4 =
* Every EzBizi form, the review faces' form and the live chat's start now
  carry EzBizi's robot check (a puzzle the visitor's browser solves; nothing to
  click). Elementor forms have carried it since 1.23.1.

= 1.24.3 =
* The review faces tell EzBizi about each review, so a happy customer can be
  thanked. A customer who came by their personal review link is known; anyone
  else is asked for a phone or email first, and can skip it.

= 1.24.2 =
* Each live chat can have its own colour for the Send button, the Start chat
  button and the border of the box being typed in.

= 1.24.1 =
* The live chat asks for a phone number, an email address and a ZIP code,
  each in its own box, before the conversation starts.

= 1.24.0 =
* NEW: the live chat shows who answers. The team's faces until somebody
  replies, then that person's name and photo, their first name on each reply,
  and "Lenin is typing..." while they write.
* NEW: visitors can send a photo, and the window opens at one size that never
  changes (the whole screen on a phone).
* NEW: a friendlier chat button with a face and an online dot, and "Powered by
  EzBizi" under the chat.

= 1.23.3 =
* FIX: on a site connected to EzBizi, an Elementor form whose email step fails
  no longer tells the visitor it failed; the enquiry is in EzBizi Leads.

= 1.23.2 =
* FIX: a second click on Send while the robot check was still working sent
  the enquiry twice; now it sends once.

= 1.23.1 =
* NEW: every Elementor form on the site asks the visitor's browser to solve a
  small puzzle first; a robot posting straight at the form is refused before
  anybody is emailed. Nothing for a person to click.

= 1.23.0 =
* NEW: the contact form takes a photo or document, shows a question only
  when another is answered a certain way, and can stop robots with a
  CAPTCHA that EzBizi checks (nothing for the visitor to click).
* NEW: Contact Details lists more phone numbers, each with its own label, and
  each section can wear a different icon or none.
* NEW: Social Links adds platforms EzBizi does not list, with their own icon.

= 1.22.2 =
* FIX: a search title or description saved from EzBizi's SEO Helper clears
  the site's cached pages too.

= 1.22.1 =
* FIX: a change made in EzBizi shows on the website at once. EzBizi tells the
  site to read it, and the site asks its host to drop the cached pages.

= 1.22.0 =
* NEW: EzBizi's SEO Helper can read and change each page's search title,
  description and focus keyphrase. Your SEO plugin still prints them.

= 1.21.0 =
* NEW: the site's own Elementor forms reach EzBizi as leads too. Their email
  still goes as before; the copy is sent after the visitor's page has
  answered, with the site's token.

= 1.20.6 =
* NEW: a page's own text or a template can name another EzBizi value, such
  as [ezbizi_state_anchor], and it is filled in. Only EzBizi's shortcodes run.

= 1.20.5 =
* NEW: the gallery crops its tiles to a chosen ratio, draws "our work" tiles
  at one height, and takes its corner and page-number colours.

= 1.20.4 =
* NEW: the city list can show group names as headings, and folds towns in
  no group into a panel of their own; a town with several pages is listed
  once; the fold arrows show whatever the theme does to them.
* NEW: the video carousel reads its gap, arrow position, colours and sizes,
  and shows page dots.

= 1.20.3 =
* FIX: social links show each network's own logo; opening hours have a
  clock and a licence a badge; a call button keeps its number on one line.

= 1.20.2 =
* FIX: a contact icon's box grows around the icon, so a large icon or
  padding no longer squeezes it out of sight.

= 1.20.1 =
* FIX: the contact form's consent words can carry links, written
  [words](https://…); a dropdown with no placeholder says "Choose…"; the
  consent and "I'm not a robot" boxes sit beside their words; the required
  mark takes the accent colour.

= 1.20.0 =
* NEW: the live chat takes an agent name and photo, an online dot, its own
  icon, size, distance from the corner, colours and shadow, fades or slides
  in, can wait before showing, show on desktop or phones only, stay off
  chosen pages, and report opens and starts to analytics.
* NEW: the phone box shows either number, written as you choose, and can
  text or WhatsApp; opening hours as a table, list or line, with a 24-hour
  clock and an open-now badge; the map as satellite or terrain with a way
  there; the page title as a heading of your choice.

= 1.19.0 =
* NEW: [ezbizi_services], [ezbizi_reviews], [ezbizi_before_after] and
  [ezbizi_faq], with an Elementor widget each, drawn from lists kept in
  EzBizi under Web Management > Widgets. Reviews show featured ones, or the
  Google reviews EzBizi has; the FAQ carries the markup search engines read.

= 1.18.0 =
* NEW: the contact form takes number, date, time, checkbox, radio and hidden
  questions, a placeholder, help line and width per question, a title and a
  line under it, an "I'm not a robot" box, its own consent wording, and a
  page to go to after sending. Its look takes a preset and per-widget changes.
* NEW: contact details can show the hours, the licence and the second number
  and inbox, with each section's own title, in any order, in a grid or a row.
* NEW: the city list can have a search box, a count per group, bullets and the
  state left off; social links can show chosen networks in order, in their
  brand colours, with a hover effect.
* NEW: [ezbizi_state_name] and [ezbizi_state_anchor] print the state's full
  name, linked to its website.
* NEW: the call to action can show the number, address or link itself.

= 1.17.0 =
* NEW: style options for every widget: colours and hover colours, font,
  size, weight, capitals and spacing, borders, corners, padding, shadow,
  alignment, and showing on desktop or phones only. Set in EzBizi per
  preset, or per widget.
* NEW: the call to action can email, open the review link or any link (with
  tracking added), text, or WhatsApp; choose its icon and side; animate; and
  become a bar or hide on phones.
* FIXED: the review faces' happy face uses the Google review link, and never
  links to the page it is on.

= 1.16.1 =
* FIXED: the store locator's search box no longer offers to search by
  state; states are stored as two letters, so "Ohio" found nothing. The
  state list beside it does that.

= 1.16.0 =
* NEW: [ezbizi_team], [ezbizi_branches] and [ezbizi_store_locator], with an
  Elementor widget each. They draw the team, the branches this website
  answers for, and every location, from lists kept in EzBizi under Web
  Management > Widgets.
* NEW: [ezbizi_street_view], and an Elementor widget, for a page's Street
  View link. Only a Google Maps embed is drawn.
* NEW: EzBizi Gallery and EzBizi Video Carousel Elementor widgets, for a
  page's own pictures and YouTube videos.
* NEW: one widget can change any option of its preset for itself, as a
  shortcode attribute ([ezbizi_social_links size="40"]) or under "This
  widget only" in Elementor. The rest still comes from the preset.

= 1.15.0 =
* NEW: [ezbizi_review_faces], and an Elementor widget for it. A happy and
  a sad face: the happy one sends the visitor to your review link, the sad
  one opens a short form that arrives in EzBizi as a lead instead of on
  Google. Set up in EzBizi under Web Management > Widgets.
* FIXED: the Elementor widgets for [ezbizi_contact_info], [ezbizi_cta] and
  [ezbizi_phone_box] that 1.13.0 described were missing. They are there now.

= 1.14.0 =
* NEW: the contact form and the chat carry a box, unticked, asking
  whether the visitor wants news and offers by email. EzBizi records the
  tick together with the exact words beside it, and only somebody who
  ticked it can be added to a mailing list by an automation.

= 1.13.0 =
* FIXED: a setting changed in EzBizi now reaches your visitors straight
  away. Switching the live chat off left the bubble on the page until the
  site's own page cache expired, because the cached HTML had been built
  while the chat was still on. The plugin now clears that cache whenever
  EzBizi's answer changes, so this applies to every setting and not just
  the chat.
* Three new shortcodes, and an Elementor widget for each:
  [ezbizi_contact_info] for your phone, email and address as one block,
  [ezbizi_cta] for a call-to-action button, a bar along the bottom of the
  page or a panel that opens once a visit, and [ezbizi_phone_box] for your
  number on its own, big enough to tap. All three are set up in EzBizi
  under Web Management > Widgets.

= 1.12.0 =
* Five new shortcodes, and an Elementor widget for each:
  [ezbizi_social_links] for your social and directory links as a row of icons,
  [ezbizi_google_map] for a map of where you are, [ezbizi_hours_table] for your
  opening hours as a table with today marked, [ezbizi_city_list] for the towns
  you have pages for, and [ezbizi_post_title] for the title of the page it sits
  on.
* Each takes the name of a preset — `[ezbizi_social_links preset="footer"]` —
  set up in EzBizi under Web Management > Marketing > Widgets. Sizes and
  spacing are still edited in one place for every site you run. A preset name
  that does not exist shows the default rather than nothing.
* The map needs no Google API key, and no icons, fonts or scripts are loaded
  from anywhere: everything the new widgets draw is in the plugin.
* The hours table falls back to your hours line as written, so a site that
  never used the hours grid is unchanged.
* Supports the new Second phone number, Second email address, Leave-a-review
  link, Latitude and Longitude fields, which appear in EzBizi under Web
  Management.

= 1.11.0 =
* The live chat can now be limited to working hours. Set them in EzBizi
  under Web Management > Widgets, and the bubble disappears from your site
  outside them, so nobody starts a conversation when there is no one to
  answer it. Sites that set no hours are unchanged: the chat stays on all
  the time. The hours are read on your business's clock, not the
  visitor's, and daylight saving is handled for you.

= 1.10.0 =
* Supports the new City link, State link and Website address fields, which
  appear in EzBizi under Web Management. Link fields are now rendered as web
  addresses rather than as text.

= 1.9.1 =
* Fixes two contact forms on the same page occasionally sharing an id, which
  stopped one of them sending.

= 1.9.0 =
* The chat and contact form now disappear as soon as EzBizi stops recognising
  this site's token, rather than a week later. A site that is not connected
  should not be collecting messages.
* Your published values are unaffected, as before: a phone number that is a
  week old is still your phone number.

= 1.8.0 =
* If EzBizi stops recognising this site's token for a week, the chat and
  contact form stop showing rather than collecting messages nobody will
  receive, and an administrator is told why. Your published values keep
  working — a phone number that is a week old is still your phone number.

= 1.7.1 =
* The chat bubble sits higher, so it no longer lands on a sticky bar along the
  bottom of the page.

= 1.7.0 =
* The live chat has been rebuilt. It now opens a proper panel with a coloured
  header and a close button, and asks the visitor for a name and a phone or
  email before the conversation starts, so a chat you miss is still somebody
  you can call back.
* Fixes the chat borrowing your theme's styling — the launcher and the message
  box were taking their colours and shape from the page around them.
* All of its wording is set in EzBizi, under Web Management, Marketing,
  Widgets.

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
