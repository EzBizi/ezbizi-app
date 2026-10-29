import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_accounts.dart';
import 'package:ezbizi_client/widgets/leads_office_hours.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Leads › Manage, the Connections page: UI queue 34, Jose's 1 + 4.
///
/// The defect the accounts list exists for still holds it: one connect made
/// twenty-two Google Ads accounts in the same second and the screen showed one
/// of them. Twenty-one were invisible.
final _deleted = <String>[];
final _patched = <String, Map<String, dynamic>>{};
final _posted = <Map<String, dynamic>>[];

void main() {
  /// Twelve Thumbtack rows are one consent. Letting them all go is one
  /// question and one call, not twelve of each.
  testWidgets('Disconnect all asks once, then lets every account of the '
      'provider go in one call', (tester) async {
    var reloaded = 0;
    await _pump(
      tester,
      connections: [
        for (var i = 0; i < 3; i++)
          {
            'id': 'tb-$i',
            'provider': 'thumbtack',
            'display_name': 'A1 Chimney',
            'remote_id': '5812668784914432$i',
            'leads_count': 0,
          },
      ],
      onChanged: () async => reloaded++,
    );

    expect(
      find.byKey(const ValueKey('accounts-disconnect-all')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('accounts-disconnect-all')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Disconnect all 3 accounts?'), findsOneWidget);
    expect(_deleted, isEmpty, reason: 'asking is not doing');

    await tester.tap(
      find.byKey(const ValueKey('accounts-disconnect-all-confirm')),
    );
    await tester.pumpAndSettle();

    expect(_deleted, ['/api/v1/social/providers/thumbtack/connections']);
    expect(reloaded, 1);
  });

  // Design 160, B1: Manage › Hidden only with leads.hidden.view.
  testWidgets('the Hidden tab is drawn only with its tick', (tester) async {
    await _pump(tester, connections: const []);
    expect(find.text('Hidden'), findsNothing);
    await _pump(
      tester,
      connections: const [],
      permissions: const ['leads.view', 'leads.edit', 'leads.hidden.view'],
    );
    expect(find.text('Hidden'), findsOneWidget);
  });

  testWidgets('a single account has no Disconnect all; its own is enough', (
    tester,
  ) async {
    await _pump(tester, connections: _many(1));

    expect(find.byKey(const ValueKey('accounts-disconnect-all')), findsNothing);
  });

  testWidgets('every account is listed, not the first one the query returned', (
    tester,
  ) async {
    await _pump(tester, connections: _many(22));

    // The accounts sending leads are open; the rest are behind the fold with
    // a count that names them, so nothing is silently absent.
    expect(find.text('Sending leads'), findsOneWidget);
    expect(find.text('19 that have never sent a lead'), findsOneWidget);
    expect(_rows(), findsNWidgets(3));

    await tester.tap(find.text('19 that have never sent a lead'));
    await tester.pumpAndSettle();

    // The table builds lazily, so count what the one-row-per-account list
    // holds by scrolling through it rather than what is on screen.
    final seen = <String>{};
    for (var i = 0; i < 12; i++) {
      for (final e in _rows().evaluate()) {
        seen.add('${(e.widget.key as ValueKey).value}');
      }
      await tester.drag(find.byType(ListView).last, const Offset(0, -300));
      await tester.pumpAndSettle();
    }
    expect(
      seen,
      hasLength(22),
      reason:
          'Twenty-two accounts exist and all twenty-two must be reachable. '
          'Showing one was the reported defect.',
    );
  });

  /// One Thumbtack connect made twelve businesses all called "A1 Chimney"
  /// (API Integrations, 2026-09-08). The row has nothing to tell them apart
  /// with until somebody names them.
  testWidgets('twins with the same display name show the provider id until '
      'named', (tester) async {
    await _pump(
      tester,
      connections: [
        {
          ..._account('A1 Chimney', leads: 3, label: ''),
          'id': 'conn-a',
          'remote_id': '3910921',
        },
        {
          ..._account('A1 Chimney', leads: 2, label: ''),
          'id': 'conn-b',
          'remote_id': '3910944',
        },
        {
          ..._account('Only one', leads: 1, label: ''),
          'id': 'conn-c',
          'remote_id': '3910999',
        },
      ],
    );

    expect(find.byKey(const ValueKey('twin-conn-a')), findsOneWidget);
    expect(find.byKey(const ValueKey('twin-conn-b')), findsOneWidget);
    expect(find.text('3910921'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('twin-conn-c')),
      findsNothing,
      reason: 'a name nobody else wears needs no id beside it',
    );
  });

  testWidgets('search reaches through the fold', (tester) async {
    await _pump(tester, connections: _many(22));

    expect(find.text('Search reaches in here'), findsOneWidget);
    expect(_rows(), findsNWidgets(3));

    await tester.enterText(
      find.byKey(const ValueKey('accounts-search')),
      'quiet-17',
    );
    await tester.pumpAndSettle();

    expect(
      _rows(),
      findsOneWidget,
      reason:
          'A fold is only legitimate while the thing behind it stays findable. '
          'Hiding a match from the person who searched for it is the defect '
          'the fold was supposed to avoid, one level down.',
    );
    expect(find.text('400-000-0017'), findsOneWidget);
  });

  testWidgets('the chips narrow to what needs attention', (tester) async {
    await _pump(
      tester,
      connections: [
        {..._account('111-111-1111', leads: 5), 'status': 'broken'},
        {
          ..._account('222-222-2222', leads: 4),
          'certification': {'state': 'attention', 'problems': <String>[]},
        },
        _account('333-333-3333', leads: 3),
      ],
    );

    expect(find.text('Reconnect'), findsOneWidget);
    expect(find.text('Needs attention'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('accounts-chip-attention')));
    await tester.pumpAndSettle();

    expect(_rows(), findsNWidgets(2));
    expect(find.text('333-333-3333'), findsNothing);
  });

  /// A Google row can stay `connected` while every fetch fails, and
  /// reconnecting over it is the fix: disconnecting first hides its leads
  /// (Leads, 2026-09-30).
  testWidgets('an account nothing is fetched from needs attention, and says '
      'why and how to fix it', (tester) async {
    await _pump(
      tester,
      connections: [
        {
          ..._account('111-111-1111', leads: 5),
          'status': 'connected',
          'sync_failing': true,
          'status_reason': 'No lasting permission stored.',
        },
        _account('222-222-2222', leads: 4),
      ],
    );

    expect(find.text('Not fetching'), findsOneWidget);
    expect(find.byKey(const ValueKey('accounts-chip-attention')), findsOne);

    await _open(tester, '111-111-1111');
    expect(
      find.textContaining('No lasting permission stored. Connect the account'),
      findsOneWidget,
    );
    expect(find.textContaining('no need to disconnect first'), findsOneWidget);
  });

  testWidgets('a placement you cannot see is stated, and cannot be changed', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        // A real id with a null name: the server names a franchise only when
        // the reader can reach it. This is an answer, not missing data.
        _account('405-172-9356', leads: 5, franchiseId: 'fr-elsewhere'),
      ],
    );

    expect(find.text('Placed somewhere you cannot see'), findsOneWidget);

    await _open(tester, '405-172-9356');

    expect(
      find.byKey(const ValueKey('account-place')),
      findsNothing,
      reason:
          'Offering to move something whose current value you cannot see is '
          'offering to guess.',
    );
  });

  /// Google stops showing a Local Services account whose licence or
  /// insurance lapses, and tells nobody. The page says so, and names the fix.
  testWidgets('a certification problem is shown with its reason; a clean '
      'account says Certified; an unchecked one says neither', (tester) async {
    await _pump(
      tester,
      connections: [
        {
          ..._account('111-111-1111', leads: 3),
          'certification': {
            'state': 'attention',
            'problems': ['Insurance expires on Oct 12, 2026.'],
            'failed': null,
          },
        },
        {
          ..._account('222-222-2222', leads: 2),
          'certification': {
            'state': 'ok',
            'problems': <String>[],
            'failed': null,
          },
        },
        _account('333-333-3333', leads: 1),
      ],
    );

    expect(find.text('Needs attention'), findsWidgets);
    expect(find.text('Certified'), findsOneWidget);
    expect(
      find.text('Connected'),
      findsOneWidget,
      reason: 'an account never checked must not read as either verdict',
    );

    await _open(tester, '111-111-1111');
    expect(find.text('Certification needs attention'), findsOneWidget);
    expect(find.text('• Insurance expires on Oct 12, 2026.'), findsOneWidget);

    await _open(tester, '333-333-3333');
    expect(find.byKey(const ValueKey('lsa-certification')), findsNothing);
  });

  testWidgets('an unnamed account is named in its panel; a named one keeps '
      'its number', (tester) async {
    await _pump(
      tester,
      connections: [
        _account('405-172-9356', leads: 5),
        _account('118-904-2210', leads: 3, label: 'Connecticut LSA'),
      ],
    );

    // Named: both, because the name is how you find it and the number is how
    // you check it against Google Ads.
    expect(find.text('Connecticut LSA'), findsOneWidget);
    expect(find.text('118-904-2210'), findsOneWidget);

    await _open(tester, '405-172-9356');
    await tester.enterText(
      find.byKey(const ValueKey('account-name')),
      'Bridgeport',
    );
    await tester.tap(find.byKey(const ValueKey('account-name-save')));
    await tester.pumpAndSettle();

    expect(_patched['/api/v1/social/connections/conn-405-172-9356/label'], {
      'label': 'Bridgeport',
    });
  });

  testWidgets('a move of 3 leads says so; a move of 0 says something else', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 3)],
      moved: 3,
    );

    await _open(tester, '405-172-9356');
    await _place(tester, 'Connecticut');

    expect(
      find.text('3 leads moved to Connecticut.'),
      findsOneWidget,
      reason:
          'Placement is retroactive, so the count that moved is the whole '
          'confirmation somebody looks for.',
    );
  });

  testWidgets('a placement that moved nothing still says it took', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 0)],
      moved: 0,
    );

    // An account with no leads is, by definition, in the folded group.
    await tester.tap(find.text('1 that has never sent a lead'));
    await tester.pumpAndSettle();
    await _open(tester, '405-172-9356');
    await _place(tester, 'Connecticut');

    expect(
      find.text('Moved to Connecticut. No leads to move yet.'),
      findsOneWidget,
      reason:
          '0 and null are different answers. Silence after a change that took '
          'reads as a failure.',
    );
  });

  testWidgets('re-submitting the same placement announces nothing', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 4)],
      moved: null,
    );

    await _open(tester, '405-172-9356');
    await _place(tester, 'Connecticut');

    expect(
      find.textContaining('moved to'),
      findsNothing,
      reason:
          'leads_moved null means nothing happened. Saying "0 moved" would '
          'report a non-event as an outcome.',
    );
  });

  testWidgets('disconnecting one account asks first, in the panel', (
    tester,
  ) async {
    await _pump(tester, connections: _many(2));

    await _open(tester, 'busy-0');
    await tester.tap(find.byKey(const ValueKey('account-disconnect')));
    await tester.pumpAndSettle();

    expect(find.textContaining('removes the stored permission'), findsOne);
    expect(
      find.textContaining('hidden from Leads until you connect it again'),
      findsOne,
      reason:
          'disconnecting hides its leads; saying only "nothing is '
          'deleted" let people expect them to stay',
    );
    expect(_deleted, isEmpty, reason: 'asking is not doing');

    await tester.tap(find.byKey(const ValueKey('account-disconnect-confirm')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('disconnect-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const Key('disconnect-confirm')));
    await tester.pumpAndSettle();

    expect(_deleted, ['/api/v1/social/connections/conn-400-000-0000']);
  });

  testWidgets('somebody who can only view sees the page and no controls', (
    tester,
  ) async {
    await _pump(tester, connections: _many(3), canEdit: false);

    expect(find.byKey(const ValueKey('accounts-disconnect-all')), findsNothing);
    expect(find.textContaining('Connect '), findsNothing);

    await _open(tester, 'busy-0');

    expect(find.byKey(const ValueKey('account-name')), findsNothing);
    expect(find.byKey(const ValueKey('account-place')), findsNothing);
    expect(find.byKey(const ValueKey('account-disconnect')), findsNothing);
  });

  /// Jose's 4: on a phone the providers, the accounts and the account are
  /// steps in place of each other, each with ← back, never a pushed page.
  testWidgets('a phone steps from providers to accounts to one account and '
      'back', (tester) async {
    var backToLeads = 0;
    await _pump(
      tester,
      connections: _many(3),
      size: const Size(390, 800),
      onBack: () => backToLeads++,
    );

    expect(find.byKey(const ValueKey('provider-google_ads')), findsOneWidget);
    expect(_rows(), findsNothing);

    await tester.tap(find.byKey(const ValueKey('provider-google_ads')));
    await tester.pumpAndSettle();
    expect(_rows(), findsNWidgets(3));

    await _open(tester, 'busy-0');
    expect(_rows(), findsNothing, reason: 'the account in place of the list');
    expect(tester.takeException(), isNull);

    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(_rows(), findsNWidgets(3));

    await tester.tap(find.byTooltip('Back to the connections'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('provider-google_ads')), findsOneWidget);

    await tester.tap(find.byTooltip('Back to the leads'));
    await tester.pumpAndSettle();
    expect(backToLeads, 1);
  });

  /// Jose, 2026-09-30: "inside manage and we can make a connection sub tab
  /// and templates sub tab". Both tabs stay alive: an account open beside the
  /// list and a half-typed template both survive a look at the other tab.
  testWidgets('Manage has Connections and Templates, and each keeps its '
      'place', (tester) async {
    await _pump(tester, connections: _many(3));

    expect(find.byKey(const ValueKey('manage-tabs')), findsOneWidget);
    await _open(tester, 'busy-0');
    expect(find.byKey(const ValueKey('account-name')), findsOneWidget);

    await tester.tap(find.text('Templates'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('reply-templates-manager')),
      findsOneWidget,
    );
    expect(_rows(), findsNothing);
    expect(
      find.textContaining('Connect '),
      findsNothing,
      reason: 'connecting belongs to the connections tab',
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Thanks for reaching out.'),
      'Thanks, Harbor here.',
    );
    await tester.tap(find.text('Connections'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('account-name')),
      findsOneWidget,
      reason: 'the account stayed open',
    );

    await tester.tap(find.text('Templates'));
    await tester.pumpAndSettle();

    // In the field; the list and the preview say it as well.
    expect(
      find.widgetWithText(TextField, 'Thanks, Harbor here.'),
      findsOneWidget,
    );
  });

  /// UI queue 138: Office hours is Manage's own tab, beside Stats.
  testWidgets('Manage has an Office hours tab', (tester) async {
    await _pump(tester, connections: _many(3));

    await tester.tap(find.text('Office hours'));
    await tester.pumpAndSettle();

    expect(find.byType(LeadsOfficeHours), findsOneWidget);
    expect(_rows(), findsNothing);
    expect(tester.takeException(), isNull);
  });

  /// UI queue 38, 4: a template open on a phone takes the page, the Manage
  /// header and tabs with it, and ← back returns them.
  testWidgets('a template open on a phone takes the page', (tester) async {
    await _pump(tester, connections: _many(3), size: const Size(390, 800));

    await tester.tap(find.text('Templates'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Thank you'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('template-text')), findsOneWidget);
    expect(find.byKey(const ValueKey('manage-tabs')), findsNothing);
    expect(find.byTooltip('Back to the leads'), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('template-text')),
      'Thanks, Harbor here.',
    );
    await tester.tap(find.byTooltip('Back to the templates'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('manage-tabs')), findsOneWidget);
    expect(find.byTooltip('Back to the leads'), findsOneWidget);
    expect(
      find.text('Unsaved'),
      findsOneWidget,
      reason: 'the typing survived the header coming back',
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('a phone has the tabs on its first step only', (tester) async {
    await _pump(tester, connections: _many(3), size: const Size(390, 800));

    await tester.tap(find.text('Templates'));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('reply-templates-manager')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('provider-google_ads')), findsNothing);

    await tester.tap(find.text('Connections'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('provider-google_ads')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('manage-tabs')),
      findsNothing,
      reason: 'a step has its own back, not the tabs',
    );
    expect(tester.takeException(), isNull);
  });

  /// Jose, 2026-09-30: pausing a Google campaign from Manage as well as from
  /// the Ads report. Pausing stops the account's leads, so it is asked once.
  testWidgets('a campaign is paused after one question, and says so', (
    tester,
  ) async {
    await _pump(tester, connections: [_withCampaign()]);
    await _open(tester, 'Harbor');

    expect(_pill(tester, 'camp-1'), 'Running');
    await tester.tap(find.byKey(const ValueKey('campaign-pause-camp-1')));
    await tester.pump();
    expect(_posted, isEmpty, reason: 'asked first');
    expect(find.textContaining('no new leads'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('campaign-pause-confirm')));
    await tester.pumpAndSettle();

    expect(_posted.single, {
      'connection_id': 'conn-lsa',
      'campaign_ids': ['camp-1'],
      'status': 'PAUSED',
    });
    expect(_pill(tester, 'camp-1'), 'Paused');
    expect(
      find.byKey(const ValueKey('campaign-resume-camp-1')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('campaign-said')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('campaign-resume-camp-1')));
    await tester.pumpAndSettle();
    expect(_posted.last['status'], 'ENABLED');
    expect(_pill(tester, 'camp-1'), 'Running');
  });

  testWidgets('a pause Google refuses keeps the campaign running and says '
      'why', (tester) async {
    await _pump(
      tester,
      connections: [_withCampaign()],
      campaignRefusal: 'The campaign is managed by another account.',
    );
    await _open(tester, 'Harbor');

    await tester.tap(find.byKey(const ValueKey('campaign-pause-camp-1')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('campaign-pause-confirm')));
    await tester.pumpAndSettle();

    expect(_pill(tester, 'camp-1'), 'Running');
    expect(
      find.text('The campaign is managed by another account.'),
      findsOneWidget,
    );
  });

  testWidgets('without the Ads permission the campaign is shown, not '
      'offered', (tester) async {
    await _pump(tester, connections: [_withCampaign(mayPause: false)]);
    await _open(tester, 'Harbor');

    expect(_pill(tester, 'camp-1'), 'Running');
    expect(find.byKey(const ValueKey('campaign-pause-camp-1')), findsNothing);
  });

  testWidgets('a campaign not read yet says when it will be', (tester) async {
    await _pump(
      tester,
      connections: [
        {..._withCampaign(), 'campaigns': null},
      ],
    );
    await _open(tester, 'Harbor');

    expect(
      find.textContaining('The hourly check fills this in'),
      findsOneWidget,
    );
  });

  test('placements are counted, which is the number that changes', () {
    expect(
      placedCount([
        _account('a', leads: 1),
        _account('b', leads: 1, franchiseId: 'fr-1', franchiseName: 'CT'),
        _account('c', leads: 1, franchiseId: 'fr-elsewhere'),
      ]),
      2,
      reason:
          'An account placed outside the reader\'s reach is still placed. '
          'Counting only the ones it can name would under-report.',
    );
  });
}

/// A Google Ads account with its Local Services campaign, as the hourly watch
/// reads it into `meta.connections` (bb23c17b).
Map<String, dynamic> _withCampaign({bool mayPause = true}) => {
  ..._account('lsa', leads: 4, label: 'Harbor'),
  'campaigns': [
    {
      'id': 'camp-1',
      'name': 'Local Services',
      'status': 'ENABLED',
      'primary_status': 'ELIGIBLE',
    },
  ],
  'may_pause': mayPause,
};

String? _pill(WidgetTester tester, String id) =>
    tester.widget<StatusPill>(find.byKey(ValueKey('campaign-state-$id'))).label;

/// Account rows, in the table or the list: both key a row `row-<id>`.
Finder _rows() => find.byWidgetPredicate(
  (w) => w.key is ValueKey && '${(w.key as ValueKey).value}'.startsWith('row-'),
);

Future<void> _open(WidgetTester tester, String text) async {
  await tester.tap(find.text(text).first);
  await tester.pumpAndSettle();
}

Future<void> _place(WidgetTester tester, String where) async {
  await tester.tap(find.byType(SearchPickerField<String?>));
  await tester.pumpAndSettle();
  await tester.tap(find.text(where).last);
  await tester.pumpAndSettle();
}

/// One connections row, in the shape `meta.connections` actually sends.
Map<String, dynamic> _account(
  String number, {
  required int leads,
  String? label,
  String? franchiseId,
  String? franchiseName,
}) => {
  'id': 'conn-$number',
  'provider': 'google_ads',
  'display_name': number,
  'status': 'active',
  'label': label,
  'leads_franchise_id': franchiseId,
  'leads_franchise_name': franchiseName,
  'leads_count': leads,
};

/// Three producing accounts and the rest silent — the real shape at A-1.
List<Map<String, dynamic>> _many(int total) => [
  for (var i = 0; i < total; i++)
    _account(
      '400-000-${i.toString().padLeft(4, '0')}',
      leads: i < 3 ? (10 - i) : 0,
      label: i < 3 ? 'busy-$i' : 'quiet-$i',
    ),
];

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> connections,
  int? moved,
  bool canEdit = true,
  Size size = const Size(1400, 900),
  Future<void> Function()? onChanged,
  VoidCallback? onBack,
  String? campaignRefusal,
  List<String> permissions = const ['leads.view', 'leads.edit'],
}) async {
  _deleted.clear();
  _patched.clear();
  _posted.clear();
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': permissions,
          },
        });
      }
      if (request.method == 'DELETE') {
        _deleted.add(request.url.path);
        return _json({
          'data': {'disconnected': 3, 'problems': []},
        });
      }
      if (request.url.path.contains('leads-franchise')) {
        return _json({
          'data': {
            'id': 'conn-1',
            'provider': 'google_ads',
            'display_name': '405-172-9356',
            'leads_franchise_id': 'fr-1',
            'leads_moved': moved,
          },
        });
      }
      if (request.method == 'PATCH') {
        _patched[request.url.path] = (jsonDecode(request.body) as Map)
            .cast<String, dynamic>();
        return _json({'data': <String, dynamic>{}});
      }
      if (request.url.path.endsWith('/ads/campaigns/status')) {
        final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
        _posted.add(body);
        return _json({
          'data': [
            for (final id in body['campaign_ids'] as List)
              {
                'campaign_id': id,
                'ok': campaignRefusal == null,
                'because': campaignRefusal,
              },
          ],
        });
      }
      if (request.url.path.endsWith('/leads/reply-templates')) {
        return _json({
          'data': [
            {
              'company': {'id': 'co-1', 'name': 'Harbor Chimney'},
              'templates': [
                {'name': 'Thank you', 'text': 'Thanks for reaching out.'},
              ],
            },
          ],
          'meta': {'may_edit': true},
        });
      }
      return _json({
        'message': 'Nothing stubbed for ${request.url.path}.',
      }, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadsConnections(
          api: api,
          session: session,
          connections: connections,
          canEdit: canEdit,
          onChanged: onChanged ?? () async {},
          onBack: onBack ?? () {},
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@ezbizi.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
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
  'companies': <Map<String, Object?>>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
