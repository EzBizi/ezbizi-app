import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';

/// Filing follows the current view, not every company on the account.
void main() {
  test('a company view does not include sibling companies', () {
    final me = _me(
      scope: {
        'type': 'company',
        'id': 'co-a1',
        'label': 'A-1 Chimney',
        'path': ['A-1 Chimney'],
      },
    );

    expect(me.companiesInView.map((c) => c.name).toList(), ['A-1 Chimney']);
    expect(me.franchisesInView.map((f) => f.name).toList(), ['Connecticut']);
    expect(me.offersCompanyChoice, isFalse);
    expect(me.offersFranchiseChoice, isTrue);
  });

  test('a parent company view includes its sub companies', () {
    final me = _me(
      scope: {
        'type': 'company',
        'id': 'co-ever',
        'label': 'Everdoors',
        'path': ['Everdoors'],
      },
    );

    expect(me.companiesInView.map((c) => c.name).toList(), [
      'Everdoors',
      'Premier Garage Repair',
      'Speedy Garage Doors',
    ]);
    expect(me.offersCompanyChoice, isTrue);
    expect(
      me.franchisesInView.map((f) => f.name).toList(),
      containsAll(['Ohio', 'Georgia']),
    );
    expect(
      me.franchisesInView.map((f) => f.name).toList(),
      isNot(contains('Connecticut')),
    );
  });

  test('a franchise view is that one place', () {
    final me = _me(
      scope: {
        'type': 'franchise',
        'id': 'fr-a1',
        'label': 'Connecticut',
        'path': ['A-1 Chimney', 'Connecticut'],
      },
    );

    expect(me.companiesInView.map((c) => c.name).toList(), ['A-1 Chimney']);
    expect(me.franchisesInView.map((f) => f.id).toList(), ['fr-a1']);
    expect(me.offersCompanyChoice, isFalse);
    expect(me.offersFranchiseChoice, isFalse);
  });

  test('the group view still offers every reachable company', () {
    final me = _me(
      scope: {
        'type': 'organization',
        'id': 'org-1',
        'label': 'EzBizi',
        'path': ['EzBizi'],
      },
    );

    expect(me.companiesInView.length, 5);
    expect(me.offersCompanyChoice, isTrue);
    expect(me.franchisesInView.length, 3);
  });
}

Me _me({required Map<String, dynamic> scope}) => Me.fromJson({
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'organization': {
    'id': 'org-1',
    'name': 'EzBizi',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': scope,
  'companies': _companies,
  'franchises': _franchises,
});

const _companies = [
  {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
  {
    'id': 'co-premier',
    'name': 'Premier Garage Repair',
    'parent_company_id': 'co-ever',
  },
  {
    'id': 'co-speedy',
    'name': 'Speedy Garage Doors',
    'parent_company_id': 'co-ever',
  },
  {'id': 'co-ez', 'name': 'EzBizi', 'parent_company_id': null},
];

const _franchises = [
  {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
  {'id': 'fr-ever', 'name': 'Ohio', 'company_id': 'co-ever'},
  {'id': 'fr-premier', 'name': 'Georgia', 'company_id': 'co-premier'},
];
