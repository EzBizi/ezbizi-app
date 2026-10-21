import '../tab_tours.dart';

/// Field, Fleet Management, Human Resources, Inspection, Inventory and Jobs:
/// each sub tab its own walkthrough (Jose, 2026-10-14: "it should cover
/// everything, all the sub tabs").
///
/// Keyed as `tourKeyFor` looks them up: the module, then the label of the tab
/// open in each strip on screen. Estimates, a child module drawn in Jobs'
/// strip, is `jobs/Estimates`.
const Map<String, Tour> operationsTours = {
  // ── Field ────────────────────────────────────────────────────────────
  'field/Map': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-field-map',
        title: 'The map',
        body:
            "Everyone you can see and the day's jobs. Click a person for "
            'their route that day, or a job for who is nearest.',
      ),
      TourStep(
        anchor: 'tour-field-map-panel',
        title: 'People and jobs',
        body:
            'The same people, and the jobs if you can see them, as a list. '
            'Click one to find it on the map.',
      ),
      TourStep(
        anchor: 'tour-field-map-day',
        title: 'The day',
        body:
            "Which day's jobs and routes the map shows. The arrows step a day "
            'either way.',
      ),
      TourStep(
        anchor: 'tour-field-map-search',
        title: 'Find',
        body: 'A person by name, or a job by its number, customer or address.',
      ),
      TourStep(
        anchor: 'tour-field-map-person',
        title: 'The person',
        body:
            'When they clocked in, their battery, speed, miles today, when '
            'they were last seen and their next job.',
      ),
      TourStep(
        anchor: 'tour-field-map-job',
        title: 'The job',
        body:
            'The nearest people by drive time. Pick one for the road, the '
            'minutes and a sentence to read to the caller.',
      ),
      TourStep(
        anchor: 'tour-field-map-legend',
        title: 'Legend',
        body: 'What each colour on the map means.',
      ),
    ],
  ),
  'field/Recordings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-field-recordings',
        title: 'Recordings',
        body:
            'Safety recordings: who, which job, when, how long, and when each '
            'is deleted. Click one to play it.',
      ),
      TourStep(
        anchor: 'field-recordings-day',
        title: 'The day',
        body: "One day's recordings, or any day.",
      ),
    ],
  ),
  'field/Route': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-field-route',
        title: 'The route',
        body:
            "One person's jobs for the day in the order Google finds fastest, "
            'with drive times. It only suggests: no job changes time.',
      ),
      TourStep(
        anchor: 'tour-field-route-person',
        title: 'Person',
        body: 'Whose route to work out.',
      ),
      TourStep(
        anchor: 'tour-field-route-day',
        title: 'The day',
        body: "Which day's jobs to put in order.",
      ),
      TourStep(
        anchor: 'tour-field-route-refresh',
        title: 'Refresh',
        body: 'Asks for the route again.',
      ),
      TourStep(
        anchor: 'tour-field-route-empty',
        title: 'No jobs',
        body: 'Nothing is booked for them on this day.',
      ),
    ],
  ),
  'field/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-field-settings',
        title: 'Field settings',
        body:
            'When phones report their location, what counts as arrived, and '
            'when standing still or a silent phone raises an alert.',
      ),
    ],
  ),

  // ── Fleet Management ─────────────────────────────────────────────────
  'fleet_management/Vehicles': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'fleet-list',
        title: 'The vehicles',
        body:
            'Each with its status, owner, where it is kept and who has the '
            'keys. Click one to open it beside the list.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-add',
        title: 'Add a vehicle',
        body: 'Opens a new vehicle beside the list.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-states',
        title: 'By state',
        body:
            'One state at a time, or all of them, with how many vehicles each has.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-search',
        title: 'Find a vehicle',
        body: 'By name, plate, VIN, number or driver; press Enter to search.',
      ),
      TourStep(
        anchor: 'tour-fleet_management-filters',
        title: 'Narrow the list',
        body:
            'Owned, rented, a status, unassigned or due soon. Sold and '
            'returned stay hidden until you pick them.',
      ),
      TourStep(
        anchor: 'fleet-pane',
        title: 'The vehicle',
        body:
            'Its photos, identity, who has it, ownership, insurance and '
            'service, each on its own card.',
      ),
    ],
  ),

  // ── Human Resources › Records ────────────────────────────────────────
  'hr/Records': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-records-list',
        title: 'The people',
        body:
            'Everybody here, with where they work and their role. An amber '
            'number counts the boxes on their record not filled in.',
      ),
      TourStep(
        anchor: 'tour-hr-records-search',
        title: 'Find someone',
        body: 'Narrows the list as you type.',
      ),
      TourStep(
        anchor: 'tour-hr-records-showing',
        title: 'Who to show',
        body: 'Everyone, only the active, or only the inactive.',
      ),
      TourStep(
        anchor: 'hr.records.role',
        title: 'Role',
        body: 'Only the people in one role.',
      ),
      TourStep(
        anchor: 'hr.records.place',
        title: 'Company',
        body: 'Only the people at one company or franchise.',
      ),
      TourStep(
        anchor: 'tour-hr-records-pick',
        title: 'Their record',
        body:
            'Pick somebody to see their whole record. Opening one is logged, '
            'because it shows personal details.',
      ),
      TourStep(
        anchor: 'hr-list-edge',
        title: 'Resize',
        body: 'Drag the edge to widen the list; a double-click puts it back.',
      ),
    ],
  ),
  'hr/Records/Personal': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Personal details',
        body:
            'Their name, phone, home address, date of birth and gender. An '
            'amber box is not filled in yet.',
      ),
      TourStep(
        anchor: 'tour-hr-record-address',
        title: 'Home address',
        body: 'Search for the address and pick it; it fills in the parts.',
      ),
      TourStep(
        anchor: 'hr-date-of-birth',
        title: 'Date of birth',
        body: 'Type it, or pick it on the calendar.',
      ),
      TourStep(
        anchor: 'tour-hr-record-header',
        title: 'Who this is',
        body: 'Their role, where they work, and whether they are active.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Emergency contacts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Emergency contacts',
        body: 'Who to call for them: a name, how they are related, a phone.',
      ),
      TourStep(
        anchor: 'tour-hr-record-add',
        title: 'Add one',
        body:
            'Adds another contact. Each can be changed or removed on its row.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Employment': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Employment',
        body:
            'Their role, whether they are active, when they started and '
            'where they work.',
      ),
      TourStep(
        anchor: 'tour-hr-record-role',
        title: 'Role',
        body:
            'Organization-wide: changing it changes what they reach '
            'everywhere.',
      ),
      TourStep(
        anchor: 'tour-hr-record-status',
        title: 'Active or inactive',
        body:
            'Inactive people cannot sign in. Their record stays, and making '
            'them active again puts them back.',
      ),
      TourStep(
        anchor: 'hr-start-date',
        title: 'Start date',
        body: 'The day they started here. Paid time off counts from it.',
      ),
      TourStep(
        anchor: 'hr-works-at',
        title: 'Works at',
        body:
            'Everything beneath it comes with it. Moving them replaces where '
            'they were.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Pay': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Pay',
        body:
            'Their pay rates. A raise is a new rate from a new date; the old '
            'one stays as history.',
      ),
      TourStep(
        anchor: 'tour-hr-record-add',
        title: 'Add a pay rate',
        body: 'Hourly or salary, the amount, per what, and from when.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Work': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Work',
        body:
            'Their work schedule, and whether the lock screen applies to them.',
      ),
      TourStep(
        anchor: 'tour-hr-record-schedule',
        title: 'Their schedule',
        body: 'Change the days and hours they work.',
      ),
      TourStep(
        anchor: 'tour-hr-record-lock',
        title: 'Lock screen',
        body:
            'When it applies, their session locks outside their schedule. '
            'Saved as soon as it is switched.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Equipment': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Equipment',
        body:
            'The tools and equipment they hold, with serial numbers and when '
            'each was issued.',
      ),
      TourStep(
        anchor: 'tour-hr-record-add',
        title: 'Add an item',
        body:
            'Records something handed to them. Mark returned keeps the row as '
            'the record of its return.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Onboarding': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Onboarding',
        body:
            'What they filled in and signed, one row per onboarding. Click one '
            'to read it in full.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Time off': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Time off',
        body:
            'Their paid time off and when they can use it. Read only: it is '
            'set under Work Hours › Settings.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Training': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Training',
        body: 'What they have been assigned, started and passed.',
      ),
      TourStep(
        anchor: 'training-counts',
        title: 'At a glance',
        body: 'How many need a pass, are outstanding, or are passed.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Notes': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Notes',
        body: 'Kept for HR. The person does not see these.',
      ),
      TourStep(
        anchor: 'tour-hr-record-add',
        title: 'Add a note',
        body: 'Each note keeps who wrote it and when.',
      ),
      _recordSections,
    ],
  ),
  'hr/Records/Access': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-record-section',
        title: 'Access',
        body:
            'Reset their authenticator for a lost phone, or make a single-use '
            'password reset link to hand over yourself.',
      ),
      _recordSections,
    ],
  ),
  // An onboarding read from a record, on a narrow screen.
  'hr/Records/Form': _onboardingForm,
  'hr/Records/Contract': _onboardingContract,

  // ── Human Resources › Contracts ──────────────────────────────────────
  'hr/Contracts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-contracts-list',
        title: 'Your contracts',
        body:
            'Each with its version, how many questions it asks, its wording '
            'and its cover. Click one to design or read it.',
      ),
      TourStep(
        anchor: 'tour-hr-contracts-new',
        title: 'New contract',
        body: 'Opens the designer on a new one.',
      ),
      TourStep(
        anchor: 'tour-hr-contracts-tiles',
        title: 'Narrow the list',
        body: 'Wording written, questions only, or no cover page, with counts.',
      ),
      TourStep(
        anchor: 'tour-hr-contracts-search',
        title: 'Search',
        body: 'Finds a contract by its name or what it is for.',
      ),
      _contractForm,
      _contractPage,
      _contractFooter,
    ],
  ),
  'hr/Contracts/Set it up': Tour(
    version: 1,
    steps: [_contractForm, _contractFooter],
  ),
  'hr/Contracts/The contract': Tour(
    version: 1,
    steps: [_contractPage, _contractFooter],
  ),

  // ── Human Resources › Invitations ────────────────────────────────────
  'hr/Invitations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-invitations-list',
        title: 'The invitations',
        body:
            'Each person invited, their role, where they will work, how far it '
            'got and who sent it. Resend or Revoke sit at the end of a row.',
      ),
      TourStep(
        anchor: 'tour-hr-invitations-invite',
        title: 'Invite somebody',
        body:
            'One person at a time, with their role and where they work. A '
            'link works once and expires after a week.',
      ),
      TourStep(
        anchor: 'tour-hr-invitations-tiles',
        title: 'Where it got to',
        body: 'Sent, opened, completed, expired or revoked, with counts.',
      ),
      TourStep(
        anchor: 'tour-hr-invitations-search',
        title: 'Search',
        body: 'Finds an invitation by the name or email it went to.',
      ),
    ],
  ),

  // ── Human Resources › Onboarding ─────────────────────────────────────
  'hr/Onboarding': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-hr-onboarding-list',
        title: 'The onboardings',
        body:
            'Each person being taken on, their contract, its status and what '
            'is still owed. Click one to open its form.',
      ),
      TourStep(
        anchor: 'tour-hr-onboarding-start',
        title: 'Start an onboarding',
        body:
            'Pick the person and the contract. The contract decides what the '
            'form asks and which side answers.',
      ),
      TourStep(
        anchor: 'tour-hr-onboarding-tiles',
        title: 'Where it is',
        body:
            'Ongoing, being filled in, submitted, to approve, completed or '
            'cancelled, with counts.',
      ),
      TourStep(
        anchor: 'tour-hr-onboarding-search',
        title: 'Search',
        body: "Finds an onboarding by the person's name or email.",
      ),
      _onboardingProgress,
      _onboardingApproval,
      _onboardingSigning,
      _onboardingPaper,
    ],
  ),
  'hr/Onboarding/Form': _onboardingForm,
  'hr/Onboarding/Contract': _onboardingContract,

  // ── Inspection ───────────────────────────────────────────────────────
  'inspection/Generated Reports': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inspection-reports-list',
        title: 'Finished reports',
        body:
            'Each inspection that was submitted, with its client and job. '
            'Click one to read it.',
      ),
      TourStep(
        anchor: 'tour-inspection-reports-search',
        title: 'Find a report',
        body: 'By client name or job number; press Enter to search.',
      ),
      TourStep(
        anchor: 'tour-inspection-reports-archived',
        title: 'Archived',
        body: 'Archived reports stay hidden until this is on.',
      ),
      TourStep(
        anchor: 'inspection-reports-document',
        title: 'The report',
        body:
            'Every answer as it was submitted, with who submitted it and when.',
      ),
      TourStep(
        anchor: 'inspection-report-pdf',
        title: 'Download PDF',
        body: 'The report as a PDF.',
      ),
      TourStep(
        anchor: 'tour-inspection-reports-archive',
        title: 'Archive',
        body: 'Takes the report out of the list; Unarchive brings it back.',
      ),
    ],
  ),
  'inspection/Builder': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inspection-builder-list',
        title: 'Screens',
        body:
            'The pages a technician walks through, in order. Drag one to move '
            'it, or click it for its questions.',
      ),
      TourStep(
        anchor: 'inspection-builder-fields',
        title: 'Questions',
        body:
            "The screen's questions as cards. Click one to change it where it "
            'is; Add a question sits at the foot.',
      ),
      TourStep(
        anchor: 'inspection-builder-phone',
        title: 'Phone or Map',
        body:
            'The screen as a technician sees it, or the map of which screens '
            'are reached.',
      ),
      TourStep(
        anchor: 'inspection-saved',
        title: 'Saved',
        body: 'Changes save themselves as you go.',
      ),
      TourStep(
        anchor: 'inspection-publish',
        title: 'Publish',
        body:
            'Technicians use the published version until you publish again, so '
            'nothing half done reaches them.',
      ),
      TourStep(
        anchor: 'inspection-settings',
        title: 'Settings',
        body:
            'Its name, who it belongs to, and the intro technicians see when '
            'they start it.',
      ),
      TourStep(
        anchor: 'inspection-new',
        title: 'New inspection',
        body: 'Starts another inspection to build.',
      ),
    ],
  ),

  // ── Inventory ────────────────────────────────────────────────────────
  'inventory/Stock': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'Your stock',
        body:
            'Each item with its status, what is on hand, its reorder point and '
            'what is on order. The tiles above narrow it.',
      ),
      TourStep(
        anchor: 'stock-search',
        title: 'Find an item',
        body: 'By name, SKU or number, as you type.',
      ),
      TourStep(
        anchor: 'stock-place',
        title: 'One place',
        body: 'Only what is at one place, or all of them.',
      ),
      TourStep(
        anchor: 'tour-inventory-stock-transfer',
        title: 'Transfer',
        body: 'Moves stock from one place to another, beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-stock-order',
        title: 'Purchase order',
        body: 'Starts a purchase order beside the list.',
      ),
      TourStep(
        anchor: 'inventory-pane',
        title: 'Beside the list',
        body:
            'The item you opened, with what is on hand at each place, a count '
            'to correct it, and its history.',
      ),
    ],
  ),
  'inventory/Items': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'The catalogue',
        body:
            'Each item with its SKU, reorder point and what is on hand. Click '
            'one to open it beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-items-add',
        title: 'Add an item',
        body: 'Opens a new item beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-items-tiles',
        title: 'Narrow the list',
        body:
            'All items, below reorder, out of stock or discontinued, with '
            'counts.',
      ),
      TourStep(
        anchor: 'tour-inventory-items-search',
        title: 'Find an item',
        body: 'By name, SKU or number; press Enter to search.',
      ),
      TourStep(
        anchor: 'inventory-pane',
        title: 'The item',
        body:
            'The item you opened, or a new one. An item shows what is on hand '
            'at each place, and its history.',
      ),
    ],
  ),
  'inventory/Orders': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'Orders, sales, issues',
        body:
            'Purchase orders, sales and stock issued, in one list, with how '
            'far along each is. Click one to open it.',
      ),
      TourStep(
        anchor: 'tour-inventory-orders-tiles',
        title: 'Narrow the list',
        body: 'To receive, open purchase orders, sales or issues.',
      ),
      TourStep(
        anchor: 'tour-inventory-orders-add',
        title: 'Add purchase order',
        body: 'Starts an order to a vendor, beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-orders-issue',
        title: 'Issue',
        body: 'Records stock leaving a place, against a job or with why.',
      ),
      TourStep(
        anchor: 'tour-inventory-orders-sale',
        title: 'Add sale',
        body: 'Records a sale, beside the list.',
      ),
      TourStep(
        anchor: 'inventory-pane',
        title: 'Beside the list',
        body: 'The order, sale or issue you opened, or a new one.',
      ),
    ],
  ),
  'inventory/Locations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'Your places',
        body:
            'Each place stock is kept, its kind and how many items it holds. '
            'Click one to open it beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-locations-add',
        title: 'Add a location',
        body: 'Opens a new place beside the list.',
      ),
      TourStep(
        anchor: 'tour-inventory-locations-tiles',
        title: 'By kind',
        body: 'All places, storage, shops or vans, with counts.',
      ),
      TourStep(
        anchor: 'tour-inventory-locations-search',
        title: 'Find a place',
        body: 'By name; press Enter to search.',
      ),
      TourStep(
        anchor: 'tour-inventory-locations-closed',
        title: 'Closed',
        body: 'Includes the places that are closed.',
      ),
      TourStep(
        anchor: 'inventory-pane',
        title: 'The place',
        body: 'The place you opened, or a new one.',
      ),
    ],
  ),

  // ── Jobs ─────────────────────────────────────────────────────────────
  // Materials is a section of a job, not a strip of its own.
  'jobs/Materials': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-jobs-list',
        title: 'Open a job',
        body: 'Materials show inside a job. Open one from the list.',
      ),
      _jobMaterials,
    ],
  ),
  'jobs/Project Management': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-jobs-pm-stages',
        title: 'Stage',
        body:
            'Waiting, Partly paid, Paid in full or Closed, each with how many '
            'jobs are in it.',
      ),
      TourStep(
        anchor: 'tour-jobs-pm-filters',
        title: 'Branch and days',
        body: 'One branch, and only the jobs waiting at least so many days.',
      ),
      TourStep(
        anchor: 'tour-jobs-pm-list',
        title: 'The jobs',
        body:
            'Closest to cancelling first. Click one for its timeline, and Open '
            'the job from there.',
      ),
    ],
  ),
  // The open job's own strip, with Estimates beside Jobs and without.
  'jobs/Jobs/Job': _jobPanelJob,
  'jobs/Job': _jobPanelJob,
  'jobs/Jobs/Money': _jobPanelMoney,
  'jobs/Money': _jobPanelMoney,
  'jobs/Jobs/Photos & files': _jobPanelFiles,
  'jobs/Photos & files': _jobPanelFiles,
  'jobs/Estimates': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-jobs-estimates-list',
        title: 'The estimates',
        body:
            'Newest first, with the customer, job, status and total. Click one '
            'to open it beside the list.',
      ),
      TourStep(
        anchor: 'estimate-new',
        title: 'New estimate',
        body: 'Starts a draft for a customer.',
      ),
      TourStep(
        anchor: 'tour-jobs-estimates-chips',
        title: 'Status',
        body: 'Each chip narrows the list, with how many are in it.',
      ),
      TourStep(
        anchor: 'estimate-search',
        title: 'Find an estimate',
        body:
            'By number or customer; press Enter. Quoted and Accepted beside it '
            'follow the search.',
      ),
      TourStep(
        anchor: 'estimate-steps',
        title: 'Where it stands',
        body:
            'Draft, Sent, then Accepted or Declined. The buttons at the foot '
            'move it on.',
      ),
      TourStep(
        anchor: 'estimate-line-add',
        title: 'Lines',
        body: 'Add a line, or pick a service to add as one.',
      ),
      TourStep(
        anchor: 'estimate-total',
        title: 'Total',
        body: 'The sum of the lines, or the total brought over from A1 Tools.',
      ),
    ],
  ),
};

