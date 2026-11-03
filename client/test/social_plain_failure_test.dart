import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:flutter_test/flutter_test.dart';

/// The post list shows a network's raw refusal as one plain line (deep dive
/// 2026-11-02 #3); a plain sentence passes through.
void main() {
  test('raw provider text becomes one plain line', () {
    expect(
      plainFailure('unaudited_client_can_only_post_to_private_accounts'),
      "Didn't post. Open it for the details.",
    );
    expect(
      plainFailure('Trial access. See https://developers.example/docs'),
      "Didn't post. Open it for the details.",
    );
    expect(plainFailure('The image is too large.'), 'The image is too large.');
  });
}
