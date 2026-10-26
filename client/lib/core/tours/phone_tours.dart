import '../tab_tours.dart';

/// Communication's and Scheduling's sub tabs, each its own walkthrough.
///
/// Keyed as `tourKeyFor` looks them up (see reports_tours.dart for the
/// pattern). Communication › Phone's Dial, Team, Messages and Voicemail are
/// chips, not a tab strip, so the Phone tab names the open one with an
/// `OpenTabMark`; Scheduling › Settings has a strip of its own.
const Map<String, Tour> phoneTours = {
  'phone/Phone/Dial': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'phone-call-history',
        title: 'Recent calls',
        body:
            'Grouped by day, with names. Clicking one puts the number on the '
            'pad; it never dials by itself.',
      ),
      TourStep(
        anchor: 'tour-phone-no-line',
        title: 'No line yet',
        body:
            'Calls need a phone line. Add one in Settings, or ask an '
            'administrator to grant one to your role.',
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
            'Whether you are ready to take calls, and the line you call from. '
            'With two lines or more, pick which.',
      ),
      TourStep(
        anchor: 'tour-phone-number',
        title: 'Name or number',
        body:
            'Type a number, or letters to find a customer or a teammate, then '
            'press Call.',
      ),
      TourStep(
        anchor: 'dial-availability',
        title: 'Availability',
        body:
            "Opens Scheduling's Availability beside the pad, for the caller's "
            'zip when it is on file.',
      ),
      TourStep(
        anchor: 'phone-call-person',
        title: 'Who it is',
        body:
            'The customer on file for the number, the calls and texts with '
            'it, and a box to text them. The pin keeps it open between calls.',
      ),
    ],
  ),
  'phone/Phone/Team': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'phone-call-teammates',
        title: 'Your team',
        body:
            'Everyone at this desk, whether they can take a call, and their '
            'unread messages. Message or Call is on each row.',
      ),
      TourStep(
        anchor: 'tour-phone-team-tiles',
        title: 'Narrow it',
        body:
            'Available, On a call, Do not disturb or Unread, each with how '
            'many. Click a tile again for everyone.',
      ),
      TourStep(
        anchor: 'staff-message-composer',
        title: 'Message a colleague',
        body: 'Write to them here. Back returns to the team.',
      ),
      TourStep(
        anchor: 'tour-phone-no-line',
        title: 'No line yet',
        body:
            'Calls need a phone line. Add one in Settings, or ask an '
            'administrator to grant one to your role.',
      ),
    ],
  ),
  'phone/Phone/Messages': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'phone-records-list',
        title: 'Conversations',
        body:
            'One row for each number: the last message, how many, and when. '
            'Click one to read it.',
      ),
      TourStep(
        anchor: 'tour-phone-records-empty',
        title: 'Nothing yet',
        body: 'There are no calls or messages yet.',
      ),
      TourStep(
        anchor: 'tour-phone-messages-tiles',
        title: 'Narrow them',
        body:
            'Waiting on us: they wrote last. Answered: we did. Today: anything '
            'said today.',
      ),
      TourStep(
        anchor: 'phone-records-pane',
        title: 'The conversation',
        body: 'Every message with that number, oldest at the top.',
      ),
    ],
  ),
  'phone/Phone/Voicemail': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-phone-voicemail-list',
        title: 'Voicemails',
        body:
            'Who left each one and when, its recording and what they said. '
            'Each is marked To deal with or Dealt with.',
      ),
      TourStep(
        anchor: 'tour-phone-voicemail-boxes',
        title: 'Boxes',
        body:
            "All voicemails, or one box: a person's, a ring group's, a line's "
            "or the company's.",
      ),
      TourStep(
        anchor: 'tour-phone-voicemail-box-chips',
        title: 'Boxes',
        body: 'All mailboxes, or one box at a time.',
      ),
      TourStep(
        anchor: 'tour-phone-voicemail-tiles',
        title: 'Narrow them',
        body:
            'To deal with, Dealt with or No transcript, counted over the whole '
            'box.',
      ),
      TourStep(
        anchor: 'voicemail-transcript-note',
        title: 'No transcript',
        body:
            'Some messages were not written down. This says where that is '
            'switched on.',
      ),
    ],
  ),
  'phone/Users': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-phone-users-calling-out',
        title: 'Calling out',
        body:
            'Whoever has no number of their own calls out from the line picked '
            'on Phone › Dial.',
      ),
      TourStep(
        anchor: 'tour-phone-users-tiles',
        title: 'Narrow the list',
        body:
            'With an extension, no extension, or their own outgoing line, each '
            'with how many.',
      ),
      TourStep(
        anchor: 'tour-phone-users-table',
        title: 'Each person',
        body:
            'The extension that reaches them, starting with 0, and the number '
            'they call out from.',
      ),
      TourStep(
        anchor: 'tour-phone-users-save',
        title: 'Save',
        body:
            'Names what is not saved yet. Save keeps it; Discard puts it back.',
      ),
    ],
  ),
  'phone/Records': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'phone-records-list',
        title: 'The records',
        body:
            'Each call: who, the line, who answered, how it ended, its tag, '
            'length and when. Click one to open it.',
      ),
      TourStep(
        anchor: 'tour-phone-records-empty',
        title: 'Nothing yet',
        body: 'There are no calls or messages yet.',
      ),
      TourStep(
        anchor: 'tour-phone-records-filter',
        title: 'Calls, Voicemail, SMS',
        body: 'Which of the three the list shows.',
      ),
      TourStep(
        anchor: 'tour-phone-records-tiles',
        title: 'Narrow the calls',
        body:
            "Answered, Missed, Voicemail, Didn't connect or Not tagged, each "
            'with how many. Click a tile again for all.',
      ),
      TourStep(
        anchor: 'phone-records-pane',
        title: 'What you opened',
        body:
            "A call's recording, transcripts and what was pressed in the menu, "
            "or a conversation's messages. An incoming caller can be blocked "
            'here, if your role allows.',
      ),
    ],
  ),
  'phone/Reports': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'report-period',
        title: 'When',
        body: 'Which days of calls the report counts.',
      ),
      TourStep(
        anchor: 'report-grouping',
        title: 'Broken down',
        body:
            'By day, hour, what it was about, who answered, line, direction, '
            'menu key or caller.',
      ),
      TourStep(
        anchor: 'report-direction',
        title: 'Direction',
        body: 'Incoming, outgoing, or both.',
      ),
      TourStep(
        anchor: 'tour-phone-report-totals',
        title: 'Totals',
        body:
            'Calls, missed, voicemails, the average call and calls not tagged. '
            'Incoming and Outgoing also set Direction.',
      ),
      TourStep(
        anchor: 'tour-phone-report-empty',
        title: 'No calls',
        body: 'No calls in this period, so there is no rate to work out.',
      ),
      TourStep(
        anchor: 'report-chart',
        title: 'The breakdown',
        body:
            'Calls in each part, with the missed share inside each bar, and '
            'the same numbers in a table under it.',
      ),
      TourStep(
        anchor: 'report-name',
        title: 'Save this view',
        body:
            'A saved report keeps the question, not the dates, so it answers '
            'itself again next month.',
      ),
    ],
  ),
  'phone/Alerts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'alert-new',
        title: 'New alert',
        body: 'Pick the people and write what they should know.',
      ),
      TourStep(
        anchor: 'alert-people-search',
        title: 'To',
        body: 'Add the people it goes to.',
      ),
      TourStep(
        anchor: 'alert-message',
        title: 'Message',
        body: 'What they should know.',
      ),
      TourStep(
        anchor: 'alert-send',
        title: 'Send alert',
        body: 'It pops up for each of them until they dismiss it.',
      ),
      TourStep(
        anchor: 'tour-phone-alerts-sent',
        title: 'Sent alerts',
        body:
            'Each alert with how many have dismissed it. Click one to see who '
            'has and who has not.',
      ),
      TourStep(
        anchor: 'tour-phone-alerts-empty',
        title: 'None sent yet',
        body: 'No alert has been sent yet.',
      ),
    ],
  ),
  'phone/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-phone-settings-rail',
        title: 'The sections',
        body:
            'Connect, Answering, Identity and Running it. Click a section to '
            'open it.',
      ),
      TourStep(
        anchor: 'tour-phone-settings-pane',
        title: 'The open section',
        body: 'Its settings. Settings opens on Connection.',
      ),
      TourStep(
        anchor: 'tour-phone-connection-tiles',
        title: 'Connection',
        body:
            'How many places are connected, not working, or have no account of '
            'their own.',
      ),
      TourStep(
        anchor: 'tour-phone-connection-table',
        title: 'Each account',
        body:
            "Which Twilio account each company's calls run on. Open goes to "
            'its numbers.',
      ),
    ],
  ),
  'scheduling/Schedule': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-schedule-day',
        title: 'The day',
        body:
            'Click the date to pick another; the arrows move a day either way. '
            'Beside it, how many jobs are scheduled and unassigned.',
      ),
      TourStep(
        anchor: 'schedule-location',
        title: 'Location',
        body: 'One location, or all of them.',
      ),
      TourStep(
        anchor: 'tour-scheduling-board',
        title: 'The board',
        body:
            'Technicians down the side and time slots across, each job in the '
            'cell of whoever is doing it. Click a job to open it.',
      ),
      TourStep(
        anchor: 'tour-scheduling-unassigned',
        title: 'Unassigned',
        body:
            'Jobs that day with no technician. Search by customer, job number '
            'or address.',
      ),
      TourStep(
        anchor: 'tour-scheduling-job',
        title: 'The job',
        body:
            'Who is on it. If your role can dispatch, assign, unassign or move '
            'it here; each change goes straight to Workiz.',
      ),
    ],
  ),
  'scheduling/Overbooking': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-overbooking-counts',
        title: 'Overbooked and full',
        body:
            'Days booked past what an area or a franchise takes, and areas '
            'with no spot left. Click an overbooked row below for its jobs.',
      ),
      TourStep(
        anchor: 'tour-scheduling-overbooking-span',
        title: 'How far ahead',
        body: 'Overbooked days in the next 7, 14, 30 or 60 days.',
      ),
      TourStep(
        anchor: 'overbooking-pick-date',
        title: 'One day',
        body: 'Pick a date to see that day alone; the × clears it.',
      ),
      TourStep(
        anchor: 'tour-scheduling-fully-booked',
        title: 'Fully booked',
        body: 'Areas with no spot left, each with its next opening.',
      ),
    ],
  ),
  'scheduling/Re-Scheduling/Rescheduling Availability': Tour(
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-booked-day',
        title: 'The day',
        body:
            'Tomorrow by default, the day the day-before email is about. '
            '‹ and › move a day; Today and Tomorrow jump.',
      ),
      TourStep(
        anchor: 'booked-available-search',
        title: 'Find an area',
        body: 'Search by area, company or branch.',
      ),
      TourStep(
        anchor: 'tour-booked-summary',
        title: 'Over, open and full',
        body:
            'Areas over capacity come first, then open spots, then full ones. '
            'Click an area for its jobs; Offer openings texts customers '
            'booked later. Replies land in Leads as "Reschedule".',
      ),
    ],
  ),
  'scheduling/Re-Scheduling': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'rescheduling-list',
        title: 'Jobs worth moving',
        body:
            'Booked jobs alone or far from other work on their day, each with '
            'a time beside other work. Biggest saving first.',
      ),
      TourStep(
        anchor: 'rescheduling-empty',
        title: 'Nothing to move',
        body: 'Every booked job has other work near it on its day.',
      ),
      TourStep(
        anchor: 'rescheduling-area',
        title: 'Area',
        body: 'All areas, or one, with how many in each.',
      ),
      TourStep(
        anchor: 'tour-scheduling-reschedule-job',
        title: 'Now and suggested',
        body:
            'Where the job is now and where it could go, with how far the '
            'nearest other job would be.',
      ),
      TourStep(
        anchor: 'rescheduling-move',
        title: 'Move',
        body:
            "Asks first, then changes the customer's appointment in Workiz. "
            'Confirm with the client before you move it.',
      ),
      TourStep(
        anchor: 'rescheduling-day-plan',
        title: 'That day',
        body:
            'Both days window by window, with the open spots before → after '
            'the move: the day it leaves gains one, the day it takes loses '
            'one. The suggested day lists its jobs and where this one would '
            'go. Repairs and redos are tagged.',
      ),
    ],
  ),
  'scheduling/Routing': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'routing-area',
        title: 'Area and day',
        body:
            'Which service area and which day to plan. The arrows step a day.',
      ),
      TourStep(
        anchor: 'routing-map',
        title: 'The routes',
        body:
            'A colour for each technician, through their stops in order. A '
            'hollow stop is placed at its zip code, not its address.',
      ),
      TourStep(
        anchor: 'routing-routes',
        title: 'Each technician',
        body:
            'Stops, straight-line miles and Google Maps for the real drive. '
            'Click a '
            'route for its stops. One marked Needs another technician is past '
            "the area's capacity.",
      ),
      TourStep(
        anchor: 'routing-unplaced',
        title: 'No location',
        body: 'Jobs Workiz has no usable address for, on no route.',
      ),
      TourStep(
        anchor: 'routing-elsewhere',
        title: 'Far outside this area',
        body:
            "Jobs 60 miles or more from the day's others, usually booked in "
            'the wrong area. Check the booking.',
      ),
      TourStep(
        anchor: 'routing-empty',
        title: 'Nothing booked',
        body: 'No jobs in this area on this day.',
      ),
    ],
  ),
  'scheduling/Helpers': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'helpers-branch',
        title: 'Branch and month',
        body:
            'Whose helpers you see. The arrows beside it step a month, and '
            'Today comes back to this one.',
      ),
      TourStep(
        anchor: 'helpers-month-grid',
        title: 'The month',
        body:
            'Each day lists its bookings as helper → technician: blue a whole '
            'day, green the morning, amber the afternoon. Click a day to open '
            'it.',
      ),
      TourStep(
        anchor: 'helpers-day',
        title: 'The day',
        body:
            "Every helper and which halves are free, the day's bookings, and "
            'Book. Change and Remove show on the bookings you may change.',
      ),
      TourStep(
        anchor: 'helpers-book',
        title: 'Book a helper',
        body:
            'A whole day, the morning or the afternoon, with a job number and '
            'a note if you like. A whole day takes both halves.',
      ),
      TourStep(
        anchor: 'helpers-roster',
        title: 'The helpers',
        body:
            'Add a helper, rename one or switch one off. Changing the list '
            'takes the right to edit Scheduling.',
      ),
    ],
  ),
  'scheduling/Locations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'locations-lookup',
        title: 'Who serves this address',
        body:
            "Type a customer's address or ZIP, then Find the closest. The "
            'branch that serves the ZIP comes first.',
      ),
      TourStep(
        anchor: 'locations-availability',
        title: 'Check availability',
        body: "Opens Availability for the ZIP you typed, or the branch's own.",
      ),
      TourStep(
        anchor: 'locations-find',
        title: 'Filter branches',
        body: 'Narrows the branches as you type.',
      ),
      TourStep(
        anchor: 'locations-state',
        title: 'State',
        body: 'One state at a time, with how many branches in each.',
      ),
      TourStep(
        anchor: 'tour-scheduling-branches',
        title: 'The branches',
        body: 'Every branch, by state. Click one to see it.',
      ),
      TourStep(
        anchor: 'tour-scheduling-branch',
        title: 'The branch',
        body:
            'Its phone, email and address with Copy and Directions, and Check '
            'availability.',
      ),
      TourStep(
        anchor: 'locations-note',
        title: 'For new dispatchers',
        body: "The company's note: who to call, extensions, house rules.",
      ),
    ],
  ),
  // Reached only when the open Settings tab has no tour of its own: each
  // tab below does.
  'scheduling/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-settings-tabs',
        title: 'Scheduling settings',
        body:
            "Availability for each area's capacity, Schedule for Workiz "
            'technicians, Locations for dispatcher notes, and Suggestions.',
      ),
      TourStep(
        anchor: 'tour-scheduling-areas',
        title: 'Service areas',
        body: 'Search the areas, or + to add one.',
      ),
      TourStep(
        anchor: 'tour-scheduling-technicians',
        title: 'Workiz technicians',
        body: 'Pick the EzBizi user each Workiz technician is.',
      ),
      TourStep(
        anchor: 'tour-scheduling-dispatcher-notes',
        title: 'Notes for dispatchers',
        body: 'What a new dispatcher should know, per company.',
      ),
      TourStep(
        anchor: 'tour-scheduling-suggestions',
        title: 'Suggested availability',
        body:
            'How near a booked job must be for Availability to suggest its '
            'day, and how many days ahead it may look.',
      ),
    ],
  ),
  'scheduling/Settings/Availability': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-areas',
        title: 'Service areas',
        body:
            'Search the areas, or + to add one. Click an area for its week, or '
            "a franchise's name for its totals.",
      ),
      TourStep(
        anchor: 'tour-scheduling-time-slots',
        title: 'Time slots',
        body:
            'The windows every service area books into, and how many areas use '
            'each. They apply to every branch, so they are changed at '
            'organization level.',
      ),
      TourStep(
        anchor: 'overview-weekdays',
        title: 'Capacity',
        body:
            'Jobs each service area can take per day, by weekday. A dash is a '
            'closed day.',
      ),
      TourStep(
        anchor: 'scheduling-franchise-overview',
        title: 'A franchise',
        body:
            'Its areas added up for each day, and the most jobs it takes that '
            'day across them. Blank is no limit.',
      ),
      TourStep(
        anchor: 'scheduling-area-pane',
        title: 'One area',
        body:
            'Its daily limit and its week of time slots. Below: scheduled '
            'changes, specific dates, zip codes and shared availability.',
      ),
      TourStep(
        anchor: 'scheduling-area-name',
        title: 'New or renamed area',
        body: 'Its name. A new one also asks which franchise it belongs to.',
      ),
    ],
  ),
  'scheduling/Settings/Schedule': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-technicians',
        title: 'Workiz technicians',
        body:
            'Pick the EzBizi user each one is. Matched by email means the sync '
            'proposed it; a link you pick here stays.',
      ),
      TourStep(
        anchor: 'tour-scheduling-technicians-find',
        title: 'Find a technician',
        body:
            'By name, email or franchise. Unlinked only and Include inactive '
            'narrow or widen the list.',
      ),
    ],
  ),
  'scheduling/Settings/Locations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-dispatcher-notes',
        title: 'Notes for dispatchers',
        body:
            "What a new dispatcher should know, per company. It shows with "
            "that company's branches on Locations.",
      ),
      TourStep(
        anchor: 'tour-scheduling-notes-read-only',
        title: 'Read only',
        body: 'Changing the notes takes the right to edit Scheduling.',
      ),
    ],
  ),
  'scheduling/Settings/Routing & suggestions': Tour(
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-scheduling-suggestions',
        title: 'Routing and suggestions',
        body:
            'How many jobs a technician takes, how far apart they may be, '
            'how near a booked job must be for Availability to suggest its '
            'day, and what Re-Scheduling may move.',
      ),
    ],
  ),
};
