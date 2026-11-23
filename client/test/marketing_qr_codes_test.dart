import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/marketing/qr_codes_tab.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// Marketing › QR Codes (UI queue 55, desktop 1 and phone 4).
///
/// A printed code that never changes, pointed somewhere new from here. The
/// one property everything rests on is that the image drawn is the code's own
/// permanent address and never where it currently sends people: drawing the
/// destination would show a code that stops matching the van the moment
/// someone presses Change.
void main() {
  testWidgets('a card per code: the code drawn from its permanent address, '
      'where it goes, and its scans', (tester) async {
    await _pump(tester);

    final van = find.byKey(const ValueKey('qr:q-1'));
    expect(
      find.descendant(of: van, matching: find.text('Van 1, side door')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: van, matching: find.text('everdoors.example/spring')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: van,
        matching: find.text('41 scans · last 3 hours ago'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('qr:q-2')),
        matching: find.text('No scans yet'),
      ),
      findsOneWidget,
    );

    // The pixels, not the call: the drawn code is the one for the printed
    // address, and differs from the one for the destination.
    final drawn = await _pixels(
      tester,
      find.byKey(const ValueKey('qr-image:q-1')),
    );
    expect(drawn, await _reference(tester, 'q-1', _url('q-1')));
    expect(
      drawn,
      isNot(
        await _reference(tester, 'q-1', 'https://everdoors.example/spring'),
      ),
    );
  });

  testWidgets('Change re-points the code on its card; a refused address is '
      "explained under the field in the server's words", (tester) async {
    final server = _Server();
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const ValueKey('qr-change:q-1')));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'The printed code stays the same; people who scan it go here instead.',
      ),
      findsOneWidget,
    );
    final field = find.byKey(const ValueKey('qr-destination:q-1'));
    expect(
      tester.widget<TextField>(field).controller!.text,
      'https://everdoors.example/spring',
    );

    await tester.enterText(field, 'everdoors summer');
    await tester.tap(find.byKey(const ValueKey('qr-save:q-1')));
    await tester.pumpAndSettle();
    expect(
      find.text('Enter a full web address starting with https://.'),
      findsOneWidget,
    );
    expect(field, findsOneWidget);

    await tester.enterText(field, 'https://everdoors.example/summer');
    await tester.tap(find.byKey(const ValueKey('qr-save:q-1')));
    await tester.pumpAndSettle();

    expect(server.puts, [
      {'destination_url': 'everdoors summer'},
      {'destination_url': 'https://everdoors.example/summer'},
    ]);
    expect(field, findsNothing);
    expect(find.text('everdoors.example/summer'), findsOneWidget);
  });

  testWidgets('cards side by side are one height, an open Change box '
      'included', (tester) async {
    await _pump(tester);

    Set<double> heights() => {
      for (final id in ['q-1', 'q-2'])
        tester.getSize(find.byKey(ValueKey('qr:$id'))).height,
    };
    final top = tester.getTopLeft(find.byKey(const ValueKey('qr:q-1'))).dy;
    expect(tester.getTopLeft(find.byKey(const ValueKey('qr:q-2'))).dy, top);
    expect(heights(), hasLength(1));

    await tester.tap(find.byKey(const ValueKey('qr-change:q-1')));
    await tester.pumpAndSettle();
    expect(heights(), hasLength(1));
    expect(
      tester.getSize(find.byKey(const ValueKey('qr:q-2'))).height,
      greaterThan(200),
    );
  });

  // Jose, 2026-10-06: "i dont want anyone deleting qr codes that we printed by
  // accident so instead of deleting them add deactivation instead, so they can
  // be reactivated".
  testWidgets('Deactivate is asked inside the card, says what a scan will '
      'show, and Keep keeps it', (tester) async {
    final server = _Server();
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const ValueKey('qr-more:q-1')));
    await tester.pumpAndSettle();
    expect(find.text('Delete'), findsNothing, reason: 'nothing deletes a code');
    await tester.tap(find.byKey(const ValueKey('qr-deactivate:q-1')));
    await tester.pumpAndSettle();
    final ask = find.byKey(const ValueKey('qr-deactivate-ask:q-1'));
    expect(
      find.descendant(
        of: ask,
        matching: find.text(
          'Deactivate it? Anyone who scans a printed copy sees “This QR code '
          'is paused.” until you reactivate it.',
        ),
      ),
      findsOneWidget,
    );

    await tester.tap(find.descendant(of: ask, matching: find.text('Keep')));
    await tester.pumpAndSettle();
    expect(ask, findsNothing);
    expect(server.switched, isEmpty);

    await tester.tap(find.byKey(const ValueKey('qr-more:q-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('qr-deactivate:q-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('qr-deactivate-yes:q-1')));
    await tester.pumpAndSettle();

    expect(server.switched, ['q-1/deactivate']);
    // Paused, not gone: labelled, still downloadable, and one tap from back.
    expect(find.byKey(const ValueKey('qr-paused:q-1')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('qr-paused-line:q-1')),
        matching: find.textContaining('“This QR code is paused.”'),
        matchRoot: true,
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('qr-svg:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-png:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-reactivate:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-more:q-1')), findsNothing);
    expect(find.byKey(const ValueKey('qr:q-2')), findsOneWidget);
  });

  testWidgets('Reactivate brings a paused code back as it was', (tester) async {
    final server = _Server(paused: const {'q-2'});
    await _pump(tester, server: server);

    expect(find.byKey(const ValueKey('qr-paused:q-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-paused:q-1')), findsNothing);
    // Where it goes can still be changed while it is paused.
    expect(find.byKey(const ValueKey('qr-change:q-2')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('qr-reactivate:q-2')));
    await tester.pumpAndSettle();

    expect(server.switched, ['q-2/reactivate']);
    expect(find.byKey(const ValueKey('qr-paused:q-2')), findsNothing);
    expect(find.byKey(const ValueKey('qr-more:q-2')), findsOneWidget);
  });

  testWidgets('New QR code is made in a side panel, and a refusal lands on '
      'its own field', (tester) async {
    final server = _Server();
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const Key('qr-new')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('qr-new-name')), 'Yard sign');
    await tester.enterText(find.byKey(const Key('qr-new-destination')), 'nope');
    await tester.tap(find.byKey(const Key('qr-new-save')));
    await tester.pumpAndSettle();

    final destination = find.byKey(const Key('qr-new-destination'));
    expect(
      find.descendant(
        of: destination,
        matching: find.text('Enter a full web address starting with https://.'),
      ),
      findsOneWidget,
    );

    await tester.enterText(destination, 'https://everdoors.example/');
    await tester.tap(find.byKey(const Key('qr-new-save')));
    await tester.pumpAndSettle();

    expect(server.posts.last, {
      'name': 'Yard sign',
      'destination_url': 'https://everdoors.example/',
    });
    expect(find.byKey(const Key('qr-new-name')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('qr:q-3')),
        matching: find.text('everdoors.example'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the downloads ask for SVG, and for PNG by name', (tester) async {
    final server = _Server();
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const ValueKey('qr-svg:q-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('qr-png:q-1')));
    await tester.pumpAndSettle();

    expect(server.images, [
      '/api/v1/marketing/qr-codes/q-1/image',
      '/api/v1/marketing/qr-codes/q-1/image?format=png',
    ]);
  });

  testWidgets('without marketing.edit the codes and downloads stay, the '
      'changes go', (tester) async {
    await _pump(
      tester,
      server: _Server(paused: const {'q-2'}),
      permissions: const ['marketing.qr_codes.view'],
    );

    expect(find.byKey(const ValueKey('qr:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-png:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-svg:q-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-change:q-1')), findsNothing);
    expect(find.byKey(const ValueKey('qr-more:q-1')), findsNothing);
    expect(find.byKey(const Key('qr-new')), findsNothing);
    // A paused code says so, but only an editor can bring it back.
    expect(find.byKey(const ValueKey('qr-paused:q-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('qr-reactivate:q-2')), findsNothing);
    expect(find.byKey(const ValueKey('qr-png:q-2')), findsOneWidget);
    expect(
      find.text(
        'You can see the codes and download them, but not change them.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('no codes yet says what one is for', (tester) async {
    await _pump(tester, server: _Server(empty: true));

    expect(
      find.text(
        'No QR codes yet. Make one for a van, a sign or your cards: where it '
        'sends people can change later without reprinting.',
      ),
      findsOneWidget,
    );
    // Rodrigo 01a1ef57: the genuine empty state offers the first one.
    await tester.tap(find.byKey(const Key('qr-create-empty')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('qr-new-name')), findsOneWidget);
  });

  testWidgets('Preview shows the code large with where it sends people, and '
      'Copy link copies the code’s own link', (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String?;
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('qr-copy:q-1')));
    await tester.pumpAndSettle();
    expect(copied, _url('q-1'));
    expect(find.text('Link copied.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('qr-preview:q-1')));
    await tester.pumpAndSettle();
    final panel = find.byKey(const Key('qr-preview-panel'));
    expect(panel, findsOneWidget);
    expect(
      find.descendant(
        of: panel,
        matching: find.byKey(const Key('qr-preview-image')),
      ),
      findsOneWidget,
    );
    expect(
      tester
          .widget<SelectableText>(
            find.byKey(const Key('qr-preview-destination')),
          )
          .data,
      'https://everdoors.example/spring',
    );
    expect(find.byKey(const Key('qr-preview-open')), findsOneWidget);
    // Scan history is on the same card.
    expect(find.text('Scan history'), findsWidgets);
  });

  // Bar, 01a1f4ba: "Be able to add the company logo or any other image to
  // QR Codes". The logo is in the middle by default, on the card and the
  // preview; No picture takes it out; Upload sends any picture.
  testWidgets('the company logo sits in the middle of the code; No picture '
      'and Upload change it', (tester) async {
    final server = _Server();
    await _pump(tester, server: server);

    Finder centre(String id) => find.descendant(
      of: find.byKey(ValueKey('qr-image:$id')),
      matching: find.byKey(const Key('qr-center-picture')),
    );
    expect(centre('q-1'), findsOneWidget);
    final card = tester.getSize(find.byKey(const ValueKey('qr-image:q-1')));
    final pad = tester.getSize(centre('q-1'));
    expect(pad.width / card.width, closeTo(0.22, 0.01));

    await tester.tap(find.byKey(const ValueKey('qr-preview:q-1')));
    await tester.pumpAndSettle();
    final preview = find.byKey(const Key('qr-preview-image'));
    expect(
      find.descendant(
        of: preview,
        matching: find.byKey(const Key('qr-center-picture')),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('qr-picture-none')));
    await tester.pumpAndSettle();
    expect(server.puts.last, {'center_image': 'none'});
    expect(
      find.descendant(
        of: preview,
        matching: find.byKey(const Key('qr-center-picture')),
      ),
      findsNothing,
    );
    expect(centre('q-1'), findsNothing);

    Files.debugPickBytes = (_) async =>
        PickedBytes('badge.png', _Server._png.toList());
    addTearDown(() => Files.debugPickBytes = null);
    await tester.tap(find.byKey(const Key('qr-picture-upload')));
    await tester.pumpAndSettle();
    expect(server.uploads, ['q-1']);
    expect(centre('q-1'), findsOneWidget);
    expect(find.text('Your picture · change'), findsOneWidget);
  });

  testWidgets('on a phone the cards stack and Change says what it changes', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 844));

    expect(tester.takeException(), isNull);
    expect(find.text('Change where it goes'), findsNWidgets(2));
    // The menu sits on the name's line, not on a line of its own.
    expect(
      tester.getCenter(find.byKey(const ValueKey('qr-more:q-1'))).dy,
      moreOrLessEquals(
        tester.getCenter(find.text('Van 1, side door')).dy,
        epsilon: 2,
      ),
    );
    final first = tester.getTopLeft(find.byKey(const ValueKey('qr:q-1')));
    final second = tester.getTopLeft(find.byKey(const ValueKey('qr:q-2')));
    expect(second.dx, first.dx);
    expect(second.dy, greaterThan(first.dy));
  });

  testWidgets('Social Media shows QR Codes beside Posts and Accounts for its '
      'own grant only', (tester) async {
    await _pump(
      tester,
      permissions: const ['marketing.qr_codes.view'],
      screen: (api, session) => SocialMediaTab(api: api, session: session),
    );
    // UI queue 199: a tool beside the calendar; with no post grant it is the
    // page itself.
    expect(find.byKey(const Key('social-qr')), findsOneWidget);
    expect(find.byType(QrCodesTab), findsOneWidget);

    await _pump(
      tester,
      permissions: const ['marketing.accounts.view'],
      screen: (api, session) => SocialMediaTab(api: api, session: session),
    );
    expect(find.byKey(const Key('social-qr')), findsNothing);
    expect(find.byType(QrCodesTab), findsNothing);
  });

  // ── UI queue 63: stats (Jose: "how many people used it") ─────────

  testWidgets('a card shows its last 30 days: scans, daily scanners and a '
      'strip of days', (tester) async {
    await _pump(tester, server: _Server(month: true));

    expect(
      tester.widget<Text>(find.byKey(const ValueKey('qr-scans:q-1'))).data,
      '128 scans · 96 daily scanners in 30 days · last 3 hours ago',
    );
    final strip = find.byKey(const ValueKey('qr-strip:q-1'));
    expect(
      find.descendant(of: strip, matching: find.byType(Container)),
      findsNWidgets(30),
    );
    // A scanner is a phone a day, never a person (Marketing, 2026-10-06).
    expect(find.textContaining('people'), findsNothing);
  });

  testWidgets('without 30-day figures the card keeps its all-time line', (
    tester,
  ) async {
    await _pump(tester, server: _Server());

    expect(
      tester.widget<Text>(find.byKey(const ValueKey('qr-scans:q-1'))).data,
      '41 scans · last 3 hours ago',
    );
    expect(find.byKey(const ValueKey('qr-strip:q-1')), findsNothing);
  });

  testWidgets('Stats shows a code\'s 30 days, and 90 on asking', (
    tester,
  ) async {
    final server = _Server(month: true);
    await _pump(tester, server: server);

    await tester.tap(find.byKey(const ValueKey('qr-stats:q-1')));
    await tester.pumpAndSettle();
    expect(server.stats, ['q-1?days=30']);

    String figure(String key) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(ValueKey(key)),
            matching: find.byType(Text),
          ),
        )
        .first
        .data!;
    expect(figure('qr-stats-scans'), '128');
    expect(figure('qr-stats-scanners'), '96');
    expect(
      find.descendant(
        of: find.byKey(const Key('qr-stats-busiest')),
        matching: find.text('Busiest day · 11'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('qr-stats-meaning')), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const Key('qr-stats-all-time'))).data,
      'All time: 41 scans.',
    );
    // Every day drawn, zeros included.
    expect(
      find.descendant(
        of: find.byKey(const Key('qr-stats-chart')),
        matching: find.byWidgetPredicate(
          (w) =>
              w.key is ValueKey &&
              '${(w.key as ValueKey).value}'.startsWith('qr-bar:'),
        ),
      ),
      findsNWidgets(30),
    );

    await tester.tap(find.text('90 days'));
    await tester.pumpAndSettle();
    expect(server.stats, ['q-1?days=30', 'q-1?days=90']);
  });

  test('a paused stretch starts on the day the code was paused', () {
    final today = DateTime(2026, 10, 6, 15);
    final row = QrCodeRow(
      id: 'q',
      name: 'Yard sign',
      url: 'https://ezbizi.example/q/1',
      destinationUrl: 'https://everdoors.example',
      scans: 0,
      active: false,
      deactivatedAt: DateTime(2026, 10, 4, 9),
    );
    expect(row.pausedIndex(30, today), 27);

    // Paused before the window: every day of it is paused.
    final older = QrCodeRow(
      id: 'q',
      name: 'Yard sign',
      url: 'https://ezbizi.example/q/1',
      destinationUrl: 'https://everdoors.example',
      scans: 0,
      active: false,
      deactivatedAt: DateTime(2026, 8, 1),
    );
    expect(older.pausedIndex(30, today), 0);

    // Active again: nothing is shaded.
    final back = QrCodeRow(
      id: 'q',
      name: 'Yard sign',
      url: 'https://ezbizi.example/q/1',
      destinationUrl: 'https://everdoors.example',
      scans: 0,
      deactivatedAt: DateTime(2026, 10, 4, 9),
    );
    expect(back.pausedIndex(30, today), isNull);
  });
}

