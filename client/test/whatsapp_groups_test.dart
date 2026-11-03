import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance/whatsapp_groups_tab.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// AI Assistance › WhatsApp groups (UI queue 61; Jose: "go ahead as u
/// recommend").
///
/// The reports that replace the Rodrigo bot's go to one WhatsApp group each.
/// What these hold to: a report with no group is listed, a member who would
/// receive nothing says so by name, the ninth member is refused before saving,
/// Meta's refusal is shown in its own words, and a real group's invite link
/// can be copied.
void main() {
  testWidgets('the reports with no group yet are listed above the cards', (
    tester,
  ) async {
    await _pump(tester, _Server());

    // One picker (deep dive 2026-11-02); its list names the reports.
    await tester.tap(find.byKey(const Key('whatsapp-unset-picker')));
    await tester.pumpAndSettle();
    final list = find.byType(Dialog);
    expect(
      find.descendant(of: list, matching: find.text('Dispatch')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: list, matching: find.text('Ohio - Same-Day Report')),
      findsOneWidget,
    );
    // A report that has its group is not listed again.
    expect(
      find.descendant(
        of: list,
        matching: find.text('New leads and customer messages'),
      ),
      findsNothing,
    );
  });

  testWidgets(
    'a card says which report, how it is delivered, and who is in it',
    (tester) async {
      await _pump(tester, _Server());

      final private = find.byKey(const ValueKey('whatsapp-group-g-1'));
      expect(
        find.descendant(
          of: private,
          matching: find.text('ALL-COMPANIES SUMMARY AND FINANCIAL REPORTS'),
        ),
        findsOneWidget,
      );
      expect(
        tester
            .widget<StatusPill>(
              find.descendant(of: private, matching: find.byType(StatusPill)),
            )
            .label,
        'Sent privately',
      );
      // The one who would receive nothing, by name.
      expect(
        find.descendant(
          of: private,
          matching: find.text('Riley Chen · no phone'),
        ),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('whatsapp-count-g-1')))
            .data,
        '3 of 8 · each gets the report privately until this is a WhatsApp group',
      );

      final real = find.byKey(const ValueKey('whatsapp-group-g-2'));
      expect(
        tester
            .widget<StatusPill>(
              find.descendant(of: real, matching: find.byType(StatusPill)),
            )
            .label,
        'WhatsApp group',
      );
      expect(find.byKey(const ValueKey('whatsapp-copy-g-2')), findsOneWidget);
      expect(find.byKey(const ValueKey('whatsapp-publish-g-2')), findsNothing);
    },
  );

  testWidgets('cards side by side are one height', (tester) async {
    await _pump(tester, _Server());
    // Meta's refusal makes the first card the taller by its own content.
    await tester.tap(find.byKey(const ValueKey('whatsapp-publish-g-1')));
    await tester.pumpAndSettle();

    final a = tester.getRect(find.byKey(const ValueKey('whatsapp-group-g-1')));
    final b = tester.getRect(find.byKey(const ValueKey('whatsapp-group-g-2')));
    expect(a.top, b.top, reason: 'the same row');
    expect(a.height, b.height);
  });

  testWidgets('a WhatsApp group whose link has not come back is not offered '
      'again', (tester) async {
    final server = _Server();
    server.groups[1]['invite_link'] = null;
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('whatsapp-publish-g-2')), findsNothing);
    expect(find.byKey(const ValueKey('whatsapp-copy-g-2')), findsNothing);
  });

  testWidgets("Meta's refusal is shown as the server worded it", (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.byKey(const ValueKey('whatsapp-publish-g-1')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('whatsapp-refused-g-1')),
        matching: find.text(_refusal),
      ),
      findsOneWidget,
    );
    expect(server.published, ['g-1']);
  });

  testWidgets('a full group refuses a ninth member before anything is saved', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    final add = tester.widget<ActionChip>(
      find.byKey(const ValueKey('whatsapp-add-g-3')),
    );
    expect(add.onPressed, isNull);
    expect(find.text("Add · 8 is WhatsApp's limit"), findsOneWidget);
    expect(server.patches, isEmpty);
  });

  testWidgets('adding and taking out members saves the list', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.byKey(const ValueKey('whatsapp-add-g-1')));
    await tester.pumpAndSettle();
    // The picker's row, laid over the cards that also show the name.
    await tester.tap(find.text('Taylor Lin').last);
    await tester.pumpAndSettle();
    expect(server.patches.last['member_ids'], ['u-1', 'u-2', 'u-3', 'u-4']);

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('whatsapp-group-g-1')),
        matching: find.byTooltip('Take Riley Chen out'),
      ),
    );
    await tester.pumpAndSettle();
    expect(server.patches.last['member_ids'], ['u-1', 'u-2', 'u-4']);
  });

  testWidgets('a new group is made in a side panel, eight at most', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    // Not the first report listed, so the panel cannot just default to it.
    await tester.tap(find.byKey(const Key('whatsapp-unset-picker')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ohio - Same-Day Report').last);
    await tester.pumpAndSettle();

    // The report chosen from the strip, and its name offered.
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('whatsapp-new-subject')))
          .controller!
          .text,
      'Ohio - Same-Day Report',
    );
    for (var i = 1; i <= 8; i++) {
      await tester.tap(find.byKey(ValueKey('whatsapp-new-person-u-$i')));
      await tester.pump();
    }
    final ninth = tester.widget<CheckboxListTile>(
      find.byKey(const ValueKey('whatsapp-new-person-u-9')),
    );
    expect(
      ninth.onChanged,
      isNull,
      reason: 'the ninth is refused before saving',
    );

    await tester.tap(find.byKey(const Key('whatsapp-new-save')));
    await tester.pumpAndSettle();
    expect(server.created.single['purpose'], 'daily:fr-oh');
    expect((server.created.single['member_ids'] as List).length, 8);
    expect(find.byKey(const ValueKey('whatsapp-group-g-new')), findsOneWidget);
  });
}

