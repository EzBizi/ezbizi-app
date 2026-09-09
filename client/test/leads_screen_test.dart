import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:ezbizi_client/widgets/leads_accounts.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;

/// Leads — enquiries from connected advertising.
///
/// The case worth a test is the empty one. "No leads" and "nothing connected"
/// draw the same empty list and mean opposite things: a quiet week, or an
/// enquiry sitting unanswered because nothing is fetching. A screen that says
/// the wrong one is the exact failure this module was built to stop, and it is
/// invisible in a screenshot.
void main() {
  _conversationRowTests();

  testWidgets('search runs as you type, without Enter', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );
    _lastListQuery = const {};

    await tester.enterText(find.byType(TextField).first, '609');
    await tester.pump(const Duration(milliseconds: 100));
    expect(
      _lastListQuery.containsKey('search'),
      isFalse,
      reason: 'not on every keystroke',
    );

    await tester.pump(const Duration(milliseconds: 400));
    expect(_lastListQuery['search'], '609');
  });

  testWidgets('the divider drags the panes, within limits', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );

    final divider = find.byKey(const ValueKey('pane-divider'));
    final before = tester.getTopLeft(divider).dx;

    await tester.drag(divider, const Offset(150, 0));
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(divider).dx, greaterThan(before + 100));

    // Far past the limit: the list stops at its ceiling rather than eating
    // the thread, and nothing overflows.
    await tester.drag(divider, const Offset(5000, 0));
    await tester.pumpAndSettle();
    final width = tester.getSize(find.byType(LeadsScreen)).width;
    expect(tester.getTopLeft(divider).dx, lessThanOrEqualTo(width - 460));
    expect(tester.takeException(), isNull);
  });
  _triageTests();

  testWidgets('with nothing connected the empty state says so, rather than '
      'reporting a quiet week', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    expect(find.text('No advertising account connected'), findsOneWidget);
    expect(
      find.textContaining('this is not a quiet week'),
      findsOneWidget,
      reason:
          'An empty list with no connection must not read as "no enquiries". '
          'It means nothing is fetching.',
    );
    expect(find.text('No enquiries yet'), findsNothing);
  });

  /// THE REPORTED DEFECT. One connect created twenty-two accounts in the same
  /// second, and the header showed one of them — whichever the query returned
  /// first. Twenty-one were invisible and the one on screen was arbitrary.
  ///
  /// Jose: "I'm seeing only one account, even tho we have 20 somethings
  /// accounts for a1-chimney."
  testWidgets('the header counts every connected account, not the first one', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [
        {
          'id': 'c1',
          'provider': 'google_ads',
          'display_name': '405-172-9356',
          'status': 'connected',
          'leads_franchise_id': null,
          'leads_count': 4,
        },
        {
          'id': 'c2',
          'provider': 'google_ads',
          'display_name': '118-904-2210',
          'status': 'connected',
          'leads_franchise_id': 'fr-1',
          'leads_franchise_name': 'Connecticut',
          'leads_count': 2,
        },
        {
          'id': 'c3',
          'provider': 'google_ads',
          'display_name': '772-410-3388',
          'status': 'connected',
          'leads_franchise_id': null,
          'leads_count': 0,
        },
      ],
    );

    expect(
      find.textContaining('Google Ads · 3 accounts'),
      findsOneWidget,
      reason:
          'The band states how many accounts exist. Naming one of them was the '
          'defect: it looked correct and was arbitrary.',
    );

    // And the second line carries the fact that actually changes.
    // Placement moved into the panel with the sources layout; the band
    // names the providers and their counts (UI/UX 80646605).
  });

  testWidgets('with an account connected and no leads it says the week was '
      'quiet, not that something is broken', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': '123-456-7890',
          'status': 'connected',
        },
      ],
    );

    expect(find.text('No enquiries yet'), findsOneWidget);
    expect(find.text('No advertising account connected'), findsNothing);
  });

  testWidgets('a lead with no name is titled by the number somebody would '
      'call back', (tester) async {
    await _pump(
      tester,
      leads: const [
        {
          'id': 'lead-1',
          'contact_name': null,
          'phone': '+1 (555) 123-4567',
          'category': 'chimney_sweep',
          'status': 'new',
          'received_at': null,
        },
      ],
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': '123-456-7890',
          'status': 'connected',
        },
      ],
    );

    expect(find.text('+1 (555) 123-4567'), findsWidgets);
  });

  /// §4: a control that is offered must be a control that works.
  ///
  /// This shipped drawing Connect for anybody who could open the tab. Somebody
  /// holding `leads.view` and not `leads.edit` pressed it and got a 403 — the
  /// server check was right, the screen simply never asked.
  testWidgets('Connect is not offered to somebody who cannot connect', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view'],
    );

    expect(find.text('Connect Google Ads'), findsNothing);
    expect(
      find.textContaining('Somebody who can manage Leads'),
      findsOneWidget,
      reason:
          'An absent button with no explanation reads as a broken tab to the '
          'person least able to tell the difference.',
    );
  });

  testWidgets('Connect is offered to somebody who can connect', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.edit'],
    );

    expect(find.text('Connect Google Ads'), findsOneWidget);
  });

  // ── one list across every source (sources layout 1 / 4) ─────────

  testWidgets('one list under a source strip: All first, and every source '
      'has a tab', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    expect(find.text('Connected sources'), findsOneWidget);
    expect(find.byKey(const ValueKey('source-tabs')), findsOneWidget);

    // Every source reachable, Thumbtack included. The drawn mobile option had
    // it pushed off the end; the strip scrolls, so it is here.
    for (final word in ['Google', 'Thumbtack', 'Chat', 'Forms']) {
      expect(
        find.widgetWithText(ChoiceChip, word),
        findsOneWidget,
        reason:
            '$word has no tab, so that source can only be reached by '
            'scrolling the whole list.',
      );
    }

    // Still ONE list: a tab narrows it, it does not open a screen per source.
    expect(find.text('Google Leads'), findsNothing);
    expect(find.text('Thumbtack Leads'), findsNothing);
  });

  testWidgets('a source tab narrows the list, and clearing the filters puts '
      'the strip back to All', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    await tester.tap(find.widgetWithText(ChoiceChip, 'Chat'));
    await tester.pumpAndSettle();
    expect(_lastListQuery['source'], 'website_chat');
    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Chat'))
          .selected,
      isTrue,
    );

    // There are two Alls -- the strip's tab and the filter row's chip -- and
    // both mean the unfiltered list. Clearing the filters must move the strip
    // too, or a tab stays lit describing something that is no longer on screen.
    await tester.tap(find.byKey(const ValueKey('filter-all')));
    await tester.pumpAndSettle();
    expect(_lastListQuery.containsKey('source'), isFalse);
    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Chat'))
          .selected,
      isFalse,
      reason: 'The Chat tab is still lit after All cleared the source.',
    );
  });

  testWidgets('every row carries its source as a badge', (tester) async {
    await _pump(
      tester,
      leads: [
        _lead(
          'lead-1',
          'Marcus Bell',
          from: 'CONSUMER',
          channel: 'PHONE_CALL',
          preview: 'Call · 4:32',
        ),
        {
          ..._lead('lead-2', '(no name given)'),
          'source': 'website_form',
          'contact_name': null,
          'phone': null,
        },
      ],
      connections: _connected,
    );

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('source-badge-lead-1')),
        matching: find.text('Google'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('source-badge-lead-2')),
        matching: find.text('Form'),
      ),
      findsOneWidget,
    );
  });

  /// "Chat · Chat" was one fact twice. The channel badge is drawn only when
  /// it says a word the source badge does not; Google rows keep both.
  testWidgets('a website row does not say its source twice; a Google row '
      'keeps channel and source', (tester) async {
    await _pump(
      tester,
      leads: [
        _lead(
          'lead-1',
          'Marcus Bell',
          from: 'CONSUMER',
          channel: 'PHONE_CALL',
          preview: 'Call · 4:32',
        ),
        {
          ..._lead(
            'lead-2',
            'Visitor 4471',
            from: 'CONSUMER',
            channel: 'LIVECHAT',
            preview: 'Do you service gas inserts?',
          ),
          'source': 'website_chat',
        },
      ],
      connections: _connected,
    );

    expect(find.text('Call'), findsOneWidget);
    expect(
      find.text('Chat'),
      findsNWidgets(2),
      reason: 'the chip and one badge, not a second badge',
    );
  });

  /// THE REPORTED DEFECT (Jose, 2026-09-09): "i replied a to sean lang, but it
  /// does not update on the left side". The reply reached Google and the
  /// thread drew it; the row went on quoting the message before it, because
  /// the widget that sent it had no way to tell the screen holding the list.
  ///
  /// The caller is what is tested here. The callback on its own would pass a
  /// widget test while the screen forgot to pass it — which is the shape of
  /// this defect, not a hypothetical.
  testWidgets('a reply updates the row on the left, not only the thread', (
    tester,
  ) async {
    final leads = [
      _lead(
        'lead-1',
        'Sean Lang',
        from: 'CONSUMER',
        channel: 'MESSAGE',
        preview: 'Do you offer free estimates?',
      ),
    ];

    await _pump(
      tester,
      leads: leads,
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': '123-456-7890',
          'status': 'connected',
        },
      ],
    );

    // The control: this is what the row says before anybody replies. The
    // first lead is already open in the pane beside the list.
    expect(find.text('Do you offer free estimates?'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('reply-box')),
      'On my way',
    );
    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();

    expect(
      find.text('Do you offer free estimates?'),
      findsNothing,
      reason: 'the row must stop quoting the message before the reply',
    );
    expect(
      find.textContaining('On my way'),
      findsWidgets,
      reason: 'the row now carries what was actually said',
    );
  });

  testWidgets('an unconnected Thumbtack offers to connect from the sources '
      'panel and says what connecting is for', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.text('No Thumbtack account connected'), findsOneWidget);
    expect(find.text('Connect Thumbtack'), findsOneWidget);
  });

  testWidgets('a connected Thumbtack says enquiries are not fetched yet', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [
        {
          'id': 'tb-1',
          'provider': 'thumbtack',
          'display_name': 'A-1 Chimney',
          'remote_id': '58126687849144320',
          'status': 'connected',
        },
      ],
    );

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.text('Connected — enquiries not fetched yet'), findsOneWidget);
    expect(find.textContaining('not missing'), findsOneWidget);
  });

  testWidgets('every connected Thumbtack business is reachable, not just the '
      'first', (tester) async {
    // The defect, exactly as it happened on 2026-09-08: one connect made TWELVE
    // businesses and the screen drew one. Eleven were invisible, and
    // disconnecting the visible one redrew the next, so the button read as
    // broken. It was not.
    await _pump(
      tester,
      leads: const [],
      connections: [
        for (var i = 0; i < 12; i++)
          {
            'id': 'c$i',
            'provider': 'thumbtack',
            'display_name': 'A1 Chimney',
            'remote_id': '58126687849144320$i',
            'status': 'connected',
          },
      ],
    );

    expect(find.textContaining('Thumbtack · 12'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.byType(LeadsAccountsList), findsOneWidget);
    expect(find.text('Thumbtack · A1 Chimney'), findsNothing);
  });

  /// The panel is its own route, so the screen's reload did not reach it: a
  /// row disconnected from inside it stayed until the panel was closed and
  /// opened again (Jose, 2026-09-09: "after clicking it the item remains").
  testWidgets('a row disconnected inside the open panel leaves the panel', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: [
        for (var i = 0; i < 2; i++)
          {
            'id': 'c$i',
            'provider': 'thumbtack',
            'display_name': 'A1 Chimney',
            'remote_id': '58126687849144320$i',
            'status': 'connected',
          },
      ],
    );

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('2 that have never sent a lead'));
    await tester.pumpAndSettle();

    final row = find.byKey(const ValueKey('leads-account-c0'));
    expect(row, findsOneWidget);

    await tester.tap(
      find.descendant(of: row, matching: find.text('Disconnect')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(of: row, matching: find.text('Disconnect')),
    );
    await tester.pumpAndSettle();

    expect(find.text('Connected sources'), findsWidgets, reason: 'still open');
    expect(find.byKey(const ValueKey('leads-account-c0')), findsNothing);
    expect(find.byKey(const ValueKey('leads-account-c1')), findsOneWidget);
    expect(find.textContaining('Thumbtack · 1'), findsOneWidget);
  });

  testWidgets('without leads.edit the sources panel names who can connect '
      'instead of offering a button that would be refused', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view'],
    );

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.text('Connect Thumbtack'), findsNothing);
    expect(find.text('Connect Google Ads'), findsNothing);
    expect(find.textContaining('Somebody who can manage Leads'), findsWidgets);
  });
}

