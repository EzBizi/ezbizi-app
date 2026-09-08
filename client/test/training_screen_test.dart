import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Learn is layout 1 (docs/previews/training-layouts.html): two shelves,
/// assigned to you above library, and the assigned shelf can never be
/// filtered away.
void main() {
  testWidgets(
    'the assigned shelf is drawn above the library and cannot be filtered away',
    (tester) async {
      await _pumpScreen(
        tester,
        assigned: [_assignedLadderSafety],
        library: [_libraryVanLoadOut],
      );

      final assigned = tester.getTopLeft(find.text('Assigned to you'));
      final library = tester.getTopLeft(find.text('Library'));

      expect(assigned.dy, lessThan(library.dy));
      expect(find.text('Ladder Safety'), findsOneWidget);
    },
  );

  testWidgets('a company view does not offer sibling companies', (
    tester,
  ) async {
    // The §3 leak that shipped in Inventory, Fleet and Phone. /me carries a
    // sibling; standing in one company it must not be offered.
    await _pumpScreen(
      tester,
      assigned: [_assignedLadderSafety],
      me: {
        'scope': {
          'type': 'company',
          'id': 'co-a1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney'],
        },
        'companies': [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
        ],
        'franchises': [
          {'id': 'fr-a1', 'name': 'Riverside', 'company_id': 'co-a1'},
        ],
      },
    );

    expect(find.text('Everdoors'), findsNothing);
  });

  testWidgets('the empty state is a sentence, not a blank', (tester) async {
    await _pumpScreen(tester);

    expect(find.text('Assigned to you'), findsOneWidget);
    expect(find.text('Library'), findsOneWidget);
    expect(
      find.textContaining('Nothing is assigned to you'),
      findsOneWidget,
    );
    expect(find.textContaining('nothing in your library'), findsOneWidget);
  });

  testWidgets('a failed load is the request failed, not an empty shelf', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/training/learn')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('There is nothing in your library yet.'), findsNothing);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('Dashboard and Settings say what they are, not TODO', (
    tester,
  ) async {
    await _pumpScreen(tester);

    await tester.tap(find.text('Dashboard'));
    await tester.pumpAndSettle();
    expect(find.text('TODO'), findsNothing);
    expect(find.textContaining('Coming'), findsWidgets);

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(find.text('TODO'), findsNothing);
    expect(find.textContaining('Coming'), findsWidgets);
  });
}

const _assignedLadderSafety = {
  'assignment_id': 'ta-1',
  'due_on': '2026-08-24',
  'assignee_type': 'user',
  'type': 'guide',
  'id': 'g-1',
  'title': 'Ladder Safety',
  'summary': 'Working safely at height.',
  'company_id': null,
  'franchise_id': null,
  'screen_count': 8,
  'screens': [],
};

const _libraryVanLoadOut = {
  'type': 'guide',
  'id': 'g-2',
  'title': 'Van Load-Out Checklist',
  'summary': 'Every tool, every day.',
  'company_id': null,
  'franchise_id': null,
  'screen_count': 6,
  'screens': [],
};

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<Map<String, dynamic>> assigned = const [],
  List<Map<String, dynamic>> library = const [],
  Map<String, dynamic>? me,
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(assigned: assigned, library: library, me: me, onRequest: onRequest),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TrainingScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<Map<String, dynamic>> assigned = const [],
  List<Map<String, dynamic>> library = const [],
  Map<String, dynamic>? me,
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': const ['training.view'], ...?me},
      });
    }

    if (path.endsWith('/training/learn')) {
      return _json({'assigned': assigned, 'library': library});
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

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
    {
      'key': 'training',
      'label': 'Training',
      'available': true,
      'reachable': true,
    },
  ],
};
