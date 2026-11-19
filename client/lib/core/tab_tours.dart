import 'tours/admin_tours.dart';
import 'tours/assist_tours.dart';
import 'tours/leads_tours.dart';
import 'tours/marketing_tours.dart';
import 'tours/operations_tours.dart';
import 'tours/phone_tours.dart';
import 'tours/reports_tours.dart';

/// Each tab's walkthrough (UI queue 81; Jose: "as u recommend" — 1 and 4).
///
/// Data, not code: a tab's owner adds or rewrites its steps here and the
/// engine in `widgets/tab_help.dart` does the rest. A step points at the
/// widget carrying `ValueKey<String>(anchor)`. A step whose widget is not on
/// screen (a role without it, a module switched off) is skipped, so a tour
/// never points at nothing.
///
/// Raise [Tour.version] when steps are added, and mark the new ones with
/// [TourStep.since]: somebody who saw the old version is offered only those,
/// as "What's new here".
class TourStep {
  const TourStep({
    required this.anchor,
    required this.title,
    required this.body,
    this.since = 1,
  });

  final String anchor;
  final String title;

  /// One or two sentences: what the part is for, in the person's words.
  final String body;

  /// The [Tour.version] that added this step.
  final int since;
}

class Tour {
  const Tour({required this.version, required this.steps});

  final int version;
  final List<TourStep> steps;
}

