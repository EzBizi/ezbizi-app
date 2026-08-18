/// UI copy loaded from the server (§8). English is the fallback. A missing
/// key stays the key, never a class name.
class UiStrings {
  UiStrings._();

  static String locale = 'en';
  static Map<String, String> _strings = {};

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
  };

  static void adopt(Map<String, dynamic> payload) {
    locale = payload['locale'] as String? ?? 'en';
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
