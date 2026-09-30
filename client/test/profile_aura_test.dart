import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/identity_card.dart';
import 'package:ezbizi_client/widgets/profile_aura.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-09-30, from A1 Tools: "if stuff was missing or incomplete from
/// ur profile, the profile pic had an effect like a pulsating aura, let's
/// implement that and also the highlighting the missing fields".
void main() {
  group('the aura', () {
    testWidgets('pulses while something is missing, and stops once not', (
      tester,
    ) async {
      Widget aura(bool active) => MaterialApp(
        theme: EzBiziTheme.light(),
        home: Center(
          child: ProfileAura(
            active: active,
            child: const CircleAvatar(radius: 15),
          ),
        ),
      );

      await tester.pumpWidget(aura(true));
      final glow = find.byKey(const ValueKey('profile-aura'));
      expect(glow, findsOneWidget);

      double spread() =>
          (tester.widget<DecoratedBox>(glow).decoration as BoxDecoration)
              .boxShadow!
              .single
              .spreadRadius;
      final at0 = spread();
      await tester.pump(const Duration(milliseconds: 700));
      expect(spread(), isNot(at0), reason: 'it moves');

      await tester.pumpWidget(aura(false));
      expect(glow, findsNothing);
      // Nothing left ticking: a finished profile costs no frames.
      await tester.pumpAndSettle();
    });

    testWidgets('holds still when the platform asks for less motion', (
      tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: MediaQuery(
            data: const MediaQueryData(disableAnimations: true),
            child: const Center(
              child: ProfileAura(active: true, child: CircleAvatar()),
            ),
          ),
        ),
      );

      expect(find.byKey(const ValueKey('profile-aura')), findsOneWidget);
      await tester.pumpAndSettle(); // settles: nothing repeats
    });

    test('says what is missing in the words the boxes use', () {
      expect(profileMissingWords(const []), '');
      expect(profileMissingWords(const ['photo']), 'photo');
      expect(
        profileMissingWords(const ['phone', 'date_of_birth', 'photo']),
        'phone number, date of birth and photo',
      );
    });
  });

  testWidgets("the top bar's picture pulses and says what is missing", (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'profile_missing': ['phone', 'photo'],
            },
          });
        }
        return _json({'message': 'Nothing stubbed.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        // Still, so the shell can settle; the pulse itself is tested above.
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(1400, 900),
            disableAnimations: true,
          ),
          child: HomeShell(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('profile-aura')), findsOneWidget);
    expect(
      find.byWidgetPredicate(
        (w) =>
            w is PopupMenuButton &&
            (w.tooltip ?? '').endsWith(
              'Missing from your profile: phone number and photo',
            ),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the profile lights the boxes that are empty', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/me/profile')) {
          return _json({
            'first_name': 'Ana',
            'middle_name': null,
            'last_name': 'Ruiz',
            'phone': null,
            'address': null,
            'gender': null,
            'date_of_birth': null,
            'has_avatar': false,
          });
        }
        return _json({'message': 'Nothing stubbed.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1200, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(1200, 1000),
            disableAnimations: true,
          ),
          child: Scaffold(
            body: SingleChildScrollView(
              child: IdentityCard(api: api, session: session),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    String? hint(String label) => tester
        .widget<TextField>(find.widgetWithText(TextField, label))
        .decoration
        ?.hintText;

    for (final label in ['Phone number', 'Home address', 'Gender']) {
      expect(hint(label), 'Not filled in', reason: label);
    }
    expect(hint('First name'), isNot('Not filled in'), reason: 'filled in');
    expect(
      hint('Middle name (optional)'),
      isNot('Not filled in'),
      reason: 'most people have none',
    );
    expect(find.text('Date of birth'), findsOneWidget, reason: 'not optional');
    expect(
      find.descendant(
        of: find.byType(CalendarDayField),
        matching: find.text('Not filled in'),
      ),
      findsOneWidget,
      reason: 'the date of birth says so under its boxes',
    );
    expect(
      find.byKey(const ValueKey('profile-aura')),
      findsOneWidget,
      reason: 'no photo',
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Phone number'),
      '4045550100',
    );
    await tester.pump();
    expect(hint('Phone number'), isNot('Not filled in'));
  });
}

const _me = {
  'id': 'u-1',
  'name': 'Ana Ruiz',
  'email': 'ana@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': <dynamic>[],
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
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
