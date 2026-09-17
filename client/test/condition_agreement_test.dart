// The Dart evaluator, against the same table the PHP one is held to.
//
// `conditionPasses` decides what a technician is SHOWN while filling a form in.
// `Condition::passes` in app/Support/Inspection/Condition.php decides what a
// SUBMITTED report contains and what RequiredFields refuses. Neither can be
// deleted — a form that posted to hide a field would be unusable on a roof, and
// the server cannot trust a client's word about what was asked.
//
// What can be deleted is the silence. Nothing failed when they disagreed, from
// either side, and a field the app hides while the server believes it was
// answerable is a required question nobody was asked: the technician sees a
// submission refused for something never put in front of them.
//
// tests/Fixtures/condition_cases.json is the ONE table. Its expectations are
// written from the documented rules rather than recorded from either build, so
// a wrong implementation fails here instead of being enshrined.

import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/screens/inspection/condition.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final file = File('../tests/Fixtures/condition_cases.json');

  test('the shared condition table is actually present', () {
    expect(
      file.existsSync(),
      isTrue,
      reason: 'without the fixture every case below silently vanishes',
    );
  });

  final table = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  final cases = (table['cases'] as List).cast<Map<String, dynamic>>();

  test('the table is the real one, not a stub', () {
    expect(cases.length, greaterThanOrEqualTo(15));
  });

  for (final c in cases) {
    test('dart: ${c['name']}', () {
      expect(
        conditionPasses(
          c['condition'],
          Map<String, dynamic>.from(c['answers'] as Map),
        ),
        c['expected'],
        reason:
            'the Dart evaluator disagrees with the shared table, so it also '
            'disagrees with what the server believes was asked',
      );
    });
  }
}
