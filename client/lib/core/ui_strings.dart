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
    'shell.email': 'Email',
    'shell.password': 'Password',
    'shell.language': 'Language',
    'shell.find_module': 'Find a module',
    'shell.clear_filter': 'Clear',
    'shell.modules_shown': '{n} of {total} modules',
    'shell.no_module': 'No module called “{q}”.',
    'shell.no_module_hint':
        'The list is filtered, not empty — clear the box to see all '
        '{total} again.',
    'time_clock.clock_in': 'Clock In',
    'time_clock.clock_out': 'Clock Out',
    'time_clock.tab': 'Clock in\u2013out',
    'time_clock.empty': 'Nobody clocked in on this day.',
    'time_clock.times_in': 'Times in {zone}',
    'time_clock.in': 'In',
    'time_clock.off': 'Clock in is switched off for this organization.',
    'time_clock.already_in': 'You are already clocked in.',
    'time_clock.not_in': 'You are not clocked in.',
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
}
