import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:ezbizi_client/widgets/leads_accounts.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
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
  // UI queue 103 (A1 takeover): the charge / IVR / dispatcher / date
  // filters, and editing a lead with its caller's history.
  testWidgets('a charge filter asks the server, and Edit saves the fields', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );

    // Folded behind Filters since UI queue 108.
    await tester.tap(find.byKey(const ValueKey('leads-more-filters')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('leads-charge')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Charged').last);
    await tester.pumpAndSettle();
    expect(_lastListQuery['charge'], 'CHARGED');

    await tester.tap(find.text('Marcus Bell').first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('lead-edit')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('lead-edit-name')),
      'Marcus Bellamy',
    );
    await tester.enterText(find.byKey(const ValueKey('lead-edit-zip')), '62704');
    await tester.tap(find.byKey(const ValueKey('lead-edit-save')));
    await tester.pumpAndSettle();

    expect(_patches['lead-1']?['contact_name'], 'Marcus Bellamy');
    expect(_patches['lead-1']?['postal_code'], '62704');
    expect(_patches['lead-1']!.containsKey('phone'), isFalse);
  });

  _conversationRowTests();

  /// A NOTIFICATION OPENS ITS OWN LEAD, not the newest.
  ///
  /// Jose, 2026-09-28: clicking one "always [opens] the newest lead, instead
  /// of the one it was spawned for". Two leads, the notification's second:
  /// its name drawn twice (row and thread header) and the newest once is
  /// what says the thread is the right one.
  testWidgets('arriving from a notification opens that lead', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell'), _lead('lead-2', 'Dana Whitfield')],
      connections: _connected,
      initialLeadId: 'lead-2',
    );

    expect(find.text('Dana Whitfield'), findsNWidgets(2));
    expect(find.text('Marcus Bell'), findsOneWidget);
  });

  /// Jose, 2026-11-05: a notification for A1 Garage Doors opened while the
  /// view was on Ace Fireplace, and every reply was refused as out of view.
  testWidgets('a notification for another company moves the view there', (
    tester,
  ) async {
    final inCompany = {
      ..._me,
      'scope': {'type': 'company', 'id': 'co-1', 'label': 'Everdoors', 'path': ['Everdoors']},
      'companies': [
        {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
        {'id': 'co-2', 'name': 'Garage Doors', 'parent_company_id': null},
      ],
    };
    Map<String, dynamic> at(String company, [String id = 'lead-2']) => {
      ..._lead(id, id == 'lead-2' ? 'Dana Whitfield' : 'Ruth Okafor'),
      'company': {'id': company, 'name': company},
    };
    Map<String, dynamic> viewing(String company) => {
      ...inCompany,
      'scope': {'type': 'company', 'id': company, 'label': company, 'path': [company]},
    };
    Future<void> remount(String company, List<Map<String, dynamic>> leads, String? open) async {
      await tester.pumpWidget(const SizedBox());
      await _pump(
        tester,
        leads: leads,
        connections: _connected,
        initialLeadId: open,
        me: viewing(company),
      );
    }

    // Leads opened the ordinary way first, as between two notifications: an
    // earlier test opened lead-2 too.
    await remount('co-1', const [], null);
    final leads = [_lead('lead-1', 'Marcus Bell'), at('co-2')];
    await remount('co-1', leads, 'lead-2');
    expect(_preferences, [
      {'navigation.scope': 'company:co-2'},
    ]);

    // The switch's own remount opens the lead there.
    await remount('co-2', leads, 'lead-2');
    expect(_preferences, isEmpty);
    expect(find.text('Dana Whitfield'), findsNWidgets(2));

    // Then another company picked by hand: the shell hands over the same
    // lead, and it must neither switch back nor stay selected (Jose,
    // 2026-11-05: switching company unselects).
    await remount('co-1', leads, 'lead-2');
    expect(_preferences, isEmpty);
    expect(find.text('Dana Whitfield'), findsOneWidget, reason: 'not selected');

    // A notification lead the view already covers moves nothing, and a
    // company picked afterwards does not snap back to it either.
    final here = [_lead('lead-1', 'Marcus Bell'), at('co-1', 'lead-3')];
    await remount('co-1', here, 'lead-3');
    expect(_preferences, isEmpty);
    expect(find.text('Ruth Okafor'), findsNWidgets(2));
    await remount('co-2', here, 'lead-3');
    expect(_preferences, isEmpty);
    expect(find.text('Ruth Okafor'), findsOneWidget, reason: 'not selected');
  });

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

  /// THE LIST REFRESHES ITSELF.
  ///
  /// Jose, 2026-09-16: "we missed 1 live chat 30 mins ago cause the dispatcher
  /// was on the chat page, but it never auto updated by itself to show the new
  /// message had arrived." A person waiting in a live chat had nobody answer
  /// for half an hour, and the enquiry was on the server the whole time.
  ///
  /// The control is the first pump. Without it this passes on a screen that
  /// re-reads on EVERY frame, which would be a different defect wearing the
  /// same green.
  testWidgets('a new enquiry appears with nobody touching the screen', (
    tester,
  ) async {
    final leads = [_lead('lead-1', 'Marcus Bell')];

    await _pump(tester, leads: leads, connections: _connected);

    // Marcus is drawn twice - the row and the thread header beside it - which
    // is why the assertions below are about Dana.
    expect(find.text('Marcus Bell'), findsWidgets);
    expect(find.text('Dana Whitfield'), findsNothing);

    // A website chat arrives on the server. Nobody clicks anything.
    leads.insert(0, _lead('lead-2', 'Dana Whitfield'));

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();
    expect(
      find.text('Dana Whitfield'),
      findsNothing,
      reason: 'five seconds in, nothing should have been asked for yet - '
          'without this the test cannot tell a poll from a rebuild',
    );

    await tester.pump(const Duration(seconds: 16));
    await tester.pump();

    expect(
      find.text('Dana Whitfield'),
      findsWidgets,
      reason: 'the enquiry was on the server and the dispatcher was looking '
          'straight at the screen',
    );
  });

  /// AND IT MUST NOT MOVE THE PANE UNDER SOMEBODY.
  ///
  /// `_selected` falls back to the first row when nobody has picked one, so a
  /// new enquiry arriving at the top opens itself - which is right for a
  /// dispatcher watching for the next chat. Once they HAVE picked a row, the
  /// refresh must leave it alone: a thread that swaps itself out every twenty
  /// seconds while somebody is reading it would be a worse bug than the one
  /// this fixes, and it would look like the feature working.
  testWidgets('a refresh leaves the open thread where it was', (tester) async {
    final leads = [
      _lead('lead-1', 'Marcus Bell'),
      _lead('lead-2', 'Priya Raman'),
    ];

    await _pump(tester, leads: leads, connections: _connected);

    await tester.tap(find.text('Priya Raman').first);
    await tester.pumpAndSettle();

    // Two of her on screen now: the row, and the thread header beside it.
    expect(find.text('Priya Raman'), findsNWidgets(2));

    leads.insert(0, _lead('lead-3', 'Dana Whitfield'));

    await tester.pump(const Duration(seconds: 21));
    await tester.pump();

    expect(find.text('Dana Whitfield'), findsOneWidget,
        reason: 'the new enquiry belongs in the LIST');
    expect(
      find.text('Priya Raman'),
      findsNWidgets(2),
      reason: 'the thread being read must still be the thread on screen',
    );
  });

  /// And it is not a Google rule. The source tabs are one list with a filter,
  /// so the refresh has to survive the filter being set - which is the state a
  /// dispatcher watching live chat is actually in.
  testWidgets('the refresh keeps the source filter it was given', (
    tester,
  ) async {
    final leads = [_lead('lead-1', 'Marcus Bell')];

    await _pump(tester, leads: leads, connections: _connected);

    // The tab, not All's toggle of the same name (2026-09-29).
    await tester.tap(find.descendant(
      of: find.byKey(const ValueKey('source-tabs')),
      matching: find.text('Chat'),
    ));
    await tester.pumpAndSettle();
    expect(_lastListQuery['source'], 'website_chat');

    _lastListQuery = const {};
    await tester.pump(const Duration(seconds: 21));
    await tester.pump();

    expect(
      _lastListQuery['source'],
      'website_chat',
      reason: 'a refresh that drops the filter would swap the dispatcher off '
          'the tab they are watching',
    );
  });

  /// A STALLED SYNC SAYS SO WHERE SOMEBODY IS LOOKING.
  ///
  /// 2026-09-16: Google refused every Local Services fetch for four hours and
  /// nothing on any screen said a word. The auto-refresh shipped the same day
  /// makes that worse rather than better - a dead sync now looks exactly like
  /// a quiet afternoon, the same rows re-read every twenty seconds with a
  /// dispatcher watching it happen.
  ///
  /// Two tests rather than two pumps in one: a second `pumpWidget` of the same
  /// widget type REUSES the State, so `initState` and the load it starts never
  /// run again and the screen keeps the first answer. The first version of
  /// this test did that and failed for a reason that had nothing to do with
  /// the feature.
  testWidgets('the sources band says when enquiries are delayed', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
      delayedSince: '2026-09-16T09:25:00Z',
    );

    expect(find.byKey(const ValueKey('sync-delayed')), findsOneWidget);

    // And it says the thing that stops somebody panicking: the enquiries are
    // late, not lost. Without that sentence the honest answer reads as data
    // loss, which is a different and worse claim.
    expect(find.textContaining('Nothing is lost'), findsOneWidget);
  });

  /// THE CONTROL, and it is the half that keeps the feature honest: a healthy
  /// list must not wear an outage.
  testWidgets('a healthy sync says nothing at all', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );

    expect(
      find.byKey(const ValueKey('sync-delayed')),
      findsNothing,
      reason: 'a quiet afternoon must not be dressed as an outage',
    );
  });

  /// Design 30: each source in the rail carries its unread count, All the
  /// sum of what it holds, and a red mark when its accounts stop delivering.
  testWidgets('the rail counts unread leads per source and marks a broken one', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': 'A1 Chimney - Cleveland',
          'status': 'connected',
        },
        {
          'provider': 'thumbtack',
          'display_name': 'A1 Chimney',
          'status': 'broken',
        },
      ],
      unreadBySource: const {'ls_text': 9, 'website_chat': 2, 'ls_call': 4},
    );

    Finder inTab(String key, String text) => find.descendant(
      of: find.byKey(ValueKey('source-tab-$key')),
      matching: find.text(text),
    );

    // LS Text is inside SMS Texts, LS Call inside Calls (Jose, 2026-11-02).
    expect(inTab('sms', '9'), findsOneWidget);
    expect(inTab('calls', '4'), findsOneWidget);
    expect(inTab('website_chat', '2'), findsOneWidget);
    // All holds everything but Calls until it is switched on: 9 + 2.
    expect(inTab('all', '11'), findsOneWidget);
    expect(find.byKey(const ValueKey('source-failing-thumbtack')), findsOneWidget);
    // The control: a connected source is not marked.
    expect(find.byKey(const ValueKey('source-failing-sms')), findsNothing);
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
  /// Jose, 2026-09-29: "resizing the sidebar - chat has no permanence it
  /// resets after u leave the tab, default should be 30 - 70".
  testWidgets('the divider starts at 30-70, and a drag is kept', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );

    final divider = find.byKey(const ValueKey('pane-divider'));
    // Of what the source rail leaves (design 30), the rail and its line.
    final left = _railRight(tester);
    final width = tester.getSize(find.byType(LeadsScreen)).width;
    expect(
      tester.getTopLeft(divider).dx,
      closeTo(left + (width - left) * 0.3, 1),
    );
    expect(_preferences, isEmpty, reason: 'nothing is saved before a drag');

    await tester.drag(divider, const Offset(150, 0));
    await tester.pumpAndSettle();

    expect(_preferences.single.keys, ['leads.list_percent']);
    expect(_preferences.single['leads.list_percent'], greaterThan(30));
  });

  testWidgets('the divider opens where it was last left', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
      preferences: {'leads.list_percent': 45},
    );

    final left = _railRight(tester);
    final width = tester.getSize(find.byType(LeadsScreen)).width;
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('pane-divider'))).dx,
      closeTo(left + (width - left) * 0.45, 1),
    );
  });

  _triageTests();

  // Jose, 2026-09-28: "there is some overflow issues when u put the app on 50%
  // of the screen". Half of a 1920 desktop, less the sidebar, and the widths
  // either side of the two-pane breakpoint.
  for (final width in [700.0, 740.0, 880.0, 960.0, 1040.0]) {
    testWidgets('nothing overflows at ${width.toInt()} wide', (tester) async {
      final errors = <FlutterErrorDetails>[];
      final previous = FlutterError.onError;
      FlutterError.onError = errors.add;

      await _pump(
        tester,
        size: Size(width, 900),
        leads: [
          {
            ..._lead('lead-1', 'Dave Malkowski with a long surname'),
            'phone': '+12196692720',
            'can_reply': true,
            'reply_overdue': true,
            'customer': {'id': 'cu-1', 'number': 'C-0042', 'name': 'Dave'},
          },
          {..._lead('lead-2', ''), 'phone': '+12155550199'},
        ],
        connections: const [
          {
            'id': 'g-1',
            'provider': 'google_ads',
            'display_name': 'A1 Chimney - Cleveland',
            'status': 'connected',
            'leads_count': 3,
          },
        ],
      );

      FlutterError.onError = previous;
      final overflows = errors
          .where((e) => '${e.exception}'.contains('overflowed'))
          .map((e) => '${e.exception}'.split('\n').first)
          .toList();
      expect(overflows, isEmpty);
    });
  }

  // Jose, 2026-09-30: "add a tag with the name of the company it belongs to,
  // so this doesn't happen again" - chimney prices went to a garage-door
  // lead. Every row says whose lead it is.
  // Jose, 2026-09-30: the company tag belongs on the lead's header only,
  // "not on both" - the list rows carry none.
  testWidgets('list rows carry no company tag', (tester) async {
    await _pump(
      tester,
      leads: [
        {
          ..._lead('lead-2', 'B'),
          'company': {'id': 'c-2', 'name': 'Harbor Garage Doors'},
        },
      ],
      connections: const [],
    );

    expect(find.byKey(const ValueKey('company-tag-lead-2')), findsNothing);
    // The one tag left is the open lead's header.
    expect(find.byKey(const ValueKey('thread-company')), findsOneWidget);
    expect(find.byType(CompanyTag), findsOneWidget);
  });

  // Jose, 2026-09-29: the Google tag says LS Call or LS Text, and every
  // source has its own colour.
  testWidgets('each source tag has its own word and colour', (tester) async {
    await _pump(
      tester,
      leads: [
        {..._lead('lead-1', 'A'), 'source': 'local_services', 'lead_type': 'PHONE_CALL'},
        {..._lead('lead-2', 'B'), 'source': 'local_services', 'lead_type': 'MESSAGE'},
        {..._lead('lead-3', 'C'), 'source': 'thumbtack'},
        {..._lead('lead-4', 'D'), 'source': 'website_form'},
      ],
      connections: const [],
    );

    String word(String id) => tester
        .widget<Text>(find.descendant(of: find.byKey(ValueKey('source-badge-$id')), matching: find.byType(Text)))
        .data!;
    Color? fill(String id) => ((tester.widget<Container>(find.descendant(
      of: find.byKey(ValueKey('source-badge-$id')),
      matching: find.byType(Container),
    ).first)).decoration as BoxDecoration?)?.color;

    expect([for (final id in ['lead-1', 'lead-2', 'lead-3', 'lead-4']) word(id)],
        ['LS Call', 'LS Text', 'Thumbtack', 'Form']);
    expect({for (final id in ['lead-1', 'lead-2', 'lead-3', 'lead-4']) fill(id)}, hasLength(4),
        reason: 'no two sources share a colour');
  });

  // Jose, 2026-09-29: All holds every source but LS Call, and each can be
  // switched on or off. 2026-10-01: LS Booking is left out too.
  testWidgets('All leaves LS Call and LS Booking out until switched on', (tester) async {
    await _pump(tester, leads: [_lead('lead-1', 'A')], connections: const []);

    expect(_lastListQuery['sources'], isNot(contains('ls_call')));
    expect(_lastListQuery['sources'], isNot(contains('ls_booking')));
    expect(_lastListQuery['sources'], contains('ls_text'));

    // Folded behind Filters since UI queue 108.
    await tester.tap(find.byKey(const ValueKey('leads-more-filters')));
    await tester.pumpAndSettle();
    // LS Call is one of Calls, switched on together (Jose, 2026-11-02).
    await tester.tap(find.byKey(const ValueKey('all-source-calls')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], contains('ls_call'));
    expect(_lastListQuery['sources'], isNot(contains('ls_booking')));

    await tester.tap(find.byKey(const ValueKey('all-source-ls_booking')));
    await tester.pumpAndSettle();
    expect(_lastListQuery.containsKey('sources'), isFalse, reason: 'everything on: no narrowing');

    await tester.tap(find.byKey(const ValueKey('all-source-thumbtack')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], isNot(contains('thumbtack')));
    expect(_lastListQuery['sources'], contains('ls_call'));
  });

  // Jose, 2026-09-29: the list shows who else has a lead open.
  testWidgets('a lead somebody else has open says who', (tester) async {
    await _pump(
      tester,
      leads: [
        {..._lead('lead-1', 'A'), 'viewers': ['Tanya Ruiz', 'Marco Diaz']},
        {..._lead('lead-2', 'B'), 'viewers': <String>[]},
      ],
      connections: const [],
    );

    expect(find.byKey(const ValueKey('viewers-lead-1')), findsOneWidget);
    expect(find.text('Tanya Ruiz +1'), findsOneWidget);
    expect(find.byKey(const ValueKey('viewers-lead-2')), findsNothing);
  });

  // Jose, 2026-09-28: a customer left waiting is marked red on the list.
  testWidgets('a lead waiting too long for a reply wears a red warning', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        {..._lead('lead-1', 'Waiting'), 'can_reply': true, 'reply_overdue': true},
        {..._lead('lead-2', 'Answered'), 'can_reply': true, 'reply_overdue': false},
      ],
      connections: const [],
    );

    expect(find.byKey(const ValueKey('overdue-lead-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('overdue-lead-2')), findsNothing);
  });

  // Jose, 2026-10-01: the sign alone "is not enough" - the row turns redder
  // the longer the customer waits (and pulses, off here: pumpAndSettle).
  testWidgets('a waiting row reddens with the wait', (tester) async {
    OverdueGlow.pulse = false;
    addTearDown(() => OverdueGlow.pulse = true);
    String ago(int m) => DateTime.now().subtract(Duration(minutes: m)).toUtc().toIso8601String();
    await _pump(
      tester,
      leads: [
        _lead('lead-0', 'Open one'),
        {..._lead('lead-1', 'Long'), 'reply_overdue': true, 'last_contact_at': ago(55)},
        {..._lead('lead-2', 'Short'), 'reply_overdue': true, 'last_contact_at': ago(12)},
      ],
      connections: const [],
    );

    double redness(String id) {
      final box = tester.widget<DecoratedBox>(find.descendant(
        of: find.byKey(ValueKey('overdue-glow-$id')),
        matching: find.byType(DecoratedBox),
      ).first);
      return (box.decoration as BoxDecoration).color!.a;
    }

    expect(redness('lead-1'), greaterThan(redness('lead-2')));
    expect(redness('lead-2'), greaterThan(0));
  });

  // Jose, 2026-10-01: "i should be able to load more ... when scrolling to
  // the bottom by itself" - ten older leads each time the end is reached.
  testWidgets('scrolling to the end loads the next ten', (tester) async {
    await _pump(
      tester,
      leads: [for (var i = 0; i < 65; i++) _lead('lead-$i', 'Customer $i')],
      connections: const [],
    );

    final list = find.descendant(
      of: find.byKey(const ValueKey('leads-list')),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(find.text('Customer 49'), 400, scrollable: list);
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Customer 64'), 400, scrollable: list);
    await tester.pumpAndSettle();
    expect(find.text('Customer 64'), findsOneWidget);
    expect(_lastListQuery['page'], isNotNull, reason: 'the next rows were asked for by page');
  });

  // Jose, 2026-09-28: a number already in Customers is shown as the customer.
  testWidgets('a lead from a known customer is named and badged as one', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        {
          ..._lead('lead-1', ''),
          'phone': '+12155550101',
          'customer': {'id': 'cu-1', 'number': 'C-0042', 'name': 'Marcus Bell'},
        },
        {..._lead('lead-2', ''), 'phone': '+12155550199'},
      ],
      connections: const [],
    );

    expect(find.byKey(const ValueKey('customer-badge-lead-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-badge-lead-2')), findsNothing);
    expect(find.text('Marcus Bell'), findsWidgets);
  });

  /// Jose, 2026-10-13: a dispatcher's note shows on the row, not only inside.
  testWidgets('a lead with a note wears a note mark that reads it', (tester) async {
    await _pump(
      tester,
      leads: [
        {
          ..._lead('lead-1', ''),
          'note': 'i called cx no answer i left voicemail',
          'note_by': {'id': 'u-1', 'name': 'Briana'},
        },
        _lead('lead-2', ''),
      ],
      connections: const [],
    );

    final mark = find.byKey(const ValueKey('note-mark-lead-1'));
    expect(mark, findsOneWidget);
    expect(
      tester.widget<Tooltip>(find.ancestor(of: mark, matching: find.byType(Tooltip)).first).message,
      'Note · Briana\ni called cx no answer i left voicemail',
    );
    expect(find.byKey(const ValueKey('note-mark-lead-2')), findsNothing);
  });

  /// A specimen from `ezbizi:thumbtack-test-lead` is a real row with a real
  /// Thumbtack id. It stays in the list — seeing it is how somebody knows the
  /// pipe works — and it says what it is, because on 2026-09-10 one of these
  /// was nearly reported as the first Thumbtack customer.
  testWidgets(
    'a test lead wears a Test badge and a customer enquiry does not',
    (tester) async {
      await _pump(
        tester,
        leads: [
          {
            ..._lead('lead-1', 'Test Customer'),
            'source': 'thumbtack',
            'is_test': true,
          },
          {..._lead('lead-2', 'Marcus Bell'), 'source': 'thumbtack'},
        ],
        connections: const [
          {
            'id': 'tb-1',
            'provider': 'thumbtack',
            'display_name': 'A1 Chimney',
            'status': 'connected',
          },
        ],
      );

      expect(find.byKey(const ValueKey('test-badge-lead-1')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('test-badge-lead-2')),
        findsNothing,
        reason: 'the badge must mark the specimen, not every Thumbtack row',
      );

      // The row is still a lead in every other respect, and still reachable.
      // findsWidgets, not one: the first lead is open in the thread pane at
      // this width, so its name is on the row and in the thread header.
      expect(find.text('Test Customer'), findsWidgets);
      expect(find.byKey(const ValueKey('source-badge-lead-1')), findsOneWidget);
    },
  );

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

    // The count moved from a band over the list to the Connections page
    // Manage opens (UI queue 34), and to Manage's own tooltip.
    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(
      find.text('3 accounts · 2 sending leads'),
      findsOneWidget,
      reason:
          'The page states how many accounts exist. Naming one of them was '
          'the defect: it looked correct and was arbitrary.',
    );

    // And the line under the list carries the fact that actually changes.
    expect(find.text('2 at company level · 1 on a franchise'), findsOneWidget);
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
      // Sources beside it: this case is about leads.edit, and a role with no
      // source at all would be a different test failing for a second reason.
      permissions: const [
        'leads.view',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
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
      permissions: const [
        'leads.view',
        'leads.edit',
        'leads.manage',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
    );

    expect(find.text('Connect Google Ads'), findsOneWidget);
  });


  // ── arriving from a notification (Jose, 2026-09-16) ─────────────

  testWidgets('a notification can open the screen already narrowed to its '
      'source', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      initialSource: 'website_chat',
    );

    // The FIRST list request is already narrowed. Asserting the drawn chip
    // alone would pass on a screen that loads everything and then filters,
    // which is what a caller would notice as the list flashing every source's
    // leads before settling.
    expect(_lastListQuery['source'], 'website_chat');
  });

  testWidgets('a source the strip does not know is ignored, not selected',
      (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      initialSource: 'carrier_pigeon',
    );

    // Not narrowed to nothing. An unknown source passed straight through would
    // select no chip and ask the API for a source that matches no lead, which
    // draws as an empty inbox - the one failure this screen exists to avoid.
    expect(_lastListQuery.containsKey('source'), isFalse);
  });


  testWidgets('Messenger and Instagram are reachable sources', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const [
        'leads.view',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
        'leads.facebook.view',
        'leads.instagram.view',
      ],
    );

    // Jose, 2026-09-22: Facebook and Instagram messages belong in Leads rather
    // than in an inbox of their own. Since 2026-11-02 (design 166) they share
    // one Meta tab, and each is still its OWN chip inside it: they arrive in
    // one webhook envelope and are told apart only by Meta's `object`, so
    // without the chips one of them could only be reached by scrolling.
    await tester.tap(find.widgetWithText(ChoiceChip, 'Meta'));
    await tester.pumpAndSettle();
    for (final word in ['facebook', 'instagram']) {
      expect(
        find.byKey(ValueKey('meta-source-$word')),
        findsOneWidget,
        reason: '$word has no chip inside Meta.',
      );
    }
    expect(find.byKey(const ValueKey('meta-source-whatsapp')), findsNothing,
        reason: 'WhatsApp was not granted');

    // And the four that were already there still are - a list built by index
    // from two const lists is exactly where an insertion goes wrong silently.
    for (final word in ['Calls', 'SMS Texts', 'Thumbtack', 'Chat', 'Forms']) {
      expect(find.widgetWithText(ChoiceChip, word), findsOneWidget);
    }
  });

  testWidgets('picking Messenger asks the API for that source', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.facebook.view'],
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Meta'));
    await tester.pumpAndSettle();
    // Meta asks for the Meta sources this person holds, never 'meta' itself.
    expect(_lastListQuery['source'], isNull);
    expect(_lastListQuery['sources'], 'facebook');

    await tester.tap(find.byKey(const ValueKey('meta-source-facebook')));
    await tester.pumpAndSettle();

    // The chip label and the API value are different strings, and the mapping
    // between them lives in one list. Asserting the drawn label alone would
    // pass with the wrong source wired behind it.
    expect(_lastListQuery['sources'], 'facebook');
  });

  /// Design 166 (Jose, 2026-11-02): one Meta tab, its three sources picked inside it.
  testWidgets('Meta holds all three, picks one, and All never asks for "meta"', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.local_services.view', 'leads.facebook.view',
        'leads.instagram.view', 'leads.whatsapp.view'],
    );
    // All leaves LS Call out by default, so it names what it holds: Meta as its three.
    expect(_lastListQuery['sources']!.split(','), containsAll(['facebook', 'instagram', 'whatsapp']));
    expect(_lastListQuery['sources']!.split(','), isNot(contains('meta')));

    await tester.tap(find.widgetWithText(ChoiceChip, 'Meta'));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'facebook,instagram,whatsapp');

    await tester.tap(find.byKey(const ValueKey('meta-source-whatsapp')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'whatsapp');

    await tester.tap(find.byKey(const ValueKey('meta-source-all')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'facebook,instagram,whatsapp');
  });

  /// Jose, 2026-11-02: Calls (LS, RC, EZ), SMS Texts (LS, EZ) and Forms +
  /// Job applicants, each one tab with its sources picked inside it.
  testWidgets('Calls, SMS Texts and Forms each hold their sources', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.local_services.view', 'leads.ringcentral.view',
        'leads.ez_calls.view', 'leads.ez_texts.view', 'leads.website_form.view'],
    );
    for (final gone in ['LS Call', 'RC Calls', 'EZ Texts', 'Job applicants']) {
      expect(find.widgetWithText(ChoiceChip, gone), findsNothing, reason: '$gone is inside a tab now');
    }
    // All: every call left out by default, no applicant ever.
    final all = _lastListQuery['sources']!.split(',');
    expect(all, containsAll(['ls_text', 'ez_texts', 'website_form']));
    expect(all, isNot(contains('ls_call')));
    expect(all, isNot(contains('job_applicant')));

    await tester.tap(find.widgetWithText(ChoiceChip, 'Calls'));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'ls_call,ringcentral,ez_calls');
    await tester.tap(find.byKey(const ValueKey('calls-source-ringcentral')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'ringcentral');

    await tester.tap(find.widgetWithText(ChoiceChip, 'SMS Texts'));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'ls_text,ez_texts');

    await tester.tap(find.widgetWithText(ChoiceChip, 'Forms'));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'website_form,job_applicant');
    await tester.tap(find.byKey(const ValueKey('forms-source-job_applicant')));
    await tester.pumpAndSettle();
    expect(_lastListQuery['sources'], 'job_applicant');
  });

  testWidgets('a link to a source inside a tab opens the tab with it picked', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.website_chat.view', 'leads.ez_calls.view'],
      initialSource: 'ez_calls',
    );
    expect(_lastListQuery['sources'], 'ez_calls');
    // Without Forms, the applicants' tab says what it holds.
    expect(find.widgetWithText(ChoiceChip, 'Job applicants'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Forms'), findsNothing);
  });

  // ── one list across every source (sources layout 1 / 4) ─────────

  testWidgets('one list under a source strip: All first, and every source '
      'has a tab', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    expect(find.byKey(const ValueKey('sources-manage')), findsOneWidget);
    expect(find.byKey(const ValueKey('source-tabs')), findsOneWidget);

    // Every source reachable, Thumbtack included: the rail scrolls when a
    // window is too short for all of them.
    for (final word in ['Calls', 'SMS Texts', 'Thumbtack', 'Chat', 'Forms']) {
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

  /// A SOURCE THIS ROLE WAS NOT GRANTED HAS NO TAB - Jose, 2026-09-17:
  /// *"i should be able to grant access by sub tab as well"*.
  ///
  /// The drawing half only. The rows are filtered by the server on every
  /// request including All, because one list behind one endpoint cannot be
  /// gated by hiding a chip - a chip is a convenience, not a permission.
  testWidgets('the strip draws only the sources this role holds', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.website_chat.view'],
    );

    // The positive half: the one they hold is still there, so an empty strip
    // cannot pass this by hiding everything.
    expect(find.widgetWithText(ChoiceChip, 'Chat'), findsOneWidget);

    for (final word in ['Google', 'Thumbtack', 'Forms']) {
      expect(
        find.widgetWithText(ChoiceChip, word),
        findsNothing,
        reason: '$word was not granted, so it must not be offered.',
      );
    }
  });

  /// Tapping the second tab means the second SURVIVING source.
  ///
  /// The strip is built from the filtered list, so the index the widget hands
  /// back is an index into that list. Built from the full list and filtered
  /// only for drawing, this tap would have asked for Thumbtack.
  testWidgets('a tab in a narrowed strip asks for its own source', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const [
        'leads.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Forms'));
    await tester.pumpAndSettle();

    // Forms holds Job applicants too (Jose, 2026-11-02).
    expect(_lastListQuery['source'], isNull);
    expect(_lastListQuery['sources'], 'website_form,job_applicant');
  });

  /// A notification for a source this person may not see opens on All.
  ///
  /// Same branch as `carrier_pigeon` above and the same reason: a request
  /// narrowed to a source that answers nothing draws as an empty inbox, and an
  /// empty inbox is indistinguishable from a quiet morning.
  testWidgets('a deep link to an ungranted source falls back to All', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.website_chat.view'],
      initialSource: 'thumbtack',
    );

    expect(_lastListQuery.containsKey('source'), isFalse);
  });

  testWidgets('a source tab narrows the list, and All widens it again', (
    tester,
  ) async {
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

    // One All now, the rail's (design 30): the filter row's second one went
    // with the strip it had to be kept in step with.
    await tester.tap(find.widgetWithText(ChoiceChip, 'All'));
    await tester.pumpAndSettle();
    expect(_lastListQuery.containsKey('source'), isFalse);
    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Chat'))
          .selected,
      isFalse,
      reason: 'The Chat tab is still lit after All widened the list.',
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
      reason:
          'the rail\'s tab and one badge, not a second badge (In All is '
          'behind Filters since UI queue 108)',
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

  // Manage opens the Connections page in place of the leads (UI queue 34,
  // Jose's 1 + 4 of 2026-09-30). leads_accounts_test.dart holds the page
  // itself; these hold it inside the screen.
  testWidgets('an unconnected Thumbtack offers to connect from Connections '
      'and says what connecting is for', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('provider-thumbtack')));
    await tester.pumpAndSettle();

    expect(find.text('No Thumbtack account connected'), findsOneWidget);
    expect(find.text('Connect Thumbtack'), findsOneWidget);
    expect(find.textContaining('brings Thumbtack enquiries'), findsOneWidget);
  });

  /// It used to say "enquiries not fetched yet". They are: Thumbtack's
  /// webhook delivers them into Leads (three in production by 2026-09-30), so
  /// the sentence had become the lie it was written to prevent.
  testWidgets('a connected Thumbtack business is listed like any account', (
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
          'leads_count': 3,
        },
      ],
    );

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.text('A-1 Chimney'), findsOneWidget);
    expect(find.text('1 business · 1 sending leads'), findsOneWidget);
    expect(find.textContaining('not fetched yet'), findsNothing);
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

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();

    expect(find.text('12 businesses · 0 sending leads'), findsOneWidget);
    expect(find.byType(LeadsConnections), findsOneWidget);
    expect(find.text('12 that have never sent a lead'), findsOneWidget);
  });

  /// The panel was its own route, so the screen's reload did not reach it: a
  /// row disconnected from inside it stayed until the panel was closed and
  /// opened again (Jose, 2026-09-09: "after clicking it the item remains").
  /// The page is part of the screen now, and must stay that way.
  testWidgets('an account disconnected on the Connections page leaves it', (
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

    await tester.tap(find.byKey(const ValueKey('twin-c0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('account-disconnect')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('account-disconnect-confirm')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('disconnect-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const Key('disconnect-confirm')));
    await tester.pumpAndSettle();

    expect(find.byType(LeadsConnections), findsOneWidget, reason: 'still open');
    expect(find.byKey(const ValueKey('row-c0')), findsNothing);
    expect(find.text('1 business · 0 sending leads'), findsOneWidget);
  });

  testWidgets('without leads.manage there is no Manage to open', (
    tester,
  ) async {
    // leads.manage (2026-10-15) is the grant for the Manage pane; a role
    // holding the sources and edit but not it is not offered the pane.
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const [
        'leads.view',
        'leads.edit',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
    );

    expect(find.byKey(const ValueKey('sources-manage')), findsNothing);
    // Control: the strip itself is drawn, so the absence is the grant.
    expect(find.text('Thumbtack'), findsWidgets);
  });

  testWidgets('Connections goes back to the leads', (tester) async {
    await _pump(tester, leads: [_lead('l1', 'Maria')], connections: const []);

    await tester.tap(find.byKey(const ValueKey('sources-manage')));
    await tester.pumpAndSettle();
    expect(find.byType(LeadsConnections), findsOneWidget);

    await tester.tap(find.byTooltip('Back to the leads'));
    await tester.pumpAndSettle();
    expect(find.byType(LeadsConnections), findsNothing);
    expect(find.text('Maria'), findsWidgets);
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
  bool canReply = false,
  String? note,
}) => {
  'id': id,
  'is_unread': unread,
  // Defaults to false so every existing fixture keeps its old meaning: a row
  // nobody can reply to, which is what a CALL is and what most of these are.
  'can_reply': canReply,
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
  // Design 28 (Jose: "the manage, mark all leads ... like floating in the
  // air"): each control sits beside what it acts on. Design 30 put Manage at
  // the foot of the sources it manages.
  testWidgets('Manage sits under the sources, Mark all read after the chips', (
    tester,
  ) async {
    // Wide enough that the three chips and the button share a line in the
    // test font, which is half again as wide as the app's.
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
      size: const Size(1900, 900),
    );

    final tabs = tester.getRect(find.byKey(const ValueKey('source-tabs')));
    final rail = tester.getRect(find.byKey(const ValueKey('source-rail')));
    final manage = tester.getRect(find.byKey(const ValueKey('sources-manage')));
    expect(manage.top, greaterThanOrEqualTo(tabs.bottom));
    expect(manage.center.dx, closeTo(rail.center.dx, 8));

    final starred = tester.getRect(
      find.byKey(const ValueKey('filter-starred')),
    );
    final markAll = tester.getRect(find.byKey(const ValueKey('mark-all-read')));
    expect((markAll.center.dy - starred.center.dy).abs(), lessThan(8));
    expect(markAll.left - starred.right, lessThan(40));
  });

  testWidgets('a lead that is not New says which on its row', (tester) async {
    await _pump(
      tester,
      leads: [
        _lead('lead-1', 'Marcus Bell'),
        {..._lead('lead-2', 'Dana Whitfield'), 'status': 'archived'},
      ],
      connections: _connected,
    );

    expect(find.byKey(const ValueKey('status-pill-lead-1')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('status-pill-lead-2')),
        matching: find.text('Archived'),
      ),
      findsOneWidget,
    );
  });

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

    // No Unread chip since 2026-11-02 (Jose); Unreplied stands in.
    expect(find.byKey(const ValueKey('filter-unread')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('filter-unreplied')));
    await tester.pumpAndSettle();
    // Design 162: the quick filters are one sideways-scrolling row, so the
    // later chips start off screen in a narrow list.
    await tester.ensureVisible(find.byKey(const ValueKey('filter-starred')));
    await tester.tap(find.byKey(const ValueKey('filter-starred')));
    await tester.pumpAndSettle();

    expect(_lastListQuery['unreplied'], '1');
    expect(_lastListQuery['starred'], '1', reason: 'both, not either');

    await tester.ensureVisible(find.byKey(const ValueKey('mark-all-read')));
    await tester.tap(find.byKey(const ValueKey('mark-all-read')));
    await tester.pumpAndSettle();

    expect(_posts, contains('/api/v1/leads/read-all'));
    expect(find.byKey(const ValueKey('unreplied-lead-2')), findsNothing);
    expect(
      tester.widget<Text>(find.text('Dana').first).style?.fontWeight,
      isNot(FontWeight.w700),
    );
  });

  // Jose, 2026-11-02: "these 3 fields have different sizes".
  testWidgets('search, status and Filters are one height', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
    );
    final heights = [
      for (final k in [
        'tour-leads-search',
        'tour-leads-status',
        'leads-more-filters',
      ])
        tester.getSize(find.byKey(ValueKey(k))).height,
    ];
    expect(heights.toSet(), hasLength(1), reason: '$heights');
    for (final (k, t) in [
      ('tour-leads-search', 'Search name, phone or message'),
      ('tour-leads-status', 'Any status'),
      ('leads-more-filters', 'Filters'),
    ]) {
      final box = tester.getCenter(find.byKey(ValueKey(k))).dy;
      final text = tester
          .getCenter(
            find
                .descendant(
                  of: find.byKey(ValueKey(k)),
                  // Narrow, Filters is its icon alone.
                  matching: t == 'Filters' ? find.byType(Icon) : find.text(t),
                )
                .first,
          )
          .dy;
      expect((box - text).abs(), lessThan(2), reason: '$k off centre');
    }
  });
}