String _url(String id) => 'https://ezbizi.test/q/$id-7KQ2';

/// The painted modules of the code drawn under [image].
Future<List<int>> _pixels(WidgetTester tester, Finder image) async {
  final painter =
      tester
              .widget<CustomPaint>(
                find
                    .descendant(of: image, matching: find.byType(CustomPaint))
                    .first,
              )
              .painter!
          as QrPainter;
  final data = await tester.runAsync(() => painter.toImageData(120));
  return data!.buffer.asUint8List();
}

/// What the code for [address] looks like, drawn the way the card draws it.
Future<List<int>> _reference(
  WidgetTester tester,
  String id,
  String address,
) async {
  final shown =
      tester
              .widget<CustomPaint>(
                find
                    .descendant(
                      of: find.byKey(ValueKey('qr-image:$id')),
                      matching: find.byType(CustomPaint),
                    )
                    .first,
              )
              .painter!
          as QrPainter;
  final painter = QrPainter(
    data: address,
    version: shown.version,
    errorCorrectionLevel: shown.errorCorrectionLevel,
    gapless: shown.gapless,
    eyeStyle: shown.eyeStyle,
    dataModuleStyle: shown.dataModuleStyle,
  );
  final data = await tester.runAsync(() => painter.toImageData(120));
  return data!.buffer.asUint8List();
}