/// The open record's strip, last on each of its tabs.
const _recordSections = TourStep(
  anchor: 'hr.record.sections',
  title: 'Other parts',
  body:
      'Each part of their record has its own tab. An amber number counts the '
      'boxes there not filled in.',
);

// ── An onboarding's form, opened ─────────────────────────────────────────

const _onboardingProgress = TourStep(
  anchor: 'tour-hr-onboarding-progress',
  title: 'How far it is',
  body:
      'How many questions are answered so far. Save whenever you like; it '
      'picks up where you left off.',
);

const _onboardingApproval = TourStep(
  anchor: 'onboarding-approval',
  title: 'Approval',
  body:
      'Signed by both sides and waiting for approval. Approving it opens the '
      'rest of the app to them.',
);

const _onboardingSigning = TourStep(
  anchor: 'tour-hr-onboarding-signing',
  title: 'Signing',
  body: "The document, what is still to answer, and each side's signature.",
);

const _onboardingPaper = TourStep(
  anchor: 'onboarding-contract',
  title: 'The contract',
  body: 'The document as the answers fill it in, or as it was signed.',
);

const _onboardingForm = Tour(
  version: 1,
  steps: [
    _onboardingProgress,
    _onboardingApproval,
    TourStep(
      anchor: 'tour-hr-onboarding-save',
      title: 'Save',
      body: 'Keeps your answers, and says how many are not saved yet.',
    ),
    _onboardingSigning,
  ],
);