/// The dot is UNREAD; replied-or-not is a badge, and only where a reply is
/// possible. Jose's ruling, 2026-09-15 — see `_row` for why the old meaning was
/// the confusing one.
void _conversationRowTests() {
  testWidgets('the dot is unread, not unreplied', (tester) async {
    await _pump(
      tester,
      leads: [
        // READ, and the enquirer spoke last. Under the OLD rule this row HAD a
        // dot; under the new one it must not.
        //
        // It is first on purpose: a desktop auto-selects the first row and
        // selecting marks a lead read, so an UNREAD row in this position would
        // lose the state under test before the assertion ran. That is what
        // failed the first draft of this test.
        _lead(
          'lead-1',
          'R. Okonkwo',
          from: 'CONSUMER',
          channel: 'MESSAGE',
          preview: 'Can you come out Thursday morning?',
          canReply: true,
        ),
        // UNREAD, and the business already replied. Under the OLD rule this row
        // had no dot; it must have one now. The two rows together are what make
        // this test able to tell the rules apart - either alone passes under
        // both.
        _lead(
          'lead-2',
          'Dana Whitfield',
          from: 'ADVERTISER',
          channel: 'MESSAGE',
          preview: 'Thursday works, see you at 9.',
          unread: true,
          canReply: true,
        ),
      ],
      connections: _connected,
    );

    expect(find.byKey(const ValueKey('unread-lead-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('unread-lead-1')), findsNothing);
    expect(find.textContaining('You: Thursday works'), findsOneWidget);
  });

  testWidgets('the reply badge is drawn only where a reply is possible', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [
        _lead('lead-1', 'Dana Whitfield',
            from: 'CONSUMER', channel: 'MESSAGE',
            preview: 'Can you come out Thursday morning?', canReply: true),
        _lead('lead-2', 'R. Okonkwo',
            from: 'ADVERTISER', channel: 'MESSAGE',
            preview: 'Booked you for Tuesday.', canReply: true),
        // A CALL. Nobody can reply to it, so it carries NO reply badge at all —
        // not "Replied", which would claim a duty was discharged that never
        // existed.
        _lead('lead-3', 'T. Bergqvist',
            from: 'CONSUMER', channel: 'PHONE_CALL', preview: 'Call · 1:08'),
        // Not a lead: the customer spoke last and it can be replied to, but
        // nobody owes an answer (Jose, 2026-11-02).
        {
          ..._lead('lead-4', 'Spam Caller',
              from: 'CONSUMER', channel: 'MESSAGE',
              preview: 'Buy followers', canReply: true),
          'status': 'not_a_lead',
          'reply_owed': false,
        },
      ],
      connections: _connected,
    );

    expect(find.byKey(const ValueKey('reply-badge-lead-4')), findsNothing);
    expect(find.byKey(const ValueKey('reply-badge-lead-1')), findsOneWidget);
    expect(find.text('Awaiting reply'), findsOneWidget);

    expect(find.byKey(const ValueKey('reply-badge-lead-2')), findsOneWidget);
    expect(find.text('Replied'), findsOneWidget);

    expect(
      find.byKey(const ValueKey('reply-badge-lead-3')),
      findsNothing,
      reason: 'a call has no reply box, so it is neither replied nor awaiting one',
    );
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

  // The search, source strip, Connected sources and filters stood over the
  // leads and took 340 of a phone's 800 pixels.
  testWidgets('on a phone the header scrolls away with the leads', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [for (var i = 0; i < 30; i++) _lead('lead-$i', 'Caller $i')],
      connections: _connected,
      size: const Size(375, 812),
    );

    // Design 171: search and the one Filters button.
    final search = find.byKey(const ValueKey('leads-more-filters'));
    expect(tester.getTopLeft(search).dy, greaterThanOrEqualTo(0));

    await tester.drag(find.text('Caller 0'), const Offset(0, -500));
    await tester.pumpAndSettle();

    // Scrolled off the top: past the scroll cache it leaves the tree, and
    // inside it sits above the screen.
    expect(
      search.evaluate().isEmpty || tester.getBottomLeft(search).dy < 0,
      isTrue,
    );
  });

  /// Jose, 2026-11-10: "add a third line, move all the tags to the third
  /// line instead, maybe make them a bit smaller too". Name, time and star on
  /// line 1; every tag on line 3, under the name, status first. Same on phone.
  for (final size in const [Size(1400, 900), Size(390, 844)]) {
    testWidgets('every tag is on line 3, under the name (${size.width})', (
      tester,
    ) async {
      await _pump(
        tester,
        leads: [
          {
            ..._lead('lead-1', 'Christopher Wellington', canReply: true),
            'customer': {'id': 'c1'},
            'status': 'booked',
          },
        ],
        connections: _connected,
        size: size,
      );

      Rect at(String key) => tester.getRect(find.byKey(ValueKey(key)));
      final name = tester.getRect(find.text('Christopher Wellington').first);
      final line3 = at('lead-tags-lead-1');
      expect(line3.top, greaterThan(name.bottom));
      expect((line3.left - name.left).abs(), lessThan(1));
      // The star stays on line 1, beside the time.
      expect(at('star-lead-1').center.dy, lessThan(line3.top));
      // Status, then reply, then Customer, then the source, in reading
      // order across however many runs the pane needs; none hidden.
      final order = [
        'status-pill-lead-1',
        'reply-badge-lead-1',
        'customer-badge-lead-1',
        'source-badge-lead-1',
      ].map((k) => at(k).top.round() * 10000 + at(k).left).toList();
      expect(order, [...order]..sort());
      for (final k in ['status-pill', 'reply-badge', 'customer-badge']) {
        expect(at('$k-lead-1').top, greaterThanOrEqualTo(line3.top));
      }
      expect(find.textContaining(RegExp(r'^\+\d$')), findsNothing);
      // Smaller than today's labelSmall.
      expect(
        tester
            .renderObject<RenderParagraph>(find.text('Customer'))
            .text
            .style
            ?.fontSize,
        10,
      );
      expect(name.width, greaterThan(150));
      expect(tester.takeException(), isNull);
    });
  }

  /// Tapping a row opens the thread in place, with a way back: never a page
  /// pushed over the app (the containment rule; design 30).
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

    expect(find.byType(AppBar), findsNothing, reason: 'no page pushed over it');
    expect(find.byKey(const ValueKey('thread-back')), findsOneWidget);
    // NOT "Google has not recorded a call or message on this enquiry": a
    // claim about Google's records made from ours, and false in the ordinary
    // case, because conversations are read on a slower tick than leads. It
    // read as "the customer said nothing", which is the one thing this screen
    // must never say wrongly.
    expect(
      find.text(
        'Nothing has reached EzBizi on this enquiry yet. A call or message '
        'usually appears within a few minutes of the enquiry itself.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('on a phone, the way back returns to the list', (tester) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
      size: const Size(375, 812),
    );

    await tester.tap(find.text('Marcus Bell'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('thread-back')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('leads-more-filters')), findsOneWidget);
    expect(find.byKey(const ValueKey('thread-back')), findsNothing);
  });

  /// Design 30, phone 5: one button says the source, and opens a page of
  /// them in place; picking one narrows the list and comes back to it.
  testWidgets('on a phone, the source button opens the sources and picks one', (
    tester,
  ) async {
    await _pump(
      tester,
      leads: [_lead('lead-1', 'Marcus Bell')],
      connections: _connected,
      size: const Size(375, 812),
    );

    // Design 171: the source button is on the Filters page.
    await tester.tap(find.byKey(const ValueKey('leads-more-filters')));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('source-button')),
        matching: find.text('All sources'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('source-button')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('sources-page')), findsOneWidget);
    expect(find.byType(AppBar), findsNothing, reason: 'in place, not pushed');
    // What stands behind a source, said under its name.
    expect(find.text('1 Google Ads account'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('source-row-website_chat')));
    await tester.pumpAndSettle();

    expect(_lastListQuery['source'], 'website_chat');
    expect(find.byKey(const ValueKey('sources-page')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('source-button')),
        matching: find.text('Chat'),
      ),
      findsOneWidget,
    );

    // Back on the list, the source it is narrowed to still shows.
    await tester.tap(find.byKey(const ValueKey('filters-done')));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('leads-source-chip')),
        matching: find.text('Chat'),
      ),
      findsOneWidget,
    );
  });

  /// A booking is an appointment somebody made on the ad, not a nameless
  /// enquiry and not a text message.
  ///
  /// Google returns `contactDetails: []` for lead_type BOOKING (measured
  /// against production, 2026-09-10) and there is no other field and no other
  /// resource carrying the customer. The row said "Unnamed enquiry", badged it
  /// "Text" because BOOKING fell through the channel switch, and the thread
  /// drew an empty message bubble attributed to the customer.
  testWidgets('a Google booking is named as one, badged as one, and says where '
      'the details are', (tester) async {
    await _pump(
      tester,
      leads: [
        {
          'id': 'b-1',
          'source': 'local_services',
          'lead_type': 'BOOKING',
          'contact_name': null,
          'phone': null,
          'status': 'new',
          'received_at': '2026-09-10T17:27:03Z',
          'last_contact_channel': 'BOOKING',
          'last_contact_at': '2026-09-10T17:27:03Z',
        },
      ],
      connections: _connected,
    );

    expect(find.text('Booking through Google'), findsWidgets);
    expect(find.text('Unnamed enquiry'), findsNothing);
    expect(
      find.text('Text'),
      findsNothing,
      reason: 'BOOKING fell through the channel switch and read as a message',
    );
    // One tag says both facts since 2026-09-29: Google, and a booking. (The
    // rail's own LS Booking tab, since 2026-10-01, is not the row's tag.)
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('leads-list')),
        matching: find.text('LS Booking'),
      ),
      findsOneWidget,
    );
  });
}

