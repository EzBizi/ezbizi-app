import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/organization_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Organization tab's List view: the tree on the left and the picked node
/// on the right (UI queue 111, layout 1 and phone 4).
///
/// Every name and number here is invented.
void main() {
  testWidgets('the tree shows the companies and their branches, with people', (
    tester,
  ) async {
    await _pump(tester, _server());

    for (final (kind, id, name, people) in const [
      ('organization', _org, 'Brightside Group', '48'),
      ('company', _home, 'Brightside Home Services', '31'),
      ('franchise', _spring, 'Springfield', '12'),
      ('franchise', _river, 'Riverton', '9'),
      ('company', _commercial, 'Brightside Commercial', '7'),
      ('franchise', _oak, 'Oakdale', '7'),
      ('company', _garage, 'Brightside Garage Doors', '17'),
      ('franchise', _lake, 'Lakeside', '17'),
    ]) {
      final row = find.byKey(ValueKey('org-tree-$kind-$id'));

      expect(row, findsOneWidget, reason: '$name is missing from the tree.');
      expect(
        find.descendant(of: row, matching: find.text(name)),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row, matching: find.text(people)),
        findsOneWidget,
        reason: '$name’s people count is the server’s, on its own row.',
      );
    }

    // The indent is the relationship: a sub company sits further in than the
    // company it belongs to, and its branch further in again.
    double left(String key) => tester
        .getTopLeft(
          find.descendant(
            of: find.byKey(ValueKey(key)),
            matching: find.byType(Icon),
          ),
        )
        .dx;

    expect(
      left('org-tree-company-$_commercial'),
      greaterThan(left('org-tree-company-$_home')),
    );
    expect(
      left('org-tree-franchise-$_oak'),
      greaterThan(left('org-tree-company-$_commercial')),
    );
  });

  testWidgets('picking a branch shows its numbers and its actions', (
    tester,
  ) async {
    final renames = <String>[];

    await _pump(tester, _server(patches: renames));
    await _pick(tester, 'franchise', _spring);

    final page = find.byKey(const ValueKey('org-detail'));

    expect(
      find.descendant(of: page, matching: find.text('Springfield')),
      findsNWidgets(2),
      reason: 'Its name on the page, and in the field that renames it there.',
    );
    expect(find.byKey(const ValueKey('org-franchise-name')), findsOneWidget);
    expect(
      find.descendant(of: page, matching: find.text('Franchise')),
      findsOneWidget,
      reason: 'The pill says what it is, in the organization’s own word.',
    );
    expect(_stat(tester, 'people'), '12 people');
    expect(_stat(tester, 'numbers'), '2 phone numbers');
    expect(_stat(tester, 'connections'), '3 connections');
    expect(
      find.byKey(const ValueKey('org-stat-places')),
      findsNothing,
      reason: 'A branch has no branches under it to count.',
    );
    expect(
      find.descendant(
        of: page,
        matching: find.text('Part of Brightside Home Services'),
      ),
      findsOneWidget,
    );

    final rename = _button(tester, 'Rename');
    final off = _button(tester, 'Switch off');

    expect(rename.onPressed, isNotNull);
    expect(off.onPressed, isNotNull);

    await tester.tap(find.text('Switch off'));
    await tester.pumpAndSettle();

    expect(renames, hasLength(1));
    expect(
      renames.single,
      endsWith('/franchises/$_spring {"is_active":false}'),
    );
  });

  testWidgets('a company’s page counts its branches and refuses what it may '
      'not do, saying why', (tester) async {
    await _pump(tester, _server(mayAddSub: false));
    await _pick(tester, 'company', _home);

    expect(
      _stat(tester, 'places'),
      '3 franchises',
      reason: 'Its own two and its sub company’s one.',
    );
    expect(_stat(tester, 'people'), '31 people');
    expect(_button(tester, 'Edit').onPressed, isNotNull);
    expect(_button(tester, 'Add a franchise').onPressed, isNotNull);
    expect(_button(tester, 'Add a sub company').onPressed, isNull);
    expect(
      find.byTooltip('Only somebody assigned at Brightside Group can do that.'),
      findsOneWidget,
    );
    // Edited where it is read since 2026-10-20 (Jose: "most of the
    // editable stuff just in the main screen").
    expect(find.widgetWithText(TextField, '12 Main St'), findsOneWidget);
    expect(find.widgetWithText(TextField, '(555) 010-0142'), findsOneWidget);
    expect(find.byKey(const ValueKey('org-company-save')), findsOneWidget);
  });

  // Jose, 2026-10-20: edit on the page itself, with what is empty marked.
  testWidgets('a company is edited on its page and saved from there', (
    tester,
  ) async {
    final patches = <String>[];
    await _pump(tester, _server(patches: patches));
    await _pick(tester, 'company', _home);

    final save = find.byKey(const ValueKey('org-company-save'));
    expect(
      tester.widget<FilledButton>(save).onPressed,
      isNull,
      reason: 'Nothing changed yet.',
    );

    await tester.enterText(
      find.byKey(const ValueKey('org-company-phone')),
      '(555) 010-0199',
    );
    await tester.pump();
    await tester.tap(save);
    await tester.pumpAndSettle();

    expect(
      patches.where((p) => p.startsWith('PATCH /api/v1/companies/$_home ')),
      hasLength(1),
    );
    expect(patches.last, contains('"phone":"(555) 010-0199"'));
  });

  testWidgets('the settings set at exactly that node are listed', (
    tester,
  ) async {
    final asked = <String>[];

    await _pump(tester, _server(settingsAsked: asked));
    await _pick(tester, 'company', _home);

    // The real editors for that place, on its Settings tab, opened on
    // "Set here" (Jose, 2026-10-20: they "aren't there to set").
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('org-page-tabs')),
        matching: find.text('Settings'),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked, contains('company:$_home'));
    expect(find.text('Start of day'), findsOneWidget);
    expect(find.text('Reply within'), findsOneWidget);
    expect(
      find.text('Currency'),
      findsNothing,
      reason: 'Inherited from the organization, so not set here.',
    );
    expect(
      find.text('Timezone'),
      findsNothing,
      reason: 'Nothing chose it anywhere, so not set here either.',
    );
  });

  testWidgets('the search filters the tree and keeps a match where it sits', (
    tester,
  ) async {
    await _pump(tester, _server());

    await tester.enterText(find.byKey(const ValueKey('org-search')), 'river');
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('org-tree-franchise-$_river')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('org-tree-company-$_home')),
      findsOneWidget,
      reason: 'Riverton’s company stays, so the match is shown where it is.',
    );
    for (final key in const [
      'org-tree-franchise-$_spring',
      'org-tree-company-$_commercial',
      'org-tree-franchise-$_oak',
      'org-tree-company-$_garage',
      'org-tree-franchise-$_lake',
    ]) {
      expect(find.byKey(ValueKey(key)), findsNothing, reason: key);
    }

    // A company found by name keeps everything under it.
    await tester.enterText(find.byKey(const ValueKey('org-search')), 'garage');
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('org-tree-company-$_garage')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('org-tree-franchise-$_lake')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('org-tree-company-$_home')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('org-search')), 'zzz');
    await tester.pumpAndSettle();

    expect(find.textContaining('is called “zzz”'), findsOneWidget);
  });

  testWidgets('on a narrow tab the tree is alone and a node opens its page', (
    tester,
  ) async {
    await _pump(tester, _server(), size: const Size(375, 900));

    expect(find.byKey(const ValueKey('org-tree')), findsOneWidget);
    expect(find.byKey(const ValueKey('org-detail')), findsNothing);

    await _pick(tester, 'franchise', _river);

    expect(find.byKey(const ValueKey('org-tree')), findsNothing);
    expect(find.byKey(const ValueKey('org-detail')), findsOneWidget);
    expect(_stat(tester, 'people'), '9 people');

    await tester.tap(find.byKey(const ValueKey('org-detail-back')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('org-tree')), findsOneWidget);
    expect(find.byKey(const ValueKey('org-detail')), findsNothing);
  });

  testWidgets('without counts from the server, no number is invented', (
    tester,
  ) async {
    await _pump(tester, _server(counts: false));

    // The same row draws '31' when the server sends it (the first test).
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('org-tree-company-$_home')),
        matching: find.text('31'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('org-tree')),
        matching: find.textContaining(RegExp(r'^\d+$')),
      ),
      findsNothing,
      reason: 'No people count on any row when the server sent none.',
    );

    await _pick(tester, 'company', _home);

    expect(
      _stat(tester, 'places'),
      '3 franchises',
      reason: 'The one number this side can count from the tree itself.',
    );
    for (final kind in const ['people', 'numbers', 'connections']) {
      expect(
        find.byKey(ValueKey('org-stat-$kind')),
        findsNothing,
        reason: 'Not sent, so not drawn — a 0 would be a claim.',
      );
    }

    await _pick(tester, 'franchise', _spring);

    expect(
      find.byKey(const ValueKey('org-detail-numbers')),
      findsNothing,
      reason: 'A branch has nothing to count without the server.',
    );
  });
}

