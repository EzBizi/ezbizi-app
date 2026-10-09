import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter_test/flutter_test.dart';

/// Rodrigo Mena, 2026-10-07: "enable drag and drop files inside the
/// suggestion tab". A drop keeps what Attach would take, and no more.
void main() {
  const accept = '.png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.md,.txt';

  test('a dropped screenshot and note are kept, anything else is not', () {
    final sorted = sortDrops(
      ['screen.PNG', 'notes.md', 'setup.exe', 'archive.zip', 'README'],
      accept: accept,
      room: 5,
    );

    expect(sorted.keep, {'screen.PNG', 'notes.md'});
    expect(sorted.refused, 3);
  });

  test('a drop stops at the files still allowed', () {
    final sorted = sortDrops(
      ['a.png', 'b.png', 'c.png'],
      accept: accept,
      room: 2,
    );

    expect(sorted.keep, {'a.png', 'b.png'});
    expect(sorted.refused, 1);
  });
}
