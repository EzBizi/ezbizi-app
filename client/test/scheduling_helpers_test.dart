import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/helpers_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Helpers (UI queue 112, Jose: desktop 1 and phone 4). Every
/// name is invented.
void main() {
  const wide = Size(1400, 1000);
  const phone = Size(390, 844);

  testWidgets('a month shows each booking as a tag in its slot colour', (
    tester,
  ) async {
    final calls = await _pump(tester, wide);

    expect(
      calls
          .where((c) => c.path.endsWith('/scheduling/helpers/bookings'))
          .first
          .query,
      {'franchise_id': 'fr-1', 'month': '2026-10'},
    );

    final light = ToneColours.table(Brightness.light);
    for (final (id, text, tone) in [
      ('b-1', 'Alex → Dana', Tone.ok), // morning
      ('b-2', 'Sam → Pat', Tone.info), // whole day
      ('b-3', 'Jo → Morgan', Tone.caution), // afternoon
    ]) {
      final tag = find.byKey(ValueKey('helpers-tag-$id'));
      expect(
        find.descendant(of: tag, matching: find.text(text)),
        findsOneWidget,
      );
      final box = tester.widget<Container>(tag).decoration! as BoxDecoration;
      expect(box.color, light[tone]!.fill, reason: '$id is $tone');
    }
    expect(light[Tone.ok]!.fill, isNot(light[Tone.info]!.fill));
    expect(light[Tone.caution]!.fill, isNot(light[Tone.info]!.fill));

    // A day opens its panel: who is free in which half.
    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-20')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('helpers-day')), findsOneWidget);
    String free(String helper, String slot) => tester
        .widget<Text>(
          find.descendant(
            of: find.byKey(ValueKey('helpers-free-$helper-$slot')),
            matching: find.byType(Text),
          ),
        )
        .data!;
    // Alex has the morning, so the whole day is gone too; the afternoon is not.
    expect(free('h-1', 'am'), 'Morning · taken');
    expect(free('h-1', 'full'), 'Whole day · taken');
    expect(free('h-1', 'pm'), 'Afternoon · free');
    expect(free('h-3', 'full'), 'Whole day · free');
    // The switched-off helper is not offered.
    expect(find.byKey(const ValueKey('helpers-free-h-4-full')), findsNothing);
    expect(find.text('Helpers · 3'), findsOneWidget);
  });

  testWidgets('booking for somebody else posts helper, date, slot, who, job '
      'and note', (tester) async {
    final calls = await _pump(tester, wide);

    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-22')));
    await tester.pumpAndSettle();
    await _pick(tester, 'helpers-form-helper', 'Jo Park');
    await tester.tap(find.byKey(const ValueKey('helpers-slot-pm')));
    await tester.pumpAndSettle();
    await _pick(tester, 'helpers-form-for', 'Pat Lin');
    await tester.enterText(find.byKey(const Key('helpers-form-job')), '5120');
    await tester.enterText(
      find.byKey(const Key('helpers-form-note')),
      'Bring the long ladder',
    );
    final before = calls.length;
    await tester.ensureVisible(find.byKey(const Key('helpers-form-submit')));
    await tester.tap(find.byKey(const Key('helpers-form-submit')));
    await tester.pumpAndSettle();

    final post = calls.singleWhere((c) => c.method == 'POST');
    expect(post.path, endsWith('/scheduling/helpers/bookings'));
    expect(post.body, {
      'helper_id': 'h-3',
      'slot': 'pm',
      'date': '2026-10-22',
      'technician_id': 'u-2',
      'job_number': '5120',
      'note': 'Bring the long ladder',
    });
    // The month is read again after it.
    expect(
      calls
          .skip(before)
          .where((c) => c.path.endsWith('/scheduling/helpers/bookings'))
          .where((c) => c.method == 'GET'),
      isNotEmpty,
    );
  });

  testWidgets('a clash comes back as a sentence under the slot', (
    tester,
  ) async {
    await _pump(
      tester,
      wide,
      onPost: (_) => _json({
        'message': 'The given data was invalid.',
        'errors': {
          'slot': ['That helper is already booked then.'],
        },
      }, 422),
    );

    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-20')));
    await tester.pumpAndSettle();
    await _pick(tester, 'helpers-form-helper', 'Alex Moreno');
    await tester.ensureVisible(find.byKey(const Key('helpers-form-submit')));
    await tester.tap(find.byKey(const Key('helpers-form-submit')));
    await tester.pumpAndSettle();

    expect(
      tester.widget<Text>(find.byKey(const Key('helpers-slot-error'))).data,
      'That helper is already booked then.',
    );
    expect(find.text('The given data was invalid.'), findsNothing);
  });

  testWidgets('Change and Remove only on bookings the server says can change', (
    tester,
  ) async {
    final calls = await _pump(tester, wide);

    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-20')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('helpers-change-b-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-remove-b-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-booking-b-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-change-b-2')), findsNothing);
    expect(find.byKey(const ValueKey('helpers-remove-b-2')), findsNothing);

    // Change fills the form with the booking, and saves with PATCH.
    await tester.tap(find.byKey(const ValueKey('helpers-change-b-1')));
    await tester.pumpAndSettle();
    expect(find.text('Change booking'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const ValueKey('helpers-slot-pm')));
    await tester.tap(find.byKey(const ValueKey('helpers-slot-pm')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('helpers-form-submit')));
    await tester.tap(find.byKey(const Key('helpers-form-submit')));
    await tester.pumpAndSettle();
    final patch = calls.singleWhere((c) => c.method == 'PATCH');
    expect(patch.path, endsWith('/scheduling/helpers/bookings/b-1'));
    expect(patch.body, {
      'helper_id': 'h-1',
      'slot': 'pm',
      'technician_id': 'u-1',
      'job_number': '4417',
      'note': null,
    });

    await tester.tap(find.byKey(const ValueKey('helpers-remove-b-1')));
    await tester.pumpAndSettle();
    final delete = calls.singleWhere((c) => c.method == 'DELETE');
    expect(delete.path, endsWith('/scheduling/helpers/bookings/b-1'));
  });

  testWidgets('without the right to edit Scheduling you book for yourself', (
    tester,
  ) async {
    final calls = await _pump(
      tester,
      wide,
      permissions: const ['scheduling.view', 'scheduling.helpers.view'],
    );

    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-22')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('helpers-form-for')), findsNothing);
    expect(find.text('For you (Dana Reyes)'), findsOneWidget);
    expect(calls.where((c) => c.path.endsWith('/technicians')), isEmpty);

    await _pick(tester, 'helpers-form-helper', 'Sam Okafor');
    await tester.ensureVisible(find.byKey(const Key('helpers-form-submit')));
    await tester.tap(find.byKey(const Key('helpers-form-submit')));
    await tester.pumpAndSettle();
    final post = calls.singleWhere((c) => c.method == 'POST');
    expect(post.body, {
      'helper_id': 'h-2',
      'slot': 'full',
      'date': '2026-10-22',
    });

    // The roster opens to read, with nothing to change it.
    await tester.tap(find.byKey(const Key('helpers-roster')));
    await tester.pumpAndSettle();
    expect(find.text('Rae Lindqvist · off'), findsOneWidget);
    expect(find.byKey(const Key('helpers-roster-add')), findsNothing);
    expect(
      find.byKey(const ValueKey('helpers-roster-active-h-4')),
      findsNothing,
    );
  });

  testWidgets('the roster adds a helper and switches one back on', (
    tester,
  ) async {
    final calls = await _pump(tester, wide);

    await tester.tap(find.byKey(const Key('helpers-roster')));
    await tester.pumpAndSettle();
    expect(find.text('Has a van, 555-0142'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('helpers-roster-active-h-4')));
    await tester.pumpAndSettle();
    final patch = calls.singleWhere((c) => c.method == 'PATCH');
    expect(patch.path, endsWith('/scheduling/helpers/h-4'));
    expect(patch.body, {'is_active': true});

    await tester.enterText(
      find.byKey(const Key('helpers-roster-name')),
      'Lee Whitfield',
    );
    await tester.tap(find.byKey(const Key('helpers-roster-add')));
    await tester.pumpAndSettle();
    final post = calls.singleWhere((c) => c.method == 'POST');
    expect(post.path, endsWith('/scheduling/helpers'));
    expect(post.body, {'franchise_id': 'fr-1', 'name': 'Lee Whitfield'});
  });

  testWidgets('on a phone: dots in a compact month, the day listed under it, '
      'and Book in a panel', (tester) async {
    await _pump(tester, phone);

    expect(find.byKey(const ValueKey('helpers-dot-b-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-tag-b-1')), findsNothing);
    expect(find.byKey(const Key('helpers-day')), findsNothing);
    // Today's bookings, under the month.
    expect(find.byKey(const ValueKey('helpers-booking-b-1')), findsOneWidget);
    expect(find.text('Job 4417 · Charles O Neil · Dallas'), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-booking-b-3')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('helpers-day-2026-10-21')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('helpers-booking-b-3')), findsOneWidget);
    expect(find.byKey(const ValueKey('helpers-booking-b-1')), findsNothing);

    await tester.ensureVisible(find.byKey(const Key('helpers-book')));
    await tester.tap(find.byKey(const Key('helpers-book')));
    await tester.pumpAndSettle();
    expect(find.text('Book a helper'), findsWidgets);
    expect(find.text('Wed 21 Oct'), findsWidgets);
    expect(find.byKey(const Key('helpers-form-helper')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

class _Call {
  _Call(this.method, this.path, this.query, this.body);

  final String method;
  final String path;
  final Map<String, String> query;
  final Object? body;
}

Future<void> _pick(WidgetTester tester, String field, String option) async {
  await tester.ensureVisible(find.byKey(Key(field)));
  await tester.tap(find.byKey(Key(field)));
  await tester.pumpAndSettle();
  await tester.tap(find.text(option).last);
  await tester.pumpAndSettle();
}

Future<List<_Call>> _pump(
  WidgetTester tester,
  Size size, {
  List<String> permissions = const [
    'scheduling.view',
    'scheduling.helpers.view',
    'scheduling.edit',
  ],
  http.Response Function(http.Request request)? onPost,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final calls = <_Call>[];
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      calls.add(
        _Call(
          request.method,
          path,
          request.url.queryParameters,
          request.body.isEmpty ? null : jsonDecode(request.body),
        ),
      );
      if (request.method == 'POST' && onPost != null) return onPost(request);
      if (request.method != 'GET') return _json({'data': null});
      if (path.endsWith('/scheduling/helpers/bookings')) {
        return _json({'data': _bookings});
      }
      // `people` as the server sends it: filled only for scheduling.edit.
      if (path.endsWith('/scheduling/helpers')) {
        return _json({
          'data': _helpers,
          'people': permissions.contains('scheduling.edit')
              ? [
                  {'id': 'u-1', 'name': 'Dana Reyes'},
                  {'id': 'u-2', 'name': 'Pat Lin'},
                ]
              : const [],
        });
      }
      return _json({'data': const []});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: HelpersPane(
          api: api,
          session: session,
          visible: true,
          today: DateTime(2026, 10, 20),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return calls;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _helpers = [
  {
    'id': 'h-1',
    'franchise_id': 'fr-1',
    'name': 'Alex Moreno',
    'notes': null,
    'is_active': true,
  },
  {
    'id': 'h-2',
    'franchise_id': 'fr-1',
    'name': 'Sam Okafor',
    'notes': 'Has a van, 555-0142',
    'is_active': true,
  },
  {
    'id': 'h-3',
    'franchise_id': 'fr-1',
    'name': 'Jo Park',
    'notes': null,
    'is_active': true,
  },
  {
    'id': 'h-4',
    'franchise_id': 'fr-1',
    'name': 'Rae Lindqvist',
    'notes': null,
    'is_active': false,
  },
];

const _bookings = [
  {
    'id': 'b-1',
    'helper': {'id': 'h-1', 'name': 'Alex Moreno'},
    'technician': {'id': 'u-1', 'name': 'Dana Reyes'},
    'date': '2026-10-20',
    'slot': 'am',
    'job_number': '4417',
    'job': {'customer': 'Charles O Neil', 'address': 'Dallas'},
    'note': null,
    'can_change': true,
  },
  {
    'id': 'b-2',
    'helper': {'id': 'h-2', 'name': 'Sam Okafor'},
    'technician': {'id': 'u-2', 'name': 'Pat Lin'},
    'date': '2026-10-20',
    'slot': 'full',
    'job_number': null,
    'note': 'Roof work',
    'can_change': false,
  },
  {
    'id': 'b-3',
    'helper': {'id': 'h-3', 'name': 'Jo Park'},
    'technician': {'id': 'u-3', 'name': 'Morgan Ellis'},
    'date': '2026-10-21',
    'slot': 'pm',
    'job_number': null,
    'note': null,
    'can_change': true,
  },
];

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Brightside', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Springfield', 'company_id': 'co-1'},
  ],
};