// ── the fixture ────────────────────────────────────────────────────

const _org = '00000000-0000-4000-8000-000000000101';
const _home = '00000000-0000-4000-8000-000000000102';
const _commercial = '00000000-0000-4000-8000-000000000103';
const _garage = '00000000-0000-4000-8000-000000000104';
const _spring = '00000000-0000-4000-8000-000000000105';
const _river = '00000000-0000-4000-8000-000000000106';
const _oak = '00000000-0000-4000-8000-000000000107';
const _lake = '00000000-0000-4000-8000-000000000108';

Map<String, dynamic>? _counts(bool on, int people, [int n = 0, int c = 0]) =>
    on ? {'people': people, 'numbers': n, 'connections': c} : null;

Map<String, dynamic> _tree({required bool counts, required bool mayAddSub}) => {
  'organization': {
    'id': _org,
    'name': 'Brightside Group',
    'may_add_company': true,
    'may_add_company_reason': null,
    'has_logo': false,
    'may_edit': false,
    'may_arrange': true,
    'may_arrange_reason': null,
    'counts': ?_counts(counts, 48, 9, 14),
  },
  'companies': [
    _company(
      _home,
      'Brightside Home Services',
      counts: _counts(counts, 31, 6, 9),
      mayAddSub: mayAddSub,
      franchises: [
        _place(_spring, 'Springfield', _counts(counts, 12, 2, 3)),
        _place(_river, 'Riverton', _counts(counts, 9, 1, 1)),
      ],
      subs: [
        _company(
          _commercial,
          'Brightside Commercial',
          parent: _home,
          counts: _counts(counts, 7),
          franchises: [_place(_oak, 'Oakdale', _counts(counts, 7))],
        ),
      ],
    ),
    _company(
      _garage,
      'Brightside Garage Doors',
      counts: _counts(counts, 17),
      franchises: [_place(_lake, 'Lakeside', _counts(counts, 17))],
    ),
  ],
  'canvas': {'orientation': 'left_to_right', 'boxes': <Object>[]},
};

