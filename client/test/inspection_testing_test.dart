import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection/testing_tab.dart';
import 'package:ezbizi_client/screens/inspection_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Testing tab: a dry run of a form that saves nothing.
void main() {
  testWidgets('the Testing chip needs builder.view', (tester) async {
    for (final (perms, shown) in [
      (_all, true),
      (
        [
          for (final p in _all)
            if (p != 'inspection.builder.view') p,
        ],
        false,
      ),
    ]) {
      final fake = _Fake(perms);
      await _pumpScreen(tester, fake);
      expect(
        find.widgetWithText(ChoiceChip, 'Testing'),
        shown ? findsOneWidget : findsNothing,
      );
    }
  });

  testWidgets('rehearse body, why rows, jump, debounce, preview', (
    tester,
  ) async {
    final fake = _Fake(_all);
    await _pumpTab(tester, fake, const Size(1400, 900));

    expect(find.byKey(const ValueKey('testing-saves-nothing')), findsOneWidget);
    await _chooseForm(tester);

    // The first rehearse: the draft, no answers, no ids.
    expect(fake.bodies, hasLength(1));
    expect(fake.bodies.last['version'], 'draft');
    expect(fake.bodies.last['answers'], isEmpty);
    expect(fake.bodies.last.containsKey('customer_id'), isFalse);

    // Why: shown and skipped, with their sentences.
    final skipped = find.byKey(const ValueKey('testing-why-screen-1'));
    expect(
      find.descendant(of: skipped, matching: find.text('Skipped')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('testing-why-screen-0')),
        matching: find.text('Shown'),
      ),
      findsOneWidget,
    );
    expect(
      find.text('When system_type is wood; it is "not answered" → skipped.'),
      findsOneWidget,
    );
    expect(find.text('First missing: Firebox condition'), findsOneWidget);

    // The preview is drawn.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('testing-preview')),
        matching: find.byType(Html),
      ),
      findsOneWidget,
    );

    // Debounced: two quick keystrokes, one request, after 400 ms.
    await tester.enterText(
      find.byKey(const ValueKey('testing-field-firebox')),
      'c',
    );
    await tester.pump(const Duration(milliseconds: 200));
    await tester.enterText(
      find.byKey(const ValueKey('testing-field-firebox')),
      'cracked',
    );
    await tester.pump(const Duration(milliseconds: 399));
    expect(fake.bodies, hasLength(1));
    await tester.pump(const Duration(milliseconds: 2));
    await tester.pumpAndSettle();
    expect(fake.bodies, hasLength(2));
    expect(fake.bodies.last['answers'], {'firebox': 'cracked'});

    // The returned (prefilled) answers replace the screen's.
    expect(find.text('cracked (frozen)'), findsOneWidget);

    // A version and a test job ride along.
    await tester.tap(find.byKey(const ValueKey('testing-version')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Version 1').last);
    await tester.pumpAndSettle();
    expect(fake.bodies.last['version'], 'v-1');

    await tester.enterText(
      find.byKey(const ValueKey('testing-client-field')),
      'Smith',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ann Smith').last);
    await tester.pumpAndSettle();
    expect(fake.bodies.last['customer_id'], 'c-1');
    expect(fake.bodies.last['job_id'], 'j-1');
  });

  testWidgets('first_missing jumps to its screen', (tester) async {
    final fake = _Fake(_all)..missingOnSecond = true;
    await _pumpTab(tester, fake, const Size(1400, 900));
    await _chooseForm(tester);

    expect(
      find.byKey(const ValueKey('testing-field-system_type')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('testing-field-stove')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('testing-why-field-stove')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('testing-field-stove')), findsOneWidget);
    expect(find.text('First missing: Stove condition'), findsOneWidget);
  });

  testWidgets('a phone stacks answers, Why and preview, no overflow', (
    tester,
  ) async {
    final fake = _Fake(_all)..missingOnSecond = true;
    await _pumpTab(tester, fake, const Size(390, 844));
    await _chooseForm(tester);
    expect(tester.takeException(), isNull);

    final answers = tester.getTopLeft(
      find.byKey(const ValueKey('testing-answers')),
    );
    final why = tester.getTopLeft(find.byKey(const ValueKey('testing-why')));
    expect(why.dy, greaterThan(answers.dy));
    expect(why.dx, answers.dx);

    await tester.dragUntilVisible(
      find.byKey(const ValueKey('testing-preview')),
      find.byKey(const ValueKey('testing-scroll')),
      const Offset(0, -300),
    );
    final preview = tester.getTopLeft(
      find.byKey(const ValueKey('testing-preview')),
    );
    expect(preview.dx, answers.dx);
    expect(tester.takeException(), isNull);
  });
}

