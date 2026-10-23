import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance/tech_tabs.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// AI Assistance › Parts, Manuals and Answers (the Chimney Tech Assistant,
/// mockup tech-assistant.html). Every name and number here is invented.
void main() {
  late List<http.Request> writes;

  final parts = {
    'data': [
      {
        'id': 'p-1',
        'brand': 'Heat & Glo',
        'model': '6000C',
        'model_aliases': <String>[],
        'part_name': 'Pilot assembly',
        'part_aliases': ['pilot assy', 'piloto'],
        'part_number': '2166-302',
        'fuel': 'NG',
        'serial_from': '1500',
        'serial_to': null,
        'status': 'confirmed',
        'superseded_by': null,
        'source_type': 'manual',
        'source_ref': 'Manual rev K · p.42',
        'notes': null,
      },
      {
        'id': 'p-2',
        'brand': 'Heat & Glo',
        'model': '6000C',
        'model_aliases': <String>[],
        'part_name': 'Pilot assembly',
        'part_aliases': <String>[],
        'part_number': '2166-301',
        'fuel': 'NG',
        'serial_from': null,
        'serial_to': '1499',
        'status': 'discontinued',
        'superseded_by': '2166-302',
        'source_type': 'manual',
        'source_ref': 'Manual rev H · p.40',
        'notes': null,
      },
      {
        'id': 'p-3',
        'brand': 'Majestic',
        'model': 'Quartz 32',
        'model_aliases': <String>[],
        'part_name': 'Gas valve',
        'part_aliases': ['SIT'],
        'part_number': '0.885.003',
        'fuel': 'LP',
        'serial_from': null,
        'serial_to': null,
        'status': 'high_confidence',
        'superseded_by': null,
        'source_type': 'supplier',
        'source_ref': null,
        'notes': null,
      },
    ],
    'summary': {
      'all': 3,
      'models': 2,
      'confirmed': 1,
      'needs_review': 0,
      'discontinued': 1,
    },
  };

  final manuals = {
    'data': [
      {
        'id': 'm-1',
        'brand': 'Heat & Glo',
        'models': '6000C, 6000CLX',
        'title': 'Owner manual',
        'revision': 'K',
        'page_count': 64,
        'readable': true,
      },
    ],
  };

  final answers = {
    'data': [
      {
        'id': 'a-1',
        'channel': 'whatsapp',
        'user': 'Avi K.',
        'question': 'rating plate, need pilot assy',
        'answer': 'MODEL: 6000C\nPART #: 2166-302\nORDER CHECK: READY TO ORDER',
        'order_check': 'ready',
        'part_ids': ['p-1'],
        'manual_pages': [
          {'manual_id': 'm-1', 'page': 42},
        ],
        'review': null,
        'correction': null,
        'job': {'model': '6000C', 'fuel': 'NG'},
      },
      {
        'id': 'a-2',
        'channel': 'telegram',
        'user': 'Carlos M.',
        'question': 'valve for majestic insert',
        'answer': "I'm not ready to approve ordering this part yet.",
        'order_check': 'not_yet',
        'part_ids': <String>[],
        'manual_pages': <dynamic>[],
        'review': 'correct',
        'correction': null,
        'job': {'model': 'Majestic', 'fuel': null},
      },
    ],
    'telegram_configured': true,
    'summary': {
      'this_month': 2,
      'correct': 1,
      'incorrect': 0,
      'do_not_order': 1,
    },
  };

  Future<void> pump(WidgetTester tester, Widget Function(ApiClient) tab) async {
    tester.view.physicalSize = const Size(1440, 1000);
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
        if (request.method == 'GET') {
          final path = request.url.path;
          if (path.endsWith('/parts')) return json(parts);
          if (path.endsWith('/manuals')) return json(manuals);
          return json(answers);
        }
        writes.add(request);
        return json({
          'data': {'id': 'new'},
        }, request.method == 'POST' ? 201 : 200);
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: tab(api)),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('parts: counts, rows with aliases and status, and search', (
    tester,
  ) async {
    await pump(tester, (api) => PartsTab(api: api, canEdit: true));

    expect(find.text('ALL PARTS'), findsOneWidget);
    expect(find.text('across 2 models'), findsOneWidget);
    expect(find.text('aka "pilot assy", "piloto"'), findsOneWidget);
    expect(find.text('Discontinued → 2166-302'), findsOneWidget);
    expect(find.text('High confidence'), findsOneWidget);
    expect(find.text('Heat & Glo 6000C, serial ≥ 1500'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('ai-parts-search')),
      'majestic',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('ai-part-p-3')), findsOneWidget);
    expect(find.byKey(const ValueKey('ai-part-p-1')), findsNothing);
  });

  testWidgets('parts: the status tile filters, and a part is added', (
    tester,
  ) async {
    await pump(tester, (api) => PartsTab(api: api, canEdit: true));

    await tester.tap(find.byKey(const ValueKey('ai-parts-tile-discontinued')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('ai-part-p-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('ai-part-p-1')), findsNothing);

    await tester.tap(find.byKey(const Key('ai-parts-add')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('ai-part-brand')), 'Napoleon');
    await tester.enterText(find.byKey(const Key('ai-part-model')), 'GX70');
    await tester.enterText(
      find.byKey(const Key('ai-part-part_name')),
      'Thermopile',
    );
    await tester.enterText(
      find.byKey(const Key('ai-part-part_aliases')),
      'tpile, mV',
    );
    await tester.tap(find.byKey(const Key('ai-part-save')));
    await tester.pumpAndSettle();

    expect(writes.single.method, 'POST');
    expect(writes.single.url.path, endsWith('/ai-assistance/parts'));
    final body = jsonDecode(writes.single.body) as Map<String, dynamic>;
    expect(body['brand'], 'Napoleon');
    expect(body['part_aliases'], ['tpile', 'mV']);
    expect(body['status'], 'unverified');
    expect(body['fuel'], 'both');
  });

  testWidgets('parts: without manage there is no Add', (tester) async {
    await pump(tester, (api) => PartsTab(api: api, canEdit: false));

    expect(find.byKey(const Key('ai-parts-add')), findsNothing);
  });

  testWidgets('manuals: listed, and a PDF is picked and uploaded', (
    tester,
  ) async {
    await pump(
      tester,
      (api) => ManualsTab(
        api: api,
        canEdit: true,
        pickFile: ({String accept = ''}) async =>
            const PickedBytes('Quartz 32.pdf', [37, 80, 68, 70]),
      ),
    );

    expect(find.text('Owner manual · rev K'), findsOneWidget);
    expect(find.text('6000C, 6000CLX'), findsOneWidget);
    expect(find.text('64'), findsWidgets);

    await tester.tap(find.byKey(const Key('ai-manuals-upload')));
    await tester.pumpAndSettle();
    expect(find.text('Quartz 32.pdf'), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('ai-manual-brand')),
      'Majestic',
    );
    await tester.enterText(
      find.byKey(const Key('ai-manual-models')),
      'Quartz 32',
    );
    await tester.tap(find.byKey(const Key('ai-manual-save')));
    await tester.pumpAndSettle();

    expect(writes.single.method, 'POST');
    expect(writes.single.url.path, endsWith('/ai-assistance/manuals'));
  });

  testWidgets('answers: counts, rows, and Correct is sent', (tester) async {
    await pump(tester, (api) => AnswersTab(api: api, canEdit: true));

    expect(find.text('ANSWERS THIS MONTH'), findsOneWidget);
    expect(find.text('2166-302 · Ready to order'), findsOneWidget);
    expect(find.text('6000C · NG ✔'), findsOneWidget);
    expect(find.text('WhatsApp'), findsOneWidget);
    expect(find.text('Telegram'), findsOneWidget);
    expect(find.text('Telegram bot: configured'), findsOneWidget);
    expect(find.text('Majestic · ?'), findsOneWidget);
    // Already reviewed: a pill, not buttons.
    expect(find.byKey(const ValueKey('ai-answer-correct-a-2')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('ai-answer-correct-a-1')));
    await tester.pumpAndSettle();
    expect(
      writes.single.url.path,
      endsWith('/ai-assistance/answers/a-1/review'),
    );
    expect(jsonDecode(writes.single.body), {'review': 'correct'});
  });

  testWidgets('answers: a row opens the full answer and a correction is sent', (
    tester,
  ) async {
    await pump(tester, (api) => AnswersTab(api: api, canEdit: true));

    await tester.tap(find.byKey(const ValueKey('ai-answer-incorrect-a-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('ai-answer-panel-a-1')), findsOneWidget);
    expect(
      find.text('Looked at 1 part row and 1 manual page.'),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const Key('ai-answer-correction')),
      'Serial under 1500 takes 2166-301.',
    );
    await tester.tap(find.byKey(const Key('ai-answer-send-correction')));
    await tester.pumpAndSettle();

    expect(jsonDecode(writes.single.body), {
      'review': 'incorrect',
      'correction': 'Serial under 1500 takes 2166-301.',
    });
  });
}
