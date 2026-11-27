import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/capacity_settings.dart';
import 'package:ezbizi_client/widgets/error_note.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Capacity settings — design docs/previews/scheduling-layouts.html #7.
///
/// The check worth having is that CLOSING A DAY SENDS NO ROWS FOR IT. A close
/// that wrote `max_jobs: 0` would satisfy every other test here — the day would
/// still look closed on screen — while collapsing "we do not work Sundays" into
/// "we work Sundays and are full" in storage, with nothing erroring. So this
/// asserts on the REQUEST BODY, which is the only place the difference shows.
void main() {
  testWidgets(
    'the rail pins Overview above the areas and marks one that differs',
    (tester) async {
      await _pump(tester);

      expect(find.text('Overview'), findsOneWidget);

      // Twice each now: once in the rail, once as a row in the Overview's
      // all-areas table. The table replaced three cards that each answered a
      // smaller question.
      expect(find.text('Dallas'), findsNWidgets(2));
      expect(find.text('Houston'), findsNWidgets(2));

      // The rail still marks an off-pattern area, which is where it is useful.
      expect(find.textContaining('differs'), findsOneWidget);
    },
  );

  /// The Overview answers "what can each area take", which is what the three
  /// cards it replaced were each circling: a slot pattern, an Add button the
  /// rail already had, and a list of areas that differ from the pattern. A
  /// table of every area's week SHOWS the differences, so naming them
  /// separately was a worse version of the same answer.
  testWidgets('the overview shows every area week, and a closed day as a dash', (
    tester,
  ) async {
    await _pump(tester);

    // Every weekday is a column.
    for (final day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) {
      expect(find.text(day), findsWidgets, reason: '$day should be a column.');
    }

    // Houston takes 1 on Thursday where Dallas takes 2 — visible without any
    // card having to say "Houston differs".
    expect(find.text('1'), findsWidgets);
    expect(find.text('3'), findsWidgets);

    // Dallas is closed on Sunday, Houston on Saturday and Sunday: three dashes,
    // never a zero. A zero would be a day that is open and takes nothing, which
    // the storage cannot express.
    expect(find.text('—'), findsNWidgets(3));
    expect(find.text('0'), findsNothing);
  });

  /// The three removed cards, asserted gone rather than assumed gone.
  testWidgets(
    'the overview no longer carries the pattern, add or differs cards',
    (tester) async {
      await _pump(tester);

      expect(find.textContaining('slot pattern'), findsNothing);
      expect(find.textContaining('followed by'), findsNothing);
      expect(find.text('Areas that differ'), findsNothing);
      expect(find.textContaining('follows the pattern'), findsNothing);

      // Add lives in the rail only, and still exists — this is the control that
      // stops "findsNothing" above passing for a screen that lost the action
      // entirely.
      expect(
        find.widgetWithText(FilledButton, 'Add service area'),
        findsNothing,
      );
      expect(find.byTooltip('Add service area'), findsOneWidget);
    },
  );

  /// The defect this screen shipped with: it listed areas and had no way to
  /// make one. With zero areas that is a dead end.
  testWidgets('an empty screen offers a way to create the first area', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      areas: const [],
      pattern: null,
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/scheduling/areas')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail}, 201);
        }
        return null;
      },
    );

    expect(find.byTooltip('Add service area'), findsOneWidget);

    await tester.tap(find.byTooltip('Add service area'));
    await tester.pumpAndSettle();

    // A PANE, not a dialog. A modal over a split screen hides the rail you
    // were reading to decide what to type.
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const Key('scheduling-area-name')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('scheduling-area-name')),
      'Dallas',
    );
    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull, reason: 'Creating an area should POST it.');
    expect(posted!['name'], 'Dallas');
    expect(
      posted!['franchise_id'],
      'fr-1',
      reason: 'One franchise in view is filled in without asking.',
    );
  });

  /// The bug reported from the app: at company level, with two franchises in
  /// view, the screen REFUSED to open the form and said to go and change the
  /// switcher. The one control that could have answered "which franchise" was
  /// the control being withheld.
  testWidgets('at company level the form asks which franchise, not refuses', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      areas: const [],
      pattern: null,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
        ],
      },
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/scheduling/areas')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail}, 201);
        }
        return null;
      },
    );

    await tester.tap(find.byTooltip('Add service area'));
    await tester.pumpAndSettle();

    // It opened, and it is asking.
    expect(find.byKey(const Key('scheduling-area-franchise')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('scheduling-area-name')),
      'Dallas',
    );

    // Submitting without choosing says so IN THE PANE and does not blank the
    // screen. Sending this to the load error produced an empty page with one
    // red box on it.
    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNull, reason: 'It must not guess a franchise.');
    expect(find.byType(ErrorNote), findsOneWidget);
    expect(
      find.byKey(const Key('scheduling-area-name')),
      findsOneWidget,
      reason: 'The form survives its own error.',
    );

    await tester.tap(find.byKey(const Key('scheduling-area-franchise')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Georgia').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Add service area'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['franchise_id'], 'fr-2');
  });

  /// Items, not a comma-separated string. The old field took the whole set as
  /// one string on one Save, so a single zip held by another area refused
  /// every edit at once and named one of them.
  testWidgets(
    'a zip is added as an item, with its city, and saves on its own',
    (tester) async {
      final saves = <Map<String, dynamic>>[];

      await _pump(
        tester,
        onRequest: (request) {
          if (request.method == 'PUT' && request.url.path.endsWith('/zips')) {
            saves.add(jsonDecode(request.body) as Map<String, dynamic>);
            return _json({'data': _dallasDetail});
          }
          return null;
        },
      );

      await tester.tap(find.text('Dallas').first);
      await tester.pumpAndSettle();

      await _scrollPaneTo(
        tester,
        find.byKey(const Key('scheduling-zip-entry')),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('scheduling-zip-entry')),
        '75202',
      );
      await tester.enterText(
        find.byKey(const Key('scheduling-city-entry')),
        'Plano',
      );
      await tester.tap(find.byTooltip('Add zip code'));
      await tester.pumpAndSettle();

      expect(saves, hasLength(1), reason: 'Adding one zip saves immediately.');

      final zips = (saves.first['zips'] as List).cast<Map<String, dynamic>>();

      expect(zips.map((z) => z['zip']), containsAll(['75201', '75202']));

      final added = zips.firstWhere((z) => z['zip'] == '75202');
      expect(added['city'], 'Plano');
    },
  );

  testWidgets('removing a zip sends the set without it', (tester) async {
    final saves = <Map<String, dynamic>>[];

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/zips')) {
          saves.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    await _scrollPaneTo(tester, find.byKey(const ValueKey('zip-75201')));
    await tester.pumpAndSettle();

    // The chip's own delete affordance, so one zip goes without retyping the
    // other forty-nine.
    await tester.tap(find.byTooltip('Remove 75201'));
    await tester.pumpAndSettle();

    expect(saves, hasLength(1));
    expect(saves.first['zips'] as List, isEmpty);
  });

  /// Delete asks where the area's ZIPs go, in a side pane (UI queue 125), and
  /// stays off until somebody chooses. Leaving them sends no move_zips_to.
  testWidgets('delete asks where the ZIPs go; leaving them sends no target', (
    tester,
  ) async {
    Uri? deleted;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = request.url;
          return _json({
            'data': {'moved': 0, 'unassigned': 1, 'moved_to': null},
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    await _reveal(tester, find.text('Delete area'));
    await tester.tap(find.text('Delete area'));
    await tester.pumpAndSettle();

    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Dallas has 1 ZIP. Where should it go?'), findsOneWidget);

    // Nothing chosen: Delete does nothing.
    await tester.tap(find.byKey(const ValueKey('area-delete-go')));
    await tester.pumpAndSettle();
    expect(deleted, isNull, reason: 'No choice made, so nothing is deleted.');

    await tester.tap(find.byKey(const ValueKey('area-delete-leave')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-delete-go')));
    await tester.pumpAndSettle();

    expect(deleted?.path, endsWith('/scheduling/areas/a-dallas'));
    expect(deleted!.queryParameters.containsKey('move_zips_to'), isFalse);
    expect(find.text('1 now unassigned'), findsOneWidget);
  });

  testWidgets('delete can move the ZIPs to another area of the branch', (
    tester,
  ) async {
    Uri? deleted;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = request.url;
          return _json({
            'data': {
              'moved': 1,
              'unassigned': 0,
              'moved_to': {'id': 'a-houston', 'name': 'Houston'},
            },
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();
    await _reveal(tester, find.text('Delete area'));
    await tester.tap(find.text('Delete area'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('area-delete-move')));
    await tester.pumpAndSettle();
    // Move chosen and no area picked yet: still off.
    await tester.tap(find.byKey(const ValueKey('area-delete-go')));
    await tester.pumpAndSettle();
    expect(deleted, isNull);

    await tester.tap(find.byKey(const ValueKey('area-delete-to')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Houston').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('area-delete-go')));
    await tester.pumpAndSettle();

    expect(deleted?.queryParameters['move_zips_to'], 'a-houston');
    expect(find.text('1 moved to Houston'), findsOneWidget);
  });

  testWidgets('closing a day sends no rows for it, never a zero', (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' && request.url.path.endsWith('/slots')) {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    // Dallas is open on Monday. Close it.
    expect(find.text('Close'), findsWidgets);
    await tester.tap(find.text('Close').first);
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Save Dallas'));
    await tester.pumpAndSettle();

    expect(saved, isNotNull, reason: 'The grid should have been saved.');

    final slots = (saved!['slots'] as List).cast<Map<String, dynamic>>();

    // The closed weekday is simply absent, and nothing carries a zero.
    expect(
      slots.where((s) => s['weekday'] == 1),
      isEmpty,
      reason: 'A closed day must send no rows at all.',
    );
    expect(
      slots.where((s) => s['max_jobs'] == 0),
      isEmpty,
      reason: 'Zero capacity would make "closed" and "full" the same state.',
    );
  });

  /// Twenty-six areas across several franchises came out as one flat list with
  /// nothing saying which franchise owned which — and the same area name can
  /// legitimately sit under two franchises, so those were indistinguishable.
  testWidgets('the rail groups areas under the franchise that owns them', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
        ],
      },
      areas: const [
        {
          'id': 'a-1',
          'name': 'East',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
        },
        {
          'id': 'a-2',
          'name': 'Atlanta North',
          'franchise_id': 'fr-2',
          'zip_count': 4,
          'follows_pattern': true,
        },
      ],
      pattern: null,
    );

    // One header per franchise, so a row is never orphaned from its owner.
    // The headers are the rail's alone; the area NAMES now appear twice, once
    // in the rail and once as a row in the Overview's table.
    expect(find.text('CONNECTICUT'), findsOneWidget);
    expect(find.text('GEORGIA'), findsOneWidget);
    expect(find.text('East'), findsNWidgets(2));
    expect(find.text('Atlanta North'), findsNWidgets(2));
  });

  /// Jose, 2026-09-25, asking for the Overview to look like the area editor:
  /// each franchise heads its areas with the day's total across them, and an
  /// area with no capacity at all says so instead of seven dashes.
  testWidgets('the overview totals each franchise and flags areas not set up', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
        ],
      },
      areas: const [
        {
          'id': 'a-1',
          'name': 'East',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
          'week': {
            '1': 4,
            '2': 4,
            '3': 4,
            '4': 4,
            '5': 2,
            '6': null,
            '0': null,
          },
        },
        {
          'id': 'a-2',
          'name': 'West',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
          'week': {'1': 3, '2': 3, '3': 3, '4': 3, '5': 3, '6': 1, '0': null},
        },
        {
          'id': 'a-3',
          'name': 'Atlanta North',
          'franchise_id': 'fr-2',
          'zip_count': 4,
          'follows_pattern': false,
          'week': {
            '1': null,
            '2': null,
            '3': null,
            '4': null,
            '5': null,
            '6': null,
            '0': null,
          },
        },
      ],
      pattern: null,
    );

    Finder inRow(String key, String text) => find.descendant(
      of: find.byKey(ValueKey(key)),
      matching: find.text(text),
    );

    // Connecticut: Monday 4 + 3, Friday 2 + 3, Saturday West alone, Sunday
    // closed in both.
    expect(inRow('overview-franchise-Connecticut', '7'), findsNWidgets(4));
    expect(inRow('overview-franchise-Connecticut', '5'), findsOneWidget);
    expect(inRow('overview-franchise-Connecticut', '1'), findsOneWidget);
    expect(inRow('overview-franchise-Connecticut', '—'), findsOneWidget);

    expect(
      inRow('overview-area-a-3', 'Not set up · open it to add capacity'),
      findsOneWidget,
    );
    expect(
      find.textContaining('1 of 3 areas have no capacity yet'),
      findsOneWidget,
    );
  });

  /// Jose, 2026-09-25: "i want to be able to have overview for each franchise
  /// too ... so i can see the daily totals for the whole franchise".
  testWidgets(
    'a franchise heading opens that franchise with its daily totals',
    (tester) async {
      await _pump(
        tester,
        me: const {
          'franchises': [
            {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
            {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
          ],
        },
        areas: const [
          {
            'id': 'a-1',
            'name': 'East',
            'franchise_id': 'fr-1',
            'zip_count': 3,
            'follows_pattern': true,
            'week': {
              '1': 4,
              '2': 4,
              '3': 4,
              '4': 4,
              '5': 2,
              '6': null,
              '0': null,
            },
          },
          {
            'id': 'a-2',
            'name': 'West',
            'franchise_id': 'fr-1',
            'zip_count': 3,
            'follows_pattern': true,
            'week': {'1': 3, '2': 3, '3': 3, '4': 3, '5': 3, '6': 1, '0': null},
          },
          {
            'id': 'a-3',
            'name': 'Atlanta North',
            'franchise_id': 'fr-2',
            'zip_count': 4,
            'follows_pattern': true,
            'week': {
              '1': 9,
              '2': 9,
              '3': 9,
              '4': 9,
              '5': 9,
              '6': null,
              '0': null,
            },
          },
        ],
        pattern: null,
      );

      await tester.tap(find.byKey(const ValueKey('rail-franchise-fr-1')));
      await tester.pumpAndSettle();

      Finder tile(int weekday, String text) => find.descendant(
        of: find.byKey(ValueKey('franchise-total-$weekday')),
        matching: find.text(text),
      );

      expect(tile(1, '7'), findsOneWidget);
      expect(tile(5, '5'), findsOneWidget);
      expect(tile(6, '1'), findsOneWidget);
      expect(tile(0, 'Closed'), findsOneWidget);

      // Its own areas only: Georgia's is not in this table.
      final pane = find.byKey(const Key('scheduling-franchise-overview'));
      expect(
        find.descendant(of: pane, matching: find.text('East')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: pane, matching: find.text('Atlanta North')),
        findsNothing,
      );

      // Seven tiles, one size.
      final sizes = {
        for (var d = 0; d < 7; d++)
          tester.getSize(find.byKey(ValueKey('franchise-total-$d'))),
      };
      expect(sizes, hasLength(1), reason: '$sizes');

      // Overview goes back to the whole view.
      await tester.tap(find.text('Overview'));
      await tester.pumpAndSettle();
      expect(pane, findsNothing);
    },
  );

  /// Jose, 2026-09-25: "i should be able to set a limit of jobs as well for
  /// the franchise itself, since the technicians are shared". The limit shows
  /// under each day's total and saves as weekday => jobs, blank as none.
  testWidgets(
    "a franchise's daily limit is shown and saved from its overview",
    (tester) async {
      Map<String, dynamic>? saved;
      const areas = [
        {
          'id': 'a-1',
          'name': 'Maryland',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
          'week': {'1': 8, '2': 8, '3': 8, '4': 8, '5': 6, '6': null, '0': 8},
        },
        {
          'id': 'a-2',
          'name': 'Atlanta',
          'franchise_id': 'fr-2',
          'zip_count': 3,
          'follows_pattern': true,
          'week': {
            '1': 5,
            '2': 5,
            '3': 5,
            '4': 5,
            '5': 5,
            '6': null,
            '0': null,
          },
        },
      ];

      await _pump(
        tester,
        me: const {
          'franchises': [
            {'id': 'fr-1', 'name': 'DMV', 'company_id': 'co-1'},
            {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
          ],
        },
        onRequest: (request) {
          final path = request.url.path;
          if (request.method == 'GET' && path.endsWith('/scheduling/areas')) {
            return _json({
              'data': areas,
              'pattern': null,
              'franchise_limits': {
                'fr-1': {'1': 15},
              },
            });
          }
          if (request.method == 'PUT' &&
              path.endsWith('/franchises/fr-1/limits')) {
            saved = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({
              'data': {'1': 15, '5': 10},
            });
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const ValueKey('rail-franchise-fr-1')));
      await tester.pumpAndSettle();

      expect(
        find.descendant(
          of: find.byKey(const ValueKey('franchise-total-1')),
          matching: find.text('max 15'),
        ),
        findsOneWidget,
      );
      expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('franchise-limit-1')))
            .controller!
            .text,
        '15',
      );

      await tester.enterText(
        find.byKey(const ValueKey('franchise-limit-5')),
        '10',
      );
      await tester.tap(find.text('Save DMV limit'));
      await tester.pumpAndSettle();

      expect(saved!['limits'], {
        '0': null,
        '1': 15,
        '2': null,
        '3': null,
        '4': null,
        '5': 10,
        '6': null,
      });
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('franchise-total-5')),
          matching: find.text('max 10'),
        ),
        findsOneWidget,
      );
    },
  );

  /// Jose, 2026-09-25: "make the days sticky, cause once you go down u dont
  /// know what day ur looking at anymore".
  testWidgets('the weekday row stays on screen while the areas scroll', (
    tester,
  ) async {
    await _pump(
      tester,
      areas: [
        for (var i = 0; i < 40; i++)
          {
            'id': 'a-$i',
            'name': 'Area $i',
            'franchise_id': 'fr-1',
            'zip_count': 1,
            'follows_pattern': true,
            'week': {
              '1': 2,
              '2': 2,
              '3': 2,
              '4': 2,
              '5': 2,
              '6': null,
              '0': null,
            },
          },
      ],
    );

    final header = find.byKey(const Key('overview-weekdays'));
    final before = tester.getTopLeft(header).dy;

    await tester.drag(
      find.byKey(const ValueKey('overview-area-a-5')),
      const Offset(0, -1500),
    );
    await tester.pumpAndSettle();

    // The areas moved; the weekdays did not leave the screen.
    expect(find.text('Area 0'), findsOneWidget, reason: 'rail still lists it');
    expect(header, findsOneWidget);
    final after = tester.getTopLeft(header).dy;
    expect(after, lessThan(before), reason: 'it rose to the top');
    expect(after, greaterThanOrEqualTo(0));
    expect(
      find.descendant(of: header, matching: find.text('Mon')),
      findsOneWidget,
    );
  });

  /// Jose, 2026-09-28: "we do not have daily limit for service area". It
  /// saves as weekday => jobs, blank as none, and its tile says "max N".
  testWidgets("an area's daily limit is saved and shown on its day", (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' &&
            request.url.path.endsWith('/a-dallas/limits')) {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              ..._dallasDetail,
              'daily_limits': {'1': 1},
            },
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const ValueKey('area-limit-1')), '1');
    await tester.tap(find.text('Save limit'));
    await tester.pumpAndSettle();

    expect(saved!['limits'], {
      '0': null,
      '1': 1,
      '2': null,
      '3': null,
      '4': null,
      '5': null,
      '6': null,
    });
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('daily-total-1')),
        matching: find.textContaining('max 1'),
      ),
      findsOneWidget,
    );
  });

  /// Jose, 2026-09-28: "i also want to see the daily totals in the full
  /// overview". Each area lowered by its own limit, each franchise by its own.
  testWidgets('the full overview totals every area after the limits', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'DMV', 'company_id': 'co-1'},
        ],
      },
      onRequest: (request) =>
          request.method == 'GET' &&
              request.url.path.endsWith('/scheduling/areas')
          ? _json({
              'data': [
                {
                  'id': 'a-1',
                  'name': 'Connecticut',
                  'franchise_id': 'fr-1',
                  'zip_count': 1,
                  'follows_pattern': true,
                  'week': {
                    '1': 16,
                    '2': 16,
                    '3': 16,
                    '4': 16,
                    '5': 8,
                    '6': null,
                    '0': null,
                  },
                  'daily_limits': {'1': 12},
                },
                {
                  'id': 'a-2',
                  'name': 'Maryland',
                  'franchise_id': 'fr-2',
                  'zip_count': 1,
                  'follows_pattern': true,
                  'week': {
                    '1': 8,
                    '2': 8,
                    '3': 8,
                    '4': 8,
                    '5': 6,
                    '6': null,
                    '0': null,
                  },
                },
                {
                  'id': 'a-3',
                  'name': 'Virginia',
                  'franchise_id': 'fr-2',
                  'zip_count': 1,
                  'follows_pattern': true,
                  'week': {
                    '1': 6,
                    '2': 6,
                    '3': 6,
                    '4': 6,
                    '5': null,
                    '6': null,
                    '0': null,
                  },
                },
              ],
              'pattern': null,
              'franchise_limits': {
                'fr-2': {'1': 10},
              },
            })
          : null,
    );

    Finder tile(int weekday, String text) => find.descendant(
      of: find.byKey(ValueKey('overview-total-$weekday')),
      matching: find.text(text),
    );

    // Monday: Connecticut 16 lowered to 12, DMV 8 + 6 lowered to 10.
    expect(tile(1, '22'), findsOneWidget);
    // Tuesday: no limits, 16 + 8 + 6.
    expect(tile(2, '30'), findsOneWidget);
    expect(tile(6, 'Closed'), findsOneWidget);
  });

  /// Eve, 2026-09-29: "change automatically starting from October 30, going
  /// Eve, 2026-09-29: "give 2 service areas, the same availability pool".
  /// Picking a lead saves it, and the member stops offering a week of its own:
  /// the server never reads it, so editing it would change nothing.
  testWidgets('an area shares another area availability pool', (tester) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'PUT' &&
            request.url.path.endsWith(
              '/scheduling/areas/a-dallas/shares-with',
            )) {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {..._dallasDetail, 'shares_with_id': 'a-houston'},
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();
    // The control: at the top of the pane, before sharing, the area's own
    // limits are there to find.
    expect(find.text('DAILY LIMIT FOR DALLAS'), findsOneWidget);

    await _scrollPaneTo(tester, find.byKey(const Key('shares-with')));
    await tester.ensureVisible(find.byKey(const Key('shares-with')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('shares-with')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Houston').last);
    await tester.pumpAndSettle();

    expect(sent, {'shares_with_id': 'a-houston'});
    expect(
      find.textContaining("books into Houston's availability"),
      findsOneWidget,
    );
    await tester.drag(
      find.byKey(const Key('scheduling-area-pane')),
      const Offset(0, 5000),
    );
    await tester.pumpAndSettle();
    expect(find.text('DAILY LIMIT FOR DALLAS'), findsNothing);
    expect(find.text('Save Dallas'), findsNothing);
  });

  /// forward". A week scheduled ahead is listed, opens in the same grid, and
  /// saves to its own date rather than over the current week.
  testWidgets(
    'a week scheduled ahead is edited in the grid and saved to its date',
    (tester) async {
      final puts = <String, Map<String, dynamic>>{};

      await _pump(
        tester,
        onRequest: (request) {
          final path = request.url.path;
          if (path.endsWith('/scheduling/time-slots')) {
            return _json({'data': _twoWindows, 'can_edit': true});
          }
          if (request.method == 'GET' &&
              path.endsWith('/scheduling/areas/a-dallas')) {
            return _json({
              'data': {
                ..._dallasDetail,
                'scheduled': [
                  {
                    'starts_on': '2026-10-30',
                    'slots': [
                      {
                        'weekday': 1,
                        'start_time': '10:00',
                        'end_time': '13:00',
                        'max_jobs': 3,
                      },
                      {
                        'weekday': 5,
                        'start_time': '10:00',
                        'end_time': '13:00',
                        'max_jobs': 2,
                      },
                    ],
                  },
                ],
              },
            });
          }
          if (request.method == 'PUT') {
            puts[path] = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({
              'data': {..._dallasDetail, 'scheduled': const []},
            });
          }
          return null;
        },
      );

      await tester.tap(find.text('Dallas').first);
      await tester.pumpAndSettle();

      expect(find.text('From Fri, Oct 30'), findsOneWidget);
      expect(find.textContaining('Mon 3'), findsOneWidget);

      await _scrollPaneTo(tester, find.byTooltip('Edit this week'));
      await tester.ensureVisible(find.byTooltip('Edit this week'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Edit this week'));
      await tester.pumpAndSettle();

      // The banner and the grid sit above the card: scroll back up to them.
      await tester.scrollUntilVisible(
        find.byKey(const Key('scheduled-banner')),
        -200,
        scrollable: find
            .descendant(
              of: find.byKey(const Key('scheduling-area-pane')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      expect(find.byKey(const Key('scheduled-banner')), findsOneWidget);
      // The grid now shows the scheduled week: Friday 2 in 10–1.
      final friday = tester.widget<EditableText>(
        find.descendant(
          of: find.byKey(const ValueKey('slot-5-10:00-13:00')),
          matching: find.byType(EditableText),
        ),
      );
      expect(friday.controller.text, '2');

      await tester.enterText(
        find.byKey(const ValueKey('slot-5-10:00-13:00')),
        '4',
      );
      await tester.pumpAndSettle();
      await _scrollPaneTo(tester, find.textContaining('Save the week from'));
      await tester.ensureVisible(find.textContaining('Save the week from'));
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('Save the week from'));
      await tester.pumpAndSettle();

      final body =
          puts['/api/v1/scheduling/areas/a-dallas/scheduled/2026-10-30'];
      expect(
        body,
        isNotNull,
        reason: 'Saved to its own date. Puts: ${puts.keys}',
      );
      expect(
        puts.keys.where((k) => k.endsWith('/slots')),
        isEmpty,
        reason: 'The current week was not touched.',
      );
      final friSlots = (body!['slots'] as List)
          .cast<Map<String, dynamic>>()
          .where((s) => s['weekday'] == 5);
      expect(friSlots.map((s) => s['max_jobs']), [4]);
      expect(find.byKey(const Key('scheduled-banner')), findsNothing);
    },
  );

  testWidgets('a narrow overview scrolls its table, not the page', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    // The narrowest screen that draws the Overview beside the rail: the table
    // is wider than its pane here, so it must scroll inside itself.
    await _pump(tester, width: 600);
    expect(
      find.byKey(const ValueKey('overview-area-a-dallas')),
      findsOneWidget,
    );

    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });

  /// One franchise means nothing to disambiguate, so a header would be a
  /// label on the whole screen.
  testWidgets('a single franchise gets no group header', (tester) async {
    await _pump(tester);

    expect(find.text('CONNECTICUT'), findsNothing);

    // Still drawn — the control that stops the assertion above passing for a
    // rail that rendered nothing at all. Twice: rail row and table row.
    expect(find.text('Dallas'), findsNWidgets(2));
  });

  /// Two companies on screen means the bare franchise name is ambiguous —
  /// four Connecticuts under different companies is real data here — so the
  /// header carries the company too. Same rule FranchiseField uses.
  testWidgets('two companies on screen put the company in the header', (
    tester,
  ) async {
    await _pump(
      tester,
      me: const {
        'companies': [
          {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
          {'id': 'co-2', 'name': 'Premier', 'parent_company_id': null},
        ],
        'franchises': [
          {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
          {'id': 'fr-2', 'name': 'Connecticut', 'company_id': 'co-2'},
        ],
      },
      areas: const [
        {
          'id': 'a-1',
          'name': 'East',
          'franchise_id': 'fr-1',
          'zip_count': 3,
          'follows_pattern': true,
        },
        {
          'id': 'a-2',
          'name': 'West',
          'franchise_id': 'fr-2',
          'zip_count': 4,
          'follows_pattern': true,
        },
      ],
      pattern: null,
    );

    expect(find.text('EVERDOORS › CONNECTICUT'), findsOneWidget);
    expect(find.text('PREMIER › CONNECTICUT'), findsOneWidget);
  });

  // ── Time slots (Jose, 2026-09-24: "go ahead and add the Time Slots option") ──

  testWidgets('time slots read in plain hours, and a new one is added', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      onRequest: (request) {
        if (!request.url.path.endsWith('/scheduling/time-slots')) return null;
        if (request.method == 'POST') {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _twoWindows}, 201);
        }
        return _json({'data': _oneWindow, 'can_edit': true});
      },
    );

    expect(find.text('10 AM – 1 PM'), findsOneWidget);

    // Locked until asked (Jose, 2026-09-28).
    await tester.tap(find.text('Edit time slots'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add a time slot'));
    await tester.pumpAndSettle();

    // A search picker, not a dropdown: type to narrow, then pick.
    await tester.tap(find.byKey(const ValueKey('slot-draft-start')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, '1 PM');
    await tester.pumpAndSettle();
    await tester.tap(find.text('1 PM').last);
    await tester.pumpAndSettle();

    // A search picker, not a dropdown: type to narrow, then pick.
    await tester.tap(find.byKey(const ValueKey('slot-draft-end')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, '3 PM');
    await tester.pumpAndSettle();
    await tester.tap(find.text('3 PM').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Add time slot'));
    await tester.pumpAndSettle();

    expect(posted, {'start_time': '13:00', 'end_time': '15:00'});
  });

  /// Jose, 2026-09-28: "anyone could click by accident and delete a full time
  /// slot". Nothing can change until Edit time slots; a removal says what it
  /// deletes, needs REMOVE typed, and names the window to the server.
  testWidgets('a time slot is removed only when unlocked and REMOVE is typed', (
    tester,
  ) async {
    Uri? deleted;

    await _pump(
      tester,
      onRequest: (request) {
        if (!request.url.path.contains('/scheduling/time-slots')) return null;
        if (request.method == 'DELETE') {
          deleted = request.url;
          return _json({'data': const []});
        }
        return _json({
          'data': [
            {
              'id': 't-1',
              'start_time': '10:00',
              'end_time': '13:00',
              'areas': 12,
            },
          ],
          'can_edit': true,
        });
      },
    );

    expect(find.byTooltip('Remove'), findsNothing, reason: 'locked by default');
    expect(find.text('Used by 12 areas'), findsOneWidget);

    await tester.tap(find.text('Edit time slots'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Remove'));
    await tester.pumpAndSettle();

    expect(find.textContaining('used by 12 areas'), findsOneWidget);
    final button = find.widgetWithText(FilledButton, 'Remove time slot');
    expect(tester.widget<FilledButton>(button).onPressed, isNull);

    await tester.enterText(
      find.byKey(const ValueKey('slot-remove-confirm')),
      'remove',
    );
    await tester.pumpAndSettle();
    await tester.tap(button);
    await tester.pumpAndSettle();

    expect(deleted?.path, endsWith('/scheduling/time-slots/t-1'));
    expect(deleted?.queryParameters['confirm'], '10:00-13:00');
  });

  testWidgets('new hours for a slot in use say what they move first', (
    tester,
  ) async {
    Map<String, dynamic>? patched;

    await _pump(
      tester,
      onRequest: (request) {
        if (!request.url.path.contains('/scheduling/time-slots')) return null;
        if (request.method == 'PATCH') {
          patched = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': const []});
        }
        return _json({
          'data': [
            {
              'id': 't-1',
              'start_time': '10:00',
              'end_time': '13:00',
              'areas': 3,
            },
          ],
          'can_edit': true,
        });
      },
    );

    await tester.tap(find.text('Edit time slots'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Change hours'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('slot-draft-end')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, '2 PM');
    await tester.pumpAndSettle();
    await tester.tap(find.text('2 PM').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Save hours'));
    await tester.pumpAndSettle();
    expect(
      patched,
      isNull,
      reason: 'Nothing moves before the warning is confirmed.',
    );
    expect(find.textContaining('used by 3 areas'), findsOneWidget);

    await tester.tap(find.text('Move to the new hours'));
    await tester.pumpAndSettle();
    expect(patched, {'start_time': '10:00', 'end_time': '14:00'});
  });

  /// THE CASE THAT MATTERS. Opening a day used to write a hard-coded 08:00–12:00;
  /// it now offers every window at one job. Leaving a window blank means that
  /// day does not offer it — asserted on the REQUEST BODY, because a blank sent
  /// as a zero would look identical on screen and make "we do not do
  /// afternoons on Tuesday" into "Tuesday afternoon is full".
  testWidgets(
    'opening a day offers every window, and a blank one is left out',
    (tester) async {
      Map<String, dynamic>? saved;

      await _pump(
        tester,
        onRequest: (request) {
          final path = request.url.path;
          if (path.endsWith('/scheduling/time-slots')) {
            return _json({'data': _twoWindows, 'can_edit': true});
          }
          if (request.method == 'PUT' && path.endsWith('/slots')) {
            saved = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'data': _dallasDetail});
          }
          return null;
        },
      );

      await tester.tap(find.text('Dallas').first);
      await tester.pumpAndSettle();

      // Monday is open in the fixture; Tuesday is the first closed day.
      await tester.tap(find.text('Open').first);
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('slot-2-10:00-13:00')), findsOneWidget);
      expect(find.byKey(const ValueKey('slot-2-13:00-15:00')), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('slot-2-13:00-15:00')),
        '',
      );
      await tester.pumpAndSettle();

      await tester.tap(find.textContaining('Save Dallas'));
      await tester.pumpAndSettle();

      expect(saved, isNotNull, reason: 'The grid should have been saved.');

      final tuesday = (saved!['slots'] as List)
          .cast<Map<String, dynamic>>()
          .where((s) => s['weekday'] == 2)
          .toList();

      expect(tuesday, [
        {
          'weekday': 2,
          'start_time': '10:00',
          'end_time': '13:00',
          'max_jobs': 1,
        },
      ], reason: 'The blank afternoon must be absent, not sent as a zero.');
    },
  );

  /// The empty state is the entry point, and the test that stubs windows in
  /// cannot see it: with none stated there is nothing to open a day ONTO.
  /// Jose, 2026-09-25: "so i don't need to do math to find out what the end
  /// result is right now". The tile follows the typing, before Save, and "in
  /// all" adds the franchise's other areas as saved (Houston: Mon 3, Tue 3).
  testWidgets('daily totals add up the slots as you type', (tester) async {
    await _pump(
      tester,
      onRequest: (request) =>
          request.url.path.endsWith('/scheduling/time-slots')
          ? _json({'data': _twoWindows, 'can_edit': true})
          : null,
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    Finder tile(int weekday, String text) => find.descendant(
      of: find.byKey(ValueKey('daily-total-$weekday')),
      matching: find.text(text),
    );

    expect(tile(1, '2'), findsOneWidget);
    expect(tile(1, '5 in all'), findsOneWidget);
    expect(tile(2, 'Closed'), findsOneWidget);

    // Jose, twice: sibling tiles are the same size. A closed day and a day
    // with no "in all" line have less in them and used to draw shorter.
    final tileSizes = {
      for (var d = 0; d < 7; d++)
        tester.getSize(find.byKey(ValueKey('daily-total-$d'))),
    };
    expect(tileSizes, hasLength(1), reason: '$tileSizes');

    // And the editor's day rows: Tuesday is closed, Monday is open.
    final rowHeights = {
      for (var d = 0; d < 7; d++)
        tester.getSize(find.byKey(ValueKey('day-row-$d'))).height,
    };
    expect(rowHeights, hasLength(1), reason: '$rowHeights');

    await tester.tap(find.text('Open').first);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('slot-2-10:00-13:00')),
      '4',
    );
    await tester.enterText(
      find.byKey(const ValueKey('slot-2-13:00-15:00')),
      '',
    );
    await tester.pumpAndSettle();

    expect(tile(2, '4'), findsOneWidget);
    expect(tile(2, '7 in all'), findsOneWidget);
  });

  /// Jose, 2026-09-25: "10 jobs on monday, and that would divide between the
  /// available time slots". A1's rule: equal shares, the earliest windows take
  /// the remainder. Dallas's Monday shows three windows (its own 07–11 plus
  /// the two stated ones), so 10 is 4/3/3 and 2 is 1/1 with the last blank.
  testWidgets("a day's total splits across its windows, earliest first", (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pump(
      tester,
      onRequest: (request) {
        final path = request.url.path;
        if (path.endsWith('/scheduling/time-slots')) {
          return _json({'data': _twoWindows, 'can_edit': true});
        }
        if (request.method == 'PUT' && path.endsWith('/slots')) {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    String slot(String key) => tester
        .widget<EditableText>(
          find.descendant(
            of: find.byKey(ValueKey(key)),
            matching: find.byType(EditableText),
          ),
        )
        .controller
        .text;

    final day = find.byKey(const ValueKey('day-total-field-1'));
    expect(tester.widget<TextField>(day).controller!.text, '2');

    await tester.enterText(day, '10');
    await tester.pumpAndSettle();

    expect(slot('slot-1-07:00-11:00'), '4');
    expect(slot('slot-1-10:00-13:00'), '3');
    expect(slot('slot-1-13:00-15:00'), '3');
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('daily-total-1')),
        matching: find.text('10'),
      ),
      findsOneWidget,
    );

    await tester.enterText(day, '2');
    await tester.pumpAndSettle();

    expect(slot('slot-1-07:00-11:00'), '1');
    expect(slot('slot-1-10:00-13:00'), '1');
    expect(slot('slot-1-13:00-15:00'), '');

    // Typing a window puts the Day box back in step.
    await tester.enterText(
      find.byKey(const ValueKey('slot-1-13:00-15:00')),
      '5',
    );
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(day).controller!.text, '7');

    await tester.tap(find.textContaining('Save Dallas'));
    await tester.pumpAndSettle();

    final monday = (saved!['slots'] as List)
        .cast<Map<String, dynamic>>()
        .where((s) => s['weekday'] == 1)
        .map((s) => s['max_jobs'])
        .toList();
    expect(monday, [1, 1, 5]);
  });

  testWidgets('the Day box fits a phone-width area pane', (tester) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(
      tester,
      width: 375,
      onRequest: (request) =>
          request.url.path.endsWith('/scheduling/time-slots')
          ? _json({'data': _twoWindows, 'can_edit': true})
          : null,
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('day-total-field-1')), findsOneWidget);
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });

  testWidgets('with no time slots a closed day says why it cannot open', (
    tester,
  ) async {
    await _pump(
      tester,
      onRequest: (request) =>
          request.url.path.endsWith('/scheduling/time-slots')
          ? _json({'data': const [], 'can_edit': true})
          : null,
    );

    expect(find.textContaining('None yet'), findsOneWidget);

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    expect(find.textContaining('add time slots in Overview'), findsWidgets);

    final open = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Open').first,
    );
    expect(open.onPressed, isNull, reason: 'There is no window to open onto.');
  });

  testWidgets(
    'a branch-level manager reads the windows and cannot change them',
    (tester) async {
      await _pump(
        tester,
        onRequest: (request) =>
            request.url.path.endsWith('/scheduling/time-slots')
            ? _json({'data': _oneWindow, 'can_edit': false})
            : null,
      );

      expect(find.text('10 AM – 1 PM'), findsOneWidget);
      expect(find.text('Add time slot'), findsNothing);
      expect(find.text('Edit time slots'), findsNothing);
      expect(find.byTooltip('Change hours'), findsNothing);
      expect(
        find.textContaining('changed at company or organization level'),
        findsOneWidget,
      );
    },
  );

  testWidgets('a company on inherited windows says whose they are', (
    tester,
  ) async {
    await _pump(
      tester,
      onRequest: (request) =>
          request.url.path.endsWith('/scheduling/time-slots')
          ? _json({
              'data': _oneWindow,
              'can_edit': true,
              'inherited_from': 'the organization',
            })
          : null,
    );

    expect(
      find.textContaining('Using the organization\u2019s windows'),
      findsOneWidget,
    );
  });

  /// "Closed on the 18th": picked from a date picker, never typed, and sent as
  /// `closed: true` — not as zeros on each window, which would read as "open
  /// and full" to everything that counts.
  testWidgets('a specific date is closed from the date picker, as closed', (
    tester,
  ) async {
    String? path;
    Map<String, dynamic>? body;

    await _pump(
      tester,
      onRequest: (request) {
        final p = request.url.path;
        if (p.endsWith('/scheduling/time-slots')) {
          return _json({'data': _twoWindows, 'can_edit': true});
        }
        if (request.method == 'PUT' && p.contains('/dates/')) {
          path = p;
          body = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _dallasDetail});
        }
        return null;
      },
    );

    await tester.tap(find.text('Dallas').first);
    await tester.pumpAndSettle();

    // Read the day when the picker opens, not after the save: a run that
    // crosses midnight expected tomorrow (0.0.0629 mobile, 2026-11-24).
    final now = DateTime.now();
    final today =
        '${now.year.toString().padLeft(4, '0')}-'
        '${now.month.toString().padLeft(2, '0')}-'
        '${now.day.toString().padLeft(2, '0')}';
    await _reveal(tester, find.byKey(const Key('add-specific-date')));
    await tester.tap(find.byKey(const Key('add-specific-date')));
    await tester.pumpAndSettle();

    // The picker opens on today; accept it.
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();

    await _reveal(tester, find.byKey(const Key('date-closed')));
    await tester.tap(find.byKey(const Key('date-closed')));
    await tester.pumpAndSettle();

    await _reveal(tester, find.byKey(const Key('save-specific-date')));
    await tester.tap(find.byKey(const Key('save-specific-date')));
    await tester.pumpAndSettle();

    expect(path, endsWith('/dates/$today'));
    expect(body, {'closed': true});
  });
}