/// By module key, as `module_screens.dart` keys its screens, then each sub
/// tab's as `module/Tab` (see `tourKeyFor`).
const Map<String, Tour> tabTours = {
  'tasks': Tour(
    // 2: Task Board v2 (UI queue 84) - the picker, columns, statuses.
    // 3: design 178 - Calendar, Timeline, Formula, number formats, footers,
    // pinned headings, branch tasks on the company board.
    // 4: the sidebar's names (11-18 staff memo): Personal Task Board, Team
    // boards, All markets.
    version: 4,
    steps: [
      TourStep(
        anchor: 'tasks-boards',
        title: 'Your boards',
        since: 4,
        body:
            'Pick a board here. Your Personal Task Board is yours alone; '
            'Team boards are the boards shared with you. Under All markets, '
            "each company's board shows every one of its markets' tasks "
            'together, each marked with its market.',
      ),
      TourStep(
        anchor: 'tasks-view',
        title: 'Table, Kanban, Calendar or Timeline',
        since: 3,
        body:
            'The same tasks four ways: a table by group, a column for each '
            'status, a calendar by due date (drag a task to another day), or '
            'a timeline of bars you can drag to move or stretch.',
      ),
      TourStep(
        anchor: 'tasks-search',
        title: 'Find a task',
        body:
            'Searches the task, who owns it, its status and what it is about.',
      ),
      TourStep(
        anchor: 'tasks-groups',
        title: 'Groups and tasks',
        since: 3,
        body:
            'Click a group to fold it, and Add task at its foot to add one '
            "there. Click a status to change it, ▸ for a task's subtasks, or "
            "a task to open it. A group's name and column headings stay at "
            'the top while you scroll, and its footer totals number columns.',
      ),
      TourStep(
        anchor: 'column-add',
        title: 'Your own columns',
        since: 3,
        body:
            'Add a column of text, numbers, a status, a date, a person, a '
            'dropdown, a checkbox, a timeline (from one date to another) or a '
            "formula worked out from other columns. Click a column's name to "
            'change it: show numbers as money or %, and pick a sum or average '
            'for the footer.',
      ),
      TourStep(
        anchor: 'tasks-statuses',
        title: 'Your own statuses',
        since: 2,
        body:
            'Name the statuses this board uses, give each a colour, and mark '
            'which count as done.',
      ),
    ],
  ),
  'leads': Tour(
    // 2: charge, IVR, dispatcher, dates and Edit (UI queue 103).
    // 3: no Unread chip; the phone's one Filters button (2026-11-03).
    version: 3,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Your sources',
        body:
            'All first, then each source with how many are unread. A red mark '
            'means that source has stopped delivering.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Your sources',
        body: 'All, or one source at a time, with how many are unread.',
      ),
      TourStep(
        anchor: 'tour-leads-search',
        title: 'Find a lead',
        body: 'Searches names, phone numbers and messages as you type.',
      ),
      TourStep(
        anchor: 'tour-leads-status',
        title: 'Status',
        body: 'New, Booked, Archived, Completed, Lost and the rest.',
      ),
      TourStep(
        anchor: 'tour-leads-filters',
        title: 'Quick filters',
        body:
            'Unreplied, Overdue, Follow up and Starred narrow the list '
            'together. Mark all read sits at the end. On a phone they open '
            'from the one Filters button, beside search.',
      ),
      TourStep(
        anchor: 'tour-leads-more-filters',
        since: 2,
        title: 'Charge, IVR, dispatcher, dates',
        body:
            'Narrow the list by what Google charged, which phone line the '
            'call came in on, who answered it, and when.',
      ),
      TourStep(
        anchor: 'lead-edit',
        since: 2,
        title: 'Edit',
        body:
            "Fix the name, ZIP, classification or dispatcher, ask AI to "
            "classify it again, and see every call from this number.",
      ),
      TourStep(
        anchor: 'leads-fetch',
        title: 'Fetch new leads',
        body: 'Asks every source now instead of waiting for the next check.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The list',
        body:
            'Every source in one list. A dot and bold text mean unread; the '
            'star keeps one to hand.',
      ),
      TourStep(
        anchor: 'thread-header',
        title: 'The conversation',
        body:
            'The open lead, beside the list: change its status, Book it into '
            'a customer and a job, or reply below.',
      ),
      TourStep(
        anchor: 'sources-manage',
        title: 'Manage sources',
        body: 'Where advertising and lead accounts are connected.',
      ),
    ],
  ),
  'jobs': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-jobs-tabs',
        title: 'Jobs and Estimates',
        body: 'Both stay open as you switch, so nothing half-typed is lost.',
      ),
      TourStep(
        anchor: 'tour-jobs-add',
        title: 'Add a job',
        body: 'Opens a new job beside the list.',
      ),
      TourStep(
        anchor: 'tour-jobs-search',
        title: 'Find a job',
        body: 'By job number, title or customer; press Enter to search.',
      ),
      TourStep(
        anchor: 'tour-jobs-status',
        title: 'Status',
        body:
            'Each chip narrows the list, with how many are in it, and doubles '
            'as the legend for the coloured pills.',
      ),
      TourStep(
        anchor: 'tour-jobs-money',
        title: 'Money',
        body:
            'Billed, paid, due and overdue. Click one to show only those jobs.',
      ),
      TourStep(
        anchor: 'jobs-filters',
        title: 'Filters',
        body: 'Unpaid, partly paid, credit, where, role, type and tags.',
      ),
      TourStep(
        anchor: 'jobs-window',
        title: 'When',
        body: 'Upcoming, Past or All.',
      ),
      TourStep(
        anchor: 'tour-jobs-list',
        title: 'The jobs',
        body: 'Grouped by day. Click one to open it beside the list.',
      ),
    ],
  ),
  'customers': Tour(
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-customers-tabs',
        title: 'Customers and Memberships',
        body: 'Your customers, and the membership program beside them.',
      ),
      TourStep(
        anchor: 'tour-customers-add',
        title: 'Add a customer',
        body:
            'If the new one looks like someone already here, it says so; it '
            'never merges them.',
      ),
      TourStep(
        anchor: 'tour-customers-duplicates',
        title: 'Possible duplicates',
        body: 'Customers that look alike across the organization, to check.',
      ),
      TourStep(
        anchor: 'data-transfer',
        title: 'Import and export',
        body: 'Customers in and out as a CSV file.',
      ),
      TourStep(
        anchor: 'tour-customers-segments',
        title: 'Who to show',
        body: 'All, repeat customers, or those with no jobs yet.',
      ),
      TourStep(
        anchor: 'tour-customers-search',
        title: 'Find a customer',
        body:
            'By name, email, phone or number. People who are still only '
            'leads show up too, on rows of their own.',
        since: 2,
      ),
      TourStep(
        anchor: 'tour-customers-franchise',
        title: 'Franchise',
        body: 'Narrows the list to one franchise.',
      ),
      TourStep(
        anchor: 'customers-tag-filter',
        title: 'Tag',
        body:
            'Only the customers with one tag. Appears once a customer is tagged.',
        since: 2,
      ),
      TourStep(
        anchor: 'tour-customers-list',
        title: 'The customers',
        body:
            'Click a heading to sort, or a customer to open them beside the '
            'list. Each row says whose customer they are and shows their '
            'tags. Open one to add or remove tags, see their Money (what '
            'was invoiced, is due and was paid; \$0 when nothing has been '
            'billed yet) and the notes on their jobs.',
        since: 2,
      ),
    ],
  ),
  'scheduling': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-tabs',
        title: 'Scheduling',
        body:
            'Availability, the Schedule, Overbooking, Locations and Settings, '
            'as your role allows.',
      ),
      TourStep(
        anchor: 'tour-scheduling-modes',
        title: 'Three ways to look',
        body:
            'By zip or address, by area to see every area in a franchise, or '
            'a month on the calendar.',
      ),
      TourStep(
        anchor: 'availability-search',
        title: 'Zip or address',
        body: 'Type a zip, an address, a city or an area name.',
      ),
      TourStep(
        anchor: 'tour-scheduling-check',
        title: 'Check',
        body:
            'Shows the first open day and its windows: green has 3 or more '
            'spots, yellow 2, red 1, grey is full.',
      ),
    ],
  ),
  'dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-dashboard-period',
        title: 'The period',
        body: 'Day, week, month, quarter or year, for every box at once.',
      ),
      TourStep(
        anchor: 'tour-dashboard-customize',
        title: 'Customize',
        body:
            'Drag the boxes into your own order, pick each one\'s size, or hide it.',
      ),
      TourStep(
        anchor: 'tour-dashboard-refresh',
        title: 'Refresh',
        body: 'Reloads every box now.',
      ),
      TourStep(
        anchor: 'tour-dashboard-boxes',
        title: 'Your boxes',
        body:
            'One box for each part of EzBizi you can open, grouped as the '
            'sidebar is. A box\'s menu can show it as a chart or as words.',
      ),
    ],
  ),
  'work_hours': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-work_hours-tabs',
        title: 'Work Hours',
        body:
            'General, Paid Time Off, Reports and Settings, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-work_hours-week',
        title: 'The week',
        body:
            'Which week you are looking at; the arrows move a week either way.',
      ),
      TourStep(
        anchor: 'tour-work_hours-search',
        title: 'Find someone',
        body: 'Narrows the list to people whose name matches.',
      ),
      TourStep(
        anchor: 'tour-work_hours-role',
        title: 'Role',
        body: 'Shows one role at a time.',
      ),
      TourStep(
        anchor: 'tour-work_hours-people',
        title: 'The people',
        body:
            'Each person with their total for the week. A green dot means on '
            'the clock right now. Click one for their week, day by day.',
      ),
      TourStep(
        anchor: 'tour-work_hours-everyone',
        title: 'Everyone',
        body:
            'Only people who clocked in that week are listed until you ask for everyone.',
      ),
      TourStep(
        anchor: 'correction-requests',
        title: 'Corrections',
        body:
            'Changes people asked for that wait on you, to approve or decline.',
      ),
    ],
  ),
  'phone': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-phone-tabs',
        title: 'Communication',
        body:
            'Phone, Users, Records, Reports, Alerts and Settings, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-phone-sections',
        title: 'Dial, Team, Messages, Voicemail',
        body: 'The phone itself, your team, texts, and the voicemail boxes.',
      ),
      TourStep(
        anchor: 'phone-call-history',
        title: 'Recent calls',
        body:
            'Grouped by day, with names. Clicking one puts the number on the '
            'pad; it never dials by itself.',
      ),
      TourStep(
        anchor: 'phone-open-keypad',
        title: 'Keypad',
        body: 'Opens the dial pad.',
      ),
      TourStep(
        anchor: 'tour-phone-calling-as',
        title: 'Calling as',
        body:
            'Whether you are ready to take calls, and the line you call from.',
      ),
      TourStep(
        anchor: 'tour-phone-number',
        title: 'Name or number',
        body: 'Type a number, or letters to find a customer or a teammate.',
      ),
      TourStep(
        anchor: 'phone-call-pad',
        title: 'Dial pad',
        body: 'The keys, then Call.',
      ),
    ],
  ),
  'email': Tour(
    // 2: the inbox laid out like Gmail (design 137).
    // 3: the All tab, the full-screen reader and attachments (2026-11-03).
    version: 3,
    steps: [
      TourStep(
        anchor: 'tour-email-tabs',
        title: 'Email',
        body: 'The inbox, and its settings.',
      ),
      TourStep(
        anchor: 'tour-email-compose',
        title: 'Compose',
        body: 'Writes from the inbox you have open, or asks which one.',
      ),
      TourStep(
        anchor: 'tour-email-inboxes',
        title: 'Folders and inboxes',
        body:
            'Inbox, Starred, Snoozed and the rest, then your inboxes grouped '
            'by company with how many are unread.',
        since: 2,
      ),
      TourStep(
        anchor: 'tour-email-search',
        title: 'Search mail',
        body: 'Type to search; the × clears it.',
      ),
      TourStep(
        anchor: 'tour-email-categories',
        title: 'All, Primary, Leads & customers, Notifications',
        body:
            'All is every message, newest first. The others narrow it; mail '
            'from your customers and leads has its own tab.',
        since: 3,
      ),
      TourStep(
        anchor: 'tour-email-toolbar',
        title: 'Select and act',
        body: 'Tick rows to archive, mark read, snooze or delete them.',
        since: 2,
      ),
      TourStep(
        anchor: 'tour-email-list',
        title: 'The messages',
        body:
            'One line per conversation, tagged with the inboxes it reached. '
            'Click one to read it (on a phone it fills the screen); Older › '
            'pages back. An attachment opens or saves from its icon.',
        since: 3,
      ),
    ],
  ),
  'financials': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-financials-tabs',
        title: 'Financials',
        body:
            'Your bases of tables, and the settings that say which Airtable table is which.',
      ),
      TourStep(
        anchor: 'tour-financials-views',
        title: 'Views',
        body:
            'Grid, kanban, calendar, gallery, form and split. Filters and sorts save on the view.',
      ),
    ],
  ),
  'financials/Bills': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-financials-bills',
        title: 'Bills',
        body:
            "Read a photo of a period's bills into lines. It saves once they add up to the photo's total.",
      ),
    ],
  ),
  'financials/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-financials-sources',
        title: 'Airtable sources',
        body:
            'Say which Airtable table holds which part of your books, and for which place. The reports read this list.',
      ),
    ],
  ),
  'financials/Invoices': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-invoices-tabs',
        title: 'Invoices',
        body:
            'Charge sheets say what each branch owes for a period; invoices are made from them.',
      ),
      TourStep(
        anchor: 'tour-invoices-sheet-picker',
        title: 'The sheet',
        body: 'Pick a period here, or start a new sheet beside it.',
      ),
      TourStep(
        anchor: 'tour-invoices-sheet-grid',
        title: 'What each branch owes',
        body:
            'Type in the cells; they save on their own. An invoiced row is locked and links to its invoice.',
      ),
      TourStep(
        anchor: 'tour-invoices-make',
        title: 'Make invoices',
        body:
            'Drafts one invoice per branch with money on it. A branch already invoiced is skipped.',
      ),
    ],
  ),
  'financials/Lead source results': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-financials-results',
        title: 'Lead source results',
        body:
            "An outside lead source's calls, jobs, cost, sales and profit per date and area. Pending rows are tinted; a row counts in reports once approved.",
      ),
      TourStep(
        anchor: 'tour-financials-results-toolbar',
        title: 'Find, add, or read a photo',
        body:
            'Filter by dates, status and source. Add a result by hand, or read a photo of the report and check its rows before saving.',
      ),
    ],
  ),
  'financials/Invoices/Invoices': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-invoices-totals',
        title: 'Totals',
        body: 'What was sent, what is still unpaid, and what is overdue.',
      ),
      TourStep(
        anchor: 'tour-invoices-filters',
        title: 'Filters',
        body: 'By status and by the month an invoice is dated.',
      ),
      TourStep(
        anchor: 'tour-invoices-list',
        title: 'The invoices',
        body:
            'Open one to see it as its PDF, and take the next step: approve, send or record a payment.',
      ),
    ],
  ),
  'financials/Invoices/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-invoices-columns',
        title: 'Charge lines',
        body:
            'The columns of every new sheet. Drag to reorder, switch off, or say which column of your books fills one.',
      ),
      TourStep(
        anchor: 'tour-invoices-sentences',
        title: 'Sending, due days, numbers',
        body:
            'How invoices are emailed, when they are due, and how their numbers start.',
      ),
    ],
  ),
  'services': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-services-tabs',
        title: 'Services',
        body: 'The catalogue of work this company sells, and its settings.',
      ),
      TourStep(
        anchor: 'tour-services-add',
        title: 'Add a service',
        body: 'Opens a new service beside the list.',
      ),
      TourStep(
        anchor: 'tour-services-search',
        title: 'Find a service',
        body: 'By name or number.',
      ),
      TourStep(
        anchor: 'inventory-list',
        title: 'The catalogue',
        body:
            'Each service with its price, number and owner. Click one to open it.',
      ),
    ],
  ),
  'inspection': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-inspection-tabs',
        title: 'Inspection',
        body:
            'Start a new inspection, read the finished reports, or build the forms.',
      ),
      TourStep(
        anchor: 'tour-inspection-search',
        title: 'Start here',
        body:
            'Search a client by name or a job by its number, then pick the '
            'inspection to start.',
      ),
      TourStep(
        anchor: 'inspection-resume-panel',
        title: 'Resume',
        body: 'Carries on the draft you have in progress, or starts it again.',
      ),
      TourStep(
        anchor: 'tour-inspection-reports-search',
        title: 'Find a report',
        body:
            'Searches the finished reports. Archived ones stay hidden until you ask.',
      ),
      TourStep(
        anchor: 'inspection-reports-list',
        title: 'The reports',
        body: 'Click one to read it beside the list and download its PDF.',
      ),
    ],
  ),
  'field': Tour(
    // 2: safety recordings (UI queue 102).
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-field-tabs',
        title: 'Field',
        body:
            'Dashboard, Map, Route, Recordings and Settings, as your role '
            'allows.',
      ),
      TourStep(
        anchor: 'tour-field-sos',
        title: 'You, and SOS',
        body:
            'Whether your location is being shared right now. SOS takes two '
            'taps, so it is never sent by accident.',
      ),
      TourStep(
        anchor: 'tour-field-record',
        since: 2,
        title: 'Safety recording',
        body:
            'On your phone at a job: Start, Pause and Stop. It stops by itself '
            'when you leave the job or after four hours.',
      ),
      TourStep(
        anchor: 'tour-field-counts',
        title: 'Right now',
        body:
            'Who is tracked, reporting, at a job, stopped too long or out of '
            'signal, and how many alerts are open.',
      ),
      TourStep(
        anchor: 'tour-field-alerts',
        title: 'Alerts',
        body:
            'What needs a look. Acknowledge one once it is handled, if your role allows.',
      ),
    ],
  ),
  'fleet_management': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-fleet_management-tabs',
        title: 'Fleet',
        body:
            'General for the overview, and Vehicles for the list, as your role allows.',
      ),
      TourStep(
        anchor: 'fleet-general-vehicles',
        title: 'The fleet in figures',
        body:
            'Vehicles, active, in the shop, out of service, and those nobody '
            'has the keys to. Click a figure for the vehicles behind it.',
      ),
      TourStep(
        anchor: 'fleet-general-spending',
        title: 'Spending',
        body: 'What the fleet cost, by category.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-period',
        title: 'The period',
        body: 'Which stretch of time Spending covers.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-attention',
        title: 'Needs attention',
        body: 'Anything about a vehicle that wants a look.',
      ),
      TourStep(
        anchor: 'fleet-list',
        title: 'The vehicles',
        body: 'Click one to open it beside the list.',
      ),
    ],
  ),
  'inventory': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-inventory-tabs',
        title: 'Inventory',
        body:
            'General, Stock, Items, Orders and Locations, as your role allows.',
      ),
      TourStep(
        anchor: 'inventory-general-items',
        title: 'Stock status',
        body:
            'In stock, below reorder and out of stock in one bar, with the '
            'stock value and how many locations hold it.',
      ),
      TourStep(
        anchor: 'tour-inventory-movement',
        title: 'Movement',
        body: 'What came in and went out.',
      ),
      TourStep(
        anchor: 'tour-inventory-period',
        title: 'The period',
        body: 'Which stretch of time Movement covers.',
      ),
      TourStep(
        anchor: 'tour-inventory-attention',
        title: 'Needs attention',
        body: 'Only what applies, each with the button that fixes it.',
      ),
      TourStep(
        anchor: 'inventory-list',
        title: 'The list',
        body: 'Click a row to open it beside the list.',
      ),
    ],
  ),
  'marketing': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-marketing-tabs',
        title: 'Marketing',
        body:
            'Newsletter, Social Media Management, and the modules inside '
            'Marketing, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-marketing-newsletter-panes',
        title: 'Newsletter',
        body: 'Dashboard, Composer and Settings.',
      ),
      TourStep(
        anchor: 'tour-marketing-new-newsletter',
        title: 'New newsletter',
        body: 'Opens the composer on a new one.',
      ),
      TourStep(
        anchor: 'tour-marketing-status-tiles',
        title: 'Sent, Scheduled, Drafts',
        body: 'Each one narrows the list, with how many are in it.',
      ),
      TourStep(
        anchor: 'tour-marketing-social-panes',
        title: 'Social Media Management',
        body:
            'Comments, Connections, QR codes and New post, beside the '
            'calendar, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-marketing-month',
        title: 'Your posts',
        body: 'Each post on the day it goes out, with its networks and state.',
      ),
      TourStep(
        anchor: 'tour-marketing-new-post',
        title: 'New post',
        body: 'Write a post once, for the pages you pick.',
      ),
      TourStep(
        anchor: 'tour-web_management-tabs',
        title: 'Web Management',
        body: 'SEO and Widgets, for each of your websites.',
      ),
      TourStep(
        anchor: 'tour-web_management-sites',
        title: 'Your websites',
        body: 'Every website and subdomain, each with its own details.',
      ),
      TourStep(
        anchor: 'tour-web_management-add-site',
        title: 'Add a website',
        body: 'Adds another website or subdomain to the list.',
      ),
      TourStep(
        anchor: 'seo-sections',
        title: 'Its details',
        body: "The open website's details, one part at a time.",
      ),
    ],
  ),
  // Company (design 175): each tab has its own short walkthrough.
  'company/Ownership': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-company-tabs',
        title: 'Company',
        body: 'Who owns what, who owns each step, and the figures.',
      ),
      TourStep(
        anchor: 'company-ownership-search',
        title: 'Find a person',
        body: 'People are drawn under who they report to. Set it from the row.',
      ),
    ],
  ),
  'company/Processes': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'company-steps',
        title: 'Step owners',
        body:
            'Lead to review, with who owns each. Tap a step to set its owner.',
      ),
    ],
  ),
  'company/KPIs': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'company-kpi-search',
        title: 'Figures',
        body: 'Each person over the last 30 days, then the branch goals.',
      ),
    ],
  ),
  'training': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-training-tabs',
        title: 'Training',
        body: 'Learn, then Dashboard and Settings, as your role allows.',
      ),
      TourStep(
        anchor: 'learn-continue',
        title: 'Continue',
        body: 'The guide you were reading, at the page you left it.',
      ),
      TourStep(
        anchor: 'learn-assigned',
        title: 'Assigned to you',
        body: 'What you have been given to read or take.',
      ),
      TourStep(
        anchor: 'learn-search',
        title: 'Find a guide or test',
        body: 'Narrows the library as you type.',
      ),
      TourStep(
        anchor: 'learn-library',
        title: 'The library',
        body: 'Everything you can read. Click one to open it.',
      ),
    ],
  ),
  'guidelines': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-guidelines-tabs',
        title: 'Guidelines',
        body:
            'Guidelines to read and, if you can edit, Settings to write them.',
      ),
    ],
  ),
  'ai_assistance': Tour(
    // 2: the AI tab as a chat (UI queue 91).
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-ai_assistance-tabs',
        title: 'AI Assistance',
        body: 'AI, Library, WhatsApp groups and Settings, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-ai_assistance-ask',
        since: 2,
        title: 'Ask',
        body:
            'Type a question at the foot, as in a chat: a code, a price, how '
            'we do something, or a city, state or branch code.',
      ),
      TourStep(
        anchor: 'assistant-hello',
        since: 2,
        title: 'Start here',
        body: "Your Library's topics, each a first question to ask.",
      ),
      TourStep(
        anchor: 'assistant-ai-answer',
        title: "AI's answer",
        body:
            'AI reads your Library and answers in its own words, above the '
            'ask bar, newest last.',
      ),
      TourStep(
        anchor: 'assistant-answer',
        since: 2,
        title: 'From your Library',
        body:
            'The Library entries behind the answer. Click one to read it '
            'whole.',
      ),
    ],
  ),
  'customer_experience': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'customer-experience-tabs',
        title: 'Customer Experience',
        body: 'Reviews and Business Profile, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-unanswered',
        title: 'Unanswered',
        body: 'Only the reviews nobody has replied to, with how many.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-rating',
        title: 'Rating',
        body: 'One star rating at a time.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-fetch',
        title: 'Fetch new',
        body: 'Reads new reviews now instead of waiting for the next check.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-reviews',
        title: 'The reviews',
        body: 'Click one to read it and write the reply.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-check-google',
        title: 'Check Google',
        body: 'Reads the profiles from Google again.',
      ),
    ],
  ),
  'reports': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-reports-tabs',
        title: 'Reports',
        body:
            'Your reports, the Builder, Performance and the rest, as your role allows.',
      ),
      TourStep(
        anchor: 'tour-reports-new',
        title: 'New report',
        body: 'Opens the Builder on a new report.',
      ),
      TourStep(
        anchor: 'tour-reports-library',
        title: 'Your reports',
        body: 'Each saved report, what it covers, and when it was sent.',
      ),
    ],
  ),
  'monitoring': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-monitoring-tabs',
        title: 'Monitoring',
        body:
            'General, Map, PC Specs, Files, Camera Monitoring and Desktop '
            'Monitoring, as your role allows.',
      ),
      TourStep(
        anchor: 'floor-plan-picker',
        title: 'Floor plan',
        body: 'Which floor plan you are looking at.',
      ),
      TourStep(
        anchor: 'floor-plan-canvas',
        title: 'The plan',
        body: 'Each machine where it sits, with its status.',
      ),
      TourStep(
        anchor: 'general-refresh',
        title: 'Refresh status',
        body: 'Reads every status again.',
      ),
      TourStep(
        anchor: 'camera-monitoring-add-button',
        title: 'Add a camera',
        body: 'Adds a camera by its address.',
      ),
      TourStep(
        anchor: 'camera-monitoring-grid',
        title: 'Cameras',
        body: 'Every camera you can see, side by side.',
      ),
    ],
  ),
  'automations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-automations-tabs',
        title: 'Automations',
        body: 'Your automations, and Build.',
      ),
      TourStep(
        anchor: 'tour-automations-new',
        title: 'New automation',
        body: 'A rule: when something happens, what EzBizi does.',
      ),
      TourStep(
        anchor: 'automations-search',
        title: 'Find a rule',
        body: 'By its name.',
      ),
      TourStep(
        anchor: 'tour-automations-live',
        title: 'Live',
        body: 'Switches a rule on or off.',
      ),
      TourStep(
        anchor: 'tour-automations-menu',
        title: 'More',
        body:
            'Edit, duplicate, test, run it now, its run history, or delete it.',
      ),
    ],
  ),
  'hr': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-tabs',
        title: 'Human Resources',
        body:
            'Dashboard, Records, Contracts, Invitations and Onboarding, as '
            'your role allows.',
      ),
      TourStep(
        anchor: 'tour-hr-period',
        title: 'The period',
        body: 'Day, week, month, quarter or year.',
      ),
      TourStep(
        anchor: 'tour-hr-refresh',
        title: 'Refresh',
        body: 'Reads the dashboard again.',
      ),
      TourStep(
        anchor: 'hr-right-now',
        title: 'Right now',
        body:
            'Who is on the clock, who is away today, and what is due for retaking.',
      ),
      TourStep(
        anchor: 'hr-by-month',
        title: 'Joined and left',
        body: 'People who joined and left, month by month over the last year.',
      ),
      TourStep(
        anchor: 'hr-places',
        title: 'Where people work',
        body: 'How many people work at each place.',
      ),
    ],
  ),
  ...reportsTours,
  ...adminTours,
  ...assistTours,
  ...operationsTours,
  ...leadsTours,
  ...marketingTours,
  ...phoneTours,
};
