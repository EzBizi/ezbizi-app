import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance/staff_bot_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// AI Assistance › Staff bot (UI queue 88, editor 1): the staff line's menus
/// for each group of roles, the rows in WhatsApp's order with what each links
/// to, and the open row's editor. Every name here is invented.
void main() {
  late List<http.Request> writes;

  Map<String, dynamic> state() => {
    'data': [
      {
        'id': 'm-1',
        'name': 'Office',
        'role_ids': ['r-disp'],
        'items': [
          {
            'id': 'i-1',
            'title': 'Dispatch guidelines',
            'line': 'How we answer',
            'source': 'guide',
            'training_guide_id': 'g-1',
            'guide_title': 'Dispatcher guide',
            'chapter': 'Dispatcher Essentials',
            'section': 'Customer Service',
            'assistant_entry_id': null,
            'entry_title': null,
            'report': null,
            'ready': true,
          },
          {
            'id': 'i-2',
            'title': 'Daily conversion',
            'line': null,
            'source': 'report',
            'training_guide_id': null,
            'guide_title': null,
            'chapter': null,
            'section': null,
            'assistant_entry_id': null,
            'entry_title': null,
            'report': 'daily_conversion',
            'ready': false,
          },
        ],
      },
      {
        'id': 'm-2',
        'name': 'Technicians',
        'role_ids': <String>[],
        'items': <dynamic>[],
      },
    ],
    'roles': [
      {'id': 'r-disp', 'name': 'Dispatcher'},
      {'id': 'r-tech', 'name': 'Technician'},
    ],
    'guides': [
      {
        'id': 'g-1',
        'title': 'Dispatcher guide',
        'chapters': [
          {
            'name': 'Dispatcher Essentials',
            'sections': ['Customer Service', 'Speech'],
          },
        ],
      },
    ],
    'entries': [
      {'id': 'e-1', 'section': 'Company', 'title': 'Locations'},
    ],
    'reports': [
      {'key': 'daily_conversion', 'label': 'Daily conversion (new clients)'},
    ],
    'max_items': 9,
  };

  Future<void> pump(
    WidgetTester tester, {
    double width = 1440,
    Map<String, dynamic>? body,
  }) async {
    tester.view.physicalSize = Size(width, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    writes = [];

    http.Response json(Object b, [int code = 200]) => http.Response(
      jsonEncode(b),
      code,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'GET') return json(body ?? state());
        writes.add(request);
        return json({
          'data': {'id': 'new'},
        });
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: StaffBotTab(api: api)),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('the menus, who gets each, and the rows with their sources', (
    tester,
  ) async {
    await pump(tester);

    expect(find.byKey(const ValueKey('staff-bot-menu-m-1')), findsOneWidget);
    expect(find.text('2 of 9 topics · 1 needs content'), findsOneWidget);
    // Technicians has no roles yet: it says so on the card.
    expect(find.text('No roles yet'), findsOneWidget);
    expect(
      find.text('Dispatcher guide › Dispatcher Essentials › Customer Service'),
      findsOneWidget,
    );
    expect(find.text('From Reports'), findsOneWidget);
    expect(find.text('Ready'), findsOneWidget);
    expect(find.text('Needs content'), findsOneWidget);
    expect(find.text('Pick a row to edit it, or add one.'), findsOneWidget);
  });

  testWidgets('a role is ticked into a menu, and the rows can be added to', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('staff-bot-role-r-tech')));
    await tester.pumpAndSettle();
    expect(writes.single.method, 'PUT');
    expect(
      writes.single.url.path,
      endsWith('/ai-assistance/staff-bot/menus/m-1'),
    );
    expect(jsonDecode(writes.single.body), {
      'role_ids': ['r-disp', 'r-tech'],
    });

    await tester.tap(find.byKey(const Key('staff-bot-add-item')));
    await tester.pumpAndSettle();
    expect(
      writes.last.url.path,
      endsWith('/ai-assistance/staff-bot/menus/m-1/items'),
    );
  });

  testWidgets('a row opens its editor and saves where its answer comes from', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.text('Dispatch guidelines').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('staff-bot-editor-i-1')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('staff-bot-line')),
      'How we answer and what we say',
    );
    await tester.tap(find.byKey(const Key('staff-bot-save')));
    await tester.pumpAndSettle();

    expect(writes.single.method, 'PUT');
    expect(
      writes.single.url.path,
      endsWith('/ai-assistance/staff-bot/items/i-1'),
    );
    expect(jsonDecode(writes.single.body), {
      'title': 'Dispatch guidelines',
      'line': 'How we answer and what we say',
      'source': 'guide',
      'training_guide_id': 'g-1',
      'chapter': 'Dispatcher Essentials',
      'section': 'Customer Service',
      'assistant_entry_id': null,
      'report': null,
    });
  });

  testWidgets(
    'on a phone: menus, then a menu, then a row, each with a way back',
    (tester) async {
      await pump(tester, width: 390);

      expect(find.byKey(const Key('staff-bot-items')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('staff-bot-menu-m-1')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('staff-bot-items')), findsOneWidget);

      await tester.tap(find.text('Daily conversion').first);
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('staff-bot-editor-i-2')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('staff-bot-back')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('staff-bot-items')), findsOneWidget);
    },
  );

  testWidgets('no menus yet says so, and offers to add one', (tester) async {
    await pump(tester, body: {...state(), 'data': <dynamic>[]});

    expect(find.byKey(const Key('staff-bot-empty')), findsOneWidget);
    expect(find.byKey(const Key('staff-bot-add-menu')), findsOneWidget);
  });
}
