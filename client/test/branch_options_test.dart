import 'package:ezbizi_client/core/branch_options.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-21: a branch picker read "Main" twenty times. Every picker
/// names a branch "Branch · Company" and keeps a company's branches together.
void main() {
  const companies = [
    CompanySummary(
      id: 'co-s',
      name: 'Summit Garage Doors',
      parentCompanyId: null,
    ),
    CompanySummary(
      id: 'co-h',
      name: 'harbor Home Services',
      parentCompanyId: null,
    ),
    // A Sub Company of Summit.
    CompanySummary(id: 'co-p', name: 'Peak Openers', parentCompanyId: 'co-s'),
  ];
  const branches = [
    FranchiseSummary(id: 'f-1', name: 'Main', companyId: 'co-s'),
    FranchiseSummary(id: 'f-2', name: 'Main', companyId: 'co-h'),
    FranchiseSummary(id: 'f-3', name: 'connecticut', companyId: 'co-s'),
    FranchiseSummary(id: 'f-4', name: 'Texas', companyId: 'co-p'),
    FranchiseSummary(id: 'f-5', name: 'Nationwide', companyId: 'co-gone'),
    FranchiseSummary(id: 'f-6', name: 'Alpha', companyId: null),
    FranchiseSummary(
      id: 'f-7',
      name: 'Closed',
      companyId: 'co-s',
      isActive: false,
    ),
  ];

  test('labels Company · Branch, sorted by company then branch', () {
    expect(branchOptions(branches, companies).map((b) => b.label), [
      'harbor Home Services · Main',
      'Peak Openers · Texas',
      'Summit Garage Doors · connecticut',
      'Summit Garage Doors · Main',
      // Company unknown: the name alone, after the named ones.
      'Alpha',
      'Nationwide',
    ]);
  });

  test('ids follow their labels and inactive branches are left out', () {
    expect(branchOptions(branches, companies).map((b) => b.id), [
      'f-2',
      'f-4',
      'f-3',
      'f-1',
      'f-6',
      'f-5',
    ]);
  });

  test('a company limit keeps that company and its Sub Companies', () {
    expect(
      branchOptions(branches, companies, companyId: 'co-s').map((b) => b.label),
      [
        'Peak Openers · Texas',
        'Summit Garage Doors · connecticut',
        'Summit Garage Doors · Main',
      ],
    );
    expect(
      branchOptions(branches, companies, companyId: 'co-h').map((b) => b.id),
      ['f-2'],
    );
  });

  test('a limit to a company not in the list still matches its branches', () {
    expect(
      branchOptions(
        branches,
        companies,
        companyId: 'co-gone',
      ).map((b) => b.label),
      ['Nationwide'],
    );
  });
}
