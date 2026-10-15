import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'issue_reporter.dart';

/// Reads this machine's hardware/OS/network specs over the Windows native
/// channel. Returns null on any non-Windows platform, and null (never throws)
/// if the native handler is absent or a read fails — an old Windows build or a
/// transient failure must degrade to activity-only, not crash.
class SpecsReader {
  const SpecsReader();

  static const MethodChannel _channel = MethodChannel('com.ezbizi.app/monitoring_specs');

  Future<Map<String, dynamic>?> read() async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.windows) return null;
    try {
      final result = await IssueReporter.trackNative(
        'readSpecs',
        () => _channel.invokeMapMethod<String, dynamic>('readSpecs'),
      );
      return (result == null || result.isEmpty) ? null : result;
    } catch (e) {
      // Any failure degrades to activity-only rather than crashing the
      // heartbeat's unawaited refresh — a missing native handler
      // (MissingPluginException), a channel error (PlatformException), and,
      // in a plain `test()` block that never initializes the Flutter test
      // binding, ServicesBinding.instance itself throwing, are all the same
      // case from this reader's point of view: no specs this time. Logged in
      // debug only so a genuinely broken native channel is not silently
      // invisible during development.
      if (kDebugMode) debugPrint('SpecsReader.read failed: $e');
      return null;
    }
  }
}