const _connected = [
  {
    'provider': 'google_ads',
    'display_name': 'A1 Chimney - Cleveland',
    'status': 'connected',
  },
];

Map<String, dynamic> _lead(
  String id,
  String name, {
  String? from,
  String? channel,
  String? preview,
  String? service = 'fireplace_chimney',
  String? category = 'xcat:service_area_business_home_inspector',
  bool unread = false,
  bool starred = false,
  String? note,
}) => {
  'id': id,
  'is_unread': unread,
  'starred': starred,
  'note': note,
  'note_by': null,
  'note_updated_at': null,
  'contact_name': name,
  'phone': '+1 (555) 123-4567',
  'category': category,
  'service': service,
  'status': 'new',
  'received_at': '2026-09-07T09:00:00Z',
  'last_contact_at': from == null ? null : '2026-09-07T09:30:00Z',
  'last_contact_channel': channel,
  'last_contact_from': from,
  'last_contact_preview': preview,
  'franchise': {'id': 'f-1', 'name': 'Connecticut'},
  'company': {'id': 'c-1', 'name': 'A-1 Chimney'},
};

/// Approved triage layout 1 / 4 (18012c74): unread is WEIGHT, the star is
/// the third mark, the three filters narrow together, Mark all read is at
/// the top of the list.
void _triageTests() {
  testWidgets('an unread row is bold and a read one is not; opening a lead '
      'marks it read', (tester) async {
    await _pump(
      tester,
      leads: [
        _lead('lead-1', 'Marcus Bell', unread: true),
        _lead('lead-2', 'Dana Whitfield', unread: true),
      ],
      connections: _connected,
    );

    // Both arrive unread. lead-1 is auto-selected on a desktop, so its
    // thread opened and it was marked read and un-bolded; lead-2 was not
    // opened and stays bold. A lead already read sends no call at all.
    expect(_posts, contains('/api/v1/leads/lead-1/read'));
    expect(_posts, isNot(contains('/api/v1/leads/lead-2/read')));

    final read = tester.widget<Text>(find.text('Marcus Bell').first);
    final unread = tester.widget<Text>(find.text('Dana Whitfield').first);
    expect(read.style?.fontWeight, isNot(FontWeight.w700));
    expect(unread.style?.fontWeight, FontWeight.w700);
  });

  testWidgets('the star on the row sets the mark, hollow until then', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        _lead('lead-1', 'Marcus Bell'),
        _lead('lead-2', 'Dana Whitfield'),
      ],
      connections: _connected,
    );

    expect(
      tester
          .widget<Icon>(
            find.descendant(
              of: find.byKey(const ValueKey('star-lead-2')),
              matching: find.byType(Icon),
            ),
          )
          .icon,
      Icons.star_border,
    );

    await tester.tap(find.byKey(const ValueKey('star-lead-2')));
    await tester.pumpAndSettle();

    expect(_patches['lead-2'], {'starred': true});
    expect(
      tester
          .widget<Icon>(
            find.descendant(
              of: find.byKey(const ValueKey('star-lead-2')),
              matching: find.byType(Icon),
            ),
          )
          .icon,
      Icons.star,
    );
  });

  testWidgets('filters narrow together and Mark all read clears the weight', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        _lead('lead-1', 'Marcus Bell'),
        _lead('lead-2', 'Dana', unread: true),
      ],
      connections: _connected,
    );

    await tester.tap(find.byKey(const ValueKey('filter-unread')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('filter-starred')));
    await tester.pumpAndSettle();

    expect(_lastListQuery['unread'], '1');
    expect(_lastListQuery['starred'], '1', reason: 'both, not either');

    await tester.tap(find.byKey(const ValueKey('mark-all-read')));
    await tester.pumpAndSettle();

    expect(_posts, contains('/api/v1/leads/read-all'));
    expect(find.byKey(const ValueKey('unreplied-lead-2')), findsNothing);
    expect(
      tester.widget<Text>(find.text('Dana').first).style?.fontWeight,
      isNot(FontWeight.w700),
    );
  });
}

