import '../tab_tours.dart';

/// Sub-tab walkthroughs, keyed as tourKeyFor() looks them up (see
/// reports_tours.dart for the pattern).
///
/// AI Assistance, Automations, Email, Services, Training and Work Hours. None
/// of their tabs draws a strip of its own inside it, so every key here is
/// `module/Tab`. A Settings tab drawn by the shared SettingsScreen has one
/// step: that screen's rows carry no fixed keys.
const Map<String, Tour> assistTours = {
  'guidelines/Guidelines': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'guidelines-search',
        title: 'Find a situation',
        body: 'Type a word to narrow the cards: it searches titles and steps.',
      ),
    ],
  ),
  'guidelines/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'guidelines-add',
        title: 'Add guideline',
        body:
            'A title, its steps one per line, a color and an image if you '
            'want one. Click a card to change or delete it.',
      ),
    ],
  ),
  'ai_assistance/Parts': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'ai-parts-search',
        title: 'Find a part',
        body: 'Type a part name, model or number to narrow the list.',
      ),
      TourStep(
        anchor: 'ai-parts-add',
        title: 'Add part',
        body:
            'Its name, numbers and status. Click a part to change or delete '
            'it.',
      ),
    ],
  ),
  'ai_assistance/Manuals': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'ai-manuals-search',
        title: 'Find a manual',
        body: 'Type a brand, model or title to narrow the list.',
      ),
      TourStep(
        anchor: 'ai-manuals-upload',
        title: 'Upload manual',
        body:
            'Pick the file, then its brand, models, title and revision. The '
            'assistant reads its pages to answer.',
      ),
    ],
  ),
  'ai_assistance/Answers': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'ai-answers-telegram',
        title: 'Telegram bot',
        body: 'Whether the bot techs ask is set up.',
      ),
      TourStep(
        anchor: 'ai-answers-search',
        title: 'Find an answer',
        body:
            'Type a word to narrow the answers. Click one to read it, mark it '
            'and send a correction.',
      ),
    ],
  ),
  'ai_assistance/Library': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-ai_assistance-library',
        title: 'The answers',
        body:
            'Grouped by section, as the WhatsApp menu groups them. Each says '
            'where it applies, and whether it is for everyone or staff only. '
            'Click one to read it, or to change it if you can edit.',
      ),
      TourStep(
        anchor: 'tour-ai_assistance-add-answer',
        title: 'Add answer',
        body:
            'Its section, title and words, who sees it, and its order in the '
            'menu. Click an answer to change or delete it.',
      ),
    ],
  ),
  'ai_assistance/WhatsApp groups': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-ai_assistance-whatsapp',
        title: 'WhatsApp groups',
        body:
            'Where each report is sent, and who gets it. Until a group is a '
            'real WhatsApp group, its report goes privately to each member.',
      ),
      TourStep(
        anchor: 'whatsapp-unset',
        title: 'Not set up yet',
        body:
            'Reports with no group, so nothing sends them. Click one to make '
            'its group.',
      ),
      TourStep(
        anchor: 'whatsapp-new',
        title: 'Make a group',
        body: 'Pick the report it gets, name it, and tick who is in it.',
      ),
      TourStep(
        anchor: 'tour-ai_assistance-whatsapp-groups',
        title: 'The groups',
        body:
            'Each with its report and members, up to 8. A member marked no '
            'phone receives nothing.',
      ),
    ],
  ),
  'ai_assistance/Staff bot': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-ai_assistance-staff-bot',
        title: 'Staff bot',
        body:
            "The staff WhatsApp line's menus, one for each group of roles. "
            'Each topic links to what answers it.',
      ),
      TourStep(
        anchor: 'staff-bot-empty',
        title: 'No menus yet',
        body:
            'Until there is one, everybody on the staff line gets the Library.',
      ),
      TourStep(
        anchor: 'tour-ai_assistance-staff-bot-menus',
        title: 'The menus',
        body:
            'Each with how many topics it has, how many need content, and the '
            'roles that get it. Click one to open it.',
      ),
      TourStep(
        anchor: 'staff-bot-new-menu',
        title: 'Add a menu',
        body: 'Type its name, such as Office, then Add menu.',
      ),
      TourStep(
        anchor: 'tour-ai_assistance-staff-bot-who',
        title: 'Who gets it',
        body:
            'Tick the roles that get this menu. A role in no menu gets the '
            'Library; a role in two gets the first.',
      ),
      TourStep(
        anchor: 'staff-bot-items',
        title: 'Its topics',
        body:
            "In WhatsApp's order: drag one to move it, click one to edit it. "
            'Needs content means nothing answers it yet.',
      ),
      TourStep(
        anchor: 'staff-bot-source',
        title: 'The answer',
        body:
            "A Training guide's chapter or section, a Library entry, or a "
            'report. Linked, not copied: change the guide and the bot says the '
            'new words.',
      ),
    ],
  ),
  'ai_assistance/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-ai_assistance-settings',
        title: 'Assistant settings',
        body:
            "The assistant's name and welcome, the locations it searches, and "
            'what it asks new contacts. Anything not set at this level comes '
            'from the one above it.',
      ),
    ],
  ),
  'automations/Build': Tour(
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-automations-name',
        title: 'Name it',
        body: 'What you will see in the list, and in every run it records.',
      ),
      TourStep(
        anchor: 'tour-automations-template',
        title: 'Start from a template',
        body:
            'Fills the form in. Nothing is saved until you press the button '
            'at the bottom.',
      ),
      TourStep(
        anchor: 'tour-automations-trigger',
        title: 'When this happens',
        body:
            'What sets it off. Pick a category, then the event, or search them '
            'all.',
      ),
      TourStep(
        anchor: 'tour-automations-add-condition',
        title: 'Only if',
        body:
            'Add a condition if you want one. Every one has to be true; with '
            'none, it runs every time the event happens.',
      ),
      TourStep(
        anchor: 'tour-automations-add-step',
        title: 'Then do this',
        body: 'Add the steps it takes. They run one after another, in order.',
      ),
      TourStep(
        anchor: 'automation-builder-try',
        title: 'Try it',
        body:
            'Runs the rule as it is now on the newest real event and shows '
            'what each step would do. Nothing is sent or changed.',
      ),
      TourStep(
        anchor: 'tour-automations-save',
        title: 'Save switched off',
        body:
            'Keeps the rule without it running yet. Saving an edit to a rule '
            'that is on makes it the live version.',
      ),
      TourStep(
        anchor: 'tour-automations-turn-on',
        title: 'Turn on',
        body:
            'Saves it and starts it on new events. It stays greyed out while '
            'the checklist above has anything marked ✕.',
        since: 2,
      ),
    ],
  ),
  'email/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-email-add-inbox',
        title: 'Add an inbox',
        body:
            'It belongs to the company you are looking at when you add it. '
            'Each role is then given the ones it should read.',
      ),
      TourStep(
        anchor: 'email-settings-list',
        title: 'Connected inboxes',
        body:
            'Each with who it belongs to, its mail service and whether it '
            'works. Connect, reconnect or disconnect it on its card.',
      ),
      TourStep(
        anchor: 'tour-email-settings-empty',
        title: 'Nothing connected',
        body:
            'Add the address people already write to, then give the roles '
            'that answer it access under Roles & permissions.',
      ),
    ],
  ),
  'email/Google Workspace': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-email-workspace-cards',
        title: 'Your Workspaces',
        body:
            'One card for each connected Google Workspace, with whether its '
            'signatures can be set. With only one, it opens straight away.',
      ),
      TourStep(
        anchor: 'tour-email-workspace-template',
        title: 'Signature template',
        body:
            'Everyone without their own signature gets this. The chips put in '
            "each person's name, title or phone, and the preview shows it for "
            'the person you pick.',
      ),
      TourStep(
        anchor: 'tour-email-workspace-search',
        title: 'Find a person',
        body: 'Type a name, address or title to narrow the people.',
      ),
      TourStep(
        anchor: 'tour-email-workspace-people',
        title: 'People',
        body:
            'Who each address is in EzBizi and which signature they get. Tick '
            'people to set theirs together, or click one to see what Gmail '
            'has now.',
      ),
    ],
  ),
  'services/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'Groups and labels',
        body:
            'What you can assign to a service. Each says which company and '
            'place it belongs to.',
      ),
      TourStep(
        anchor: 'tour-services-groups',
        title: 'Groups',
        body:
            'Add one by name for a company or place, or delete one, as your '
            'role allows.',
      ),
      TourStep(
        anchor: 'tour-services-labels',
        title: 'Labels',
        body: 'The same, for labels.',
      ),
    ],
  ),
  'training/Dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'inventory-list',
        title: 'Guides and tests',
        body:
            'Each with how many are assigned, passed and overdue, and a bar of '
            'passes. Click one for the people behind it.',
      ),
      TourStep(
        anchor: 'tour-training-dashboard-summary',
        title: 'Across everyone',
        body:
            'People assigned, overdue and due for retaking. Each person counts '
            'once, however many they have.',
      ),
      TourStep(
        anchor: 'tour-training-dashboard-counts',
        title: 'This one',
        body: 'How many passed, are in progress, overdue or due for retaking.',
      ),
      TourStep(
        anchor: 'tour-training-dashboard-assign',
        title: 'Assign to more people',
        body: 'Give it to a person or a role, with a due date if you want one.',
      ),
      TourStep(
        anchor: 'tour-training-dashboard-people',
        title: 'The people',
        body:
            'Everyone it is given to that you can see: passed, in progress, '
            'overdue, due for retaking or not started.',
      ),
    ],
  ),
  'training/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'training.settings-list',
        title: 'Guides and tests',
        body:
            'Each marked Published or Draft. Click one to write it, or the bin '
            'to delete it.',
      ),
      TourStep(
        anchor: 'training.screen-strip',
        title: 'Topics',
        body:
            "The guide's topics in order. Click one to edit it, move it with "
            'the arrows on its card, or Add topic at the end.',
      ),
      TourStep(
        anchor: 'training.screen-editor',
        title: 'The topic',
        body:
            'Its title, chapter and section, and its content: text, an image '
            'or video link, or a table.',
      ),
      TourStep(
        anchor: 'training.save-guide',
        title: 'Save the guide',
        body: 'Keeps what you wrote. Until it is published, nobody sees it.',
      ),
      TourStep(
        anchor: 'training.add-question',
        title: 'Questions',
        body: 'Add a question and its choices, and mark the one that is right.',
      ),
      TourStep(
        anchor: 'training.option-pass-mark',
        title: 'Pass mark',
        body:
            'The percent of questions to get right. Below it: retakes allowed, '
            'and when a pass runs out.',
      ),
      TourStep(
        anchor: 'training.save-test',
        title: 'Save the test',
        body: 'Keeps the questions and the options above.',
      ),
    ],
  ),
  'work_hours/Paid Time Off': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-work_hours-pto',
        title: 'Paid Time Off',
        body: 'What people are allowed, and when they may start using it.',
      ),
      TourStep(
        anchor: 'tour-work_hours-pto-not-set-up',
        title: 'Not set up yet',
        body:
            'Nobody has an allowance until it is set under Work Hours › '
            'Settings.',
      ),
      TourStep(
        anchor: 'tour-work_hours-pto-people',
        title: 'Everyone you can see',
        body:
            'Each person with their allowance. It opens on yours; click '
            'someone to see theirs.',
      ),
      TourStep(
        anchor: 'tour-work_hours-pto-allowance',
        title: 'The allowance',
        body: 'Days of paid time off, a week, a month or a year.',
      ),
      TourStep(
        anchor: 'tour-work_hours-pto-when',
        title: 'When to use them',
        body:
            'Now, or the date they open, counted from the day the person '
            'started. With no start date on record, it says it cannot tell.',
      ),
    ],
  ),
  'work_hours/Reports': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-work_hours-reports',
        title: 'Weekly hours reports',
        body:
            'Each says who is in it and who it goes to. Preview shows its hours '
            'for last week or this week so far.',
      ),
      TourStep(
        anchor: 'tour-work_hours-reports-new',
        title: 'New report',
        body: 'Its name, the roles and people in it, and who it is sent to.',
      ),
      TourStep(
        anchor: 'tour-work_hours-reports-empty',
        title: 'No reports yet',
        body:
            'A saved report can be sent by hand, or by an automation rule on a '
            'schedule.',
      ),
    ],
  ),
  'work_hours/Daily approval': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-work_hours-approval',
        title: 'Daily approval',
        body:
            'One day of clock-ins for the people in your view, yesterday '
            'unless you pick another.',
      ),
      TourStep(
        anchor: 'tour-work_hours-approval-day',
        title: 'The day',
        body: 'The arrows move a day; the calendar jumps to any day.',
      ),
      TourStep(
        anchor: 'tour-work_hours-approval-summary',
        title: 'Waiting, approved, no-shows',
        body:
            'A no-show was scheduled and never clocked in. Approve all leaves '
            'them alone.',
      ),
      TourStep(
        anchor: 'tour-work_hours-approval-all',
        title: 'Approve all',
        body: 'Approves every day still waiting, except the no-shows.',
      ),
      TourStep(
        anchor: 'tour-work_hours-approval-rows',
        title: 'Each person',
        body:
            'Their shifts, a bar of the day over the schedule (dashed), the '
            'total against the schedule, and Late or Early. Adjust changes a '
            'time with a reason and keeps the original; it does not approve.',
      ),
    ],
  ),
  'work_hours/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-work_hours-settings',
        title: 'Work Hours settings',
        body:
            'Clock in, shifts and paid time off. Anything not set at this '
            'level comes from the one above it.',
      ),
    ],
  ),
};
