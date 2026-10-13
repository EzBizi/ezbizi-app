import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/tab_tours.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/tab_help.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 81: the walkthrough and the how-to video beside a tab's title,
/// run against the Task Board's real steps.
void main() {
  late List<Object?> patches;

  Future<Session> signedIn({
    Object seen = const <Object>[],
    Object videos = const <String, Object>{},
  }) async {
    patches = [];
    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me/preferences')) {
          patches.add(jsonDecode(request.body)['preferences']);
          return json({'data': <String, Object>{}});
        }
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': <String>[],
              'modules': <dynamic>[],
              'preferences': {'help.tours_seen': seen},
              'organization': {
                'id': 'o',
                'name': 'Northside Chimney',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/help/videos')) return json({'data': videos});
        return http.Response('{}', 404);
      }),
    );
    final session = Session(api);
    await session.adopt('tok');
    return session;
  }

  /// A page carrying three of the four controls the Task Board tour points
  /// at; the search box is missing, as it would be for somebody without it.
  Future<void> pumpPage(WidgetTester tester, Session session) async {
    tester.view.physicalSize = const Size(1300, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Text('Task Board'),
                  TabHelpButtons(
                    module: 'tasks',
                    label: 'Task Board',
                    api: session.api,
                    session: session,
                  ),
                ],
              ),
              TourOffer(module: 'tasks', label: 'Task Board', session: session),
              const SizedBox(
                key: ValueKey('tasks-boards'),
                width: 300,
                height: 40,
              ),
              const SizedBox(
                key: ValueKey('tasks-view'),
                width: 200,
                height: 40,
              ),
              const SizedBox(
                key: ValueKey('tasks-groups'),
                width: 600,
                height: 200,
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('(?) walks through the tab, skipping a control that is not '
      'there, and Done remembers it for the person', (tester) async {
    final session = await signedIn(seen: {'tasks': tabTours['tasks']!.version});
    await pumpPage(tester, session);

    // Seen already: no offer, but (?) still runs it.
    expect(find.byKey(const Key('tab-help-offer')), findsNothing);
    await tester.tap(find.byKey(const Key('tab-help-tour')));
    await tester.pumpAndSettle();

    expect(find.text('STEP 1 OF 3'), findsOneWidget);
    expect(find.text('Your boards'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tour-next')));
    await tester.pumpAndSettle();
    expect(find.text('Table or Kanban'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tour-next')));
    await tester.pumpAndSettle();
    // "Find a task" is skipped: its control is not on the page.
    expect(find.text('Groups and tasks'), findsOneWidget);
    expect(find.text('Done'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tour-next')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tour-card')), findsNothing);
    expect(patches.last, {
      'help.tours_seen': {'tasks': tabTours['tasks']!.version},
    });
  });

  testWidgets('the first visit offers it and never starts it; Not now puts '
      'it away for good', (tester) async {
    final session = await signedIn();
    await pumpPage(tester, session);

    expect(find.byKey(const Key('tab-help-offer')), findsOneWidget);
    expect(find.textContaining('New to Task Board?'), findsOneWidget);
    expect(find.byKey(const Key('tour-card')), findsNothing);

    await tester.tap(find.byKey(const Key('tab-help-offer-dismiss')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('tab-help-offer')), findsNothing);
    expect(patches.single, {
      'help.tours_seen': {'tasks': tabTours['tasks']!.version},
    });
  });

  testWidgets('Esc ends the walkthrough', (tester) async {
    final session = await signedIn();
    await pumpPage(tester, session);

    await tester.tap(find.byKey(const Key('tab-help-offer-start')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tour-card')), findsOneWidget);

    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tour-card')), findsNothing);
    expect(patches, isNotEmpty);
  });

  testWidgets('▶ shows only on a tab with a video, with its length', (
    tester,
  ) async {
    final without = await signedIn();
    await pumpPage(tester, without);
    expect(find.byKey(const Key('tab-help-video')), findsNothing);

    final with_ = await signedIn(
      videos: {
        'tasks': {
          'url': 'https://example.test/tasks.mp4',
          'title': 'How Task Board works',
          'duration_seconds': 160,
          'chapters': [
            {'at': 0, 'label': 'Your boards'},
          ],
          'captions_url': null,
        },
      },
    );
    await pumpPage(tester, with_);
    final button = tester.widget<IconButton>(
      find.byKey(const Key('tab-help-video')),
    );
    expect(button.tooltip, 'Watch how Task Board works · 2:40');
  });
}