/// Scrolls the AREA pane (not the rail) until [what] is on screen.
///
/// The pane is a lazy ListView, so anything below the fold is not merely
/// off-screen — it has not been built, and ensureVisible throws "No element"
/// rather than scrolling to it.
Future<void> _reveal(WidgetTester tester, Finder what) async {
  await tester.scrollUntilVisible(
    what,
    300,
    // `.first` is the ListView's own Scrollable. Every TextField in the pane
    // carries an internal one too, so the unqualified finder matches several
    // and scrollUntilVisible asks for exactly one.
    scrollable: find
        .descendant(
          of: find.byKey(const Key('scheduling-area-pane')),
          matching: find.byType(Scrollable),
        )
        .first,
  );
  await tester.pumpAndSettle();
}

/// Scrolls the AREA pane, not the rail, until [target] is built. The pane is a
/// lazy ListView, so a card below the fold does not exist to ensureVisible.
Future<void> _scrollPaneTo(WidgetTester tester, Finder target) =>
    tester.scrollUntilVisible(
      target,
      200,
      scrollable: find
          .descendant(
            of: find.byKey(const Key('scheduling-area-pane')),
            matching: find.byType(Scrollable),
          )
          .first,
    );

Future<void> _pump(
  WidgetTester tester, {
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>>? areas,
  Map<String, dynamic>? pattern = _defaultPattern,
  Map<String, dynamic>? me,
  double width = 1280,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(onRequest: onRequest, areas: areas, pattern: pattern, me: me),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CapacitySettings(api: api, session: session, visible: true),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>>? areas,
  Map<String, dynamic>? pattern,
  Map<String, dynamic>? me,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          ...?me,
          'permissions': const [
            'scheduling.view',
            'scheduling.create',
            'scheduling.edit',
            'scheduling.delete',
          ],
        },
      });
    }

    if (path.endsWith('/scheduling/areas')) {
      return _json({'data': areas ?? _defaultAreas, 'pattern': pattern});
    }

    if (RegExp(r'/scheduling/areas/[^/]+$').hasMatch(path)) {
      return _json({'data': _dallasDetail});
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

const _defaultAreas = [
  {
    'id': 'a-dallas',
    'name': 'Dallas',
    'franchise_id': 'fr-1',
    'zip_count': 18,
    'follows_pattern': true,
    // STRING KEYS, copied from what the server actually sends rather than
    // from the shape I meant. The server builds an int-keyed PHP array and
    // casts it to an object so json_encode writes `{"0":null,"1":2,...}`;
    // without that cast it wrote a JSON ARRAY, this fixture still agreed with
    // itself, and the real screen threw a cast error on every area.
    'week': {'1': 2, '2': 2, '3': 2, '4': 2, '5': 2, '6': 1, '0': null},
  },
  {
    'id': 'a-houston',
    'name': 'Houston',
    'franchise_id': 'fr-1',
    'zip_count': 24,
    'follows_pattern': false,
    'week': {'1': 3, '2': 3, '3': 3, '4': 1, '5': 3, '6': null, '0': null},
  },
];

const _defaultPattern = {
  'slots': [
    {'weekday': 1, 'start_time': '07:00', 'end_time': '11:00', 'max_jobs': 2},
  ],
  'followed_by': 1,
  'of': 2,
};

const _dallasDetail = {
  'id': 'a-dallas',
  'name': 'Dallas',
  'franchise_id': 'fr-1',
  'zips': [
    {'zip': '75201', 'city': 'Dallas'},
  ],
  'slots': [
    {
      'id': 's-1',
      'weekday': 1,
      'start_time': '07:00',
      'end_time': '11:00',
      'max_jobs': 2,
    },
  ],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};

const _oneWindow = [
  {'id': 't-1', 'start_time': '10:00', 'end_time': '13:00'},
];

const _twoWindows = [
  {'id': 't-1', 'start_time': '10:00', 'end_time': '13:00'},
  {'id': 't-2', 'start_time': '13:00', 'end_time': '15:00'},
];
