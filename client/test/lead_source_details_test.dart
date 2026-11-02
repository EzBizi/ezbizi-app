import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:ezbizi_client/widgets/lead_source_details.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Approved design 159, options 1 + 4 (docs/previews/leads-source-details-
/// layouts.html): a source chip, an avatar, "job type · city" on the row, a
/// referral line in the header, the details beside the thread on a desktop
/// and folded under the header on a phone. Every name here is invented.
void main() {
  const names = {
    'whatsapp': 'WhatsApp',
    'facebook': 'Facebook',
    'instagram': 'Instagram',
    'google_ads': 'Google Ads',
    'thumbtack': 'Thumbtack',
    'website': 'Website',
  };

  for (final brightness in Brightness.values) {
    for (final MapEntry(:key, :value) in names.entries) {
      testWidgets(
        '${brightness.name}: the $key chip says $value in its token',
        (tester) async {
          await _pumpBare(tester, LeadSourceChip(key), brightness: brightness);

          expect(find.text(value), findsOneWidget);
          final expected = SourceColours.forKey(key, brightness);
          expect(_fill(tester), expected.fill);
          expect(
            tester.widget<Text>(find.text(value)).style?.color,
            expected.ink,
          );
          // Its own colour, not the neutral one.
          expect(
            expected.fill,
            isNot(ToneColours.table(brightness)[Tone.neutral]!.fill),
          );
        },
      );
    }
  }

  testWidgets('an unknown source key is neutral and still named', (
    tester,
  ) async {
    await _pumpBare(tester, const LeadSourceChip('carrier_pigeon'));

    expect(find.text('Other'), findsOneWidget);
    expect(
      _fill(tester),
      ToneColours.table(Brightness.light)[Tone.neutral]!.fill,
    );
    expect(chipKeyFor('carrier_pigeon'), isNull);
    expect(chipKeyFor(null), isNull);
  });

  testWidgets('no picture: the avatar shows the initials', (tester) async {
    await _pumpBare(tester, const LeadAvatar(name: 'Wren Calloway'));
    expect(find.text('WC'), findsOneWidget);
    expect(leadInitials('Ola'), 'O');
    expect(leadInitials('+1 (555) 010-0142'), '#');
  });

  test('the mapper reads the detail first and trims empties away', () {
    final f = LeadSourceFields.from(
      {'source': 'facebook', 'job_type': 'Old', 'city': '  ', 'handle': ''},
      {
        'job_type': 'Dryer vent',
        'referral': {'ad_name': 'Autumn sweep', 'campaign_name': ''},
      },
    );
    expect(f.sourceKey, 'facebook');
    expect(f.jobType, 'Dryer vent');
    expect(f.city, isNull);
    expect(f.handle, isNull);
    expect(f.jobLine, 'Dryer vent');
    expect(f.referralLine, 'via ad: Autumn sweep');
  });

  group('the header', () {
    // Design 161: the details column is gone; its facts are one labelled
    // line under the name, each once.
    testWidgets('a referral is the Via ad fact; no details column', (
      tester,
    ) async {
      await _pumpThread(tester, width: 1200, detail: _referralDetail);

      expect(find.byKey(const ValueKey('thread-avatar')), findsOneWidget);
      expect(find.text('TB'), findsOneWidget);
      expect(find.byKey(const ValueKey('thread-source-chip')), findsOneWidget);
      expect(find.byKey(const ValueKey('source-details-column')), findsNothing);
      expect(find.text('Via ad'), findsOneWidget);
      expect(find.byKey(const ValueKey('details-ad')), findsOneWidget);
      expect(find.text('Autumn sweep'), findsOneWidget);
      expect(find.text('12 Elm St, Springfield'), findsOneWidget);
    });

    testWidgets('no referral: no via-ad fact', (tester) async {
      await _pumpThread(tester, width: 1200, detail: const {});

      expect(find.text('Via ad'), findsNothing);
      expect(find.byKey(const ValueKey('thread-source-chip')), findsOneWidget);
    });

    testWidgets('phone at 375: the rest folds under More', (tester) async {
      await _pumpThread(tester, width: 375, detail: _referralDetail);

      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('details-ad')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('thread-details-toggle')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('details-ad')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the list row', () {
    for (final width in [1200.0, 375.0]) {
      testWidgets('at $width: avatar, chip, job line, ad-question prefix', (
        tester,
      ) async {
        await _pumpScreen(
          tester,
          width: width,
          leads: [
            _row(
              'l-1',
              'Tamsin Brook',
              preview: 'Can I get a quote?',
              ad: true,
            ),
            _row('l-2', 'Odile Fenn', preview: 'Thanks!'),
          ],
        );

        expect(tester.takeException(), isNull);
        expect(find.byKey(const ValueKey('lead-avatar-l-1')), findsOneWidget);
        expect(find.text('TB'), findsWidgets);
        expect(find.byKey(const ValueKey('source-badge-l-1')), findsOneWidget);
        expect(find.text('WhatsApp'), findsWidgets);
        expect(find.text('Dryer vent · Riverton'), findsNWidgets(2));
        expect(
          find.textContaining(
            'Ad question: Can I get a quote?',
            findRichText: true,
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining('Ad question: Thanks!', findRichText: true),
          findsNothing,
        );
      });
    }
  });
}

const _referralDetail = {
  'referral': {
    'ad_name': 'Autumn sweep',
    'campaign_name': 'Fall leads',
    'page_name': 'Brightside',
  },
  'address': '12 Elm St, Springfield',
  'job_type': 'Chimney repair',
};

Color? _fill(WidgetTester tester) =>
    ((tester.widget<Container>(
              find.descendant(
                of: find.byType(LeadSourceChip),
                matching: find.byType(Container),
              ),
            )).decoration!
            as BoxDecoration)
        .color;

Future<void> _pumpBare(
  WidgetTester tester,
  Widget child, {
  Brightness brightness = Brightness.light,
}) => tester.pumpWidget(
  MaterialApp(
    theme: brightness == Brightness.dark
        ? EzBiziTheme.dark()
        : EzBiziTheme.light(),
    home: Scaffold(body: Center(child: child)),
  ),
);

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

Future<void> _pumpThread(
  WidgetTester tester, {
  required double width,
  required Map<String, dynamic> detail,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/leads/lead-1')) {
        return _json({
          'data': {'id': 'lead-1', 'conversations': const [], ...detail},
        });
      }
      return _json(const {});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadThread(
          api: api,
          lead: const {
            'id': 'lead-1',
            'contact_name': 'Tamsin Brook',
            'phone': '+1 (555) 010-0142',
            'source': 'facebook',
            'status': 'new',
          },
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _row(
  String id,
  String name, {
  String? preview,
  bool ad = false,
}) => {
  'id': id,
  'is_unread': false,
  'can_reply': false,
  'starred': false,
  'contact_name': name,
  'phone': '+1 (555) 010-0100',
  'source': 'whatsapp',
  'status': 'new',
  'received_at': '2026-09-07T09:00:00Z',
  'last_contact_at': '2026-09-07T09:30:00Z',
  'last_contact_channel': 'MESSAGE',
  'last_contact_from': 'CONSUMER',
  'last_contact_preview': preview,
  'last_contact_ad_question': ad,
  'job_type': 'Dryer vent',
  'city': 'Riverton',
};

Future<void> _pumpScreen(
  WidgetTester tester, {
  required double width,
  required List<Map<String, dynamic>> leads,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me});
      }
      if (request.url.path.endsWith('/leads')) {
        return _json({
          'data': leads,
          'meta': {
            'total': leads.length,
            'per_page': 50,
            'current_page': 1,
            'last_page': 1,
            'connections': const [],
            'unread_by_source': const <String, int>{},
          },
        });
      }
      return _json(const {'data': {}});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Pell Ashby',
  'email': 'pell@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['leads.view', 'leads.edit', 'leads.whatsapp.view'],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Brightside', 'parent_company_id': null},
  ],
  'franchises': <Map<String, dynamic>>[],
  'modules': [
    {'key': 'leads', 'label': 'Leads', 'available': true, 'reachable': true},
  ],
};