Map<String, dynamic> _company(
  String id,
  String name, {
  String? parent,
  Map<String, dynamic>? counts,
  bool mayAddSub = true,
  List<Map<String, dynamic>> franchises = const [],
  List<Map<String, dynamic>> subs = const [],
}) => {
  'id': id,
  'name': name,
  'is_active': true,
  'parent_company_id': parent,
  'address_line1': id == _home ? '12 Main St' : null,
  'city': id == _home ? 'Springfield' : null,
  'state': id == _home ? 'IL' : null,
  'postal_code': id == _home ? '62701' : null,
  'phone': id == _home ? '(555) 010-0142' : null,
  'may_edit': true,
  'may_add_franchise': true,
  'may_add_franchise_reason': null,
  'may_add_sub_company': parent == null && mayAddSub,
  'may_add_sub_company_reason': mayAddSub
      ? null
      : 'Only somebody assigned at Brightside Group can do that.',
  'may_reparent': true,
  'may_reparent_reason': null,
  'has_logo': false,
  'counts': ?counts,
  'franchises': franchises,
  'sub_companies': subs,
};

Map<String, dynamic> _place(
  String id,
  String name,
  Map<String, dynamic>? counts,
) => {
  'id': id,
  'name': name,
  'is_active': true,
  'timezone': 'America/Chicago',
  'currency': 'USD',
  'may_edit': true,
  'counts': ?counts,
};