class _Server {
  _Server({
    bool empty = false,
    Set<String> paused = const {},
    this.month = false,
  }) : rows = empty
           ? []
           : [
               _row(
                 'q-1',
                 'Van 1, side door',
                 'https://everdoors.example/spring',
                 41,
                 3,
               ),
               _row(
                 'q-2',
                 'Yard sign',
                 'https://everdoors.example/fall',
                 0,
                 null,
               ),
             ] {
    for (final row in rows) {
      if (paused.contains(row['id'])) {
        row['active'] = false;
        row['deactivated_at'] = '2026-10-06T14:00:00+00:00';
      }
    }
  }

  final List<Map<String, Object?>> rows;
  final List<Map<String, dynamic>> puts = [];
  final List<Map<String, dynamic>> posts = [];
  final List<String> switched = [];
  final List<String> stats = [];

  /// Whether the server sends each code's last 30 days.
  final bool month;
  final List<String> images = [];
  final List<String> uploads = [];
  Map<String, Object?> me = const {};

  /// A 1x1 PNG: the company logo, as the server sends it.
  static final _png = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  );

  static Map<String, Object?> _row(
    String id,
    String name,
    String destination,
    int scans,
    int? hoursAgo,
  ) {
    final last = hoursAgo == null
        ? null
        : DateTime.now().toUtc().subtract(Duration(hours: hoursAgo));

    return {
      'id': id,
      'name': name,
      'code': '$id-7KQ2',
      'url': _url(id),
      'destination_url': destination,
      'scans': scans,
      // As the server sends it: ISO-8601 with an offset.
      'last_scanned_at': last == null
          ? null
          : '${last.toIso8601String().substring(0, 19)}+00:00',
      'active': true,
      'deactivated_at': null,
    };
  }

  static final _refused = _json({
    'message': 'The given data was invalid.',
    'errors': {
      'destination_url': ['Enter a full web address starting with https://.'],
    },
  }, 422);

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': me});

    if (path.endsWith('/image')) {
      images.add(
        '${request.url.path}${request.url.hasQuery ? '?${request.url.query}' : ''}',
      );
      return http.Response.bytes([60, 115, 118, 103, 62], 200);
    }

    final centre = RegExp(
      r'/marketing/qr-codes/([^/]+)/center-image$',
    ).firstMatch(path);
    if (centre != null) {
      final row = rows.firstWhere((r) => r['id'] == centre.group(1));
      if (request.method == 'POST') {
        uploads.add(centre.group(1)!);
        row['center_image'] = 'upload';
        return _json({'data': row});
      }
      return row['center_image'] == 'none'
          ? _json({'message': 'Not found.'}, 404)
          : http.Response.bytes(_png, 200);
    }

    final one = RegExp(r'/marketing/qr-codes/([^/]+)$').firstMatch(path);
    if (one != null && request.method == 'PUT') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      puts.add(body);
      if (body['center_image'] is String) {
        final row = rows.firstWhere((r) => r['id'] == one.group(1));
        row['center_image'] = body['center_image'];
        return _json({'data': row});
      }
      final to = body['destination_url'] as String;
      if (!to.startsWith('https://')) return _refused;
      final row = rows.firstWhere((r) => r['id'] == one.group(1));
      row['destination_url'] = to;
      return _json({'data': row});
    }
    final stat = RegExp(r'/marketing/qr-codes/([^/]+)/stats$').firstMatch(path);
    if (stat != null && request.method == 'GET') {
      final days = int.parse(request.url.queryParameters['days'] ?? '30');
      stats.add('${stat.group(1)}?days=$days');
      final to = DateTime(2026, 10, 6);
      return _json({
        'data': {
          'from': _ymd(to.subtract(Duration(days: days - 1))),
          'to': _ymd(to),
          'timezone': 'America/New_York',
          'days': [
            for (var i = 0; i < days; i++)
              {
                'date': _ymd(to.subtract(Duration(days: days - 1 - i))),
                'scans': i == 20 ? 11 : (i % 7 == 3 ? 0 : 4),
                'people': i == 20 ? 8 : (i % 7 == 3 ? 0 : 3),
              },
          ],
          'scans': 128,
          'people': 96,
        },
      });
    }

    final pause = RegExp(
      r'/marketing/qr-codes/([^/]+)/(deactivate|reactivate)$',
    ).firstMatch(path);
    if (pause != null && request.method == 'POST') {
      switched.add('${pause.group(1)}/${pause.group(2)}');
      final row = rows.firstWhere((r) => r['id'] == pause.group(1));
      final on = pause.group(2) == 'reactivate';
      row['active'] = on;
      row['deactivated_at'] = on ? null : '2026-10-06T14:00:00+00:00';
      return _json({'data': row});
    }

    if (path.endsWith('/marketing/qr-codes') && request.method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      posts.add(body);
      final to = body['destination_url'] as String;
      if (!to.startsWith('https://')) return _refused;
      final row = _row(
        'q-${rows.length + 1}',
        body['name'] as String,
        to,
        0,
        null,
      );
      rows.add(row);
      return _json({'data': row}, 201);
    }
    if (path.endsWith('/marketing/qr-codes')) {
      return _json({
        'data': [
          for (final row in rows)
            if (month && row['id'] == 'q-1')
              {
                ...row,
                'scans_30d': 128,
                'people_30d': 96,
                'daily_30d': [for (var i = 0; i < 30; i++) i % 7 == 3 ? 0 : 4],
              }
            else
              row,
        ],
      });
    }

    return _json({'data': const []});
  }
}

Future<void> _pump(
  WidgetTester tester, {
  _Server? server,
  List<String> permissions = const [
    'marketing.qr_codes.view',
    'marketing.edit',
  ],
  Size size = const Size(1400, 1000),
  Widget Function(ApiClient, Session)? screen,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final serving = server ?? _Server();
  serving.me = {
    'id': 'u-1',
    'name': 'Dana Reyes',
    'email': 'dana@example.com',
    'has_avatar': false,
    'role': {'id': 'role-1', 'name': 'Administrator'},
    'permissions': permissions,
    'modules': <Map<String, Object?>>[],
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

  final api = ApiClient(client: MockClient(serving.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: KeyedSubtree(
          key: UniqueKey(),
          child: (screen ?? (a, s) => QrCodesTab(api: a, session: s))(
            api,
            session,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

String _ymd(DateTime d) =>
    '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';
