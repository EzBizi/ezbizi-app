import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/structure_outline.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// The structure on a phone, and with a keyboard.
///
/// ── The fixture is the real shape, on purpose ────────────────────
///
/// This organization operates FOUR places called Connecticut and TWO called
/// DMV, each under a different company, and it did so within a day of the
/// second company existing. That is not an edge case somebody imagined for a
/// test — it is why `ViewScope::path` exists on the server, and it is the thing
/// an indented outline gets wrong by default: four rows, identical, at the same
/// indent, with the parent that would tell them apart scrolled off the top.
///
/// So every assertion below about a place names the whole row rather than the
/// place, and the one test that matters most is the one that would pass against
/// a beautiful outline nobody could actually use.
void main() {
  group('the outline draws the whole tree', () {
    testWidgets('one row for the organization, each company, each sub company '
        'and each place', (tester) async {
      await _pump(tester);

      expect(find.text('Everdoors Group'), findsOneWidget);
      expect(find.text('Everdoors'), findsOneWidget);
      expect(find.text('Premier Garage Repair'), findsOneWidget);
      expect(find.text('Sunward Overhead Doors'), findsOneWidget);
      expect(find.text('Sunward Commercial'), findsOneWidget);

      expect(
        find.text('Connecticut'),
        findsNWidgets(4),
        reason:
            'Four companies each operate one. An outline that showed three has '
            'lost a place; one that showed one has collapsed four.',
      );
      expect(find.text('DMV'), findsNWidgets(2));
    });

    testWidgets('a sub company sits under its company, not beside it', (
      tester,
    ) async {
      await _pump(tester);

      // The indent is the relationship: what a sub company inherits resolves
      // through the company above it before it reaches the organization, and a
      // row drawn level with its parent says the opposite.
      expect(
        _indentOf(
          tester,
          'Premier Garage Repair — Sub company in Everdoors, '
          'switched off',
        ),
        greaterThan(_indentOf(tester, 'Everdoors — Company')),
      );
      expect(
        _indentOf(
          tester,
          'Connecticut — Territory in Everdoors › Premier '
          'Garage Repair',
        ),
        greaterThan(
          _indentOf(
            tester,
            'Premier Garage Repair — Sub company in Everdoors, '
            'switched off',
          ),
        ),
      );
    });

    testWidgets('every level is said in words as well as drawn as an indent', (
      tester,
    ) async {
      await _pump(tester);

      // Colour and position are the two things a reader may not have. The
      // caption is the same fact in a form they do.
      expect(find.text('The whole business'), findsOneWidget);
      expect(find.text('Company'), findsNWidgets(2));
      expect(find.text('Sub company in Everdoors'), findsOneWidget);
      expect(
        find.text('Sub company in Sunward Overhead Doors'),
        findsOneWidget,
      );
    });

    testWidgets('a broken entry costs its own row and nothing else', (
      tester,
    ) async {
      await _pump(tester, tree: _brokenTree);

      expect(
        tester.takeException(),
        isNull,
        reason:
            'This is JSON off the wire. A screen that throws on it is a screen '
            'nobody can open and nobody can fix from inside the app.',
      );
      expect(
        find.text('Sunward Overhead Doors'),
        findsOneWidget,
        reason: 'One unreadable company must not take the next one with it.',
      );
      expect(
        find.text('(unnamed)'),
        findsOneWidget,
        reason:
            'A company with a broken name still exists, and dropping it would '
            'drop its places too — the business would quietly shrink on the '
            'one screen whose job is to show all of it.',
      );
      expect(
        find.text('Connecticut'),
        findsOneWidget,
        reason: 'The place under the unnamed company is still its place.',
      );
    });
  });

  group('four places called Connecticut', () {
    testWidgets('are four different rows, each naming the company it belongs '
        'to', (tester) async {
      final semantics = tester.ensureSemantics();
      await _pump(tester);

      // The premise, asserted first: the NAME genuinely does not tell them
      // apart. Without this the test below could pass against an outline that
      // simply had one Connecticut in it.
      expect(find.text('Connecticut'), findsNWidgets(4));

      // Each row, on screen, says which company. Four rows, four sentences,
      // none of them the same as another.
      const rows = [
        'Connecticut — Territory in Everdoors',
        'Connecticut — Territory in Everdoors › Premier Garage Repair',
        'Connecticut — Territory in Sunward Overhead Doors',
        'Connecticut — Territory in Sunward Overhead Doors › Sunward Commercial',
      ];

      for (final row in rows) {
        expect(
          find.bySemanticsLabel(row),
          findsOneWidget,
          reason: 'No row says "$row", so that place is unidentifiable.',
        );
      }

      expect(
        rows.toSet(),
        hasLength(4),
        reason: 'The four sentences have to differ from each other.',
      );

      // And it is on the screen, not only in the semantics tree. A caption a
      // sighted person cannot see is four identical rows to them.
      expect(
        find.text('Territory in Everdoors › Premier Garage Repair'),
        findsOneWidget,
      );
      expect(
        find.text('Territory in Sunward Overhead Doors › Sunward Commercial'),
        findsOneWidget,
      );

      semantics.dispose();
    });

    testWidgets('and the two called DMV are told apart the same way', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await _pump(tester);

      expect(find.text('DMV'), findsNWidgets(2));
      expect(
        find.bySemanticsLabel('DMV — Territory in Everdoors'),
        findsOneWidget,
      );
      expect(
        find.bySemanticsLabel(
          'DMV — Territory in Sunward Overhead Doors, switched off',
        ),
        findsOneWidget,
      );

      semantics.dispose();
    });

    testWidgets('with the whole path readable on the screen it is drawn for', (
      tester,
    ) async {
      await _pump(tester);

      // Not merely present in the widget tree — actually laid out, whole, at
      // 375px. The tail of the path is the half that disambiguates, so a
      // caption cut short is a caption that has answered nothing: at one line
      // both of these read "Territory in Sunward Overhead…".
      for (final caption in const [
        'Territory in Everdoors › Premier Garage Repair',
        'Territory in Sunward Overhead Doors › Sunward Commercial',
      ]) {
        expect(
          tester
              .renderObject<RenderParagraph>(find.text(caption))
              .didExceedMaxLines,
          isFalse,
          reason: '"$caption" is truncated on the screen it exists for.',
        );
      }
    });

    testWidgets('even when the company names are far too long for the screen', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await _pump(tester, tree: _longNamedTree);

      // The eye gets two lines and an ellipsis if it needs one; the sentence a
      // screen reader is handed is never truncated, so the answer to "which
      // Connecticut is this" survives at any width.
      expect(
        find.bySemanticsLabel(
          'Connecticut — Territory in Everdoors Residential and Commercial '
          'Garage Door Services of the Greater Northeast › Premier Garage '
          'Repair of Southern Connecticut',
        ),
        findsOneWidget,
      );

      semantics.dispose();
    });
  });

  group('at 375px', () {
    testWidgets('nothing runs off the side of the screen', (tester) async {
      await _pump(tester, tree: _longNamedTree);

      expect(
        tester.takeException(),
        isNull,
        reason:
            'A RenderFlex overflow here is the yellow-and-black bar on a '
            'phone, and it takes the rest of the page with it.',
      );

      _expectNothingWiderThanTheScreen(tester, 375);
    });

    testWidgets('and nothing runs off it in dark either', (tester) async {
      await _pump(tester, tree: _longNamedTree, theme: EzBiziTheme.dark());

      expect(tester.takeException(), isNull);

      _expectNothingWiderThanTheScreen(tester, 375);
    });

    testWidgets('nor when somebody has turned their text size up', (
      tester,
    ) async {
      // The row is two lines of text beside an icon, so the size everything is
      // drawn at is not this file's to assume. A fixed row height is the usual
      // way this breaks.
      await _pump(tester, tree: _longNamedTree, textScale: 2.0);

      expect(tester.takeException(), isNull);

      _expectNothingWiderThanTheScreen(tester, 375);
    });
  });

  group('a switched-off company', () {
    testWidgets('says so in words, not only in grey and a line through it', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await _pump(tester);

      expect(find.text('switched off'), findsNWidgets(2));
      expect(
        find.bySemanticsLabel(
          'Premier Garage Repair — Sub company in Everdoors, switched off',
        ),
        findsOneWidget,
        reason:
            'Grey text and a strike-through are the two things a screen reader '
            'cannot see. Without the clause it reads as an ordinary company.',
      );

      semantics.dispose();
    });
  });

  group('the keyboard', () {
    testWidgets('reaches the outline in one Tab and leaves it in one more', (
      tester,
    ) async {
      final before = FocusNode(debugLabel: 'before');
      final after = FocusNode(debugLabel: 'after');
      addTearDown(before.dispose);
      addTearDown(after.dispose);

      await _pump(tester, before: before, after: after);

      before.requestFocus();
      await tester.pump();

      await _press(tester, LogicalKeyboardKey.tab);
      expect(
        _focused(),
        'organization:org-1',
        reason: 'Tab enters the outline at the row the cursor is on.',
      );

      await _press(tester, LogicalKeyboardKey.tab);
      expect(
        _focused(),
        'after',
        reason:
            'One stop for the whole outline. A stop per row means the control '
            'after it is fifteen presses away in this fixture and sixty in a '
            'real organization.',
      );
    });

    testWidgets('walks the rows with the arrow keys, in tree order', (
      tester,
    ) async {
      final before = FocusNode(debugLabel: 'before');
      addTearDown(before.dispose);

      await _pump(tester, before: before);

      before.requestFocus();
      await tester.pump();
      await _press(tester, LogicalKeyboardKey.tab);

      await _press(tester, LogicalKeyboardKey.arrowDown);
      expect(_focused(), 'company:co-1');

      await _press(tester, LogicalKeyboardKey.arrowDown);
      expect(_focused(), 'franchise:f-1');

      await _press(tester, LogicalKeyboardKey.arrowUp);
      expect(_focused(), 'company:co-1');

      await _press(tester, LogicalKeyboardKey.home);
      expect(_focused(), 'organization:org-1');

      await _press(tester, LogicalKeyboardKey.arrowUp);
      expect(
        _focused(),
        'organization:org-1',
        reason:
            'The top is the top. It must not wrap to the bottom and it must '
            'not hand the key to the scroll view underneath.',
      );
    });

    testWidgets('brings the row it moved to into view', (tester) async {
      final before = FocusNode(debugLabel: 'before');
      addTearDown(before.dispose);

      // Short on purpose: the bottom of this structure is a long way below the
      // fold, which is the state a keyboard user is in on a phone.
      await _pump(tester, before: before, size: const Size(375, 320));

      before.requestFocus();
      await tester.pump();
      await _press(tester, LogicalKeyboardKey.tab);
      await _press(tester, LogicalKeyboardKey.end);
      await tester.pumpAndSettle();

      expect(_focused(), 'franchise:f-6');

      final row = tester.getRect(
        find.bySemanticsLabel(
          'Connecticut — Territory in Sunward Overhead Doors › Sunward '
          'Commercial',
        ),
      );

      expect(
        row.top,
        greaterThanOrEqualTo(-0.5),
        reason: 'Focus does not scroll anything by itself.',
      );
      expect(
        row.bottom,
        lessThanOrEqualTo(320.5),
        reason:
            'The cursor walked off the bottom of the window: the row has '
            'focus and nobody can see it.',
      );
    });

    testWidgets('shows which row it is on', (tester) async {
      final before = FocusNode(debugLabel: 'before');
      addTearDown(before.dispose);

      await _pump(tester, before: before);

      expect(
        _ringedRows(tester),
        0,
        reason: 'Nothing is focused yet — the control assertion for below.',
      );

      before.requestFocus();
      await tester.pump();
      await _press(tester, LogicalKeyboardKey.tab);

      expect(
        _ringedRows(tester),
        1,
        reason:
            'A cursor nobody can see is a cursor nobody can use. Exactly one '
            'row wears the ring, and it is drawn from the theme rather than '
            'from a colour of its own.',
      );
    });

    testWidgets('reaches the control a caller puts on the row it is on, and '
        'not the ones on every other row', (tester) async {
      final before = FocusNode(debugLabel: 'before');
      final after = FocusNode(debugLabel: 'after');
      addTearDown(before.dispose);
      addTearDown(after.dispose);

      await _pump(
        tester,
        before: before,
        after: after,
        trailing: (context, row) => row.kind == structureCompanyKind
            ? IconButton(
                focusNode: FocusNode(debugLabel: 'menu:${row.id}'),
                icon: const Icon(Icons.more_horiz),
                tooltip: 'What to do with ${row.name}',
                onPressed: () {},
              )
            : null,
      );

      before.requestFocus();
      await tester.pump();

      // Row 0 is the organization, which this caller gives no control to.
      await _press(tester, LogicalKeyboardKey.tab);
      expect(_focused(), 'organization:org-1');

      await _press(tester, LogicalKeyboardKey.arrowDown);
      expect(_focused(), 'company:co-1');

      await _press(tester, LogicalKeyboardKey.tab);
      expect(
        _focused(),
        'menu:co-1',
        reason: 'The row the cursor is on offers its own control to Tab.',
      );

      await _press(tester, LogicalKeyboardKey.tab);
      expect(
        _focused(),
        'after',
        reason:
            'And the four menus on the rows below it are not in the way. '
            'Marking the row skippable has to mark its contents skippable too.',
      );
    });
  });

  group('the organization’s own word for a place', () {
    testWidgets('is what the rows say — §8', (tester) async {
      await _pump(tester, franchiseLabel: 'Territory');

      expect(find.text('Territory in Everdoors'), findsNWidgets(2));
      expect(
        find.textContaining('Franchise'),
        findsNothing,
        reason:
            '"Franchise" is the one noun this product lets a customer replace, '
            'and this view is nothing but nouns.',
      );
    });

    testWidgets('verbatim, including its capitals', (tester) async {
      await _pump(tester, franchiseLabel: 'DMA');

      expect(
        find.text('DMA in Everdoors'),
        findsNWidgets(2),
        reason:
            'It is their word. Lower-casing it to make a sentence read better '
            'damages an acronym and any proper noun.',
      );
    });
  });

  group('an empty structure', () {
    testWidgets('says so rather than showing an empty screen', (tester) async {
      await _pump(
        tester,
        tree: const {
          'organization': {'id': 'org-1', 'name': 'Everdoors Group'},
          'companies': <Map<String, dynamic>>[],
        },
      );

      expect(find.text('Everdoors Group'), findsOneWidget);
      expect(find.textContaining('No companies yet'), findsOneWidget);
    });
  });
}

