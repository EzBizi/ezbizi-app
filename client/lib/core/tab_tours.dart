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

/// By module key, as `module_screens.dart` keys its screens.
const Map<String, Tour> tabTours = {
  'tasks': Tour(
    // 2: Task Board v2 (UI queue 84) - the picker, columns, statuses.
    version: 2,
    steps: [
      TourStep(
        anchor: 'tasks-boards',
        title: 'Your boards',
        body:
            "Pick a board here. My board is yours alone; each company's "
            "board and its franchises' sit under the company, with how many "
            'open tasks are yours.',
      ),
      TourStep(
        anchor: 'tasks-view',
        title: 'Table or Kanban',
        body:
            'The same tasks, as a table by group or as a column for each '
            'status.',
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
        body:
            'Click a group to fold it, and Add task at its foot to add one '
            "there. Click a status to change it, ▸ for a task's subtasks, or "
            'a task to open it.',
      ),
      TourStep(
        anchor: 'column-add',
        title: 'Your own columns',
        since: 2,
        body:
            'Add a column of text, numbers, a status, a date, a person, a '
            "dropdown or a checkbox. Click a column's name to rename it, "
            'change its options, move or hide it.',
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
    version: 1,
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
            'Unread, Unreplied, Overdue, Follow up and Starred narrow the list '
            'together. Mark all read sits at the end.',
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
        body: 'Narrows to jobs by where their money stands, with counts.',
      ),
      TourStep(
        anchor: 'jobs-where',
        title: 'Where',
        body: 'Anywhere, not placed yet, one franchise, or one area.',
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
    version: 1,
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
        body: 'By name, email, phone or number.',
      ),
      TourStep(
        anchor: 'tour-customers-franchise',
        title: 'Franchise',
        body: 'Narrows the list to one franchise.',
      ),
      TourStep(
        anchor: 'tour-customers-list',
        title: 'The customers',
        body:
            'Click a heading to sort, or a customer to open them beside the '
            'list. Each row says whose customer they are.',
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
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-email-tabs',
        title: 'Email',
        body: 'The inbox, and its settings.',
      ),
      TourStep(
        anchor: 'tour-email-inboxes',
        title: 'Your inboxes',
        body:
            'All inboxes together, or one at a time, with how many are unread, '
            'then its folders.',
      ),
      TourStep(
        anchor: 'tour-email-inbox-bar',
        title: 'Where you are',
        body: 'The inbox you are reading, by its address.',
      ),
      TourStep(
        anchor: 'tour-email-search',
        title: 'Search mail',
        body: 'Press Enter to search; the × clears it.',
      ),
      TourStep(
        anchor: 'tour-email-compose',
        title: 'New message',
        body: 'Writes from the inbox you have open.',
      ),
      TourStep(
        anchor: 'tour-email-unread',
        title: 'Unread',
        body: 'Shows only what has not been read.',
      ),
      TourStep(
        anchor: 'tour-email-list',
        title: 'The messages',
        body: 'Click one to read it. Older messages load at the end.',
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
};
