import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// The regular-session empty-icon failure.
///
/// Incognito was fine because it had no HTTP cache. FontManifest.json is not
/// hashed; nginx had been telling browsers to keep it for 30 days, still
/// naming a font file that the next build had renamed. index.html is
/// no-cache, so it is the one place that can unstick a poisoned copy before
/// the engine reads it. The bootstrap must not start in parallel with that
/// refetch.
void main() {
  test('index.html reloads FontManifest before the engine starts', () {
    final html = File('web/index.html').readAsStringSync();

    expect(html, contains("cache: 'reload'"));
    expect(html, contains('assets/FontManifest.json'));
    expect(
      html.contains('<script src="flutter_bootstrap.js" async></script>'),
      isFalse,
      reason:
          'Loading the bootstrap from a static tag races the FontManifest '
          'refetch and the engine reads the poisoned copy.',
    );
    expect(html, contains("s.src = 'flutter_bootstrap.js'"));
  });
}
