import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 145 (Jose, 2026-10-26: "Looks good", 1 and 4): Reviews as an
/// inbox. The fake server below honours the filters the controller reads
/// (answered, rating, from, q), so a chip or tile that sent the wrong thing
/// shows the wrong rows here, not just a wrong query string.
void main() {
  testWidgets('the summary tiles count the fixture', (tester) async {
    final server = _Server();
    await server.pump(tester);

    expect(_value(tester, 'average'), '3.0 / 5');
    expect(find.text('▼ 0.5 vs 3.5 last month'), findsOneWidget);
    expect(_value(tester, 'new'), '2');
    expect(_value(tester, 'unanswered'), '2');
    expect(_value(tester, 'low'), '2');

    final info = tester.widget<Tooltip>(
      find.byKey(const ValueKey('reviews-tile-average-info')),
    );
    expect(info.message, contains('since the 1st of this month'));
    expect(info.message, isNot(contains('partial')));
  });

  testWidgets('a capped window says the averages are partial', (tester) async {
    final server = _Server(windowTotal: 450);
    await server.pump(tester);

    final info = tester.widget<Tooltip>(
      find.byKey(const ValueKey('reviews-tile-new-info')),
    );
    expect(info.message, contains('Only the newest 4 of the 450'));
    expect(info.message, contains('partial'));
  });

  testWidgets('each tile sets its filter', (tester) async {
    final server = _Server();
    await server.pump(tester);
    final firstOfMonth = _ymd(DateTime(_now.year, _now.month));

    await tester.tap(find.byKey(const ValueKey('reviews-tile-average')));
    await tester.pumpAndSettle();
    expect(server.last['from'], firstOfMonth);
    expect(server.last.containsKey('answered'), isFalse);
    expect(_names(), ['Dana Whitfield', 'Omar Castillo']);

    await tester.tap(find.byKey(const ValueKey('reviews-tile-unanswered')));
    await tester.pumpAndSettle();
    expect(server.last['answered'], 'no');

    await tester.tap(find.byKey(const ValueKey('reviews-tile-low')));
    await tester.pumpAndSettle();
    expect(server.last['answered'], 'no');
    expect(server.last['rating'], 'negative');

    // Back to all time, so the low tile shows both 1-2 star reviews.
    await tester.tap(find.byKey(const ValueKey('reviews-more-filters')));
    await tester.pumpAndSettle();
    await _pick(tester, 'reviews-time', 'All time');
    expect(_names(), ['Dana Whitfield', 'Lee Park']);

    await tester.tap(find.byKey(const ValueKey('reviews-tile-new')));
    await tester.pumpAndSettle();
    expect(server.last['from'], firstOfMonth);
    expect(server.last.containsKey('answered'), isFalse);
  });

  testWidgets('the chips send their filters', (tester) async {
    final server = _Server();
    await server.pump(tester);

    // Unanswered ships on.
    expect(server.last['answered'], 'no');
    expect(find.text('Unanswered (2)'), findsOneWidget);
    expect(_names(), ['Dana Whitfield', 'Lee Park']);

    await tester.tap(find.text('Unanswered (2)'));
    await tester.pumpAndSettle();
    expect(server.last.containsKey('answered'), isFalse);
    expect(_names(), hasLength(4));

    await tester.tap(find.text('1–3 stars'));
    await tester.pumpAndSettle();
    expect(server.last['rating'], 'negative');
    // The chip is the server's "negative": 1 to 3 stars (2026-11-19).
    expect(_names(), ['Dana Whitfield', 'Lee Park']);
    await tester.tap(find.text('1–3 stars'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('This week'));
    await tester.pumpAndSettle();
    expect(
      server.last['from'],
      _ymd(DateTime.now().subtract(const Duration(days: 7))),
    );
    expect(_names(), ['Dana Whitfield', 'Omar Castillo']);
  });

  testWidgets('More filters opens a panel, and its count is right', (
    tester,
  ) async {
    final server = _Server();
    await server.pump(tester);

    expect(_more(), 'More filters');
    await tester.tap(find.byKey(const ValueKey('reviews-more-filters')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reviews-more-panel')), findsOneWidget);

    await _pick(tester, 'reviews-status', 'Working on it');
    expect(server.last['status'], 'working');
    expect(_more(), 'More filters (1)');

    await _pick(tester, 'reviews-rating', '5 stars');
    expect(server.last['rating'], '5');
    expect(_more(), 'More filters (2)');

    // Sort is an order, not a filter; This week is its own chip.
    await _pick(tester, 'reviews-sort', 'Oldest first');
    await tester.tap(find.text('This week'));
    await tester.pumpAndSettle();
    expect(_more(), 'More filters (2)');

    await tester.tap(find.byKey(const ValueKey('reviews-more-clear')));
    await tester.pumpAndSettle();
    expect(_more(), 'More filters');
    expect(server.last.containsKey('status'), isFalse);
    expect(server.last.containsKey('rating'), isFalse);
  });

  testWidgets('a list row: red stars for 1–2, the dot, no "Unverified"', (
    tester,
  ) async {
    final server = _Server();
    await server.pump(tester, unanswered: false);

    final dana = find.byKey(const ValueKey('review-row-rev-1'));
    final omar = find.byKey(const ValueKey('review-row-rev-2'));
    final lee = find.byKey(const ValueKey('review-row-rev-3'));

    // Unanswered: the dot. Answered: none.
    expect(find.byKey(const ValueKey('review-unanswered-rev-1')), findsOne);
    expect(find.byKey(const ValueKey('review-unanswered-rev-2')), findsNothing);
    // An unanswered one-star needs a reply; a five-star does not.
    expect(find.byKey(const ValueKey('review-needs-reply-rev-1')), findsOne);
    expect(
      find.byKey(const ValueKey('review-needs-reply-rev-2')),
      findsNothing,
    );

    // Company · Branch, the letter avatar, one line of text.
    expect(
      find.descendant(of: dana, matching: find.text('A-1 Chimney · Main')),
      findsOneWidget,
    );
    expect(find.descendant(of: dana, matching: find.text('D')), findsOneWidget);
    final line = tester.widget<Text>(
      find.descendant(of: dana, matching: find.text('Turned up late.')),
    );
    expect(line.maxLines, 1);

    // Matched shows the name; unmatched shows nothing about it.
    expect(
      find.descendant(of: lee, matching: find.text('Matched to Lee Park')),
      findsOneWidget,
    );
    expect(find.textContaining('Unverified'), findsNothing);
    expect(find.textContaining('no customer match'), findsNothing);
    expect(
      find.descendant(of: omar, matching: find.text('Link')),
      findsNothing,
    );
  });

  testWidgets('with reviews.manage an unmatched row offers Link', (
    tester,
  ) async {
    final server = _Server(permissions: const ['reviews.manage']);
    await server.pump(tester);

    await tester.tap(find.byKey(const ValueKey('review-link-rev-1')));
    await tester.pumpAndSettle();
    expect(
      server.paths,
      contains('GET /api/v1/reviews/rev-1/customer-suggestions'),
    );
    expect(find.byKey(const ValueKey('review-match-search')), findsOneWidget);
  });

  // PAINTED: the colour the star glyph is drawn in, not a flag beside it.
  testWidgets('a 1-star review paints red stars, a 5-star amber', (
    tester,
  ) async {
    final server = _Server();
    await server.pump(tester, unanswered: false);
    final theme = Theme.of(tester.element(find.byType(ReviewsScreen)));

    Color? painted(String id) {
      final star = find.descendant(
        of: find.byKey(ValueKey('review-row-$id')),
        matching: find.byIcon(Icons.star),
      );
      final glyph = tester.widget<RichText>(
        find.descendant(of: star.first, matching: find.byType(RichText)),
      );
      return glyph.text.style?.color;
    }

    expect(painted('rev-1'), theme.colorScheme.error);
    expect(painted('rev-2'), EzBiziTheme.reviewStar);
    expect(painted('rev-1'), isNot(painted('rev-2')));
  });

  testWidgets('selecting shows the detail; up and down move it', (
    tester,
  ) async {
    final server = _Server();
    await server.pump(tester, unanswered: false);

    expect(find.byKey(const ValueKey('review-pick-one')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('review-row-rev-3')));
    await tester.pumpAndSettle();

    final panel = find.byKey(const ValueKey('review-panel'));
    expect(panel, findsOneWidget);
    expect(
      find.descendant(of: panel, matching: find.text('Technician: Sam Ortiz')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: panel, matching: find.textContaining('Matched to')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: panel, matching: find.text('The cap was crooked.')),
      findsOneWidget,
    );

    await tester.sendKeyEvent(LogicalKeyboardKey.arrowDown);
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: panel, matching: find.text('Kim Avery')),
      findsOneWidget,
    );
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowUp);
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowUp);
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: panel, matching: find.text('Omar Castillo')),
      findsOneWidget,
    );
    // A posted reply is a block with its date, and no box.
    expect(find.byKey(const ValueKey('review-posted-reply')), findsOneWidget);
    expect(find.textContaining('Your published reply · '), findsOneWidget);
    expect(find.byKey(const ValueKey('review-reply-box')), findsNothing);
  });

  testWidgets('Draft with AI fills the box; Post reply saves and publishes', (
    tester,
  ) async {
    final server = _Server();
    await server.pump(tester);

    await tester.tap(find.byKey(const ValueKey('review-row-rev-1')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Draft with AI'));
    await tester.pumpAndSettle();
    expect(server.paths, contains('POST /api/v1/reviews/rev-1/reply/suggest'));
    expect(find.widgetWithText(TextField, 'Thank you, Dana!'), findsOneWidget);
    expect(find.text('Draft by AI'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('review-post')));
    await tester.pumpAndSettle();
    // Nothing is posted until the inline confirm.
    expect(server.paths, isNot(contains(endsWith('/reply/publish'))));

    await tester.tap(find.byKey(const ValueKey('review-post-confirm')));
    await tester.pumpAndSettle();
    final put = server.paths.indexOf('PUT /api/v1/reviews/rev-1/reply');
    final publish = server.paths.indexOf(
      'POST /api/v1/reviews/rev-1/reply/publish',
    );
    expect(put, isNonNegative);
    expect(publish, greaterThan(put));
    expect(server.bodies['PUT /api/v1/reviews/rev-1/reply'], {
      'body': 'Thank you, Dana!',
    });
  });

  testWidgets('the Table and Board toggle still work', (tester) async {
    final server = _Server();
    await server.pump(tester, unanswered: false);

    await tester.tap(find.text('Table'));
    await tester.pumpAndSettle();
    expect(find.text('CUSTOMER · LOCATION'), findsOneWidget);
    expect(find.text('REVIEW'), findsOneWidget);
    // Every row has no status and no notes: those columns are not drawn.
    expect(find.text('STATUS'), findsNothing);
    expect(find.text('NOTES'), findsNothing);

    await tester.tap(find.text('Board'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-card-rev-1')), findsOneWidget);
    expect(find.text('CUSTOMER · LOCATION'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('review-row-rev-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-panel')), findsOneWidget);

    await tester.tap(find.text('Inbox'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-unanswered-rev-1')), findsOne);
  });

  testWidgets('on a phone a review opens in place, with back', (tester) async {
    final server = _Server();
    await server.pump(tester, size: const Size(390, 844));

    expect(find.byKey(const ValueKey('reviews-tile-average')), findsNothing);
    expect(find.text('1–3 stars'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('review-row-rev-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-panel')), findsOneWidget);
    expect(find.byKey(const ValueKey('review-row-rev-3')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('review-panel-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-row-rev-3')), findsOneWidget);
  });

  for (final size in const [
    Size(1600, 1000),
    Size(1024, 768),
    Size(390, 844),
  ]) {
    testWidgets('no overflow at ${size.width.toInt()}', (tester) async {
      final complaints = <String>[];
      final previous = FlutterError.onError;
      FlutterError.onError = (d) => complaints.add(d.exceptionAsString());
      addTearDown(() => FlutterError.onError = previous);

      final server = _Server(permissions: const ['reviews.manage']);
      await server.pump(tester, size: size, unanswered: false);
      await tester.tap(find.byKey(const ValueKey('review-row-rev-3')));
      await tester.pumpAndSettle();
      if (size.width < 900) {
        await tester.tap(find.byKey(const ValueKey('review-panel-close')));
        await tester.pumpAndSettle();
      }
      await tester.tap(find.byKey(const ValueKey('reviews-more-filters')));
      await tester.pumpAndSettle();
      FlutterError.onError = previous;

      expect(complaints.where((c) => c.contains('overflowed')), isEmpty);
    });
  }
}

final _now = DateTime.now();

String _ymd(DateTime d) =>
    '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';

/// The More filters button's own label (the panel repeats the words).
String? _more() {
  final text =
      find
              .descendant(
                of: find.byKey(const ValueKey('reviews-more-filters')),
                matching: find.byType(Text),
              )
              .evaluate()
              .single
              .widget
          as Text;
  return text.data;
}

String? _value(WidgetTester tester, String tile) =>
    tester.widget<Text>(find.byKey(ValueKey('reviews-tile-$tile-value'))).data;

/// The names in the list, top to bottom.
List<String> _names() {
  const all = ['Dana Whitfield', 'Omar Castillo', 'Lee Park', 'Kim Avery'];
  final shown = [
    for (final n in all)
      if (find
          .descendant(
            of: find.byKey(const ValueKey('tour-customer_experience-reviews')),
            matching: find.text(n),
          )
          .evaluate()
          .isNotEmpty)
        n,
  ];
  return shown;
}

Future<void> _pick(WidgetTester tester, String key, String label) async {
  await tester.tap(find.byKey(ValueKey(key)));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

Map<String, dynamic> _review(
  String id,
  String name,
  int rating,
  DateTime at, {
  String comment = 'Turned up late.',
  bool replied = false,
  Map<String, dynamic>? customer,
  Map<String, dynamic>? job,
}) => {
  'id': id,
  'reviewer_name': name,
  'reviewer_photo_url': null,
  'rating': rating,
  'comment': comment,
  'reviewed_at': at.toUtc().toIso8601String(),
  'edited_at': null,
  'was_edited': false,
  'company': {'id': 'c-1', 'name': 'A-1 Chimney'},
  'franchise': {'id': 'f-1', 'name': 'Main'},
  'location': 'locations/1',
  'status': null,
  'note_count': 0,
  'customer': customer,
  'match': null,
  'job': job,
  'reply': replied
      ? {
          'id': 'rp-$id',
          'body': 'Thank you!',
          'status': 'published',
          'drafted_by': 'person',
          'published_at': at.toUtc().toIso8601String(),
          'failure_reason': null,
          'editable': false,
        }
      : null,
};

class _Server {
  _Server({this.windowTotal, this.permissions = const []}) {
    final start = DateTime(_now.year, _now.month);
    final recent = _now.subtract(const Duration(minutes: 5));
    final thisMonth = recent.isBefore(start) ? start : recent;
    final lastMonth = DateTime(_now.year, _now.month - 1, 15, 12);
    reviews = [
      _review('rev-1', 'Dana Whitfield', 1, thisMonth),
      _review(
        'rev-2',
        'Omar Castillo',
        5,
        thisMonth,
        comment: 'Spotless.',
        replied: true,
      ),
      _review(
        'rev-3',
        'Lee Park',
        2,
        lastMonth,
        comment: 'The cap was crooked.',
        customer: {'id': 'cu-3', 'name': 'Lee Park', 'phone': null},
        job: {'id': 'j-3', 'number': '1203', 'tech': 'Sam Ortiz'},
      ),
      _review(
        'rev-4',
        'Kim Avery',
        5,
        lastMonth,
        comment: 'Great.',
        replied: true,
      ),
    ];
  }

  final int? windowTotal;
  final List<String> permissions;
  late final List<Map<String, dynamic>> reviews;
  final paths = <String>[];
  final bodies = <String, Object?>{};

  /// The query of the last list load (not the summary's own requests).
  Map<String, String> last = const {};

  bool _published(Map<String, dynamic> r) =>
      (r['reply'] as Map?)?['status'] == 'published';

  http.Response _json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: {'content-type': 'application/json'},
  );

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    paths.add('${request.method} $path');
    if (request.body.isNotEmpty) {
      bodies['${request.method} $path'] = jsonDecode(request.body);
    }

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Dana',
          'email': 'dana@brightside.example',
          'permissions': permissions,
          'modules': <dynamic>[],
          'organization': {'id': 'org-1', 'name': 'Brightside Home Services'},
          'scope': {
            'type': 'organization',
            'id': null,
            'label': 'Brightside Home Services',
            'path': ['Brightside Home Services'],
          },
        },
      });
    }

    if (path.endsWith('/reviews')) {
      final q = request.url.queryParameters;
      final isWindow =
          q['per_page'] == '200' &&
          q.keys.toSet().containsAll(const ['from']) &&
          q.length == 2;
      final summary = isWindow || q['per_page'] == '1';
      if (!summary) last = q;
      final from = q['from'] == null ? null : DateTime.parse(q['from']!);
      final rows = reviews.where((r) {
        final rating = r['rating'] as int;
        if (q['answered'] == 'no' && _published(r)) return false;
        final want = q['rating'];
        if (want == 'negative' && rating > 3) return false;
        if (want == 'positive' && rating < 4) return false;
        if (int.tryParse(want ?? '') case final n? when n != rating) {
          return false;
        }
        if (from != null &&
            DateTime.parse(
              r['reviewed_at'] as String,
            ).toLocal().isBefore(from)) {
          return false;
        }
        final term = (q['q'] ?? '').toLowerCase();
        if (term.isNotEmpty &&
            !'${r['reviewer_name']} ${r['comment']}'.toLowerCase().contains(
              term,
            )) {
          return false;
        }
        return true;
      }).toList();
      return _json({
        'data': q['per_page'] == '1' ? rows.take(1).toList() : rows,
        'meta': {
          'total': isWindow && windowTotal != null ? windowTotal : rows.length,
          'unanswered': reviews.where((r) => !_published(r)).length,
          'locations': const <dynamic>[],
          'connections': const [
            {
              'provider': 'google_business_profile',
              'display_name': 'A-1 Chimney',
              'status': 'connected',
            },
          ],
        },
      });
    }

    final one = RegExp(r'/reviews/(rev-\d)$').firstMatch(path);
    if (one != null && request.method == 'GET') {
      return _json({
        'data': reviews.firstWhere((r) => r['id'] == one.group(1)),
      });
    }
    if (path.endsWith('/reply/suggest')) {
      return _json({
        'data': {'body': 'Thank you, Dana!'},
      });
    }
    if (path.endsWith('/customer-suggestions')) {
      return _json({'data': const <dynamic>[]});
    }
    return _json({'data': <String, dynamic>{}});
  }

  Future<void> pump(
    WidgetTester tester, {
    Size size = const Size(1600, 1000),
    bool unanswered = true,
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(client: MockClient(handle));
    final session = Session(api);
    if (permissions.isNotEmpty) {
      await tester.runAsync(() => session.adopt('tok'));
    }

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReviewsScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    if (!unanswered) {
      await tester.tap(find.textContaining('Unanswered ('));
      await tester.pumpAndSettle();
    }
  }
}