/// Approved desktop layout 1: the dot is the only mark for unreplied and it
/// is on the left; when the business sent last the preview says "You:".
void _conversationRowTests() {
  testWidgets('they spoke last gets the dot; you spoke last gets "You:" and '
      'no dot', (tester) async {
    await _pump(
      tester,
      leads: [
        _lead(
          'lead-1',
          'Dana Whitfield',
          from: 'CONSUMER',
          channel: 'MESSAGE',
          preview: 'Can you come out Thursday morning?',
        ),
        _lead(
          'lead-2',
          'R. Okonkwo',
          from: 'ADVERTISER',
          channel: 'PHONE_CALL',
          preview: 'Call · 1:08',
        ),
      ],
      connections: _connected,
    );

    expect(find.byKey(const ValueKey('unreplied-lead-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('unreplied-lead-2')), findsNothing);
    expect(find.textContaining('You: Call · 1:08'), findsOneWidget);
    expect(find.textContaining('Can you come out Thursday'), findsOneWidget);
  });

  testWidgets('the badge says Call or Text, never Google\'s word', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        _lead(
          'lead-1',
          'Marcus Bell',
          from: 'CONSUMER',
          channel: 'PHONE_CALL',
          preview: 'Call · 4:32',
        ),
        _lead(
          'lead-2',
          'Dana Whitfield',
          from: 'CONSUMER',
          channel: 'MESSAGE',
          preview: 'Thursday?',
        ),
      ],
      connections: _connected,
    );

    expect(find.text('Call'), findsOneWidget);
    expect(find.text('Text'), findsOneWidget);
    expect(find.textContaining('PHONE_CALL'), findsNothing);
    expect(find.textContaining('LS Ads'), findsNothing);
  });

  /// 23,686 of 23,875 live leads are classified "home inspector" by Google,
  /// for a chimney company. The category is a false statement about the
  /// business and never reaches the screen; the service does, humanised.
  testWidgets('the category never reaches the screen; the service does, '
      'humanised', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );

    expect(
      find.text('Fireplace chimney · Google Ads · Connecticut'),
      findsOneWidget,
    );
    expect(find.textContaining('home inspector'), findsNothing);
    expect(find.textContaining('xcat'), findsNothing);
    expect(find.textContaining('fireplace_chimney'), findsNothing);
  });

  /// 1,576 live leads carry no service. The segment is omitted, not replaced
  /// with "No service given": nobody can supply what Google did not send.
  testWidgets('a lead with no service omits the segment rather than naming '
      'the absence', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell', service: null)],
      connections: _connected,
    );

    expect(find.text('Google Ads · Connecticut'), findsOneWidget);
    expect(find.textContaining('No service'), findsNothing);
  });

  /// Mobile layout 4: the row states its value, tapping opens the thread
  /// full screen with a back bar.
  testWidgets('on a phone, tapping a row opens the thread with a back bar', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        _lead(
          'lead-1',
          'Marcus Bell',
          from: 'CONSUMER',
          channel: 'PHONE_CALL',
          preview: 'Call · 4:32',
        ),
      ],
      connections: _connected,
      size: const Size(375, 812),
    );

    expect(find.byType(AppBar), findsNothing);

    await tester.tap(find.text('Marcus Bell'));
    await tester.pumpAndSettle();

    expect(find.byType(AppBar), findsOneWidget);
    expect(find.byType(BackButton), findsOneWidget);
    expect(
      find.text(
        'Nothing said yet. Google has not recorded a call or '
        'message on this enquiry.',
      ),
      findsOneWidget,
    );
  });
}