const _onboardingContract = Tour(
  version: 1,
  steps: [
    _onboardingPaper,
    TourStep(
      anchor: 'contract-view',
      title: 'Live or PDF',
      body: 'The contract as text, or as its PDF.',
    ),
    TourStep(
      anchor: 'onboarding-download-pdf',
      title: 'Download PDF',
      body: 'The signed copy, with every signature drawn in.',
    ),
  ],
);

// ── A contract, in the designer ──────────────────────────────────────────

const _contractForm = TourStep(
  anchor: 'tour-hr-contract-form',
  title: 'Set it up',
  body:
      'Name it, then the questions it asks, what each side sees, its cover and '
      'its wording, step by step.',
);

const _contractPage = TourStep(
  anchor: 'tour-hr-contract-page',
  title: 'The contract',
  body: 'The document as it reads, changing as you type.',
);

const _contractFooter = TourStep(
  anchor: 'tour-hr-contract-footer',
  title: 'Create or publish',
  body:
      'A new contract is saved as version 1. An edit publishes a new version; '
      'contracts already signed keep theirs.',
);

// ── An open job ──────────────────────────────────────────────────────────

const _jobMaterials = TourStep(
  anchor: 'tour-jobs-materials',
  title: 'Materials',
  body:
      'What Inventory issued against this job: each item, how many and when. '
      'Add or return them in Inventory.',
);

