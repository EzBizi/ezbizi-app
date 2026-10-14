import 'dart:async';

import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/tab_help.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:video_player_platform_interface/video_player_platform_interface.dart';

/// The how-to video panel (UI queue 94, 1 and 4; Jose, 2026-10-14: "video
/// should be seen on desktop, mobile, etc ... the fullscreen option and also
/// the download option all inside the app"). The player is faked at its
/// platform interface, so the panel's own controls are what is tested.
class _FakePlayer extends VideoPlayerPlatform {
  final _events = StreamController<VideoEvent>.broadcast();
  Duration position = Duration.zero;
  final calls = <String>[];

  @override
  Future<void> init() async {}

  @override
  Future<int?> create(DataSource dataSource) async => 1;

  @override
  Future<int?> createWithOptions(VideoCreationOptions options) async => 1;

  @override
  Stream<VideoEvent> videoEventsFor(int playerId) {
    scheduleMicrotask(
      () => _events.add(
        VideoEvent(
          eventType: VideoEventType.initialized,
          duration: const Duration(seconds: 250),
          size: const Size(1280, 720),
        ),
      ),
    );
    return _events.stream;
  }

  @override
  Future<void> play(int playerId) async => calls.add('play');

  @override
  Future<void> pause(int playerId) async => calls.add('pause');

  @override
  Future<void> setLooping(int playerId, bool looping) async {}

  @override
  Future<void> setVolume(int playerId, double volume) async {}

  @override
  Future<void> setPlaybackSpeed(int playerId, double speed) async {}

  @override
  Future<void> setMixWithOthers(bool mixWithOthers) async {}

  @override
  Future<void> seekTo(int playerId, Duration to) async {
    position = to;
    calls.add('seek ${to.inSeconds}');
  }

  @override
  Future<Duration> getPosition(int playerId) async => position;

  @override
  Widget buildView(int playerId) => const SizedBox.expand();

  @override
  Widget buildViewWithOptions(VideoViewOptions options) =>
      const SizedBox.expand();

  @override
  Future<void> dispose(int playerId) async => calls.add('dispose');
}

void main() {
  late _FakePlayer fake;

  setUp(() {
    fake = _FakePlayer();
    VideoPlayerPlatform.instance = fake;
  });

  const video = HelpVideo(
    url: 'https://videos.example.test/scheduling-how-to.mp4',
    title: 'Scheduling: how to use it',
    seconds: 250,
    chapters: [
      (at: 0, label: 'The calendar'),
      (at: 80, label: 'Booking a job'),
    ],
  );

  Future<void> open(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () =>
                  playHelpVideo(context, video, label: 'Scheduling'),
              child: const Text('Play'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Play'));
    await tester.pumpAndSettle();
  }

  Future<void> close(WidgetTester tester) async {
    Navigator.of(tester.element(find.byType(SidePanel))).pop();
    await tester.pumpAndSettle();
  }

  testWidgets('the video plays in the panel, with Fullscreen and Download in '
      'its bar and the playing chapter lit', (tester) async {
    await open(tester);

    expect(fake.calls, contains('play'));
    expect(find.byKey(const Key('help-video-fullscreen')), findsOneWidget);
    expect(find.byKey(const Key('help-video-download')), findsOneWidget);
    // Never handed to a browser.
    expect(find.text('Open the video'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('help-video-chapter-80')));
    await tester.pump(const Duration(seconds: 1));
    expect(fake.calls, contains('seek 80'));
    expect(
      tester
          .widget<ListTile>(find.byKey(const ValueKey('help-video-chapter-80')))
          .selected,
      isTrue,
    );
    expect(
      tester
          .widget<ListTile>(find.byKey(const ValueKey('help-video-chapter-0')))
          .selected,
      isFalse,
    );

    await close(tester);
    expect(find.byType(SidePanel), findsNothing);
  });

  testWidgets('Fullscreen covers the app and Esc comes back', (tester) async {
    await open(tester);

    await tester.tap(find.byKey(const Key('help-video-fullscreen')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('help-video-fullscreen-view')), findsOneWidget);

    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('help-video-fullscreen-view')), findsNothing);
    // Back in the panel, still playing.
    expect(find.byType(SidePanel), findsOneWidget);

    await close(tester);
  });

  testWidgets('Download fetches the file itself', (tester) async {
    await open(tester);

    final asked = <Uri>[];
    await http.runWithClient(
      () async {
        await tester.tap(find.byKey(const Key('help-video-download')));
        await tester.pump();
      },
      () => MockClient((request) async {
        asked.add(request.url);
        return http.Response.bytes(const [0, 0, 0, 24], 200);
      }),
    );
    await tester.pumpAndSettle();

    expect(asked, [Uri.parse(video.url)]);
    await close(tester);
  });
}
