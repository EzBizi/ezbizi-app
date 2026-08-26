/// There is nothing to install in a browser. See desktop_update.dart.
///
/// The web build re-fetches itself on every load, so it is never behind in the
/// way an installed app can be. This file exists so the shared code can be
/// written once without `dart:io` reaching a web compile, not because the web
/// has a worse version of the same feature.
bool get canInstall => false;

Future<String?> download(String url, String version) async =>
    'The web version updates itself when you reload the page.';