const _jobTabs = TourStep(
  anchor: 'job-tabs',
  title: 'Job, Money, Photos',
  body: 'Its money, and its photos and files, each on a tab of its own.',
);

const _jobPanelJob = Tour(
  version: 1,
  steps: [
    TourStep(
      anchor: 'tour-jobs-job-work',
      title: 'The work',
      body:
          'Who it is assigned to and who did it. With Financials, the service '
          'lines too, and the total they add up to.',
    ),
    TourStep(
      anchor: 'job-workiz-facts',
      title: 'From Workiz',
      body: 'What Workiz says the job is.',
    ),
    TourStep(
      anchor: 'job-service-area',
      title: 'Service area',
      body:
          "Which of the franchise's areas the job is in. Not placed leaves it "
          'for somebody to place.',
    ),
    _jobMaterials,
    TourStep(
      anchor: 'tour-jobs-inspections',
      title: 'Inspections',
      body: 'The inspection reports submitted on this job.',
    ),
    _jobTabs,
  ],
);

const _jobPanelMoney = Tour(
  version: 1,
  steps: [
    TourStep(
      anchor: 'job-money',
      title: 'Money',
      body:
          "Total, paid and due, and when it is due, from Workiz. Read only: "
          'Workiz is where it changes.',
    ),
    TourStep(
      anchor: 'job-money-lines',
      title: 'The lines',
      body: 'What the total is made of, folded under the figures.',
    ),
    TourStep(
      anchor: 'job-workiz-money',
      title: 'Invoices and payments',
      body:
          "In date order, with Workiz's own estimates and the time spent on "
          'site.',
    ),
    _jobTabs,
  ],
);

const _jobPanelFiles = Tour(
  version: 1,
  steps: [
    TourStep(
      anchor: 'job-workiz-files',
      title: 'Photos and files',
      body:
          "The job's photos, and its other files such as inspection reports. "
          'Click a photo to see it large.',
    ),
    TourStep(
      anchor: 'job-photo-viewer',
      title: 'One photo',
      body: 'Step through them with the arrows; All photos goes back.',
    ),
    _jobTabs,
  ],
);
