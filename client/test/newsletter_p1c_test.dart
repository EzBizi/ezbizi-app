import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_dashboard.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_import_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Newsletter P1c (2026-11-18): the import wizard and the dashboard list.
void main() {
  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );

  const converted = {
    'data': {
      'import_id': 'imp-1',
      'mode': 'convert',
      'blocks': [
        {'type': 'heading', 'text': 'Spring sweep', 'size': 28},
        {'type': 'image', 'src': 'http://x.example.com/a.png', 'alt': ''},
      ],
      'html_override': null,
      'issues': [
        {'kind': 'asset', 'message': 'A picture is not on a secure https:// web address: http://x.example.com/a.png'},
        {'kind': 'unsupported', 'message': '<form> does not work in most inboxes; it was kept as HTML.'},
      ],
      'lost': ['The stylesheet (<style>): blocks carry their own styling.'],
      'preserved': 1,
      'preview_html': '<html><body><h1>Spring sweep</h1></body></html>',
    },
  };

  Future<void> pumpWizard(
    WidgetTester tester, {
    required MockClientHandler handler,
    required List<List<Map<String, dynamic>>> used,
    List<String>? usedHtml,
    Future<bool> Function()? before,
    List<String>? savedAsNew,
  }) async {
    tester.view.physicalSize = const Size(1400, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: NewsletterImportPanel(
            api: ApiClient(client: MockClient(handler)),
            newsletterId: 'n-1',
            subject: 'Spring',
            onUseHtml: (h) => usedHtml?.add(h),
            onUseBlocks: used.add,
            onClose: () {},
            onBeforeApply: before,
            onSavedAsNew: (id) => savedAsNew?.add(id),
          ),
        ),
      ),
    ));
  }

  Future<void> toCompare(WidgetTester tester, {bool convert = true}) async {
    await tester.enterText(find.byKey(const ValueKey('newsletter-import-source')), '<h1>Spring sweep</h1>');
    await tester.tap(find.byKey(const ValueKey('newsletter-import-next')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('newsletter-import-mode-keep')), findsOneWidget);
    expect(find.textContaining('Sends exactly as it is'), findsOneWidget);
    if (convert) {
      await tester.tap(find.byKey(const ValueKey('newsletter-import-mode-convert')));
      await tester.pumpAndSettle();
    }
    await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
    await tester.pumpAndSettle();
  }

  group('import wizard', () {
    testWidgets('source, mode, compare with issues; losses must be accepted; '
        'apply keeps a Before import restore point first', (tester) async {
      final used = <List<Map<String, dynamic>>>[];
      final order = <String>[];
      Map<String, dynamic>? asked;
      await pumpWizard(
        tester,
        used: used,
        handler: (r) async {
          asked = jsonDecode(r.body) as Map<String, dynamic>;
          return json(converted);
        },
        before: () async {
          order.add('checkpoint');
          return true;
        },
      );
      // Three distinct sources.
      expect(find.text('Paste HTML'), findsOneWidget);
      expect(find.text('Upload HTML file'), findsOneWidget);
      expect(find.text('SVG or artwork'), findsOneWidget);

      await toCompare(tester);
      expect(asked, {'html': '<h1>Spring sweep</h1>', 'mode': 'convert', 'newsletter_id': 'n-1'});
      expect(find.text('Original'), findsOneWidget);
      expect(find.text('Result'), findsOneWidget);
      final issues = find.byKey(const ValueKey('newsletter-import-issues'));
      expect(find.descendant(of: issues, matching: find.textContaining('http://x.example.com/a.png')), findsOneWidget);
      expect(find.descendant(of: issues, matching: find.textContaining('<form>')), findsOneWidget);
      expect(find.textContaining('stylesheet'), findsOneWidget);

      // Desktop / phone toggle.
      await tester.tap(find.text('Phone'));
      await tester.pumpAndSettle();

      final apply = find.byKey(const ValueKey('newsletter-import-use'));
      expect(tester.widget<FilledButton>(apply).onPressed, isNull, reason: 'losses not yet accepted');
      await tester.tap(find.byKey(const ValueKey('newsletter-import-accept-losses')));
      await tester.pumpAndSettle();
      await tester.tap(apply);
      await tester.pumpAndSettle();

      expect(order, ['checkpoint']);
      expect(used.single.first['text'], 'Spring sweep');
    });

    testWidgets('a restore point that cannot be saved leaves the content untouched', (tester) async {
      final used = <List<Map<String, dynamic>>>[];
      await pumpWizard(tester, used: used, handler: (_) async => json(converted), before: () async => false);
      await toCompare(tester);
      await tester.tap(find.byKey(const ValueKey('newsletter-import-accept-losses')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('newsletter-import-use')));
      await tester.pumpAndSettle();

      expect(used, isEmpty);
      expect(find.textContaining('nothing was changed'), findsOneWidget);
    });

    testWidgets('a refused conversion shows the reason and changes nothing', (tester) async {
      final used = <List<Map<String, dynamic>>>[];
      final html = <String>[];
      await pumpWizard(
        tester,
        used: used,
        usedHtml: html,
        handler: (_) async => json({
          'message': 'The given data was invalid.',
          'errors': {'html': ['That HTML is larger than 1 MB.']},
        }, 422),
      );
      await toCompare(tester, convert: false);

      expect(find.text('That HTML is larger than 1 MB.'), findsOneWidget);
      expect(find.byKey(const ValueKey('newsletter-import-use')), findsNothing);
      expect(used, isEmpty);
      expect(html, isEmpty);
    });

    testWidgets('Save as new draft posts a new newsletter and leaves this one', (tester) async {
      final used = <List<Map<String, dynamic>>>[];
      final html = <String>[];
      final saved = <String>[];
      Map<String, dynamic>? created;
      await pumpWizard(
        tester,
        used: used,
        usedHtml: html,
        savedAsNew: saved,
        handler: (r) async {
          if (r.url.path.endsWith('/newsletters')) {
            created = jsonDecode(r.body) as Map<String, dynamic>;
            return json({'data': {'id': 'n-2'}}, 201);
          }
          return json({
            'data': {
              'import_id': 'imp-1', 'mode': 'keep', 'blocks': null, 'html_override': '<h1>Spring sweep</h1>',
              'issues': [], 'lost': [], 'preserved': 0, 'preview_html': '<h1>Spring sweep</h1>',
            },
          });
        },
      );
      await toCompare(tester, convert: false);
      expect(find.text('No issues found.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('newsletter-import-new')));
      await tester.pumpAndSettle();

      expect(created!['html_override'], '<h1>Spring sweep</h1>');
      expect(created!['subject'], 'Spring');
      expect(saved, ['n-2']);
      expect(used, isEmpty);
      expect(html, isEmpty);
      expect(find.byKey(const ValueKey('newsletter-import-done')), findsOneWidget);
    });
  });

  group('dashboard list', () {
    Future<List<String?>> pumpDashboard(WidgetTester tester, {double width = 1280}) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final picked = <String?>[];
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: NewsletterDashboard(
            stats: const NewsletterStats(drafts: 4, scheduled: 1, sent: 2),
            rows: [
              NewsletterRow.fromJson(const {
                'id': 'n-1',
                'subject': 'A very long subject line about chimney sweeps that keeps going and going well past the column',
                'name': 'Q2 promo with an internal name long enough to need wrapping in a narrow list column for sure',
                'status': 'draft',
                'next_step': 'Choose an audience',
              }),
              NewsletterRow.fromJson(const {
                'id': 'n-5',
                'subject': 'Sent one',
                'status': 'sent',
                'audience': {'name': 'Everybody', 'kind': 'mailchimp'},
                'last_send': {'status': 'sent', 'recipients': 41, 'sent_at': '2026-11-01T10:00:00Z'},
                'delivery': {'state': 'accepted', 'label': 'Accepted by Mailchimp'},
              }),
            ],
            scheduled: const [],
            viewName: 'A-1',
            session: Session(ApiClient(client: MockClient((_) async => http.Response('{}', 404)))),
            status: null,
            onStatus: picked.add,
            onOpen: (_) {},
            onNew: () {},
          ),
        ),
      ));
      await tester.pumpAndSettle();
      return picked;
    }

    testWidgets('name first, subject second, next step and provider result; '
        'no overflow at 1280 and 1440', (tester) async {
      for (final width in [1280.0, 1440.0]) {
        await pumpDashboard(tester, width: width);
        expect(tester.takeException(), isNull);
        final draft = find.byKey(const ValueKey('newsletter-n-1'));
        expect(find.descendant(of: draft, matching: find.byKey(const ValueKey('newsletter-subject-n-1'))), findsOneWidget);
        expect(find.descendant(of: draft, matching: find.text('Next: Choose an audience')), findsOneWidget);
        expect(find.descendant(of: draft, matching: find.byType(Tooltip)), findsWidgets);
        final sent = find.byKey(const ValueKey('newsletter-n-5'));
        expect(find.descendant(of: sent, matching: find.textContaining('Accepted by Mailchimp · 41 addresses')), findsOneWidget);
        expect(find.textContaining('Delivered'), findsNothing);
        expect(tester.getSize(draft).width, lessThanOrEqualTo(width));
      }
    });

    testWidgets('status filters carry honest counts and the calendar starts collapsed', (tester) async {
      final picked = await pumpDashboard(tester);
      expect(find.descendant(of: find.byKey(const Key('newsletters-tile-draft')), matching: find.text('4')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const Key('newsletters-tile-all')), matching: find.text('7')), findsOneWidget);
      // Failed is not offered while there are none.
      expect(find.byKey(const Key('newsletters-tile-failed')), findsNothing);

      await tester.tap(find.byKey(const Key('newsletters-tile-scheduled')));
      expect(picked, ['scheduled']);

      expect(find.byKey(const ValueKey('newsletter-calendar')), findsNothing);
      await tester.ensureVisible(find.byKey(const ValueKey('newsletter-calendar-toggle')));
      await tester.tap(find.byKey(const ValueKey('newsletter-calendar-toggle')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('newsletter-calendar')), findsOneWidget);
    });
  });
}
