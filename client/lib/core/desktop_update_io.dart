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
     * /SILENT shows a progress window but asks nothing — the person already
     * agreed by pressing Update, and a wizard they must click through is how an
     * "automatic" update becomes one nobody completes.
     *
     * /CLOSEAPPLICATIONS lets Inno shut this app down so its files can be
     * replaced, and /RESTARTAPPLICATIONS starts it again afterwards, so the
     * whole thing reads as the app restarting itself.
     *
     * /NORESTART because nothing here justifies rebooting the machine, and an
     * installer that might is one nobody runs during a work day.
     */
    await Process.start(target.path, [
      '/SILENT',
      '/CLOSEAPPLICATIONS',
      '/RESTARTAPPLICATIONS',
      '/NORESTART',
    ], mode: ProcessStartMode.detached);
  } catch (e) {
    return 'The installer would not start. $e';
  }

  return null;
}
