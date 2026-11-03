import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/phone_numbers.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile/whatsapp_row.dart';
import 'package:ezbizi_client/screens/business_profile_screen.dart';
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:ezbizi_client/screens/customer_experience/review_sources.dart';
import 'package:ezbizi_client/screens/customer_experience/review_stats_band.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 154 (Jose: "yes go ahead with all ur recommendations", 1, 4, 7,
/// 10, 13, 16): media and services with copy, the 409 while Google writes
/// are off, review stats, phone check, sources, the WhatsApp link.
///
/// Every name and number here is invented.
void main() {
  Map<String, dynamic> listing(String id, String branch, {String? wa}) => {
    'id': id,
    'title': 'Brightside Home',
    'label': 'Brightside Home · $branch',
    'address': '1 Elm St',
    'phone': '+15550100110',
    'whatsapp_link': wa,
    'website': null,
    'primary_category': 'HVAC contractor',
    'verification_state': 'VERIFIED',
    'is_published': true,
    'auto_reply': false,
    'is_live': true,
    'synced_at': null,
    'company': {'id': 'co-1', 'name': 'Brightside'},
    'franchise': {'id': 'fr-$id', 'name': branch},
  };

  final locations = [
    listing('loc-1', 'Riverton', wa: 'https://wa.me/15550100110'),
    listing('loc-2', 'Oak Park'),
    listing('loc-3', 'Lakeview'),
  ];

  const media = [
    {
      'name': 'm-photo',
      'category': 'ADDITIONAL',
      'format': 'PHOTO',
      'url': null,
      'thumbnail': null,
      'created': null,
    },
    {
      'name': 'm-logo',
      'category': 'LOGO',
      'format': 'PHOTO',
      'url': null,
      'thumbnail': null,
      'created': null,
    },
    {
      'name': 'm-video',
      'category': 'ADDITIONAL',
      'format': 'VIDEO',
      'url': null,
      'thumbnail': null,
      'created': null,
    },
  ];

  final services = {
    'primary': {
      'name': 'categories/gcid:hvac',
      'displayName': 'HVAC contractor',
    },
    'additional': [
      {'name': 'categories/gcid:duct', 'displayName': 'Air duct cleaning'},
    ],
    'services': [
      {
        'freeFormServiceItem': {
          'category': 'categories/gcid:duct',
          'label': {
            'displayName': 'Duct cleaning',
            'description': 'Every vent',
          },
        },
        'price': {'currencyCode': 'USD', 'units': '149'},
      },
      {
        'structuredServiceItem': {
          'serviceTypeId': 'job_type_id:furnace_tune_up',
        },
      },
    ],
  };

  const phoneCheck = [
    {
      'id': 'loc-1',
      'name': 'Brightside Home · Riverton',
      'branch': 'Riverton',
      'listing_phone': '+15550100110',
      'ezbizi_lines': ['+15550100110'],
      'website_phone': '(555) 010-0110',
      'status': 'match',
    },
    {
      'id': 'loc-2',
      'name': 'Brightside Home · Oak Park',
      'branch': 'Oak Park',
      'listing_phone': '+15550100199',
      'ezbizi_lines': ['+15550100121'],
      'website_phone': null,
      'status': 'mismatch',
    },
    {
      'id': 'loc-3',
      'name': 'Brightside Home · Lakeview',
      'branch': 'Lakeview',
      'listing_phone': null,
      'ezbizi_lines': <String>[],
      'website_phone': null,
      'status': 'missing',
    },
  ];

  testWidgets('#1 media: chips, tick, copy with a per-listing failure', (
    tester,
  ) async {
    final sent = <http.Request>[];
    await _pumpProfile(
      tester,
      sent: sent,
      routes: {
        'GET /media': (_) => _json({'data': media}),
        'POST /media/copy': (_) => _json({
          'data': [
            {'id': 'loc-2', 'name': 'Oak Park', 'ok': true},
            {
              'id': 'loc-3',
              'name': 'Lakeview',
              'ok': false,
              'message': 'Google refused the change.',
            },
          ],
        }),
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Media'));
    await tester.pumpAndSettle();

    expect(find.text('All · 3'), findsOneWidget);
    expect(find.text('Videos · 1'), findsOneWidget);
    expect(find.text('Logo · 1'), findsOneWidget);
    // Logo pinned first.
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('cx-media-m-logo'))).dx,
      lessThan(
        tester.getTopLeft(find.byKey(const ValueKey('cx-media-m-photo'))).dx,
      ),
    );

    await tester.tap(find.byKey(const ValueKey('cx-media-kind-videos')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-media-m-photo')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('cx-media-kind-all')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('cx-media-m-photo')));
    await tester.tap(find.byKey(const ValueKey('cx-media-m-logo')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-media-copy')));
    await tester.pumpAndSettle();

    // The other two listings, "Company · Branch", with tick-all and search.
    expect(find.text('Brightside · Oak Park'), findsWidgets);
    await tester.enterText(
      find.byKey(const ValueKey('cx-targets-search')),
      'lake',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-target-loc-2')), findsNothing);
    await tester.enterText(find.byKey(const ValueKey('cx-targets-search')), '');
    await tester.tap(find.byKey(const ValueKey('cx-targets-all')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-media-copy-send')));
    await tester.pumpAndSettle();

    final copy = sent.lastWhere((r) => r.url.path.endsWith('/media/copy'));
    final body = jsonDecode(copy.body) as Map;
    expect((body['media'] as List).toSet(), {'m-photo', 'm-logo'});
    expect((body['targets'] as List).toSet(), {'loc-2', 'loc-3'});

    StatusPill pillIn(String id) => tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(ValueKey('cx-target-$id')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(pillIn('loc-2').tone, Tone.ok);
    expect(pillIn('loc-3').tone, Tone.bad);
    expect(pillIn('loc-3').label, 'Google refused the change.');
  });

  testWidgets('#1 media: a 409 while Google writes are off is shown inline', (
    tester,
  ) async {
    await _pumpProfile(
      tester,
      routes: {
        'GET /media': (_) => _json({'data': media}),
        'POST /media/copy': (_) => _json({
          'message':
              'Changing Business Profile listings is not switched on yet.',
        }, 409),
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Media'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-media-m-photo')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-media-copy')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-target-loc-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-media-copy-send')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('cx-refused')),
        matching: find.text(
          'Changing Business Profile listings is not switched on yet.',
        ),
      ),
      findsOneWidget,
    );
  });

  testWidgets('#4 services: grouped rows, diff preview first, then apply', (
    tester,
  ) async {
    final sent = <http.Request>[];
    await _pumpProfile(
      tester,
      sent: sent,
      routes: {
        'GET /services': (_) => _json({'data': services}),
        'POST /services/copy': (r) {
          final apply = (jsonDecode(r.body) as Map)['apply'] == true;
          return _json({
            'data': apply
                ? [
                    {'id': 'loc-2', 'name': 'Oak Park', 'ok': true},
                    {
                      'id': 'loc-3',
                      'name': 'Lakeview',
                      'ok': false,
                      'message': 'Google refused the change.',
                    },
                  ]
                : [
                    {
                      'id': 'loc-2',
                      'name': 'Oak Park',
                      'adds': ['Duct cleaning'],
                      'removes': ['Roof repair'],
                    },
                    {
                      'id': 'loc-3',
                      'name': 'Lakeview',
                      'adds': <String>[],
                      'removes': <String>[],
                    },
                  ],
          });
        },
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('cx-category-primary')), findsOneWidget);
    expect(find.text('AIR DUCT CLEANING'), findsOneWidget);
    expect(find.text('Duct cleaning'), findsOneWidget);
    expect(find.text('\$149'), findsOneWidget);
    expect(find.text('Furnace tune up'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('cx-services-search')),
      'furnace',
    );
    await tester.pumpAndSettle();
    expect(find.text('Duct cleaning'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('cx-services-copy')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-targets-all')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-services-preview')));
    await tester.pumpAndSettle();

    // The preview wrote nothing.
    final first = sent.where((r) => r.url.path.endsWith('/services/copy'));
    expect(first, hasLength(1));
    expect((jsonDecode(first.single.body) as Map)['apply'], false);
    expect(find.text('+ Duct cleaning'), findsOneWidget);
    expect(find.text('− Roof repair'), findsOneWidget);
    expect(find.text('Already the same.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('cx-services-apply')));
    await tester.pumpAndSettle();
    final applied = sent.lastWhere(
      (r) => r.url.path.endsWith('/services/copy'),
    );
    expect((jsonDecode(applied.body) as Map)['apply'], true);
    final bad = tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(const ValueKey('cx-diff-loc-3')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(bad.tone, Tone.bad);
    final good = tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(const ValueKey('cx-diff-loc-2')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(good.label, 'Copied');
  });

  testWidgets('#4 services: apply refused with 409 shows the message', (
    tester,
  ) async {
    await _pumpProfile(
      tester,
      routes: {
        'GET /services': (_) => _json({'data': services}),
        'POST /services/copy': (r) =>
            (jsonDecode(r.body) as Map)['apply'] == true
            ? _json({'message': 'Writes are off.'}, 409)
            : _json({
                'data': [
                  {
                    'id': 'loc-2',
                    'name': 'Oak Park',
                    'adds': ['Duct cleaning'],
                    'removes': <String>[],
                  },
                ],
              }),
        'PUT /services': (_) => _json({'message': 'Writes are off.'}, 409),
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    // Editing one service: the PUT's 409 inline in its panel.
    await tester.tap(find.byKey(const ValueKey('cx-service-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-service-save')));
    await tester.pumpAndSettle();
    expect(find.text('Writes are off.'), findsOneWidget);
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('cx-services-copy')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-target-loc-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-services-preview')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-services-apply')));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('cx-refused')),
        matching: find.text('Writes are off.'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('#10 phone check: statuses, chips and search', (tester) async {
    await _pumpProfile(
      tester,
      routes: {
        'GET /phone-check': (_) => _json({'data': phoneCheck}),
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-more')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-open-phone-check')));
    await tester.pumpAndSettle();

    String pillOf(String id) => tester
        .widget<StatusPill>(
          find.descendant(
            of: find.byKey(ValueKey('cx-phone-row-$id')),
            matching: find.byType(StatusPill),
          ),
        )
        .label;
    expect(pillOf('loc-1'), 'Match');
    expect(pillOf('loc-2'), 'Mismatch');
    expect(pillOf('loc-3'), 'No number on Google');
    // "Company · Branch" from the listing, not the server's Google name.
    expect(find.text('Brightside · Oak Park'), findsOneWidget);
    expect(find.text(formatPhoneNumber('+15550100121')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('cx-phone-mismatch')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-phone-row-loc-1')), findsNothing);
    expect(find.byKey(const ValueKey('cx-phone-row-loc-2')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('cx-phone-all')));
    await tester.enterText(
      find.byKey(const ValueKey('cx-phone-search')),
      'lakeview',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-phone-row-loc-3')), findsOneWidget);
    expect(find.byKey(const ValueKey('cx-phone-row-loc-2')), findsNothing);
  });

  testWidgets('#16 the WhatsApp link opens, and hides when null', (
    tester,
  ) async {
    final opened = <Uri>[];
    final before = WhatsAppRow.opener;
    WhatsAppRow.opener = (uri) async {
      opened.add(uri);
      return true;
    };
    addTearDown(() => WhatsAppRow.opener = before);

    await _pumpProfile(tester, routes: const {}, locations: locations);
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    expect(find.text('wa.me/15550100110'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('cx-whatsapp-open')));
    await tester.pumpAndSettle();
    expect(opened, [Uri.parse('https://wa.me/15550100110')]);

    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-whatsapp')), findsNothing);
  });

  testWidgets('#7 the histogram bars and gauge are painted to scale', (
    tester,
  ) async {
    var drafted = false;
    var windowOpened = false;
    await _pumpWidget(
      tester,
      ReviewStatsBand(
        stats: const {
          'histogram': {'1': 10, '2': 0, '3': 12, '4': 41, '5': 243},
          'replies': {'published': 280, 'by_ai': 64, 'drafts': 4, 'failed': 1},
        },
        toDraft: 7,
        onDraftAll: () => drafted = true,
        onSendWindow: () => windowOpened = true,
      ),
      const Size(1200, 400),
    );

    double width(int r) =>
        tester.getSize(find.byKey(ValueKey('cx-histogram-bar-$r'))).width;
    expect(width(5), greaterThan(150));
    expect(width(4), closeTo(width(5) * 41 / 243, 1));
    expect(width(1), closeTo(width(5) * 10 / 243, 1));
    expect(width(2), 0);
    // 4.6 of 5 across a 120 px gauge.
    final avg = (10 + 36 + 164 + 1215) / 306;
    expect(
      tester.getSize(find.byKey(const ValueKey('cx-gauge-fill'))).width,
      closeTo(120 * avg / 5, 0.5),
    );
    expect(find.text('${avg.toStringAsFixed(1)} ★'), findsOneWidget);
    expect(
      find.textContaining('64 AI replies sent', findRichText: true),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('cx-replies-failed')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('cx-draft-all')));
    await tester.tap(find.byKey(const ValueKey('cx-send-window')));
    expect(drafted, isTrue);
    expect(windowOpened, isTrue);
  });

  testWidgets(
    '#7 Draft all sends 25 ids as 20 + 5, fills drafts, posts nothing',
    (tester) async {
      tester.view.physicalSize = const Size(1300, 1000);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      final ids = [for (var i = 1; i <= 25; i++) 'rev-$i'];
      final sent = <String>[];
      final batches = <List<dynamic>>[];
      final api = ApiClient(
        client: MockClient((r) async {
          final path = r.url.path;
          sent.add('${r.method} $path');
          if (path.endsWith('/me')) {
            return _json({
              'data': {
                'id': 'u-1',
                'name': 'Dana',
                'email': 'dana@brightside.example',
                'permissions': ['reviews.view', 'reviews.manage'],
                'modules': <dynamic>[],
                'organization': {'id': 'org-1', 'name': 'Brightside'},
                'scope': {
                  'type': 'organization',
                  'id': 'org-1',
                  'label': 'Brightside',
                  'path': ['Brightside'],
                },
              },
            });
          }
          if (path.endsWith('/reviews/replies/draft-batch')) {
            final chunk = (jsonDecode(r.body) as Map)['ids'] as List;
            batches.add(chunk);
            return _json({
              'data': [
                for (final id in chunk)
                  id == 'rev-7'
                      ? {'id': id, 'ok': false, 'message': 'AI is resting.'}
                      : {'id': id, 'ok': true, 'body': 'Thanks from $id'},
              ],
            });
          }
          if (path.endsWith('/reviews')) {
            return _json({
              'data': [
                for (final id in ids)
                  {
                    'id': id,
                    'reviewer_name': 'Dana P.',
                    'rating': 5,
                    'comment': 'On time.',
                    'reviewed_at': '2026-10-20T09:00:00Z',
                    'reply': null,
                  },
              ],
              'meta': {
                'total': 25,
                'unanswered': 25,
                'stats': {
                  'histogram': {'1': 0, '2': 0, '3': 0, '4': 0, '5': 25},
                  'replies': {
                    'published': 0,
                    'by_ai': 0,
                    'drafts': 0,
                    'failed': 0,
                  },
                },
              },
            });
          }
          return _json({'data': <String, dynamic>{}});
        }),
      );
      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: ReviewsScreen(api: api, session: session),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Draft all 25'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('cx-draft-all')));
      await tester.pumpAndSettle();

      expect(batches.map((b) => b.length), [20, 5]);
      expect([...batches[0], ...batches[1]], ids);
      expect(
        find.text("24 drafts ready · 1 couldn't be drafted"),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('review-draft-failed-rev-7')),
        findsOneWidget,
      );
      expect(find.text('AI is resting.'), findsOneWidget);
      // Only the failed one is still waiting for a draft.
      expect(find.text('Draft all 1'), findsOneWidget);
      expect(sent.where((c) => c.contains('/publish')), isEmpty);
      expect(sent.where((c) => c.startsWith('PUT')), isEmpty);

      await tester.tap(find.byKey(const ValueKey('review-row-rev-3')));
      await tester.pumpAndSettle();
      expect(
        find.widgetWithText(TextField, 'Thanks from rev-3'),
        findsOneWidget,
      );
    },
  );

  testWidgets('#7 Draft all is hidden without reviews.manage', (tester) async {
    await _pumpWidget(
      tester,
      const ReviewStatsBand(stats: {}, toDraft: 3),
      const Size(1200, 400),
    );
    expect(find.byKey(const ValueKey('cx-draft-all')), findsNothing);
  });

  testWidgets('#7 the monthly chart draws 12 bars to scale, average labelled', (
    tester,
  ) async {
    final counts = [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 5];
    await _pumpWidget(
      tester,
      ReviewStatsBand(
        stats: {
          'monthly': [
            for (var i = 0; i < 12; i++)
              {
                'month': '2025-${(i + 11) % 12 + 1}',
                'count': counts[i],
                'average': counts[i] == 0 ? null : (i == 11 ? 4.25 : 4.0),
              },
          ],
        },
      ),
      const Size(1200, 400),
    );
    final bars = find.byWidgetPredicate(
      (w) =>
          w.key is ValueKey<String> &&
          (w.key! as ValueKey<String>).value.startsWith('cx-monthly-bar-'),
    );
    expect(bars, findsNWidgets(12));
    double height(int i) =>
        tester.getSize(find.byKey(ValueKey('cx-monthly-bar-$i'))).height;
    const top = ReviewStatsBand.monthlyBarMax;
    expect(height(10), closeTo(top, 0.01));
    expect(height(0), closeTo(1, 0.01));
    expect(height(5), closeTo(1 + (top - 1) / 2, 0.01));
    expect(height(11), closeTo(1 + (top - 1) * 5 / 20, 0.01));
    expect(find.text('Reviews a month · latest avg 4.3 ★'), findsOneWidget);
  });

  testWidgets('#7 no monthly array draws no chart', (tester) async {
    for (final stats in [
      <String, dynamic>{},
      {'monthly': <dynamic>[]},
      {'monthly': null},
    ]) {
      await _pumpWidget(
        tester,
        ReviewStatsBand(stats: stats),
        const Size(1200, 400),
      );
      expect(find.byKey(const ValueKey('cx-monthly')), findsNothing);
      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('#4 add missing only: mode sent, removes none, has shown', (
    tester,
  ) async {
    final sent = <http.Request>[];
    await _pumpProfile(
      tester,
      sent: sent,
      routes: {
        'GET /services': (_) => _json({'data': services}),
        'POST /services/copy': (r) {
          final body = jsonDecode(r.body) as Map;
          final add = body['mode'] == 'add_missing';
          if (body['apply'] == true) {
            return _json({
              'data': [
                {'id': 'loc-2', 'name': 'Oak Park', 'ok': true},
              ],
            });
          }
          return _json({
            'data': [
              {
                'id': 'loc-2',
                'name': 'Oak Park',
                'has': ['Roof repair'],
                'adds': ['Duct cleaning'],
                'removes': add ? <String>[] : ['Roof repair'],
              },
            ],
          });
        },
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-row-loc-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-services-copy')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-target-loc-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-services-preview')));
    await tester.pumpAndSettle();
    expect(find.text('− Roof repair'), findsOneWidget);
    expect(find.text('Has: Roof repair'), findsOneWidget);

    // Switching mode re-previews in that mode.
    await tester.tap(find.text('Add missing only'));
    await tester.pumpAndSettle();
    final previews = sent
        .where((r) => r.url.path.endsWith('/services/copy'))
        .map((r) => jsonDecode(r.body) as Map)
        .toList();
    expect(previews.last['mode'], 'add_missing');
    expect(previews.last['apply'], false);
    expect(find.text('− Roof repair'), findsNothing);
    expect(find.text('+ Duct cleaning'), findsOneWidget);
    expect(find.text('Has: Roof repair'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('cx-services-apply')));
    await tester.pumpAndSettle();
    final applied =
        jsonDecode(
              sent.lastWhere((r) => r.url.path.endsWith('/services/copy')).body,
            )
            as Map;
    expect(applied['apply'], true);
    expect(applied['mode'], 'add_missing');
  });

  testWidgets('#10 phone check says when it was checked', (tester) async {
    final at = DateTime.now().toUtc().subtract(const Duration(minutes: 5));
    await _pumpProfile(
      tester,
      routes: {
        'GET /phone-check': (_) =>
            _json({'checked_at': at.toIso8601String(), 'data': phoneCheck}),
      },
      locations: locations,
    );
    await tester.tap(find.byKey(const ValueKey('gbp-more')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('cx-open-phone-check')));
    await tester.pumpAndSettle();
    expect(find.text('Checked 5 min ago'), findsOneWidget);
  });

  testWidgets('#7 send window opens the reply rule, or Automations', (
    tester,
  ) async {
    for (final rule in <String?>['rule-9', null]) {
      final opened = <String>[];
      DeepLinks.pending.value = null;
      await _pumpReviews(tester, replyRule: rule, opened: opened);
      await tester.tap(find.byKey(const ValueKey('cx-send-window')));
      await tester.pumpAndSettle();
      expect(opened, ['automations']);
      expect(DeepLinks.pending.value?.id, rule);
      if (rule != null) expect(DeepLinks.pending.value?.type, 'automation');
    }
    DeepLinks.pending.value = null;
  });

  testWidgets('#13 sources: eKomi and Thumbtack say not connected', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient(
        (r) async => r.url.path.endsWith('/reviews/sources')
            ? _json({
                'data': [
                  {
                    'source': 'google',
                    'connected': true,
                    'count': 312,
                    'average': 4.7,
                    'last_sync': DateTime.now()
                        .toUtc()
                        .subtract(const Duration(minutes: 5))
                        .toIso8601String(),
                  },
                  {
                    'source': 'ekomi',
                    'connected': false,
                    'count': 0,
                    'average': null,
                  },
                  {
                    'source': 'thumbtack',
                    'connected': false,
                    'count': 0,
                    'average': null,
                  },
                ],
              })
            : _json({}),
      ),
    );
    await _pumpWidget(tester, ReviewSources(api: api), const Size(900, 600));

    String pill(String s) => tester
        .widget<StatusPill>(
          find.descendant(
            of: find.byKey(ValueKey('cx-source-$s')),
            matching: find.byType(StatusPill),
          ),
        )
        .label;
    expect(pill('google'), 'Connected');
    expect(pill('ekomi'), 'Not connected');
    expect(pill('thumbtack'), 'Not connected');
    expect(find.text('312 reviews'), findsOneWidget);
    expect(find.text('4.7 ★'), findsOneWidget);
    expect(find.text('Synced 5 min ago'), findsOneWidget);
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('cx-source-sync-ekomi')))
          .data,
      '—',
    );

    await tester.enterText(
      find.byKey(const ValueKey('cx-sources-search')),
      'thumb',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('cx-source-google')), findsNothing);
    expect(find.byKey(const ValueKey('cx-source-thumbtack')), findsOneWidget);
  });

  group('phone 390×844, no overflow', () {
    const phone = Size(390, 844);

    testWidgets('media and services tabs', (tester) async {
      await _pumpProfile(
        tester,
        routes: {
          'GET /media': (_) => _json({'data': media}),
          'GET /services': (_) => _json({'data': services}),
        },
        locations: locations,
        size: phone,
      );
      await tester.tap(find.text('Brightside · Riverton'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('cx-whatsapp')), findsOneWidget);
      await tester.tap(find.text('Media'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('cx-media')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('cx-media-m-photo')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('cx-media-copy')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('cx-targets-all')), findsOneWidget);
      Navigator.of(
        tester.element(find.byKey(const ValueKey('cx-targets-all'))),
      ).pop();
      await tester.pumpAndSettle();
      await tester.tap(find.text('Services'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('cx-services')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('phone check', (tester) async {
      await _pumpProfile(
        tester,
        routes: {
          'GET /phone-check': (_) => _json({'data': phoneCheck}),
        },
        locations: locations,
        size: phone,
      );
      await tester.tap(find.byKey(const ValueKey('gbp-more')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('cx-open-phone-check')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('cx-phone-row-loc-2')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('review stats', (tester) async {
      await _pumpWidget(
        tester,
        const ReviewStatsBand(
          stats: {
            'histogram': {'1': 1, '2': 0, '3': 0, '4': 2, '5': 9},
            'replies': {'published': 9, 'by_ai': 3, 'drafts': 1, 'failed': 0},
          },
          toDraft: 2,
          onDraftAll: _noop,
          onSendWindow: _noop,
        ),
        phone,
      );
      expect(find.byKey(const ValueKey('cx-gauge')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('sources', (tester) async {
      final api = ApiClient(
        client: MockClient(
          (_) async => _json({
            'data': [
              {
                'source': 'thumbtack',
                'connected': false,
                'count': 0,
                'average': null,
              },
            ],
          }),
        ),
      );
      await _pumpWidget(tester, ReviewSources(api: api), phone);
      expect(find.text('Not connected'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}

void _noop() {}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<void> _pumpWidget(WidgetTester tester, Widget child, Size size) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SingleChildScrollView(child: child)),
    ),
  );
  await tester.pumpAndSettle();
}

/// Routes are "METHOD /suffix" of the request path; the listings list answers
/// everything else under /business-profile/locations.
Future<void> _pumpProfile(
  WidgetTester tester, {
  required Map<String, http.Response Function(http.Request)> routes,
  required List<Map<String, dynamic>> locations,
  List<http.Request>? sent,
  Size size = const Size(1200, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      sent?.add(request);
      final path = request.url.path;
      for (final MapEntry(key: route, value: answer) in routes.entries) {
        final [method, suffix] = route.split(' ');
        if (request.method == method && path.endsWith(suffix)) {
          return answer(request);
        }
      }
      if (path.endsWith('/business-profile/locations')) {
        return _json({
          'data': locations,
          'meta': {
            'total': locations.length,
            'not_live': 0,
            'connections': [
              {
                'provider': 'google_business_profile',
                'display_name': 'Brightside',
                'status': 'connected',
              },
            ],
          },
        });
      }
      return _json(<String, dynamic>{});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BusinessProfileScreen(api: api, session: Session(api)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpReviews(
  WidgetTester tester, {
  required String? replyRule,
  required List<String> opened,
}) async {
  tester.view.physicalSize = const Size(1300, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = ApiClient(
    client: MockClient((r) async {
      final path = r.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@brightside.example',
            'has_avatar': false,
            'permissions': ['reviews.view', 'automations.view'],
            'modules': [
              {
                'key': 'automations',
                'label': 'Automations',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': <String, dynamic>{},
            'organization': {'id': 'org-1', 'name': 'Brightside'},
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Brightside',
              'path': ['Brightside'],
            },
          },
        });
      }
      if (path.endsWith('/reviews/sources')) {
        return _json({'data': <dynamic>[], 'reply_rule_id': replyRule});
      }
      if (path.endsWith('/reviews')) {
        return _json({
          'data': <dynamic>[],
          'meta': {
            'total': 0,
            'unanswered': 0,
            'stats': {
              'histogram': {'1': 0, '2': 0, '3': 0, '4': 0, '5': 0},
              'replies': {'published': 0, 'by_ai': 0, 'drafts': 0, 'failed': 0},
            },
          },
        });
      }
      return _json({'data': <String, dynamic>{}});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OpenDestination(
          open: opened.add,
          child: ReviewsScreen(
            key: ValueKey('$replyRule'),
            api: api,
            session: session,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