// ── the server ─────────────────────────────────────────────────────

Future<http.Response> Function(http.Request) _server({
  bool counts = true,
  bool mayAddSub = true,
  List<String>? patches,
  List<String>? settingsAsked,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Dana Reyes',
          'email': 'dana@example.com',
          'has_avatar': false,
          'permissions': const ['companies.view'],
          'preferences': const <String, dynamic>{},
          'organization': {
            'id': _org,
            'name': 'Brightside Group',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': _org,
            'label': 'Brightside Group',
            'path': const ['Brightside Group'],
          },
          'companies': const <Map<String, dynamic>>[],
          'franchises': const <Map<String, dynamic>>[],
        },
      });
    }

    if (request.method == 'PATCH') {
      patches?.add('PATCH $path ${request.body}');

      return _json({'data': <String, dynamic>{}});
    }

    if (path.endsWith('/companies')) {
      return _json(_tree(counts: counts, mayAddSub: mayAddSub));
    }

    if (path.endsWith('/settings')) {
      final type = request.url.queryParameters['scope_type'];
      final id = request.url.queryParameters['scope_id'];

      settingsAsked?.add('$type:$id');

      return _json({
        'data': [
          if (type == 'company') ...[
            _setting('work.start', 'Start of day', {
              'name': 'Brightside Home Services',
              'inherited': false,
            }),
            _setting('leads.reply', 'Reply within', {
              'name': 'Brightside Home Services',
              'inherited': false,
            }),
          ],
          _setting('money.currency', 'Currency', {
            'name': 'Brightside Group',
            'inherited': type != 'organization',
          }),
          _setting('time.zone', 'Timezone', null),
        ],
      });
    }

    if (path.contains('/branding/')) {
      return _json({'message': 'No logo.'}, 404);
    }

    return _json({'message': 'Nothing stubbed $path.'}, 500);
  };
}

Map<String, dynamic> _setting(
  String key,
  String label,
  Map<String, dynamic>? source,
) => {
  // The whole row the Settings tab draws, now that the page edits it.
  'key': key,
  'label': label,
  'type': 'string',
  'group': 'general',
  'group_label': 'General',
  'value': '',
  'is_sensitive': false,
  'options': null,
  'help': null,
  'not_yet_in_effect': null,
  'editable_here': true,
  'may_edit': true,
  'may_edit_reason': null,
  'allowed_scopes': ['organization', 'company', 'franchise'],
  'source': source == null ? null : {'type': 'company', 'id': '', ...source},
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

// ── driving it ─────────────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  Future<http.Response> Function(http.Request) handler, {
  Size size = const Size(1400, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OrganizationScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pick(WidgetTester tester, String kind, String id) async {
  await tester.tap(find.byKey(ValueKey('org-tree-$kind-$id')));
  await tester.pumpAndSettle();
}

/// A number tile's value and label, as one string.
String _stat(WidgetTester tester, String kind) => tester
    .widgetList<Text>(
      find.descendant(
        of: find.byKey(ValueKey('org-stat-$kind')),
        matching: find.byType(Text),
      ),
    )
    .map((t) => t.data)
    .join(' ');

ButtonStyleButton _button(WidgetTester tester, String label) =>
    tester.widget<ButtonStyleButton>(
      find
          .ancestor(
            of: find.descendant(
              of: find.byKey(const ValueKey('org-detail')),
              matching: find.text(label),
            ),
            matching: find.byWidgetPredicate((w) => w is ButtonStyleButton),
          )
          .first,
    );
