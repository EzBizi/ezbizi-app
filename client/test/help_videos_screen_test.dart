import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/help_videos_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 81: Staff › Help videos sets which tab's ▶ plays which video.
void main() {
  testWidgets('a tab with no video says so, and Add a video sends the whole '
      'video with its length and chapters in seconds', (tester) async {
    tester.view.physicalSize = const Size(1300, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final puts = <(String, Object?)>[];
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'PUT') {
          puts.add((request.url.path, jsonDecode(request.body)));
          return http.Response(
            '{"data":{}}',
            200,
            headers: const {'content-type': 'application/json'},
          );
        }
        return http.Response(
          jsonEncode({
            'data': [
              {
                'module_key': 'scheduling',
                'label': 'Scheduling',
                'video': null,
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: HelpVideosScreen(staff: StaffSession(api))),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('No video yet'), findsOneWidget);

    await tester.tap(find.text('Add a video'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('help-video-url')),
      'https://example.test/scheduling.mp4',
    );
    await tester.enterText(find.byKey(const Key('help-video-length')), '2:40');
    await tester.enterText(
      find.byKey(const Key('help-video-chapters')),
      '0:00 The week\n0:42 Booking a job',
    );
    await tester.tap(find.byKey(const Key('help-video-save')));
    await tester.pumpAndSettle();

    expect(puts.single.$1, endsWith('/staff/help-videos/scheduling'));
    expect(puts.single.$2, {
      'url': 'https://example.test/scheduling.mp4',
      'title': null,
      'duration_seconds': 160,
      'chapters': [
        {'at': 0, 'label': 'The week'},
        {'at': 42, 'label': 'Booking a job'},
      ],
      'captions_url': null,
    });
  });
}
