import 'package:ezbizi_client/core/sidebar_order.dart';
import 'package:flutter_test/flutter_test.dart';

/// A saved sidebar order, applied to the modules somebody actually has.
///
/// The rule the whole file is about: an order is a preference about
/// ARRANGEMENT. Nothing in it may decide what is IN the sidebar. Every case
/// below is a way a stored order and the real module list can disagree, and in
/// all of them the answer has the same length as the module list.
void main() {
  ({List<String> rows, Set<int> divideAfter}) arrange(
    List<String> modules,
    List<String> order,
  ) {
    final result = arrangeSidebar<String>(modules, order, (m) => m);

    return (rows: result.rows, divideAfter: result.divideAfter);
  }

  test('no saved order is the server order, with no rules', () {
    final result = arrange(['a', 'b', 'c'], []);

    expect(result.rows, ['a', 'b', 'c']);
    expect(result.divideAfter, isEmpty);
  });

  test('the saved order is the order', () {
    expect(arrange(['a', 'b', 'c'], ['c', 'a', 'b']).rows, ['c', 'a', 'b']);
  });

  test('a module the order never mentions goes last, not missing', () {
    // What everybody who arranged their sidebar sees the day a module is
    // added. Dropping it would make new modules invisible to exactly the
    // people who use the product most.
    expect(arrange(['a', 'b', 'c'], ['c', 'a']).rows, ['c', 'a', 'b']);
  });

  test('a module the order names but this person does not have is skipped', () {
    // A module switched off, or one their role stopped reaching. The position
    // is lost; nothing else is.
    expect(arrange(['a', 'b'], ['b', 'gone', 'a']).rows, ['b', 'a']);
  });

  test('a module named twice is placed once, where it first appears', () {
    final result = arrange(['a', 'b'], ['b', 'a', 'b']);

    expect(result.rows, ['b', 'a']);
    expect(result.rows.length, 2, reason: 'A module cannot be in two places.');
  });

  test('a rule is a position between two rows', () {
    expect(arrange(['a', 'b', 'c'], ['a', '--', 'b', 'c']).divideAfter, {0});
  });

  /// What somebody sees the first time they open the sidebar after a module
  /// was removed (the deploy desk asked, 2026-09-11).
  ///
  /// A rule is a boundary BETWEEN two neighbours, so it stays between the same
  /// two survivors and the gap closes. Nothing is revealed only in the
  /// arranger: the rail renders through here on every build, so the group
  /// closed up the moment the module went.
  group('a removed module moves the rule rather than misplacing it', () {
    test('the rule stays under the row that still precedes it', () {
      // The discriminating case. `divideAfter` counts positions in the RESULT,
      // so a rule after a removed module lands on the survivor above it. An
      // implementation counting positions in the saved ORDER instead gets 1
      // here and draws the rule one row too low — and would agree with the
      // correct answer in every test above, because none of them drops a
      // module before a rule.
      expect(arrange(['a', 'b'], ['gone', 'a', '--', 'b']).divideAfter, {0});
    });

    test('the gap closes and the boundary holds', () {
      final result = arrange(['a', 'b', 'c'], ['a', 'gone', '--', 'b', 'c']);

      expect(result.rows, ['a', 'b', 'c']);
      expect(result.divideAfter, {0}, reason: 'Still above b, which is still '
          'the first row of the group under it.');
    });
  });

  group('a rule with nothing on one side of it is not drawn', () {
    test('leading', () {
      expect(arrange(['a', 'b'], ['--', 'a', 'b']).divideAfter, isEmpty);
    });

    test('trailing', () {
      // Along the bottom of the list it reads as the sidebar's own edge
      // rather than as a break inside it.
      expect(arrange(['a', 'b'], ['a', 'b', '--']).divideAfter, isEmpty);
    });

    test('two touching', () {
      expect(arrange(['a', 'b'], ['a', '--', '--', 'b']).divideAfter, {0});
    });

    test('trailing because the module under it is gone', () {
      // The order was perfectly good when it was saved. It became a trailing
      // rule under somebody who never touched it.
      expect(arrange(['a', 'b'], ['a', 'b', '--', 'gone']).divideAfter, isEmpty);
    });
  });

  test('what is rendered is what is saved back', () {
    const order = ['b', '--', 'a', 'c'];
    final result = arrangeSidebar<String>(['a', 'b', 'c'], order, (m) => m);

    expect(
      sidebarOrderOf(result.rows, result.divideAfter, (m) => m),
      order,
      reason:
          'Opening the arranger and pressing Done without moving anything '
          'must not rewrite the order.',
    );
  });
}
