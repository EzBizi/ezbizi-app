import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/dashboard_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Human Resources dashboard (HR plan, "Dashboard").
///
/// ── The empty organization is the case under test ────────────────
///
/// Most of this file is about zero. This organization has one person, no
/// onboardings and no invitations, and that is not an edge case to degrade
/// gracefully into — it is what the owner sees on day one and probably day
/// thirty. A panel that renders "0" and stops has told them nothing about
/// whether the app is working, whether they have lost a permission, or what to
/// do next, and those are three very different problems.
///
/// So the assertions below are mostly about SENTENCES: that each empty panel
/// says what will fill it, that "nothing has stalled" and "you have not invited
/// anybody" are told apart, and that the one figure the app cannot honestly
/// date says so instead of implying a period it does not have.
void main() {
  testWidgets('every panel is drawn, including the ones sitting at zero', (
    tester,
  ) async {
    await _pump(tester, _handler());

    // Five, not "the ones with something in them". A tab that shows three cards
    // today and five next week re-teaches itself every time somebody looks.
    expect(find.text('Headcount by place'), findsOneWidget);
    expect(find.text('Onboardings in flight'), findsOneWidget);
    expect(find.text('Invitations that have stalled'), findsOneWidget);
    expect(find.text('Starting and leaving'), findsOneWidget);
    expect(find.text('Expiring certifications'), findsOneWidget);
  });

  /// The number that must not be zero for a one-person organization.
  ///
  /// The founder is assigned at ORGANIZATION level, so a server counting people
  /// by franchise would answer nobody — and the panel would look like it was
  /// working. This is the client half of that: the person is counted, and shown
  /// under the organization's own name.
  testWidgets('the one person on the books is counted and placed', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('1'), findsWidgets);
    expect(find.text('person'), findsOneWidget);
    expect(find.text('Everdoors'), findsOneWidget);
  });

  /// The unit is never left off. "1" beside "Headcount by place" could be one
  /// person or one place, and those are very different messages.
  testWidgets('the headline figure carries the word for what it counts', (
    tester,
  ) async {
    await _pump(tester, _handler(headcount: 4));

    expect(find.text('4'), findsWidgets);
    expect(find.text('people'), findsOneWidget);
    expect(find.text('person'), findsNothing);
  });

  testWidgets('an empty onboardings panel says what would fill it', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(
      find.textContaining('No paperwork is under way'),
      findsOneWidget,
      reason: 'A bare 0 is indistinguishable from a panel that failed to load.',
    );

    // And names the act that makes one appear, rather than leaving the reader
    // to work out where onboardings come from.
    expect(find.textContaining('invite somebody'), findsOneWidget);
  });

  /// Two different kinds of nothing, and they need opposite responses: one is
  /// good news, the other means this organization has not started.
  testWidgets('never having invited anybody is not the same as nothing '
      'having stalled', (tester) async {
    await _pump(tester, _handler());

    expect(find.textContaining('You have not invited anybody yet'), findsOneWidget);
    expect(find.textContaining('Nothing has stalled'), findsNothing);
  });

  testWidgets('with invitations sent and none stalled, it says so', (
    tester,
  ) async {
    await _pump(tester, _handler(sentTotal: 3));

    expect(find.textContaining('Nothing has stalled'), findsOneWidget);
    expect(find.textContaining('You have not invited anybody yet'), findsNothing);
  });

  testWidgets('a stalled invitation is broken down and says what to do', (
    tester,
  ) async {
    await _pump(tester, _handler(expired: 2, neverOpened: 1, sentTotal: 5));

    expect(find.text('3'), findsWidgets);
    expect(find.text('need chasing'), findsOneWidget);

    expect(find.text('Expired before anyone accepted'), findsOneWidget);
    expect(find.text('Sent but never opened'), findsOneWidget);

    // A count nobody can act on is a count nobody reads twice.
    expect(find.textContaining('needs sending again'), findsOneWidget);
  });

  /// The asymmetry this panel is careful about.
  ///
  /// Joining has a date. Leaving does not — `is_active` is a boolean with no
  /// timestamp beside it — so the leaving row must not inherit the window the
  /// joining row is counted over. The server says which rows the period covers
  /// and the client labels them from that.
  testWidgets('the period is applied to joining and withheld from leaving', (
    tester,
  ) async {
    await _pump(tester, _handler(joined: 2, noLongerActive: 3));

    expect(find.text('Joined in the last 30 days'), findsOneWidget);

    // Not "No longer active in the last 30 days" — the app does not know that.
    expect(find.text('No longer active'), findsOneWidget);
    expect(find.textContaining('No longer active in the last'), findsNothing);

    // And it says why, rather than leaving the inconsistency to be noticed.
    expect(
      find.textContaining('not the day it happened'),
      findsOneWidget,
      reason: 'The two rows are counted differently and nothing said so.',
    );
  });

  /// A client that decided for itself which row was the dated one would keep
  /// working until a third row was added and then quietly mislabel it. The
  /// server sends the answer; this proves the client reads it.
  testWidgets('the client does not decide for itself which row is dated', (
    tester,
  ) async {
    await _pump(tester, _handler(periodAppliesTo: const []));

    expect(find.text('Joined'), findsOneWidget);
    expect(find.text('Joined in the last 30 days'), findsNothing);
  });

  testWidgets('with nobody having joined, it says so and says when they would '
      'appear', (tester) async {
    await _pump(tester, _handler(joined: 0, people: const []));

    expect(find.textContaining('Nobody has joined in the last 30 days'),
        findsOneWidget);
    expect(find.textContaining('the day they accept their invitation'),
        findsOneWidget);
  });

  /// §4 and §3: these are not the organization's numbers unless the reader is
  /// looking at the organization. Stated on the page, because a headcount that
  /// silently meant "your branch" would be read as "the company".
  testWidgets('the page names the part of the business it counted', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.textContaining('Counted across Everdoors'), findsOneWidget);
  });

  /// The rows genuinely will not add up for anybody working in two places, and
  /// a reader who notices that without an explanation concludes it is broken.
  testWidgets('a total smaller than its rows explains itself', (tester) async {
    await _pump(
      tester,
      _handler(
        headcount: 2,
        headcountRows: const [
          {'title': 'Connecticut', 'total': 2},
          {'title': 'Texas', 'total': 1},
        ],
      ),
    );

    expect(find.textContaining('counted in each of them'), findsOneWidget);
  });

  testWidgets('when the totals agree, no explanation is offered', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.textContaining('counted in each of them'), findsNothing);
  });

  /// The panel keyed on `places` rather than on whether any rows arrived, and
  /// the two are not the same question: a place nobody is in is still a place,
  /// and it is the row somebody most often opened this tab to find.
  testWidgets('an organization with nowhere to count says so, and says what '
      'to add', (tester) async {
    await _pump(
      tester,
      _handler(headcount: 0, places: 0, headcountRows: const []),
    );

    expect(find.textContaining('Nowhere to count yet'), findsOneWidget);
    expect(find.textContaining('Add a company and a branch'), findsOneWidget);
  });

  /// The other half of that: places that exist get their rows and NOT the
  /// "go and set one up" sentence, however empty they are.
  testWidgets('a place nobody is in is a row, not a prompt to create one', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        headcount: 0,
        places: 3,
        headcountRows: const [
          {'title': 'Everdoors', 'total': 0},
          {'title': 'Connecticut', 'total': 0},
          {'title': 'Texas', 'total': 0},
        ],
      ),
    );

    expect(find.textContaining('Nowhere to count yet'), findsNothing);
    expect(find.text('Connecticut'), findsOneWidget);
    expect(find.text('Texas'), findsOneWidget);
  });

  /// A panel withheld by permission is absent, not drawn empty. The server
  /// filters the list; a client that assumed four panels would throw on the
  /// first index it could not find.
  testWidgets('a panel the server withheld is simply not there', (
    tester,
  ) async {
    await _pump(tester, _handler(only: {'hr.headcount'}));

    expect(find.text('Headcount by place'), findsOneWidget);
    expect(find.text('Onboardings in flight'), findsNothing);

    // And the tab still renders rather than failing on the missing ones.
    expect(find.text('At a glance'), findsOneWidget);
  });

  testWidgets('a failure is the server’s message and a way back, not a blank '
      'page', (tester) async {
    await _pump(tester, _handler(status: 500));

    expect(find.text('Human Resources is unavailable.'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
  });
}

// ── driving the tab ────────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 2200),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: DashboardTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// The server, parameterised on exactly the figures the tests vary.
///
/// Built as data rather than as a pile of fixtures, so a test says what it is
/// about — "two expired invitations" — instead of pointing at a constant three
/// hundred lines away that somebody else is also depending on.
MockClientHandler _handler({
  int status = 200,
  int headcount = 1,
  int places = 1,
  List<Map<String, Object?>>? headcountRows,
  int expired = 0,
  int neverOpened = 0,
  int sentTotal = 0,
  int joined = 1,
  int noLongerActive = 0,
  List<String> periodAppliesTo = const ['Joined'],
  List<Map<String, Object?>>? people,
  Set<String>? only,
}) {
  final panels = [
    {
      'key': 'hr.headcount',
      'label': 'Headcount by place',
      'data': {
        'rows': headcountRows ?? [
          {'title': 'Everdoors', 'total': headcount},
        ],
        'total': headcount,
        'places': places,
      },
    },
    {
      'key': 'hr.onboardings_in_flight',
      'label': 'Onboardings in flight',
      'data': {
        'rows': [
          {'title': 'Being filled in', 'total': 0},
          {'title': 'Waiting on a signature', 'total': 0},
        ],
        'total': 0,
        'oldest_started_at': null,
      },
    },
    {
      'key': 'hr.invitations_stalled',
      'label': 'Invitations that have stalled',
      'data': {
        'rows': [
          {'title': 'Expired before anyone accepted', 'total': expired},
          {'title': 'Sent but never opened', 'total': neverOpened},
        ],
        'total': expired + neverOpened,
        'sent_total': sentTotal,
      },
    },
    {
      'key': 'hr.starters_and_leavers',
      'label': 'Starting and leaving',
      'data': {
        'rows': [
          {'title': 'Joined', 'total': joined},
          {'title': 'No longer active', 'total': noLongerActive},
        ],
        'total': joined,
        'days': 30,
        'period_applies_to': periodAppliesTo,
        'people': people ??
            [
              {
                'id': 'u-1',
                'name': 'Jose Silva',
                'joined_at': '2026-08-01T09:00:00+00:00',
              },
            ],
        'more_people': 0,
      },
    },
  ];

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/hr/dashboard')) {
      if (status != 200) {
        return _json({'message': 'Human Resources is unavailable.'}, status);
      }

      return _json({
        'panels': only == null
            ? panels
            : panels.where((p) => only.contains(p['key'])).toList(),
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view'],
  'modules': [
    {'key': 'hr', 'label': 'Human Resources', 'available': true, 'reachable': true},
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