final _posts = <String>[];
final _patches = <String, Map<String, dynamic>>{};
final _preferences = <Map<String, dynamic>>[];
Map<String, String> _lastListQuery = const {};

/// Where the two panes start: the right edge of the source rail, and its line.
double _railRight(WidgetTester tester) =>
    tester.getTopRight(find.byKey(const ValueKey('source-rail'))).dx + 1;

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> leads,
  required List<Map<String, dynamic>> connections,
  String? delayedSince,
  /// The four sources are here because every existing role holds them: the
  /// migration that shipped with the split grants all four to anybody who had
  /// `leads.view`. A default without them would make every test in this file
  /// about the rarest role in the business.
  List<String> permissions = const [
    'leads.view',
    'leads.edit',
    'leads.manage',
    'leads.local_services.view',
    'leads.thumbtack.view',
    'leads.website_chat.view',
    'leads.website_form.view',
  ],
  Size size = const Size(1200, 900),
  String? initialSource,
  String? initialLeadId,
  Map<String, dynamic> me = _me,
  Map<String, dynamic> preferences = const {},
  Map<String, int> unreadBySource = const {},
}) async {
  _posts.clear();
  _patches.clear();
  _preferences.clear();
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

      if (request.url.path.endsWith('/me/preferences')) {
        _preferences.add(
          ((jsonDecode(request.body) as Map)['preferences'] as Map)
              .cast<String, dynamic>(),
        );
        return http.Response(
          '{"data":{}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/me')) {
        return http.Response(
          jsonEncode({
            'data': {
              ...me,
              'permissions': permissions,
              'preferences': preferences,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/leads')) {
        _lastListQuery = request.url.queryParameters;
        // Paged as the endpoint pages: per_page rows of page N.
        final per = int.tryParse(request.url.queryParameters['per_page'] ?? '') ?? leads.length;
        final page = int.tryParse(request.url.queryParameters['page'] ?? '') ?? 1;
        return http.Response(
          jsonEncode({
            'data': leads.skip((page - 1) * per).take(per).toList(),
            'meta': {
              'total': leads.length,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
              'connections': connections,
              'sync_delayed_since': delayedSince,
              'unread_by_source': unreadBySource,
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
        body: LeadsScreen(
          api: api,
          session: session,
          initialSource: initialSource,
          initialLeadId: initialLeadId,
        ),
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
