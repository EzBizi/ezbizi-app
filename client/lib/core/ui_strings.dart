/// UI copy loaded from the server (§8). English is the fallback. A missing
/// key stays the key, never a class name.
class UiStrings {
  UiStrings._();

  static String locale = 'en';
  static Map<String, String> _strings = {};

  /// Self-serve signup. The server is the source; default true so a widget
  /// test that never loads /ui-strings still reaches the form.
  static bool signupOpen = true;

  static const Map<String, String> _english = {
    'shell.sign_in': 'Sign in',
    'shell.sign_in_prompt': 'Sign in to your workspace.',
    'shell.create_account': 'Create an account',
    'shell.settings': 'Settings',
    'shell.your_account': 'Your account',
    'shell.sign_out': 'Sign out',
    'shell.find_module': 'Find a module',
    'shell.clear_filter': 'Clear',
    'shell.rearrange': 'Rearrange',
    'shell.add_divider': 'Add a divider',
    'shell.remove_divider': 'Remove this divider',
    'shell.done': 'Done',
    'shell.mobile_view': 'Mobile view',
    'shell.exit_mobile_view': 'Back to desktop view',

    // One line per module, under its name in the page header. The TITLE is
    // the server's `label` and is never written here -- an organization may
    // rename any module (§8) and a hardcoded name would go stale silently.
    // The subtitle has no server field yet, so it lives here where
    // /ui-strings can still override it.
    // UI queue 99: the pages that are not modules get a line too.
    'module.settings.subtitle':
        "How the business works in EzBizi, and every module's settings.",
    'module.suggestions.subtitle':
        'Tell EzBizi what to change, and see what came of it.',
    'module.development.subtitle': 'The EzBizi platform, for EzBizi staff.',
    'module.account.subtitle':
        "Yours, not the organization's. What an administrator controls is "
        'in Settings.',
    'module.dashboard.subtitle':
        'What each part of the business is doing right now.',
    'module.customers.subtitle':
        'Everyone you work for, and the history behind each one.',
    'module.jobs.subtitle':
        'The work itself — booked, in progress, and finished.',
    'module.scheduling.subtitle':
        'What you are set up to take, and what is on the calendar.',
    'module.inspection.subtitle':
        'Build the forms your crews fill in, and read what they filed.',
    'module.hr.subtitle':
        'Everyone on the books, what they have signed, and who is on the clock.',
    'module.marketing.subtitle': 'Reach people who are not customers yet.',
    'module.customer_experience.subtitle':
        'What customers say about you, and how they find you.',
    'module.web_management.subtitle':
        'What your website says about you, held here and rendered there.',
    'module.phone.subtitle': 'Calls, texts, and the lines they arrive on.',
    'module.fleet_management.subtitle':
        'Every vehicle, what it costs, and who is driving it.',
    'module.inventory.subtitle':
        'What you hold, where it is, and what it is worth.',
    'module.financials.subtitle':
        'Bases, tables and views for the numbers you keep yourself.',
    'module.services.subtitle':
        'What you sell, and what each one is priced at.',
    'module.monitoring.subtitle':
        'The machines your people work on, and whether they are healthy.',
    'module.business_profile.subtitle':
        'How this business appears where customers look for it.',
    'module.leads.subtitle':
        'People who asked about you, before they become customers.',
    'module.reviews.subtitle':
        'What customers said in public, and what you said back.',
    'module.automations.subtitle':
        'Rules that do the routine work without being asked.',
    'module.email.subtitle': 'Mail for this business, sent and received here.',
    'module.training.subtitle': 'Guides and tests, and who has passed what.',
    'shell.modules_shown': '{n} of {total} modules',
    'shell.no_module': 'No module called “{q}”.',
    'shell.no_module_hint':
        'The list is filtered, not empty — clear the box to see all '
        '{total} again.',
    'time_clock.clock_in': 'Clock In',
    'time_clock.clock_out': 'Clock Out',
    'time_clock.total': 'Total',
    'time_clock.days_worked': 'Days worked',
    'time_clock.average_per_day': 'Average per day',
    'time_clock.search_people': 'Search people',
    'time_clock.no_hours': 'Nothing clocked this week.',
    'time_clock.show_everyone': 'Show {count} who did not clock in',
    'time_clock.only_clocked_in': 'Only who clocked in',
    'time_clock.all_roles': 'All roles',
    'time_clock.this_week': 'This week',
    'time_clock.previous_week': 'Previous week',
    'time_clock.next_week': 'Next week',
    'time_clock.still_in': 'still clocked in',
    'time_clock.tag.office': 'Office',
    'time_clock.tag.home': 'Home',
    'time_clock.tag.absent': 'Absent',
    'time_clock.tag.sick': 'Sick',
    'time_clock.tag.holiday': 'Holiday',
    'time_clock.tag.vacation': 'Vacation',
    'time_clock.after_hours':
        'Your day ended at {end}. Clocking in adds {minutes} minutes.',
    'time_clock.action.clock_out_now': 'Clock out now',
    'time_clock.action.edit': 'Edit times',
    'time_clock.action.delete': 'Delete shift',
    'time_clock.action.tag': 'Set tag',
    'time_clock.action.extra': 'Extra hours',
    'time_clock.action.note': 'Note',
    'time_clock.shift_actions': 'Shift actions',
    'time_clock.action.request': 'Request a correction',
    'time_clock.request_reason': 'What happened?',
    'time_clock.request_send': 'Send for approval',
    'time_clock.request_sent': 'Sent for approval.',
    'time_clock.requests_title': 'Correction requests',
    'time_clock.request_was': 'Was',
    'time_clock.request_asks': 'Asks for',
    'time_clock.approve': 'Approve',
    'time_clock.decline': 'Decline',
    'time_clock.day_actions': 'Day actions',
    'time_clock.delete_confirm': 'Delete this shift? This cannot be undone.',
    'time_clock.extra_time': 'Extra time',
    'time_clock.no_tag': 'No tag',
    'time_clock.hours': 'Hours',
    'time_clock.minutes': 'Minutes',
    'time_clock.edited': 'Edited',
    'time_clock.corrected': 'Shift corrected',
    'time_clock.action.add_shift': 'Add shift',
    'time_clock.action.request_day': 'Request hours for this day',
    'time_clock.missing_day': 'Nothing clocked',
    'time_clock.using_business_hours':
        'No schedule of their own — these are the business hours.',
    'time_clock.ended_by_hr': 'Ended by HR',
    'time_clock.reminder_title': 'Your shift is ending',
    'time_clock.reminder_body':
        'Your shift ends at {time}. Clock out, or extend it.',
    'time_clock.extend': 'Extend {minutes} min',
    'time_clock.lock_sentence': 'Clock in to start your day.',
    'time_clock.sign_out': 'Sign out',
    'time_clock.skip_lock': 'Continue without clocking in',
    'time_clock.auto': 'Auto',
    'time_clock.outside': 'Outside schedule',
    'time_clock.day_off': 'Day off',
    // The phone row leads with today, so it needs today's words. "Off today"
    // and "no schedule set" are OPPOSITE facts that both used to render as
    // "Day off" -- one says the schedule answered, the other says nobody has
    // written one.
    'time_clock.today_hours': 'Today {start} – {end}',
    'time_clock.off_today': 'Off today',
    'time_clock.schedule_not_set': 'Not set yet',
    'time_clock.no_schedule': 'No working hours have been set for you yet.',
    'time_clock.work_schedule': 'Work schedule',
    'time_clock.not_scheduled': 'You are not on a scheduled shift.',
    'time_clock.overnight':
        'A shift that crosses midnight is not supported yet.',
    'time_clock.exempt_on': 'Exempt from lock screen',
    'time_clock.exempt_off': 'Lock screen applies',
  };

  static void adopt(Map<String, dynamic> payload) {
    locale = payload['locale'] as String? ?? 'en';
    signupOpen = payload['signup_open'] as bool? ?? true;
    final raw = payload['strings'];
    if (raw is Map) {
      _strings = {
        for (final entry in raw.entries)
          if (entry.key is String && entry.value is String)
            entry.key as String: entry.value as String,
      };
    }
  }

  static String t(String key) => _strings[key] ?? _english[key] ?? key;

  /// The string for [key], or null when nothing has one.
  ///
  /// [t] falls back to the key itself, which is right for a label that must
  /// show something. A subtitle is optional, and printing
  /// `module.forecasting.subtitle` under a page title is worse than printing
  /// nothing at all -- most of all for a module this build has never heard of,
  /// which is exactly what a server newer than the app will send.
  static String? maybe(String key) => _strings[key] ?? _english[key];
}
