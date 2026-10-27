import 'dart:io';

/// What a report says about the machine, off the web. See issue_reporter.dart.
/// A native window's visibility is the app lifecycle, which the reporter
/// already follows, so there is nothing extra to read here.
bool pageVisible() => true;

void onPageHidden(void Function() callback) {}

String platformLabel() => Platform.operatingSystem;

String osVersion() => Platform.operatingSystemVersion;

String? userAgent() => null;

String renderer() => 'native';

int? memoryBytes() {
  try {
    return ProcessInfo.currentRss;
  } catch (_) {
    return null;
  }
}
