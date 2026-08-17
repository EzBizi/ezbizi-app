import 'package:ezbizi_client/widgets/structure_levels.dart';
import 'package:flutter_test/flutter_test.dart';

/// One hierarchy, one answer.
///
/// The canvas and the outline both draw the structure and both used to work out
/// a level for themselves — the canvas by counting recursion, the outline by
/// typing a number at four call sites. Both copies carried the same bug, and it
/// took a customer to find it: a company's own place drew level with a sub
/// company of the same company, saying the two are the same size of thing.
///
/// So the level is a fact about WHAT A THING IS, and it lives in one function
/// with no widgets around it. These are the assertions about that function
/// alone. `structure_canvas_test.dart` asserts the canvas lays boxes out by it,
/// `structure_outline_test.dart` asserts the outline indents by it, and one
/// test in the outline file asserts the two agree on every node.
void main() {
  group('a level is what a thing is', () {
    test('the organization is on the first line', () {
      expect(
        structureLevelOf(kind: structureOrganization, isSub: false),
        kStructureLevelOrganization,
      );
    });

    test('a top-level company is on the second', () {
      expect(
        structureLevelOf(kind: structureCompany, isSub: false),
        kStructureLevelCompany,
      );
    });

    test('a sub company is on the third', () {
      expect(
        structureLevelOf(kind: structureCompany, isSub: true),
        kStructureLevelSubCompany,
      );
    });

    /// The owner's fourth line, verbatim: "company franchises AND franchises of
    /// sub companies". This is the assertion the old walk-derived row could not
    /// make, because it answered with how far it had got rather than with what
    /// it was looking at.
    test('a place is on the fourth whichever company owns it', () {
      expect(
        structureLevelOf(kind: structureFranchise, isSub: false),
        kStructureLevelFranchise,
      );
      expect(
        structureLevelOf(kind: structureFranchise, isSub: true),
        kStructureLevelFranchise,
      );
    });

    /// [isSub] is a question about a company and nothing else. A place is a
    /// place; asking it whether it hangs off a sub company is asking about its
    /// parent, and its parent is not what decides its level.
    test('being under a sub company changes nothing about a place', () {
      expect(
        structureLevelOf(kind: structureFranchise, isSub: true),
        structureLevelOf(kind: structureFranchise, isSub: false),
      );
    });

    test('the four lines are four distinct numbers, in the owner’s order', () {
      const lines = [
        kStructureLevelOrganization,
        kStructureLevelCompany,
        kStructureLevelSubCompany,
        kStructureLevelFranchise,
      ];

      expect(lines.toSet(), hasLength(kStructureLevels));

      for (var i = 1; i < lines.length; i++) {
        expect(
          lines[i],
          greaterThan(lines[i - 1]),
          reason: 'The levels are drawn top to bottom in this order, so a '
              'number out of sequence would draw the business upside down.',
        );
      }
    });
  });

  group('the vocabulary is the server’s', () {
    /// These three strings travel: they are what a stored arrangement is keyed
    /// by, and the save endpoint refuses a kind it does not recognise. A typo
    /// here would not be a cosmetic bug — it would be every future save
    /// refused, with nothing on screen a person could do about it.
    test('the three kinds are the three words the payload uses', () {
      expect(structureOrganization, 'organization');
      expect(structureCompany, 'company');
      expect(structureFranchise, 'franchise');
    });

    /// A sub company is a `company`. Not a fourth kind — the model says so with
    /// `parent_company_id` rather than with a type, and a fourth word here
    /// would be the client inventing a distinction the business does not make.
    test('there is no fourth kind for a sub company', () {
      expect(
        {structureOrganization, structureCompany, structureFranchise},
        hasLength(3),
      );
    });

    /// This is a value that arrives from the network. A level function that
    /// threw on a word it did not know would take the whole structure screen
    /// down over one entry nobody can correct from inside the app.
    test('a kind nobody recognises is drawn rather than thrown on', () {
      expect(
        structureLevelOf(kind: 'something else entirely', isSub: false),
        kStructureLevelCompany,
      );
    });
  });
}
