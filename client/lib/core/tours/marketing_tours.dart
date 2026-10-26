import '../tab_tours.dart';

/// Marketing's and Monitoring's sub tabs, each its own walkthrough (Jose,
/// 2026-10-14: "they only cover the first tab of each of the main modules,
/// when it should cover everything, all the sub tabs").
///
/// Keyed as `tourKeyFor` looks them up: the module, then the label of the tab
/// open in each strip on screen, outermost first. Marketing's own strip holds
/// Newsletter, Social Media Management and the child modules drawn there (Web
/// Management), so the panes of Social Media Management sit one level under
/// it. A key with nothing under it ('marketing/Newsletter') is what (?) runs
/// while that tab has no strip of its own on screen: loading, or no pane on
/// the role.
///
/// Nothing here may say EzBizi reads followers, comments or analytics: the
/// Meta review said it does not.
const Map<String, Tour> marketingTours = {
  // ── Social Media Management ──────────────────────────────────────
  'marketing/Social Media Management': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-social-panes',
        title: 'Social Media Management',
        body:
            'Dashboard, Compose, Calendar, Accounts and QR Codes, as your role '
            'allows.',
      ),
      TourStep(
        anchor: 'tour-marketing-social-none',
        title: 'Nothing open to you',
        body: 'None of these tabs is on your role yet.',
      ),
    ],
  ),
  'marketing/Social Media Management/Dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-social-tiles',
        title: 'Scheduled, Sent, Failed',
        body: 'Each narrows the timeline, with how many are in it.',
      ),
      TourStep(
        anchor: 'social-posts-fact-pages',
        title: 'Pages',
        body: 'How many pages are connected here, and whether they can post.',
      ),
      TourStep(
        anchor: 'tour-marketing-timeline',
        title: 'Timeline',
        body:
            "What is coming up, then each day's posts with each account's "
            'answer. Click one for its links, or Retry one that failed.',
      ),
      TourStep(
        anchor: 'tour-marketing-new-post',
        title: 'New post',
        body: 'Opens Compose to write one.',
      ),
      TourStep(
        anchor: 'tour-marketing-social-accounts-card',
        title: 'Accounts',
        body:
            'Each connected page and whether it can post. Connect adds another.',
      ),
    ],
  ),
  'marketing/Social Media Management/Compose': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-compose-form',
        title: 'New post',
        body:
            "Tick the accounts to post to and write the words; a count under "
            "them shows each network's limit. Attach a picture or video, or "
            'drag one in.',
      ),
      TourStep(
        anchor: 'tour-marketing-compose-when',
        title: 'Now or later',
        body: "Later picks a day and a time, on this view's clock.",
      ),
      TourStep(
        anchor: 'tour-marketing-compose-preview',
        title: 'How it will look',
        body:
            'The post drawn as each ticked network shows it. A close likeness: '
            'each network has the last word.',
      ),
      TourStep(
        anchor: 'tour-marketing-compose-bar',
        title: 'Publish',
        body:
            'Where it is going and when, beside Publish now or Schedule. When '
            'the button is greyed, hover over it or long press it to see why.',
      ),
      TourStep(
        anchor: 'tour-marketing-compose-outcome',
        title: 'What happened',
        body:
            "Each account's answer as it comes in, with Retry on one that "
            'failed. Write another starts a new post.',
      ),
    ],
  ),
  'marketing/Social Media Management/Calendar': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-week',
        title: 'This week',
        body: "This week's posts, day by day, for the pages this view can see.",
      ),
      TourStep(
        anchor: 'tour-marketing-month',
        title: 'The month',
        body:
            'Every post on its day: green went out, blue is scheduled, red '
            'failed. The arrows move a month; click a post to open it.',
      ),
      TourStep(
        anchor: 'tour-marketing-agenda',
        title: 'Your posts',
        body:
            'Today, the days ahead, then earlier ones. Tap a post to open it.',
      ),
    ],
  ),
  'marketing/Social Media Management/Accounts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-accounts',
        title: 'Accounts',
        body:
            'The pages connected in this view, each with Disconnect. A '
            'connection is made at the view you are in, and views side by side '
            'do not share it.',
      ),
      TourStep(
        anchor: 'tour-marketing-accounts-connect',
        title: 'Connect a page',
        body: 'Connects another page or account to this view.',
      ),
      TourStep(
        anchor: 'tour-marketing-accounts-catalogue',
        title: 'Catalogue',
        body:
            'The networks not connected here yet, and whether each is ready. '
            'One that is not says why.',
      ),
    ],
  ),
  'marketing/Social Media Management/QR Codes': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-qr-header',
        title: 'QR codes',
        body:
            'Print a code once. Change where it sends people here, any time, '
            'without reprinting.',
      ),
      TourStep(
        anchor: 'qr-new',
        title: 'New QR code',
        body: 'Name it, say where it goes, and make the code.',
      ),
      TourStep(
        anchor: 'tour-marketing-qr-list',
        title: 'Your codes',
        body:
            'Each code with where it goes, its scans, its SVG and PNG downloads '
            "and Stats. Deactivate, in a card's menu, pauses every printed copy "
            'until you reactivate it.',
      ),
      TourStep(
        anchor: 'tour-marketing-qr-empty',
        title: 'No codes yet',
        body: 'Make one for a van, a sign or your cards.',
      ),
    ],
  ),

  // ── Newsletter ───────────────────────────────────────────────────
  'marketing/Newsletter': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-tabs',
        title: 'Newsletter',
        body:
            "Mailings written here and sent through your company's own mailing "
            'account.',
      ),
      TourStep(
        anchor: 'tour-marketing-newsletter-panes',
        title: 'Its tabs',
        body: 'Dashboard, Composer and Settings, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-newsletter-none',
        title: 'Nothing open to you',
        body: "None of Newsletter's tabs is on your role yet.",
      ),
    ],
  ),
  'marketing/Newsletter/Dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-status-tiles',
        title: 'Sent, Scheduled, Drafts',
        body: 'Each narrows the table, with how many are in it.',
      ),
      TourStep(
        anchor: 'newsletters-fact-reached',
        title: 'Addresses reached',
        body:
            'How many addresses the mailing account accepted. Last one went out '
            'says when the newest was sent.',
      ),
      TourStep(
        anchor: 'tour-marketing-new-newsletter',
        title: 'New newsletter',
        body: 'Starts one and opens it in the Composer.',
      ),
      TourStep(
        anchor: 'tour-newsletter-table',
        title: 'Every newsletter',
        body:
            'Its status, mailing list, when it went or goes, and how many it '
            'reached. Click one to open it in the Composer.',
      ),
      TourStep(
        anchor: 'tour-newsletter-calendar',
        title: 'Scheduled',
        body:
            'What is booked to go out this month. Click one to edit it before '
            'it goes.',
      ),
      TourStep(
        anchor: 'tour-newsletter-empty',
        title: 'Nothing here',
        body: 'No newsletters yet, or none under the tile you picked.',
      ),
    ],
  ),
  'marketing/Newsletter/Composer': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-newsletter-pick',
        title: 'Which newsletter',
        body:
            'Pick the one to work on by its subject; each says whether it is a '
            'draft, scheduled or sent. New starts another.',
      ),
      TourStep(
        anchor: 'tour-newsletter-delete',
        title: 'Delete',
        body: 'Deletes the open newsletter. Tap it twice.',
      ),
      TourStep(
        anchor: 'tour-newsletter-blocks',
        title: 'Add a block',
        body:
            'A heading, text, an image, a button, columns, a divider or a '
            'spacer, under the block you picked. Or write the HTML yourself.',
      ),
      TourStep(
        anchor: 'tour-newsletter-email',
        title: 'What they will get',
        body:
            'The email itself: click a block to change it where it sits. Server '
            'preview shows the exact email that goes out.',
      ),
      TourStep(
        anchor: 'tour-newsletter-details',
        title: 'Subject, list, when',
        body:
            'The subject line, which mailing list gets it, and now or later. It '
            'saves as you type.',
      ),
      TourStep(
        anchor: 'tour-newsletter-send',
        title: 'Send',
        body:
            'Asks once more before it goes: a newsletter cannot be unsent. When '
            'it is greyed, hover over it to see why.',
      ),
      TourStep(
        anchor: 'tour-newsletter-composer-empty',
        title: 'Nothing open',
        body:
            "A newsletter is written here and sent through this company's "
            'mailing account. Nothing goes out until somebody presses Send.',
      ),
    ],
  ),
  'marketing/Newsletter/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-newsletter-mailing-account',
        title: 'Mailing account',
        body:
            'Newsletters go out through your own mailing account. EzBizi writes '
            'them; the account sends them.',
      ),
      TourStep(
        anchor: 'tour-newsletter-mailchimp-key',
        title: 'Connect Mailchimp',
        body: 'Paste the API key from your Mailchimp account, then Connect.',
      ),
    ],
  ),

  // ── Web Management ───────────────────────────────────────────────
  'marketing/Web Management': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-tabs',
        title: 'Web Management',
        body: 'Your websites: what they publish, and the widgets on them.',
      ),
      TourStep(
        anchor: 'tour-web_management-none',
        title: 'Nothing open to you',
        body:
            'SEO and Widgets are granted one at a time, and neither is on your '
            'role. Somebody who edits roles adds them.',
      ),
    ],
  ),
  // Also what runs over the hours editor, whose strip names websites.
  'marketing/Web Management/SEO': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-web_management-tabs',
        title: 'SEO',
        body:
            'What each of your websites publishes: its phone number, address, '
            'hours and the rest.',
      ),
      TourStep(
        anchor: 'hours-bulk-select-all',
        title: 'Which websites',
        body: 'Tick the websites whose hours change, or select them all.',
      ),
      TourStep(
        anchor: 'hours-bulk-zone',
        title: 'Convert hours from',
        body:
            'Type the hours in one time zone, and each website converts them '
            'into its own.',
      ),
      TourStep(
        anchor: 'hours-bulk-review',
        title: 'Review changes',
        body:
            "Reads every website's hours and shows what would change. Nothing "
            'is written yet.',
      ),
      TourStep(
        anchor: 'hours-bulk-apply',
        title: 'Update',
        body:
            'Writes the reviewed hours to the websites that change. A review is '
            'good for 30 minutes.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/General': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-web_management-sites',
        title: 'Your websites',
        body:
            'Every website and subdomain, connected or waiting for the plugin. '
            'A number beside one counts its General boxes not filled in.',
      ),
      TourStep(
        anchor: 'tour-web_management-add-site',
        title: 'Add a website',
        body: 'Adds a website or subdomain and gives it a plugin token.',
      ),
      TourStep(
        anchor: 'tour-seo-site-detail',
        title: 'What it publishes',
        body:
            'Each box is something this website shows, with its shortcode to '
            'copy. A grey value comes from the primary site; an amber box '
            'publishes nothing yet.',
      ),
      TourStep(
        anchor: 'seo-branch',
        title: 'Branch',
        body: 'Where chats, forms and reviews from this website land in Leads.',
      ),
      TourStep(
        anchor: 'seo-bulk-hours',
        title: 'Hours on several websites',
        body: 'Changes the hours of many websites at once.',
      ),
      TourStep(
        anchor: 'tour-seo-guide',
        title: 'Connection guide',
        body:
            "How to install the EzBizi WordPress plugin and paste this "
            "website's token. Browser snippet is for a website that is not "
            'WordPress.',
      ),
      TourStep(
        anchor: 'tour-seo-save',
        title: 'Save',
        body:
            "Saves this website's details. Make primary, Remove website and "
            'Issue a new token sit beside it.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/Social links': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-web_management-sites',
        title: 'Your websites',
        body: 'Pick the website whose social links you are filling in.',
      ),
      TourStep(
        anchor: 'tour-seo-site-detail',
        title: 'Social links',
        body:
            "The full link to the company's page on Facebook, Instagram, Yelp "
            'and the rest, each with its shortcode. Every one is optional.',
      ),
      TourStep(
        anchor: 'tour-seo-save',
        title: 'Save',
        body: "Saves this website's links.",
      ),
    ],
  ),
  'marketing/Web Management/SEO/Pages': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-seo-content-search',
        title: 'Find a page',
        body: "Searches this website's pages when you press Enter.",
      ),
      TourStep(
        anchor: 'tour-seo-content-list',
        title: 'Its pages',
        body:
            'Read live from the website. Click one to fill in what EzBizi '
            'publishes on it; the arrow opens it on the website.',
      ),
      TourStep(
        anchor: 'tour-seo-page-editor',
        title: 'This page',
        body:
            "Leave a box empty and the page publishes the website's answer. "
            'Each text field becomes a shortcode to paste into the page.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/Cities': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-seo-cities-search',
        title: 'Find a city',
        body: 'Narrows the city pages as you type.',
      ),
      TourStep(
        anchor: 'tour-seo-cities-scan',
        title: 'Look on the website',
        body:
            "Finds the website's city pages EzBizi has nothing for yet. They "
            'show as nothing yet.',
      ),
      TourStep(
        anchor: 'tour-seo-cities-list',
        title: 'City pages',
        body:
            'In groups, with how many in each; click a group to open it. Click '
            'a city to fill in what EzBizi publishes on its page.',
      ),
      TourStep(
        anchor: 'tour-seo-page-editor',
        title: 'This city page',
        body: "Leave a box empty and the page publishes the website's answer.",
      ),
    ],
  ),
  'marketing/Web Management/SEO/Posts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-seo-content-search',
        title: 'Find a post',
        body: "Searches this website's posts when you press Enter.",
      ),
      TourStep(
        anchor: 'tour-seo-content-list',
        title: 'Its posts',
        body:
            'Read live from the website. Click one to fill in what EzBizi '
            'publishes on it; the arrow opens it on the website.',
      ),
      TourStep(
        anchor: 'tour-seo-page-editor',
        title: 'This post',
        body:
            "Leave a box empty and the post publishes the website's answer. "
            'Each text field becomes a shortcode to paste into the post.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/Templates': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-seo-templates',
        title: 'Templates',
        body:
            'Text you write once and use on as many pages as you like. Change '
            'it here and it changes everywhere.',
      ),
      TourStep(
        anchor: 'tour-seo-templates-add',
        title: 'Add a template',
        body:
            'A new box with its own shortcode. Click the shortcode to copy it, '
            'then paste it into any page.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/SEO Helper': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-seo-helper-health',
        title: 'Site health',
        body:
            'How many pages are good, could be better or need work. Each tile '
            'narrows the list to its pages.',
      ),
      TourStep(
        anchor: 'seo-read-again',
        title: 'Read again',
        body: 'Reads the website again.',
      ),
      TourStep(
        anchor: 'seo-find',
        title: 'Find a page',
        body:
            'Narrows the pages as you type. Click a page to edit its title, '
            'description and keyphrase.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/SEO Helper/SEO': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'seo-keyphrase',
        title: 'Focus keyphrase',
        body: 'What somebody types into Google to find this page.',
      ),
      TourStep(
        anchor: 'seo-title',
        title: 'SEO title',
        body:
            "The title Google shows; the bar measures it against Google's 600 "
            'pixels. Insert adds the page title, site name and the rest.',
      ),
      TourStep(
        anchor: 'seo-description',
        title: 'Meta description',
        body: "The words under the title in Google's results.",
      ),
      TourStep(
        anchor: 'seo-serp',
        title: 'Google preview',
        body: 'How the page could look in Google, on a desktop or a phone.',
      ),
      TourStep(
        anchor: 'seo-check-group',
        title: 'SEO checks',
        body:
            'Each check on this page, worst first. One shared with another page '
            'names it; click to open that page.',
      ),
      TourStep(
        anchor: 'seo-save',
        title: 'Save',
        body:
            'Writes your changes to the website. Until then the checks run on '
            'what you type.',
      ),
    ],
  ),
  'marketing/Web Management/SEO/SEO Helper/Readability': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'seo-check-group',
        title: 'Readability',
        body:
            'Checks on how this page reads, worst first. SEO beside it has the '
            'search checks.',
      ),
      TourStep(
        anchor: 'seo-serp',
        title: 'Google preview',
        body: 'How the page could look in Google, on a desktop or a phone.',
      ),
      TourStep(
        anchor: 'seo-save',
        title: 'Save',
        body: 'Writes your changes to the website.',
      ),
    ],
  ),
  'marketing/Web Management/Widgets': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-widgets-sites',
        title: 'Your websites',
        body: 'Pick the website whose widgets you are dressing.',
      ),
      TourStep(
        anchor: 'widgets-cards',
        title: 'The widgets',
        body:
            'Live chat, Contact form and the rest, each saying what it is set '
            'to now. Open one to change it.',
      ),
      TourStep(
        anchor: 'widgets-editor',
        title: 'One widget',
        body:
            'Its settings; the back arrow returns to the cards. A website '
            'following the primary answers for itself once you change something.',
      ),
      TourStep(
        anchor: 'tour-widgets-preview',
        title: 'Preview',
        body:
            'Your colours, wording and questions as set. The typeface and the '
            'page around them come from your own website.',
      ),
      TourStep(
        anchor: 'tour-widgets-save',
        title: 'Save',
        body: 'Saves every widget on this website together.',
      ),
      TourStep(
        anchor: 'tour-widgets-rebuild',
        title: 'Rebuild',
        body:
            'For a page gone stale: asks the website, or all of the '
            "company's websites, to rebuild their pages within a few minutes.",
      ),
      TourStep(
        anchor: 'tour-widgets-no-sites',
        title: 'No websites yet',
        body: 'Register a website under SEO first.',
      ),
    ],
  ),

  // ── Monitoring ───────────────────────────────────────────────────
  'monitoring/General': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-monitoring-ownership',
        title: 'Who sits where',
        body:
            'Each person with their role, desk, status and office, and their PC '
            'and hours when your role allows. With the right to edit the Map, '
            'tap a desk to rename it.',
      ),
      TourStep(
        anchor: 'tour-monitoring-ownership-empty',
        title: 'Nobody yet',
        body:
            'People appear here once they can be seen from your level of the '
            'business.',
      ),
    ],
  ),
  'monitoring/Map': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'floor-plan-picker',
        title: 'Floor plan',
        body: 'Which floor plan you are looking at.',
      ),
      TourStep(
        anchor: 'tour-monitoring-map-empty',
        title: 'No floor plans yet',
        body: 'Somebody who can edit the Map adds the first one.',
      ),
      TourStep(
        anchor: 'floor-plan-canvas',
        title: 'The plan',
        body:
            'Each computer where it sits: green online, red offline, grey with '
            'nobody on it. Click one to read about it.',
      ),
      TourStep(
        anchor: 'floor-plan-summary',
        title: 'Summary',
        body:
            "The plan's computers, online, offline and unassigned, or the one "
            'you clicked.',
      ),
      TourStep(
        anchor: 'general-refresh',
        title: 'Refresh status',
        body: 'Reads every status again. It also refreshes every 30 seconds.',
      ),
      TourStep(
        anchor: 'general-edit-toggle',
        title: 'Edit map',
        body:
            'Add, move and delete computers, assign people and draw walls. Save '
            'keeps the changes.',
      ),
      TourStep(
        anchor: 'general-add-plan',
        title: 'New floor plan',
        body:
            'A plan belongs to the organization, one company or one franchise; '
            'you choose when you name it.',
      ),
    ],
  ),
  'monitoring/PC Specs': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'monitoring-machine-search',
        title: 'Find a machine',
        body: 'Searches machines, people and the window open on them.',
      ),
      TourStep(
        anchor: 'tour-monitoring-machines',
        title: 'Machines',
        body:
            "Each person's machine, online or not, with CPU, RAM and disk when "
            'the desktop app reports them. Click one for its details.',
      ),
      TourStep(
        anchor: 'tour-monitoring-glance',
        title: 'At a glance',
        body:
            'How many people are online, any device low on disk, and the '
            'machines running the desktop app.',
      ),
      TourStep(
        anchor: 'tour-monitoring-machine-detail',
        title: 'One machine',
        body:
            'Its system, network, security, activity and what is installed. '
            'Show everyone goes back.',
      ),
      TourStep(
        anchor: 'tour-monitoring-machines-empty',
        title: 'Nothing yet',
        body: 'No devices have reported in yet.',
      ),
    ],
  ),
  'monitoring/Files': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'files-device-rail',
        title: 'Computers',
        body:
            'Pick a computer to browse its files. A filled dot means it is '
            'online.',
      ),
      TourStep(
        anchor: 'files-device-picker',
        title: 'Computer',
        body: 'The computer you are browsing; pick another here.',
      ),
      TourStep(
        anchor: 'files-lock-card',
        title: 'Unlock Files',
        body:
            'Enter the 6-digit code from your authenticator app. Files locks '
            'after 15 minutes without use.',
      ),
      TourStep(
        anchor: 'tour-files-quick',
        title: 'Quick access',
        body: 'Jump to a quick-access folder, or to a drive under This PC.',
      ),
      TourStep(
        anchor: 'files-listing',
        title: 'The files',
        body:
            'Open a folder, or click a file to see it and download it. Each '
            'file you open is logged under your name.',
      ),
      TourStep(
        anchor: 'files-downloads-tray',
        title: 'Downloads',
        body: 'What you have downloaded here.',
      ),
      TourStep(
        anchor: 'files-lock-now',
        title: 'Lock now',
        body: 'Locks Files before the 15 minutes are up.',
      ),
    ],
  ),
  'monitoring/Camera Monitoring': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'camera-monitoring-grid',
        title: 'Cameras',
        body:
            'Every connected camera. Click a picture to watch it live beside '
            'the grid; a stream the app cannot play gives you its link.',
      ),
      TourStep(
        anchor: 'camera-monitoring-add-button',
        title: 'Add a camera',
        body: 'Give it a label, its kind of stream and its address.',
      ),
      TourStep(
        anchor: 'tour-monitoring-cameras-empty',
        title: 'No cameras yet',
        body: 'Nothing is connected yet.',
      ),
    ],
  ),
  'monitoring/Desktop Monitoring': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-monitoring-tabs',
        title: 'Desktop Monitoring',
        body: 'Screen captures from computers running the EzBizi desktop app.',
      ),
      TourStep(
        anchor: 'desktop-monitoring-picker',
        title: 'People',
        body: 'Pick a person whose computer runs the desktop app.',
      ),
      TourStep(
        anchor: 'desktop-monitoring-hero',
        title: 'Latest capture',
        body:
            'Their newest capture, or the live screen. It says so when capture '
            'has stopped or the computer is offline.',
      ),
      TourStep(
        anchor: 'desktop-monitoring-filmstrip',
        title: 'Earlier captures',
        body: 'Click one to see it large.',
      ),
      TourStep(
        anchor: 'desktop-monitoring-live-toggle',
        title: 'Go live',
        body: 'Watches the screen as it is now; Stop ends it.',
      ),
      TourStep(
        anchor: 'desktop-monitoring-control-toggle',
        title: 'Take control',
        body:
            'Your mouse and keyboard drive the computer; Release hands it back.',
      ),
    ],
  ),
  'monitoring/Lighting': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-lighting-bulk',
        title: 'Set many at once',
        body:
            'Pick all PCs in view or the ticked ones, a mode, a colour and a '
            'brightness, then Apply.',
      ),
      TourStep(
        anchor: 'lighting-search',
        title: 'Find a PC',
        body:
            'Searches PCs and people; the chips show Ready or the ones that '
            'need attention.',
      ),
      TourStep(
        anchor: 'tour-lighting-list',
        title: 'PCs',
        body:
            'Each PC with its lighting now, who set it and whether it is '
            'ready. Only Ready PCs can be ticked. Click one to set it alone.',
      ),
      TourStep(
        anchor: 'tour-lighting-pane',
        title: 'One PC',
        body: 'The same controls for this PC, and the lights it found.',
      ),
      TourStep(
        anchor: 'tour-lighting-empty',
        title: 'No PCs yet',
        body: 'PCs running the desktop app show here.',
      ),
    ],
  ),
};
