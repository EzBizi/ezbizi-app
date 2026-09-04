import 'dart:io';

import 'package:http/http.dart' as http;

/// Fetching and running an installer, off the web. See desktop_update.dart.
///
/// Windows only for now, and it says so rather than half-working: a macOS build
/// ships as a .app inside a .dmg and a Linux one as whatever the distribution
/// wants, and neither is "run the file and it replaces itself". Those need
/// their own answer, not this one with the extension changed.
bool get canInstall => Platform.isWindows;

/// Download to a temporary file and hand it to Windows.
///
/// Returns null when the installer has been launched. Anything else is a
/// sentence explaining why it was not.
Future<String?> download(String url, String version) async {
  if (!Platform.isWindows) {
    return 'Automatic update is only available on Windows for now.';
  }

  final File target = File(
    '${Directory.systemTemp.path}${Platform.pathSeparator}'
    'EzBizi-$version-setup.exe',
  );

  try {
    final res = await http
        .get(Uri.parse(url))
        .timeout(const Duration(minutes: 10));

    if (res.statusCode != 200) {
      return 'The download did not arrive (${res.statusCode}). '
          'Try again in a moment.';
    }

    // Guard against a proxy or a captive portal handing back a login page with
    // a 200. An Inno installer starts "MZ" like every Windows executable, and
    // running an HTML file as one would fail in a far more confusing way.
    if (res.bodyBytes.length < 2 ||
        res.bodyBytes[0] != 0x4D ||
        res.bodyBytes[1] != 0x5A) {
      return 'What downloaded was not an installer. '
          'Check the connection and try again.';
    }

    await target.writeAsBytes(res.bodyBytes, flush: true);
  } catch (e) {
    return 'The update could not be downloaded. $e';
  }

  try {
    /*
     * Detached, because this process is about to be one of the things the
     * installer closes.
     *
     * /VERYSILENT shows nothing at all. /SILENT was the first attempt and it
     * still put an installer progress window on screen, which is what Jose saw
     * updating 0.0.0129 → 0.0.0131: the app is replacing itself, and a setup
     * window appearing over your work makes that feel like installing software
     * rather than an app restarting. The person already agreed by pressing
     * Update; there is nothing left to tell them.
     *
     * What they do see is the spinner in the build stamp, then the app closing
     * and coming back — which is the whole story.
     *
     * Deliberately NOT /SUPPRESSMSGBOXES. Inno still raises a message box if
     * the install actually fails, and that is the one moment silence would be
     * wrong: the app would close, reopen on the old version, and nothing would
     * ever say why.
     *
     * /CLOSEAPPLICATIONS lets Inno shut this app down so its files can be
     * replaced.
     *
     * /RESTARTAPPLICATIONS is deliberately NOT passed. It asks the Windows
     * Restart Manager to relaunch what it closed, and on 1 Sep it did not —
     * the update installed and the app stayed shut. The installer's [Run]
     * entry starts the app instead. Passing this flag as well would override
     * RestartApplications=no in the .iss and put both mechanisms back in play,
     * which risks two copies of the app running.
     *
     * /NORESTART because nothing here justifies rebooting the machine, and an
     * installer that might is one nobody runs during a work day.
     */
    await Process.start(target.path, [
      '/VERYSILENT',
      '/CLOSEAPPLICATIONS',
      '/NORESTART',
    ], mode: ProcessStartMode.detached);
  } catch (e) {
    return 'The installer would not start. $e';
  }

  return null;
}
