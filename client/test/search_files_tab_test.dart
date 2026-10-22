import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/files_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Monitoring › Files: the search bar filters the open folder's entries by
/// name.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Viewer',
              'email': 'v@e.test',
              'permissions': ['monitoring.view', 'monitoring.files.view'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Everdoors',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/monitoring/devices')) {
          return json({
            'data': [
              {
                'id': 'dev-1',
                'label': 'TOULA',
                'platform': 'windows',
                'user': {'id': 'u-1', 'name': 'Toula Elwell'},
                'last_seen_at': DateTime.now().toUtc().toIso8601String(),
              },
            ],
          });
        }
        if (RegExp(r'/monitoring/devices/[^/]+/files$').hasMatch(path) &&
            request.method == 'POST') {
          return json({
            'data': {
              'request_id': 'req-1',
              'unlocked_until': '2026-10-12T19:00:00Z',
            },
          });
        }
        if (path.endsWith('/monitoring/devices/dev-1/files/req-1')) {
          return json({
            'data': {
              'status': 'ready',
              'op': 'list',
              'path': '',
              'entries': [
                {
                  'name': 'invoice.pdf',
                  'is_dir': false,
                  'size_bytes': 13,
                  'modified_at': '2026-10-01',
                },
                {
                  'name': 'Photos',
                  'is_dir': true,
                  'size_bytes': null,
                  'modified_at': null,
                },
              ],
            },
          });
        }
        return http.Response('{"message":"unstubbed"}', 500);
      }),
    );
    final session = Session(api);
    await session.adopt('tok');

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: FilesTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching entry and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(
      find.byKey(const ValueKey('files-entry-invoice.pdf')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('files-entry-Photos')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('files-search')), 'INVO');
    await tester.pump();

    expect(
      find.byKey(const ValueKey('files-entry-invoice.pdf')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('files-entry-Photos')), findsNothing);
  });

  testWidgets('a term matching no entry says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('files-search')), 'zzz');
    await tester.pump();

    expect(find.text('No files or folders match "zzz".'), findsOneWidget);
    expect(find.byKey(const ValueKey('files-entry-invoice.pdf')), findsNothing);
    expect(find.byKey(const ValueKey('files-entry-Photos')), findsNothing);
  });
}
