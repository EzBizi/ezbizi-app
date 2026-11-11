import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Your account, at a desk and in a hand.
///
/// These are two different designs rather than one at two widths, which is the
/// point of the pair and the thing a test can actually hold still: a desktop
/// answers "show me everything about my account", a phone answers "what do you
/// want to change?".
void main() {
  testWidgets('a wide window puts the facts beside the form, not inside it', (
    tester,
  ) async {
    await _pump(tester, size: const Size(1280, 900));

    // The facts card exists and carries its own heading.
    expect(find.text('Assigned to you. An administrator changes these in '
        'Settings.'), findsOneWidget);

    // And it is NOT under the form's Save button, which is the defect this
    // split exists for: email, role and organization sat directly above Save,
    // which said Save applied to them. It never did -- an administrator
    // changes every one of them in Settings.
    final save = find.widgetWithText(FilledButton, 'Save');
    expect(save, findsOneWidget);
    final saveCard = find.ancestor(of: save, matching: find.byType(Card));
    expect(
      find.descendant(of: saveCard, matching: find.text('Organization')),
      findsNothing,
      reason:
          'The read-only facts must not sit inside the card that has a Save '
          'button, or Save appears to apply to them.',
    );
  });

  testWidgets('a repeated place says which company it belongs to', (
    tester,
  ) async {
    await _pump(tester, size: const Size(1280, 900));

    // Two companies each operate a Connecticut. Unqualified, this list reads
    // "Connecticut, DMV, Georgia, Connecticut" and looks like the same place
    // four times -- and a list that appears to repeat itself is one nobody
    // trusts the rest of, when it is the answer to "where may I work".
    expect(find.text('Connecticut · North'), findsOneWidget);
    expect(find.text('Connecticut · South'), findsOneWidget);

    // The unambiguous one is left alone. Qualifying every chip would double
    // the width of a list that is usually clear as it stands.
    expect(find.text('Georgia'), findsOneWidget);
    expect(find.text('Georgia · North'), findsNothing);
  });

  testWidgets('a phone gets rows that show their values, not the form', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 780));

    // The form's Save is absent, because the form is not what a phone gets.
    expect(find.widgetWithText(FilledButton, 'Save'), findsNothing);

    // Each row states what it currently says, so most visits end without
    // opening anything -- you came to check the number was right.
    expect(find.text('Phone number'), findsOneWidget);
    expect(
      find.text('+1 (305) 724-2199'),
      findsOneWidget,
      reason:
          'The row is being READ. +13057242199 is not a number anybody reads '
          'back, so this one is formatted even though the field it opens is '
          'not.',
    );

    // An empty value says so. A blank is indistinguishable from a row that
    // failed to load, and this list is mostly read rather than tapped.
    expect(find.text('Not given'), findsWidgets);

    // But a row that opens something holds no value to be missing: Password
    // said "Not given" to somebody who had just signed in with one.
    expect(
      find.descendant(
        of: find.widgetWithText(ListTile, 'Password'),
        matching: find.text('Not given'),
      ),
      findsNothing,
    );
  });
  // Phone audit 2026-11-04: beside a long address the label was drawn one
  // letter per line.
  testWidgets('on a phone a long address sits under its label', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(390, 780),
      address: '1200 Brightside Avenue, Apartment 14B, Springfield, IL 62701',
    );

    expect(tester.getSize(find.text('Home address')).height, lessThan(30));
    expect(
      tester.getTopLeft(find.textContaining('1200 Brightside')).dy,
      greaterThan(tester.getTopLeft(find.text('Home address')).dy),
    );
  });

  testWidgets('on a phone the API keys row says how many there are', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 780));
    // The last group; the list builds only what is on the screen.
    await tester.scrollUntilVisible(
      find.widgetWithText(ListTile, 'API keys'),
      200,
      scrollable: find.byType(Scrollable).first,
    );

    expect(
      find.descendant(
        of: find.widgetWithText(ListTile, 'API keys'),
        matching: find.text('1'),
      ),
      findsOneWidget,
    );
  });

  // The same pair for API keys (UI queue 62). Its own test: it sits below the
  // fold at both widths, and scrolling to it would move the list the controls
  // above are checked in.
  testWidgets('API keys can be reached at both widths', (tester) async {
    for (final size in const [Size(1280, 900), Size(390, 780)]) {
      await _pump(tester, size: size);
      await tester.scrollUntilVisible(
        find.textContaining('API keys', findRichText: true).first,
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        find.textContaining('API keys', findRichText: true),
        findsWidgets,
        reason: 'no way to reach API keys at ${size.width}px',
      );
    }
  });

  /// Every control on this screen has to exist at BOTH widths.
  ///
  /// "Text me my codes" was added to the phone layout alone and was missing
  /// from the desktop one for a day. Nothing failed — the narrow tests passed,
  /// the card rendered perfectly where it was, and a desk simply had no such
  /// control. It was found by opening the real screen in a browser, which is
  /// not a thing that happens on a schedule.
  ///
  /// So the assertion is the pair, not either one. Checking only the width you
  /// happen to be thinking about is how the defect got in.
  testWidgets('the account screen offers the same controls at both widths', (
    tester,
  ) async {
    for (final size in const [Size(1280, 900), Size(390, 780)]) {
      await _pump(tester, size: size);

      expect(
        find.textContaining('Text me my codes', findRichText: true),
        findsWidgets,
        reason: 'no way to reach the text-codes setting at ${size.width}px',
      );

      expect(
        find.textContaining('Two-factor', findRichText: true),
        findsWidgets,
        reason: 'no way to reach two-factor at ${size.width}px',
      );
    }
  });
}


Future<void> _pump(
  WidgetTester tester, {
  required Size size,
  String address = '',
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': <String>[]},
        });
      }
      if (path.endsWith('/me/sessions')) {
        return _json({
          'sessions': [
            {
              'id': 's-1',
              'label': 'This browser',
              'kind': 'web',
              'current': true,
              'ip': '10.0.0.1',
              'last_used_at': null,
            },
          ],
        });
      }
      if (path.endsWith('/me/api-keys')) {
        return _json({
          'api_keys': [
            {
              'id': 3,
              'name': 'Weekly numbers script',
              'level': 'viewer',
              'effective_level': 'viewer',
              'last_used_at': null,
              'created_at': '2026-10-02T15:00:00Z',
            },
          ],
        });
      }
      if (path.endsWith('/me/profile')) {
        return _json({
          'first_name': 'Jose',
          'middle_name': '',
          'last_name': 'Demasi',
          'phone': '+13057242199',
          'address': address,
          'gender': 'Male',
          'has_avatar': false,
          'date_of_birth': '1994-04-14',
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ProfileScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Luis Demasi',
  'email': 'jose@ezbizi.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'North', 'parent_company_id': null},
    {'id': 'co-2', 'name': 'South', 'parent_company_id': null},
  ],
  // Connecticut twice, under two different companies. Two real places that
  // share a name, not one place listed twice.
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
    {'id': 'fr-2', 'name': 'Georgia', 'company_id': 'co-1'},
    {'id': 'fr-3', 'name': 'Connecticut', 'company_id': 'co-2'},
  ],
};