// ── driving it ─────────────────────────────────────────────────────

/// 375px unless a test says otherwise, because that is the width the outline
/// exists for. A test that only ever ran at desktop width would pass against
/// every layout bug this widget was written to avoid.
Future<void> _pump(
  WidgetTester tester, {
  Map<String, dynamic>? tree,
  String franchiseLabel = 'Territory',
  Size size = const Size(375, 812),
  ThemeData? theme,
  double textScale = 1.0,
  FocusNode? before,
  FocusNode? after,
  Widget? Function(BuildContext, StructureOutlineRow)? trailing,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final outline = StructureOutline(
    tree: tree ?? _tree,
    franchiseLabel: franchiseLabel,
    trailing: trailing,
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: theme ?? EzBiziTheme.light(),
      home: MediaQuery(
        data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
        child: Scaffold(
          body: Column(
            children: [
              // Something to Tab from and something to Tab to, so "the outline
              // is one stop" is a claim about the tab order rather than about
              // an outline sitting alone on a page.
              if (before != null)
                TextButton(
                  focusNode: before,
                  onPressed: () {},
                  child: const Text('before'),
                ),
              Expanded(child: outline),
              if (after != null)
                TextButton(
                  focusNode: after,
                  onPressed: () {},
                  child: const Text('after'),
                ),
            ],
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _press(WidgetTester tester, LogicalKeyboardKey key) async {
  await tester.sendKeyEvent(key);
  await tester.pump();
}

/// Which row the keyboard is on, by the key the outline gives its focus nodes.
String? _focused() => FocusManager.instance.primaryFocus?.debugLabel;

/// Where a row's leading glyph starts — the indent, measured.
double _indentOf(WidgetTester tester, String label) => tester
    .getTopLeft(
      find
          .descendant(
            of: find.bySemanticsLabel(label),
            matching: find.byType(Icon),
          )
          .first,
    )
    .dx;

/// How many rows are wearing the focus ring.
int _ringedRows(WidgetTester tester) {
  final primary = EzBiziTheme.light().colorScheme.primary;

  return tester.widgetList<DecoratedBox>(find.byType(DecoratedBox)).where((
    box,
  ) {
    final decoration = box.decoration;

    return decoration is BoxDecoration &&
        decoration.border?.top.color == primary;
  }).length;
}

/// Nothing is drawn outside the window — measured, not eyeballed.
///
/// A `RenderFlex` overflow throws and is caught by `takeException`, but that is
/// only the loudest way to be too wide. A row that is clipped, or one inside a
/// box that happens not to complain, is just as unusable and says nothing. This
/// walks what was actually laid out and checks both edges.
void _expectNothingWiderThanTheScreen(WidgetTester tester, double width) {
  for (final element in tester.allElements) {
    final object = element.renderObject;

    if (object is! RenderBox || !object.attached || !object.hasSize) continue;

    final left = object.localToGlobal(Offset.zero).dx;
    final right = object.localToGlobal(Offset(object.size.width, 0)).dx;

    expect(
      right,
      lessThanOrEqualTo(width + 0.5),
      reason:
          'A ${element.widget.runtimeType} ends at $right on a $width screen.',
    );
    expect(
      left,
      greaterThanOrEqualTo(-0.5),
      reason:
          'A ${element.widget.runtimeType} starts at $left, off the left edge.',
    );
  }
}

// ── the fixture ────────────────────────────────────────────────────

Map<String, dynamic> _franchise(String id, String name, {bool active = true}) =>
    {'id': id, 'name': name, 'is_active': active, 'may_edit': true};

/// Everdoors Group, as it actually is.
///
/// Four Connecticuts and two DMVs, spread across two companies and two sub
/// companies, plus one company and one place that are switched off.
final Map<String, dynamic> _tree = {
  'organization': {'id': 'org-1', 'name': 'Everdoors Group', 'may_edit': true},
  'companies': [
    {
      'id': 'co-1',
      'name': 'Everdoors',
      'is_active': true,
      'parent_company_id': null,
      'may_edit': true,
      'franchises': [
        _franchise('f-1', 'Connecticut'),
        _franchise('f-2', 'DMV'),
      ],
      'sub_companies': [
        {
          'id': 'co-2',
          'name': 'Premier Garage Repair',
          'is_active': false,
          'parent_company_id': 'co-1',
          'may_edit': true,
          'franchises': [_franchise('f-3', 'Connecticut')],
        },
      ],
    },
    {
      'id': 'co-3',
      'name': 'Sunward Overhead Doors',
      'is_active': true,
      'parent_company_id': null,
      'may_edit': true,
      'franchises': [
        _franchise('f-4', 'Connecticut'),
        _franchise('f-5', 'DMV', active: false),
      ],
      'sub_companies': [
        {
          'id': 'co-4',
          'name': 'Sunward Commercial',
          'is_active': true,
          'parent_company_id': 'co-3',
          'may_edit': true,
          'franchises': [_franchise('f-6', 'Connecticut')],
        },
      ],
    },
  ],
};

/// The same shape with names nobody would fit on a phone.
final Map<String, dynamic> _longNamedTree = {
  'organization': {'id': 'org-1', 'name': 'Everdoors Group Holdings Limited'},
  'companies': [
    {
      'id': 'co-1',
      'name':
          'Everdoors Residential and Commercial Garage Door Services of the '
          'Greater Northeast',
      'is_active': false,
      'franchises': [
        _franchise(
          'f-1',
          'Connecticut, Rhode Island and Western Massachusetts',
        ),
      ],
      'sub_companies': [
        {
          'id': 'co-2',
          'name': 'Premier Garage Repair of Southern Connecticut',
          'is_active': true,
          'franchises': [_franchise('f-3', 'Connecticut')],
        },
      ],
    },
  ],
};

/// What a half-broken payload looks like.
final Map<String, dynamic> _brokenTree = {
  'organization': 'nonsense',
  'companies': [
    'nonsense',
    {
      'id': 'co-1',
      // No name at all.
      'is_active': true,
      'franchises': [_franchise('f-1', 'Connecticut')],
      'sub_companies': 'nonsense',
    },
    {
      'id': 'co-3',
      'name': 'Sunward Overhead Doors',
      'is_active': true,
      'franchises': <Map<String, dynamic>>[],
      'sub_companies': <Map<String, dynamic>>[],
    },
  ],
};
