import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// `test_player.dart` shows two refusal sentences PRE-EMPTIVELY — before the
/// server has ever refused anything — so the gate reads as a reason rather
/// than a dead button (§4): the guide-not-finished sentence
/// (`_body`, "not `_guideFinished`") and the retake-cap sentence (`_body`,
/// `_capReached`). Both are typed as literal strings copied from
/// `Refusal::trainingGuideNotFinished` / `Refusal::trainingAttemptCapReached`
/// (`app/Support/Access/Refusal.php`) rather than read off a live response —
/// there is no response to read yet at the point they are shown.
///
/// Nothing binds the two copies together: `test_player.dart` matches
/// `Refusal.php` today only because whoever wrote it copied it correctly.
/// If the server sentence is ever reworded, this client keeps showing the
/// OLD text with no test going red — the actual refusal path
/// (`_submitError = response.reason`, shown verbatim) would follow the
/// server immediately, so this drift is invisible until somebody happens to
/// notice the two screens disagree.
///
/// This test is the binding that was missing. [_squash] exists because
/// neither file writes its sentence as one contiguous string literal — PHP
/// wraps `Refusal::trainingGuideNotFinished`'s across two `.`-concatenated
/// literals, and Dart wraps `test_player.dart`'s across two adjacent
/// literals, each at a DIFFERENT word than the other — so a plain
/// `String.contains` on the raw file text matches neither. Squashing
/// quotes/dots/whitespace down to single spaces in both the expected
/// sentence and the file source, before comparing, is what lets one
/// expectation — typed ONCE here — check both files without caring how
/// either one wraps its own literal.
void main() {
  final serverSource = File('../app/Support/Access/Refusal.php').readAsStringSync();
  final clientSource = File('lib/screens/training/test_player.dart').readAsStringSync();

  group('the pre-emptive refusal sentences in test_player.dart match Refusal.php', () {
    // Refusal::trainingGuideNotFinished's exact wording.
    const guideGateSentence =
        'This test requires finishing its guide first, and the guide has not been '
        'finished yet.';

    test('the guide-gate sentence appears (word for word) in both files', () {
      final needle = _squash(guideGateSentence);

      expect(
        _squash(serverSource).contains(needle),
        isTrue,
        reason: 'Refusal::trainingGuideNotFinished (app/Support/Access/Refusal.php) '
            'no longer contains the sentence this test expects — the parity check '
            'itself needs updating alongside whichever file changed.',
      );
      expect(
        _squash(clientSource).contains(needle),
        isTrue,
        reason: "test_player.dart's own copy of Refusal::trainingGuideNotFinished's "
            'sentence has drifted from the server. Update the client copy (or, '
            "better, stop copying it) to match Refusal.php's current wording.",
      );
    });

    // Refusal::trainingAttemptCapReached interpolates a number in the
    // middle — `{$max}` in PHP, a whole cast expression in Dart — so this
    // checks the fixed wording either side of that interpolation rather
    // than one contiguous sentence.
    const maxAttemptsPrefix = 'This test allows at most';
    const maxAttemptsSuffix = 'attempts, and that many have already been made.';

    test('the retake-cap sentence (around its number) appears word for word in both files', () {
      final prefixNeedle = _squash(maxAttemptsPrefix);
      final suffixNeedle = _squash(maxAttemptsSuffix);

      for (final entry in {'server (Refusal.php)': serverSource, 'client (test_player.dart)': clientSource}
          .entries) {
        final squashed = _squash(entry.value);
        expect(
          squashed.contains(prefixNeedle),
          isTrue,
          reason: "${entry.key} is missing 'This test allows at most' — the retake-cap "
              'sentence has drifted.',
        );
        expect(
          squashed.contains(suffixNeedle),
          isTrue,
          reason: "${entry.key} is missing 'attempts, and that many have already been "
              "made.' — the retake-cap sentence has drifted.",
        );
      }
    });
  });
}

/// Quotes, dots (PHP's `.` concatenation operator, and any `.` inside a
/// method call like `.toInt()`) and all whitespace, collapsed to single
/// spaces. Deliberately coarse — this exists only to compare WORDING, not
/// to validate either file's syntax — so it cannot tell a real sentence
/// period from a line-wrap artifact, and does not need to: dropping
/// period-precision on both sides equally still catches an actual wording
/// change, which is the only thing this test exists to catch.
String _squash(String s) =>
    s.replaceAll(RegExp('[\'"\\n\\r\\t.]'), ' ').replaceAll(RegExp(r'\s+'), ' ').trim();