const _all = [
  'inspection.view',
  'inspection.create',
  'inspection.edit',
  'inspection.new.view',
  'inspection.reports.view',
  'inspection.builder.view',
];

const _screens = [
  {
    'name': 'The system',
    'fields': [
      {
        'key': 'system_type',
        'label': 'System',
        'type': 'choice',
        'options': ['masonry', 'wood'],
      },
      {'key': 'firebox', 'label': 'Firebox condition', 'type': 'text'},
    ],
  },
  {
    'name': 'Wood stove',
    'fields': [
      {'key': 'stove', 'label': 'Stove condition', 'type': 'text'},
      {'key': 'pic', 'label': 'Stove photo', 'type': 'photo'},
    ],
  },
];

class _Fake {
  _Fake(this.permissions);

  final List<String> permissions;
  final bodies = <Map<String, dynamic>>[];
  bool missingOnSecond = false;

  late final client = MockClient((request) async {
    final path = request.url.path;
    Object? data;
    if (path.endsWith('/me')) {
      data = _me(permissions);
    } else if (path.endsWith('/rehearse')) {
      final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
      bodies.add(body);
      data = _rehearsal(Map<String, dynamic>.from(body['answers'] as Map));
    } else if (path.endsWith('/inspections/search')) {
      data = [
        {
          'type': 'job',
          'label': 'J-7 · Ann Smith',
          'customer_id': 'c-1',
          'job_id': 'j-1',
          'customer_name': 'Ann Smith',
          'job_number': 'J-7',
        },
      ];
    } else if (path.endsWith('/inspections/d-1')) {
      data = {
        'id': 'd-1',
        'name': 'Chimney',
        'screens': _screens,
        'versions': [
          {'id': 'v-1', 'version': 1, 'screens': _screens},
        ],
      };
    } else if (path.endsWith('/inspections')) {
      data = [
        {'id': 'd-1', 'name': 'Chimney'},
      ];
    } else if (path.contains('/inspections/')) {
      data = [];
    } else {
      return http.Response('{"message":"not stubbed"}', 500);
    }
    return http.Response(
      jsonEncode({'data': data}),
      200,
      headers: {'content-type': 'application/json'},
    );
  });

  Map<String, dynamic> _rehearsal(Map<String, dynamic> answers) {
    final wood = missingOnSecond || answers['system_type'] == 'wood';
    final frozen = {
      for (final e in answers.entries)
        e.key: e.key == 'firebox' ? '${e.value} (frozen)' : e.value,
    };
    return {
      'screens': [
        {
          'index': 0,
          'name': 'The system',
          'shown': true,
          'why': 'Always shown.',
          'fields': [
            {
              'key': 'system_type',
              'label': 'System',
              'type': 'choice',
              'required': true,
              'shown': true,
              'why': 'Always shown.',
            },
            {
              'key': 'firebox',
              'label': 'Firebox condition',
              'type': 'text',
              'required': true,
              'shown': true,
              'why': 'Always shown.',
            },
          ],
        },
        {
          'index': 1,
          'name': 'Wood stove',
          'shown': wood,
          'why': wood
              ? 'When system_type is wood; it is "wood" → shown.'
              : 'When system_type is wood; it is "not answered" → skipped.',
          'fields': [
            {
              'key': 'stove',
              'label': 'Stove condition',
              'type': 'text',
              'required': true,
              'shown': true,
              'why': 'Always shown.',
            },
            {
              'key': 'pic',
              'label': 'Stove photo',
              'type': 'photo',
              'required': false,
              'shown': true,
              'why': 'Always shown.',
            },
          ],
        },
      ],
      'first_missing': missingOnSecond
          ? 'Stove condition'
          : 'Firebox condition',
      'answers': frozen,
      'preview_html':
          '<html><body><h1>Chimney</h1><p>Firebox condition</p></body></html>',
    };
  }
}

Future<Session> _session(WidgetTester tester, _Fake fake) async {
  final session = Session(ApiClient(client: fake.client));
  await tester.runAsync(() => session.adopt('tok'));
  return session;
}

Future<void> _pumpScreen(WidgetTester tester, _Fake fake) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final session = await _session(tester, fake);
  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: InspectionScreen(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpTab(WidgetTester tester, _Fake fake, Size size) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final session = await _session(tester, fake);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TestingTab(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _chooseForm(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('testing-form')));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Chimney').last);
  await tester.pumpAndSettle();
}

Map<String, dynamic> _me(List<String> permissions) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Manager'},
  'permissions': permissions,
  'modules': [
    {
      'key': 'inspection',
      'label': 'Inspection',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': {},
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
  'companies': [],
  'franchises': [],
};
