import 'package:ezbizi_client/core/branch_options.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/scope_choice_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// UI queue 129, options 1 and 4: "Where it applies" as one list grouped by
/// company. Every name here is invented.
void main() {
  const companies = [
    CompanySummary(id: 'c-b', name: 'Birchwood Heating', parentCompanyId: null),
    CompanySummary(
      id: 'c-q',
      name: 'Quillfield Roofing',
      parentCompanyId: null,
    ),
    CompanySummary(id: 'c-d', name: 'Dellmar Plumbing', parentCompanyId: null),
  ];
  const branches = [
    FranchiseSummary(id: 'f-b1', name: 'Main', companyId: 'c-b'),
    FranchiseSummary(id: 'f-b2', name: 'Vermont', companyId: 'c-b'),
    FranchiseSummary(
      id: 'f-b3',
      name: 'Ozark',
      companyId: 'c-b',
      isActive: false,
    ),
    FranchiseSummary(id: 'f-q1', name: 'Main', companyId: 'c-q'),
    FranchiseSummary(id: 'f-q2', name: 'Lakeshore', companyId: 'c-q'),
    // Dellmar has one branch: one row, no lone "Main".
    FranchiseSummary(id: 'f-d1', name: 'Main', companyId: 'c-d'),
  ];

  Map<String, String> places({
    bool organization = true,
    bool companies = true,
    bool franchises = true,
  }) => {
    if (organization) 'organization': 'Everywhere in this business',
    if (companies)
      for (final id in ['c-b', 'c-q', 'c-d']) 'company:$id': 'server $id',
    if (franchises)
      for (final f in branches) 'franchise:${f.id}': 'server ${f.id}',
  };

  List<List<String?>> shape(List<ScopeGroup> groups) => [
    for (final g in groups) [for (final r in g.rows) r.value],
  ];

  test('organization, then each company over its branches, by name', () {
    final groups = scopeGroups(places(), companies, branches);

    expect(shape(groups), [
      ['organization'],
      ['company:c-b', 'franchise:f-b1', 'franchise:f-b3', 'franchise:f-b2'],
      ['company:c-d'],
      ['company:c-q', 'franchise:f-q2', 'franchise:f-q1'],
    ]);
    expect(groups[1].head.title, 'Birchwood Heating');
    expect(groups[1].head.trailing, '3 branches');
    expect(groups[1].branches.map((r) => r.title), [
      'Main',
      'Ozark',
      'Vermont',
    ]);
    expect(groups[1].branches.every((r) => r.indented), isTrue);
    // Switched off, and still offered because the caller offers it.
    expect(groups[1].branches[1].off, isTrue);
    expect(groups[1].branches[1].selectable, isTrue);
  });

  test('a company with one branch is one row that picks the company', () {
    final single = scopeGroups(places(), companies, branches)[2];

    expect(single.head.title, 'Dellmar Plumbing');
    expect(single.head.trailing, '1 branch');
    expect(single.branches, isEmpty);
    expect(single.head.value, 'company:c-d');
    expect(single.head.covers, {'company:c-d', 'franchise:f-d1'});
  });

  test(
    'branches only: companies are headers, the single row is the branch',
    () {
      final groups = scopeGroups(
        places(organization: false, companies: false),
        companies,
        branches,
      );

      expect(shape(groups), [
        [null, 'franchise:f-b1', 'franchise:f-b3', 'franchise:f-b2'],
        ['franchise:f-d1'],
        [null, 'franchise:f-q2', 'franchise:f-q1'],
      ]);
      expect(groups[0].head.selectable, isFalse);
    },
  );

  test('companies only: no branch rows', () {
    final groups = scopeGroups(
      places(organization: false, franchises: false),
      companies,
      branches,
    );

    expect(shape(groups), [
      ['company:c-b'],
      ['company:c-d'],
      ['company:c-q'],
    ]);
    expect(groups[0].head.trailing, '3 branches');
  });

  test('search keeps a matching branch under its company header', () {
    expect(shape(scopeGroups(places(), companies, branches, term: 'lake')), [
      ['company:c-q', 'franchise:f-q2'],
    ]);
    // A company that matches keeps all its branches.
    expect(shape(scopeGroups(places(), companies, branches, term: 'birch')), [
      ['company:c-b', 'franchise:f-b1', 'franchise:f-b3', 'franchise:f-b2'],
    ]);
    // "Main" is a branch of all three.
    expect(
      shape(
        scopeGroups(places(), companies, branches, term: 'main'),
      ).map((g) => g.first),
      ['company:c-b', 'company:c-d', 'company:c-q'],
    );
  });

  test('a place the reach does not name keeps the label it came with', () {
    final groups = scopeGroups(
      {'franchise:f-x': 'Somewhere — Elsewhere Co'},
      companies,
      branches,
    );

    expect(groups.single.head.title, 'Somewhere — Elsewhere Co');
    expect(groups.single.head.value, 'franchise:f-x');
  });

  test(
    'a chosen scope is named Company · Branch or Company (all branches)',
    () {
      expect(
        scopeLabel('organization', companies, branches),
        'Entire organization',
      );
      expect(
        scopeLabel('company:c-q', companies, branches),
        'Quillfield Roofing (all branches)',
      );
      expect(
        scopeLabel('franchise:f-q2', companies, branches),
        'Quillfield Roofing · Lakeshore',
      );
      expect(scopeLabel('franchise:nope', companies, branches), isNull);
    },
  );

  Future<List<String>> pumpField(
    WidgetTester tester, {
    required Map<String, String> offered,
    String? value,
    Size size = const Size(1200, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final chosen = <String>[];
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Padding(
            padding: const EdgeInsets.all(16),
            child: ScopeChoiceField(
              key: const ValueKey('where'),
              value: value,
              label: 'Where it applies',
              places: offered,
              companies: companies,
              branches: branches,
              onChanged: chosen.add,
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.byKey(const ValueKey('where')));
    await tester.pumpAndSettle();
    return chosen;
  }

  testWidgets('the panel draws the groups with a divider between each', (
    tester,
  ) async {
    await pumpField(tester, offered: places());

    final list = find.byKey(const Key('scope-choice-list'));
    expect(
      find.descendant(of: list, matching: find.byType(Divider)),
      findsNWidgets(3),
    );
    expect(find.text('Entire organization'), findsOneWidget);
    expect(find.text('3 branches'), findsOneWidget);
    expect(find.text('2 branches'), findsOneWidget);
    expect(find.text('1 branch'), findsOneWidget);
    expect(find.text('off'), findsOneWidget);

    // Top to bottom in the order the groups say.
    double top(String key) =>
        tester.getTopLeft(find.byKey(ValueKey('scope-choice-$key'))).dy;
    expect(top('organization'), lessThan(top('company:c-b')));
    expect(top('company:c-b'), lessThan(top('franchise:f-b1')));
    expect(top('franchise:f-b2'), lessThan(top('company:c-d')));
    expect(top('company:c-d'), lessThan(top('company:c-q')));
    // Indented under the company: the branch name starts right of the
    // company name.
    expect(
      tester.getTopLeft(find.text('Vermont')).dx,
      greaterThan(tester.getTopLeft(find.text('Birchwood Heating')).dx),
    );
  });

  testWidgets('choosing a company sends company scope and names it', (
    tester,
  ) async {
    final chosen = await pumpField(tester, offered: places());

    await tester.tap(find.byKey(const ValueKey('scope-choice-company:c-q')));
    await tester.pumpAndSettle();

    expect(chosen, ['company:c-q']);
    expect(find.byType(ScopeChoicePanel), findsNothing);
  });

  testWidgets('the current choice is highlighted and the field names it', (
    tester,
  ) async {
    await pumpField(tester, offered: places(), value: 'franchise:f-q2');

    expect(find.text('Quillfield Roofing · Lakeshore'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('scope-choice-franchise:f-q2')),
        matching: find.byIcon(Icons.check),
      ),
      findsOneWidget,
    );
    expect(find.byIcon(Icons.check), findsOneWidget);
  });

  testWidgets('search keeps the company header above a matching branch', (
    tester,
  ) async {
    await pumpField(tester, offered: places());

    await tester.enterText(
      find.byKey(const Key('scope-choice-search')),
      'verm',
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('scope-choice-company:c-b')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('scope-choice-franchise:f-b2')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('scope-choice-franchise:f-b1')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('scope-choice-company:c-q')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('scope-choice-organization')),
      findsNothing,
    );
  });

  testWidgets('branches only: a company header cannot be chosen', (
    tester,
  ) async {
    final chosen = await pumpField(
      tester,
      offered: places(organization: false, companies: false),
    );

    expect(find.text('Entire organization'), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('scope-choice-head-Birchwood Heating')),
    );
    await tester.pumpAndSettle();
    expect(chosen, isEmpty);
    expect(find.byType(ScopeChoicePanel), findsOneWidget);

    // The single-branch company row is that branch.
    await tester.tap(find.byKey(const ValueKey('scope-choice-franchise:f-d1')));
    await tester.pumpAndSettle();
    expect(chosen, ['franchise:f-d1']);
  });

  testWidgets('on a phone it fills the screen, with a way back', (
    tester,
  ) async {
    final chosen = await pumpField(
      tester,
      offered: places(),
      size: const Size(390, 844),
    );

    expect(tester.takeException(), isNull);
    expect(tester.getSize(find.byType(ScopeChoicePanel)).width, 390);
    expect(find.byTooltip('Back'), findsOneWidget);
    expect(find.text('Quillfield Roofing'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('scope-choice-franchise:f-b2')));
    await tester.pumpAndSettle();
    expect(chosen, ['franchise:f-b2']);
    expect(tester.takeException(), isNull);
  });
}
