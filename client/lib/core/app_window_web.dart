import 'package:web/web.dart' as web;

/// See app_window.dart. A browser window opened by "Open in new window" carries
/// `window=1`, so it knows it is not the first. A reload drops it.
// ponytail: a reload of a second window makes it a first one (two softphones);
// keep the flag in the address if that ever bites.
Uri? start() => Uri.base.queryParameters['window'] == '1' ? Uri.base : null;

bool get canOpen => true;

Future<void> open(String path) async {
  final at = Uri.base.resolve(path);
  final url = at.replace(
    queryParameters: {...at.queryParameters, 'window': '1'},
  );
  // Not `noopener`: the new window must get a copy of this one's
  // sessionStorage, which is where a "don't keep me signed in" token lives.
  // A size makes it a window rather than a tab.
  web.window.open('$url', '_blank', 'width=1280,height=900');
}

void watchFirst() {}

Future<void> setTitle(String title) async {}
