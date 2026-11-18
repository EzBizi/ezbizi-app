import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 199 (Rodrigo 01a1ef71 + 01a1ef57): Social is a planner that
/// opens on the calendar. A post sits on its SAVED send time, never on the
/// day it was written; a selected post opens beside the grid; the composer
/// keeps its draft when put away.
void main() {
  final now = DateTime.now();
  String two(int n) => n.toString().padLeft(2, '0');
  String wall(DateTime d, [int h = 9]) =>
      '${d.year}-${two(d.month)}-${two(d.day)} ${two(h)}:00:00';
  final first = DateTime(now.year, now.month, 1);
  final last = DateTime(now.year, now.month + 1, 0);

  Map<String, Object?> post(
    String id,
    String body,
    String status, {
    String? scheduledFor,
    DateTime? created,
  }) => {
    'id': id,
    'body': body,
    'has_media': false,
    'scheduled_for': scheduledFor,
    'created_at': (created ?? now).toUtc().toIso8601String(),
    'targets': [
      {
        'id': 't-$id',
        'connection_id': 'conn-2',
        'account': 'Everdoors Boards',
        'provider': 'pinterest',
        'provider_label': 'Pinterest',
        'status': status,
      },
    ],
  };

  final posts = [
    // Written on the 1st, going out on the last day: it sits on the last.
    post(
      'late',
      'Holiday hours',
      'scheduled',
      scheduledFor: wall(last, 18),
      created: first,
    ),
    // Held for approval WITH a time: Planned, on that day.
    post(
      'planned',
      'Inspection week',
      'awaiting_approval',
      scheduledFor: wall(last, 10),
      created: first,
    ),
    // Held with no time: on no day, under Needs attention.
    post('draft', 'Winter checklist', 'awaiting_approval', created: first),
  ];

  Future<void> pump(WidgetTester tester, Size size) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        Object body = {'data': <Object?>[]};
        if (path.endsWith('/me')) {
          body = {
            'data': {
              ..._me,
              'permissions': [
                'marketing.view',
                'marketing.edit',
                'marketing.dashboard.view',
                'marketing.compose.view',
                'marketing.calendar.view',
                'marketing.accounts.view',
              ],
            },
          };
        } else if (path.endsWith('/social/connections')) {
          body = {
            'data': [
              {
                'id': 'conn-2',
                'display_name': 'Everdoors Boards',
                'provider': 'pinterest',
                'provider_label': 'Pinterest',
                'can_publish': true,
                'status': 'connected',
              },
            ],
          };
        } else if (path.endsWith('/social/posts')) {
          body = {'data': posts};
        }
        return http.Response(
          jsonEncode(body),
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
          body: SocialMediaTab(
            api: api,
            session: session,
            pickMedia: () async => null,
            videoPlayer: (_) => null,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Finder on(DateTime day, String text) => find.descendant(
    of: find.byKey(Key('month-day-${day.day}')),
    matching: find.textContaining(text),
  );

  testWidgets('a post sits on its send time, never the day it was written; '
      'held with a time is Planned; held without one is under Needs '
      'attention', (tester) async {
    await pump(tester, const Size(1440, 1200));

    expect(on(last, 'Holiday hours'), findsOneWidget);
    if (first != last) expect(on(first, 'Holiday hours'), findsNothing);
    expect(on(last, 'Planned'), findsOneWidget);
    expect(find.textContaining('Winter checklist'), findsNothing);

    await tester.tap(find.byKey(const Key('plan-attention')));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const Key('plan-attention-panel')),
        matching: find.text('Winter checklist'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a post opens beside the grid at 1440, and over it at 1055; '
      'closing keeps the month', (tester) async {
    for (final (width, docked) in [(1440.0, true), (1055.0, false)]) {
      await pump(tester, Size(width, 1200));
      await tester.tap(find.byKey(const ValueKey('plan-post-late')));
      await tester.pumpAndSettle();

      final inspector = find.byKey(const Key('plan-inspector'));
      final grid = find.byKey(const Key('month-grid'));
      expect(inspector, findsOneWidget);
      expect(grid, findsOneWidget);
      final beside =
          tester.getRect(grid).right <= tester.getRect(inspector).left;
      expect(beside, docked, reason: 'at $width');

      await tester.tap(
        find.descendant(of: inspector, matching: find.byTooltip('Close Post')),
      );
      await tester.pumpAndSettle();
      expect(inspector, findsNothing);
      expect(on(last, 'Holiday hours'), findsOneWidget);
      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('the composer keeps its draft when put away, Esc closes it, and '
      'focus goes back to New post', (tester) async {
    await pump(tester, const Size(1440, 1200));
    final newPost = find.byKey(const ValueKey('tour-marketing-new-post'));

    await tester.tap(newPost);
    await tester.pumpAndSettle();
    final words = find.widgetWithText(TextField, 'What should go out?');
    await tester.enterText(words, 'Half a thought');
    await tester.pump();

    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('social-composer-close')), findsNothing);
    final button = tester.widget<FilledButton>(newPost);
    expect(button.focusNode?.hasFocus, isTrue);

    await tester.tap(newPost);
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(words).controller!.text, 'Half a thought');
  });

  testWidgets('a phone gets the week and the day, and a post opens over it', (
    tester,
  ) async {
    await pump(tester, const Size(390, 844));
    expect(find.byKey(const Key('month-grid')), findsNothing);
    expect(
      find.byKey(Key('plan-phone-day-${now.month}-${now.day}')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('plan-attention')), findsOneWidget);
    expect(tester.takeException(), isNull);

    // The month's last day is in a later week unless this is that week.
    final lastDay = find.byKey(Key('plan-phone-day-${last.month}-${last.day}'));
    while (lastDay.evaluate().isEmpty) {
      await tester.tap(find.byTooltip('Next week'));
      await tester.pumpAndSettle();
    }
    await tester.tap(lastDay);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('plan-row-late')));
    await tester.pumpAndSettle();
    expect(find.byType(SidePanel), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  // Newsletter is a MODULE with its own grant, not a slice of marketing.view,
  // so the default fixture holds both — a role that reaches the tab in
  // production holds both too.
  'permissions': ['marketing.view', 'newsletter.view'],
  'modules': [
    {
      'key': 'marketing',
      'label': 'Marketing',
      'available': true,
      'reachable': true,
    },
    {
      'key': 'newsletter',
      'label': 'Newsletter',
      'parent': 'marketing',
      'available': true,
      'reachable': true,
    },
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