final _posts = <String>[];
final _patches = <String, Map<String, dynamic>>{};
Map<String, String> _lastListQuery = const {};

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> leads,
  required List<Map<String, dynamic>> connections,
  List<String> permissions = const ['leads.view', 'leads.edit'],
  Size size = const Size(1200, 900),
}) async {
  _posts.clear();
  _patches.clear();
  _lastListQuery = const {};
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST' &&
          request.url.path.startsWith('/api/v1/leads/')) {
        _posts.add(request.url.path);

        // A reply rebuilds the row's cache of the newest message, the way the
        // server does when it stores one. Without this the list read after a
        // send would return the same row and the test could not tell a screen
        // that refreshes from one that does not.
        if (request.url.path.endsWith('/messages')) {
          final text = (jsonDecode(request.body) as Map)['text'] as String;
          final id = request.url.path.split('/')[4];
          final i = leads.indexWhere((l) => l['id'] == id);

          if (i >= 0) {
            leads[i] = {
              ...leads[i],
              'last_contact_from': 'ADVERTISER',
              'last_contact_channel': 'MESSAGE',
              'last_contact_at': '2026-09-09T18:00:00Z',
              'last_contact_preview': text,
            };
          }

          return http.Response(
            jsonEncode({
              'data': {
                'id': 'c-sent',
                'channel': 'MESSAGE',
                'from': 'ADVERTISER',
                'occurred_at': '2026-09-09T18:00:00Z',
                'duration_ms': null,
                'text': text,
                'attachments': <String>[],
                'has_recording': false,
              },
            }),
            201,
            headers: {'content-type': 'application/json'},
          );
        }

        return http.Response(
          '{"data":{}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      // The thread's own fetch when a row is opened.
      if (request.method == 'GET' &&
          request.url.path.startsWith('/api/v1/leads/')) {
        final id = request.url.path.split('/').last;
        final lead = leads.firstWhere(
          (l) => l['id'] == id,
          orElse: () => const {},
        );

        return http.Response(
          jsonEncode({
            'data': {
              ...lead,
              'can_reply': true,
              'conversations': const [],
              'customer_matches': const [],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.method == 'PATCH' &&
          request.url.path.startsWith('/api/v1/leads/')) {
        final id = request.url.path.split('/').last;
        final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
        _patches[id] = body;
        final lead = leads.firstWhere((l) => l['id'] == id);
        return http.Response(
          jsonEncode({
            'data': {
              ...lead,
              if (body.containsKey('starred')) 'starred': body['starred'],
              if (body.containsKey('note')) 'note': body['note'],
              if (body.containsKey('note'))
                'note_by': {'id': 'u-1', 'name': 'Jose'},
              if (body.containsKey('note'))
                'note_updated_at': '2026-09-08T18:00:00Z',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      // A disconnect takes the row out of what the next list read returns,
      // the way the real endpoint does.
      if (request.method == 'DELETE' &&
          request.url.path.startsWith('/api/v1/social/connections/')) {
        final id = request.url.path.split('/').last;
        connections.removeWhere((c) => c['id'] == id);
        return http.Response(
          '{"data":{}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/me')) {
        return http.Response(
          jsonEncode({
            'data': {..._me, 'permissions': permissions},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/leads')) {
        _lastListQuery = request.url.queryParameters;
        return http.Response(
          jsonEncode({
            'data': leads,
            'meta': {
              'total': leads.length,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
              'connections': connections,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{}',
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

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
  'modules': [
    {'key': 'leads', 'label': 'Leads', 'available': true, 'reachable': true},
  ],
};
