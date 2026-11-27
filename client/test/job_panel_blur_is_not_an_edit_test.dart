import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A click into the date and out again is not an edit.
///
/// ── The interaction this pins, measured 2026-09-24 ───────────────
///
/// `_wallClock` (4f32be80) loads a job's time WITH its seconds -- 09:43:33 --
/// because that is what the job holds. The date field (CalendarDayField)
/// rewrites its controller on BLUR as `YYYY-MM-DD HH:MM`, without seconds, even
/// when nothing changed. Two consequences, each measured:
///   - the panel's `dirty` flag (b390b6a7) compared text, so a click in and out
///     read as an unsaved edit on ALL 8,336 dated jobs, and the docked list
///     refused to switch jobs under it;
///   - a Save after that click sent 09:43 for 09:43:33 -- quietly moving 161
///     real jobs by up to 59 seconds, which the Workiz follow pass then reads as
///     a PERSON's edit and stops following that job's appointment.
///
/// The fixture carries non-zero seconds on purpose: at :00 the save half
/// cannot show the loss.
void main() {
  testWidgets('a click in and out of the date neither dirties the panel nor moves the time', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final patches = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/jobs/j-1')) {
          if (request.method == 'PATCH') {
            patches.add(jsonDecode(request.body) as Map<String, dynamic>);
          }
          return _json({'data': _detail});
        }
        if (path.endsWith('/me')) {
          return _json({'data': {..._me, 'permissions': _permissions}});
        }
        return _json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    final dirty = ValueNotifier<bool>(false);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: JobDetailPanel(
            api: api,
            session: session,
            id: 'j-1',
            dirty: dirty,
            onDone: (_) {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(dirty.value, isFalse, reason: 'The control: freshly loaded is clean.');

    // Click into the DAY box of the date field, then out -- no typing.
    //
    // The DAY box, not the minute box, and the first draft of this test is why
    // that is worth saying: CalendarDayField wires its blur-and-rewrite only to
    // the month, day and year focus nodes (calendar_day_field.dart, initState).
    // Blurring the minute box triggers nothing, so a test aimed there passed
    // against the defect it was written to catch.
    await tester.tap(find.byKey(const Key('calendar-day-text')));
    await tester.pumpAndSettle();
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();

    expect(dirty.value, isFalse,
        reason: 'Nothing was changed, so nothing is unsaved.');
    expect(
      tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Save')).onPressed,
      isNull,
      reason: 'Design 196: Save waits for a change, and a click is not one.',
    );

    // Save another field, so the date rides along untouched.
    await tester.enterText(find.widgetWithText(TextField, 'Description'), 'Two flues');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(patches, hasLength(1), reason: 'The control: Save really sent a PATCH.');
    expect(patches.single['scheduled_for'], '2026-09-23 09:43:33',
        reason: 'The seconds the job holds survive a save the person did not change.');
  });

  /// The other half: a REAL change to the date still counts and still saves.
  ///
  /// Comparing by minute is what stops a click from reading as an edit; the
  /// case that can tell "ignores only a no-op" from "ignores everything" is a
  /// genuine edit, so it is asserted rather than assumed.
  testWidgets('changing the day is an edit, and the new day is what is saved', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final patches = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/jobs/j-1')) {
          if (request.method == 'PATCH') {
            patches.add(jsonDecode(request.body) as Map<String, dynamic>);
          }
          return _json({'data': _detail});
        }
        if (path.endsWith('/me')) {
          return _json({'data': {..._me, 'permissions': _permissions}});
        }
        return _json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    final dirty = ValueNotifier<bool>(false);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: JobDetailPanel(
            api: api,
            session: session,
            id: 'j-1',
            dirty: dirty,
            onDone: (_) {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // One date-and-time field (Jose, 2026-11-03), in the fallback YYYY-MM-DD.
    await tester.enterText(
      find.byKey(const Key('calendar-day-text')),
      '2026-09-24 09:43',
    );
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();

    expect(dirty.value, isTrue, reason: 'A different day is an unsaved edit.');

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(patches.single['scheduled_for'], startsWith('2026-09-24 09:43'),
        reason: 'The day the person typed is the day that is saved.');
  });
}

const _detail = {
  'id': 'j-1',
  'number': 'JOB-1042',
  'title': 'Chimney sweep call',
  'status': 'scheduled',
  'status_label': 'Scheduled',
  'scheduled_for': '2026-09-23T09:43:33-04:00',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Alice Roofing',
    'number': 'C-1001',
    'owned_by_this_franchise': true,
  },
  'amount': {'minor': 0, 'currency': 'USD', 'display': r'$0.00'},
  'assigned_role': null,
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': <Map<String, dynamic>>[],
  'performed_by': null,
  'earned_at': null,
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _permissions = ['jobs.view', 'jobs.edit'];

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
  'companies': <dynamic>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
