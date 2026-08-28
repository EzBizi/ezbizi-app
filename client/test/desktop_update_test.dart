import 'package:ezbizi_client/core/desktop_update.dart';
import 'package:flutter_test/flutter_test.dart';

/// The comparison that decides whether somebody is offered a new build.
///
/// It is a handful of lines and it is the only real logic in the updater, so it
/// is the only part worth pinning. Everything around it is a download and a
/// process launch, which a unit test can only lie about.
///
/// The case that matters is 99 against 100. Comparing these as strings — the
/// obvious thing, and what sorting does by default — concludes that 0.0.0099 is
/// newer than 0.0.0100, so the day the version rolls over, every installed
/// client stops being offered updates and nobody finds out for a month.
void main() {
  test('a later build is newer', () {
    expect(DesktopUpdate.isNewer('0.0.0099', '0.0.0098'), isTrue);
    expect(DesktopUpdate.isNewer('0.1.0000', '0.0.9999'), isTrue);
    expect(DesktopUpdate.isNewer('1.0.0000', '0.9.9999'), isTrue);
  });

  test('the same build is not newer, so nobody is nagged forever', () {
    expect(DesktopUpdate.isNewer('0.0.0098', '0.0.0098'), isFalse);
  });

  test('an earlier build is not newer, so nobody is offered a downgrade', () {
    expect(DesktopUpdate.isNewer('0.0.0097', '0.0.0098'), isFalse);
    expect(DesktopUpdate.isNewer('0.0.0099', '0.1.0000'), isFalse);
  });

  test('segments are numbers, not text — 100 beats 99', () {
    expect(DesktopUpdate.isNewer('0.0.0100', '0.0.0099'), isTrue);
    expect(DesktopUpdate.isNewer('0.0.0099', '0.0.0100'), isFalse);

    // Zero padding is cosmetic on both sides.
    expect(DesktopUpdate.isNewer('0.0.100', '0.0.0099'), isTrue);
    expect(DesktopUpdate.isNewer('0.0.0030', '0.0.30'), isFalse);
  });

  test('a version that is not numbers offers nothing', () {
    // A manifest somebody hand-edited, or a login page served as JSON. The
    // answer is "no update", never a guess — a guess here downloads and runs
    // an executable.
    expect(DesktopUpdate.isNewer('nightly', '0.0.0098'), isFalse);
    expect(DesktopUpdate.isNewer('0.0.0099', 'dev'), isFalse);
    expect(DesktopUpdate.isNewer('', '0.0.0098'), isFalse);
  });

  test('a shorter version is padded, not truncated', () {
    expect(DesktopUpdate.isNewer('0.1', '0.0.0098'), isTrue);
    expect(DesktopUpdate.isNewer('0.0', '0.0.0098'), isFalse);
  });
}
