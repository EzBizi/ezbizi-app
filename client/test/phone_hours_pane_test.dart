import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/hours_pane.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Business hours: seven rows that have to be seven rows.
///
/// ── The defect this exists for ───────────────────────────────────
///
/// A row with a per-row CONTROL and a text-only ALTERNATE STATE has two
/// heights: the open day carries text fields with Material's tap-target
/// minimum, the closed day carries one line of bodySmall. Nothing rules
/// between them, so a week with some days shut reads as floating — which is
/// how Jose described the same shape in Web Management's hours today.
///
/// Reported here by the Marketing session, which read the structure and said
/// plainly that it had NOT measured it. So this measures it, because the same
/// fix in Web Management looked right and left the rows 45 and 44 — one pixel
/// apart, invisible by eye, and still exactly the reported defect. Only a
/// test comparing an open row to a closed one caught that.
void main() {
  /// Sunday is shut and Monday is open in the fixture, so this compares the
  /// two states of the SAME list rather than two lists.
  testWidgets('an open day and a closed day are the same height', (
    tester,
  ) async {
    await _pump(tester);

    final open = _rowHeight(tester, 'Monday');
    final shut = _rowHeight(tester, 'Sunday');

    expect(
      shut,
      open,
      reason:
          'a closed day is $shut and an open day is $open, so a week with '
          'some days shut steps up and down the list',
    );
  });

  /// And it survives a phone.
  ///
  /// The Web Management fix reached for a fixed height first; at 375 the
  /// content ran 78px past the edge. A floor plus a child that can wrap is
  /// what holds, so the narrow case is asserted rather than assumed.
  testWidgets('the rows do not overflow at 375', (tester) async {
    await _pump(tester, size: const Size(375, 900));

    expect(tester.takeException(), isNull);

    final open = _rowHeight(tester, 'Monday');
    final shut = _rowHeight(tester, 'Sunday');

    expect(shut, open, reason: 'the two heights diverge on a narrow screen');
  });

  /// A NEW schedule does not start on a fixed offset.
  ///
  /// Making places selectable fixed what could be chosen. This is where the
  /// wrong one was being chosen for you: the blank form hardcoded
  /// `Etc/GMT+5`, so every schedule created from this screen began on a clock
  /// with no daylight saving and answered an hour early from March to
  /// November.
  ///
  /// Asserted as "not an Etc/ offset" rather than as one expected zone,
  /// because the point is the CLASS of value. A test naming America/New_York
  /// would pass for a US-only default that is wrong the moment somebody opens
  /// an office in Denver; this one fails for any fixed offset and for nothing
  /// else.
  testWidgets('a new schedule starts on a real place, not an offset', (
    tester,
  ) async {
    await _pump(tester, openEditor: false);

    await tester.tap(find.widgetWithText(FilledButton, 'Add hours'));
    await tester.pumpAndSettle();

    final dropdown = tester.widget<SearchPickerField<String>>(
      find.byType(SearchPickerField<String>),
    );

    expect(
      dropdown.value,
      isNotNull,
      reason: 'a new schedule has no clock at all',
    );

    expect(
      dropdown.value!.startsWith('Etc/'),
      isFalse,
      reason:
          'a new schedule starts on ${dropdown.value}, a fixed offset '
          'that ignores daylight saving',
    );
  });
}

double _rowHeight(WidgetTester tester, String day) {
  return tester
      .getSize(
        find
            .ancestor(of: find.text(day), matching: find.byType(Row))
            .first,
      )
      .height;
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1280, 900),
  bool openEditor = true,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/phone/hours')) {
        return _json({'data': [_schedule]});
      }

      if (path.endsWith('/phone/connections')) {
        return _json({
          'data': [
            {
              'id': 'cx-1',
              'scope_type': 'company',
              'scope_id': 'co-1',
              'scope_name': 'A-1 Chimney',
              'kind': 'subaccount',
              'status': 'connected',
            },
          ],
        });
      }

      return _json({'data': _me});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: HoursPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();

  if (!openEditor) return;

  // The day rows live in the editor, not the list. Open the one schedule.
  await tester.tap(find.widgetWithText(TextButton, 'Edit'));
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

/// Sunday absent from `weeks` — that is what "closed" looks like on the wire.
const _schedule = {
  'id': 'hs-1',
  'connection_id': 'cx-1',
  'name': 'Office hours',
  'timezone': 'America/New_York',
  'weeks': {
    'mon': [
      ['09:00', '17:00'],
    ],
    'tue': [
      ['09:00', '17:00'],
    ],
    'wed': [
      ['09:00', '17:00'],
    ],
    'thu': [
      ['09:00', '17:00'],
    ],
    'fri': [
      ['09:00', '17:00'],
    ],
  },
  'exceptions': <Map<String, Object?>>[],
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@ezbizi.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