const _refusal =
    'WhatsApp would not make this group yet: the number is not an Official Business Account. '
    'Until it does, every member gets the reports in a private chat.';

Map<String, dynamic> _member(int n, {bool phone = true}) => {
  'id': 'u-$n',
  'name': _names[n - 1],
  'has_phone': phone,
};

const _names = [
  'Dana Reyes',
  'Sam Ortega',
  'Riley Chen',
  'Taylor Lin',
  'Morgan Park',
  'Quinn Avery',
  'Casey Moore',
  'Jordan Blake',
  'Rowan Ellis',
];

class _Server {
  final List<Map<String, dynamic>> patches = [];
  final List<Map<String, dynamic>> created = [];
  final List<String> published = [];

  late final List<Map<String, dynamic>> groups = [
    {
      'id': 'g-1',
      'purpose': 'financial',
      'subject': 'Harbor Financial Reports',
      'on_whatsapp': false,
      'invite_link': null,
      'members': [_member(1), _member(2), _member(3, phone: false)],
    },
    {
      'id': 'g-2',
      'purpose': 'messages',
      'subject': 'Harbor Messages',
      'on_whatsapp': true,
      'invite_link': 'https://chat.whatsapp.com/Example123',
      'members': [_member(1), _member(5)],
    },
    {
      'id': 'g-3',
      'purpose': 'gbp_updates',
      'subject': 'Local Services and GBP',
      'on_whatsapp': false,
      'invite_link': null,
      'members': [for (var i = 1; i <= 8; i++) _member(i)],
    },
  ];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'GET' &&
        path.endsWith('/ai-assistance/whatsapp-groups')) {
      return _json({
        'data': groups,
        'purposes': [
          {
            'key': 'financial',
            'label': 'All-companies summary and financial reports',
          },
          {
            'key': 'gbp_updates',
            'label': 'Local Services & Google Business Profile updates',
          },
          {'key': 'messages', 'label': 'New leads and customer messages'},
          {'key': 'dispatch', 'label': 'Dispatch'},
          {'key': 'daily:fr-oh', 'label': 'Ohio - Same-Day Report'},
        ],
        'people': [for (var i = 1; i <= 9; i++) _member(i, phone: i != 3)],
      });
    }
    if (request.method == 'POST' && path.endsWith('/publish')) {
      published.add(path.split('/').reversed.elementAt(1));
      return _json({'message': _refusal}, 422);
    }
    if (request.method == 'PATCH') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      patches.add(body);
      final id = path.split('/').last;
      final group = groups.firstWhere((g) => g['id'] == id);
      group['members'] = [
        for (final m in (body['member_ids'] as List))
          _member(int.parse('$m'.substring(2)), phone: m != 'u-3'),
      ];
      return _json({'data': group});
    }
    if (request.method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      created.add(body);
      return _json({
        'data': {
          'id': 'g-new',
          'purpose': body['purpose'],
          'subject': body['subject'],
          'on_whatsapp': false,
          'invite_link': null,
          'members': [
            for (final m in (body['member_ids'] as List))
              _member(int.parse('$m'.substring(2))),
          ],
        },
      }, 201);
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(1300, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: WhatsAppGroupsTab(
          api: ApiClient(client: MockClient(server.handle)),
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
