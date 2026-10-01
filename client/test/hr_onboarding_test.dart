import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/contracts.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/hr/onboarding_form.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/screens/hr/onboarding_tab.dart';
import 'package:ezbizi_client/screens/hr_screen.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:ezbizi_client/screens/hr/records_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Onboarding — the form both sides fill in, and the thing every other piece of
/// Human Resources was built for.
///
/// ── What is actually being defended here ─────────────────────────
///
/// Six things, and they fail in different ways.
///
/// The first is that WHO MAY WRITE WHAT is the server's answer and not a rule
/// re-implemented in Dart. The two fixtures below are the same onboarding seen
/// by the two sides, and they differ only in `writable` — so a client working it
/// out from `filled_by` and a guess at which side is looking would have to
/// disagree with one of them. On the screen where disagreeing means a new
/// starter being asked to set their own wage.
///
/// The second is that a masked answer is never echoed back. The automations
/// screen once saved `••••••••` over a working webhook secret; here the same bug
/// would replace somebody's bank account with eight dots. So the box starts
/// empty, says why, and the save is asserted field by field.
///
/// The third is that revealing is an ACT: a separate request, drawn as a button
/// rather than as an eye icon, with the sentence saying it is recorded.
///
/// The fourth is that the signing step cannot be completed by pressing one
/// thing. ESIGN wants consent given rather than assumed and an affirmative act;
/// the button stays dead until both have happened.
///
/// The fifth is save-and-resume. Somebody filling in a bank account will stop
/// halfway, and what decides whether they come back is whether the screen said
/// their first half survived — with a number, not a promise.
///
/// The sixth is that NONE of the vocabulary is compiled in. The fixture's kinds
/// of question are labelled in words nobody would guess, its `filled_by` words
/// are "Head office" and "The new starter", and its common core contains
/// `shoe_size`. A screen showing "The employer" or a core field this fixture
/// never mentioned is a screen reading a list in Dart.
void main() {
  // ── who may write what ─────────────────────────────────────────

  testWidgets('the employer fills in their half and can see, but not touch, '
      'the other', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployer));

    expect(find.text('YOURS TO FILL IN'), findsOneWidget);
    expect(find.text('NOT YOURS TO ANSWER'), findsOneWidget);

    expect(_box(tester, 'hourly_wage').enabled, isTrue);

    // Theirs. Drawn, so the employer can see what is still outstanding, and
    // dead, because it is not their answer to give.
    expect(_box(tester, 'bank_account').enabled, isFalse);
    expect(_dropdown(tester, 'shirt_size').onChanged, isNull);

    // And it says whose it is, in the SERVER's word for that side.
    expect(find.text('The new starter'), findsWidgets);
  });

  testWidgets('the employee fills in theirs, and sees what the employer owes', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    expect(_box(tester, 'bank_account').enabled, isTrue);
    expect(_dropdown(tester, 'shirt_size').onChanged, isNotNull);

    // The wage is the employer's and stays theirs — but it is on the page, so a
    // wage nobody has set is visibly outstanding rather than invisible.
    expect(_box(tester, 'hourly_wage').enabled, isFalse);
    expect(find.text('Hourly wage *'), findsOneWidget);
    expect(find.text('Head office'), findsWidgets);
  });

  /// The owner's reason for `either` existing: the employer may pre-fill a date
  /// of birth and get it wrong, and correcting it on receipt should be ordinary
  /// rather than a support request.
  testWidgets('a question marked for either side is live for both, and says '
      'who filled it in', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployer));

    expect(_day(tester, 'date_of_birth').enabled, isTrue);
    expect(find.text('Either of you'), findsWidgets);

    await _pumpForm(tester, _handler(form: _asEmployee));

    expect(_day(tester, 'date_of_birth').enabled, isTrue);

    // Provenance, which is the whole point: without it "the employer typed it"
    // and "I confirmed it" are the same fact.
    expect(
      find.textContaining('Filled in by Jose Silva'),
      findsWidgets,
      reason:
          'An answer with no author cannot be corrected with any confidence — '
          'and for a bank account that difference is the feature.',
    );
  });

  // ── save and resume ────────────────────────────────────────────

  testWidgets('a half-filled form comes back with its answers, and says where '
      'it got to', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    // The values the server already holds, in the boxes, not lost.
    expect(_box(tester, 'hourly_wage').controller!.text, '22.50');
    expect(_day(tester, 'date_of_birth').controller.text, '1990-02-11');

    // Counted, because "your progress is saved" with no number behind it is
    // what every form says and nobody believes.
    expect(find.textContaining('4 of 7 answered'), findsOneWidget);
    expect(find.textContaining('Last answered'), findsOneWidget);
    expect(find.textContaining('do not have to finish this in one go'),
        findsOneWidget);
  });

  testWidgets('saving sends the answers that changed and nothing else', (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pumpForm(
      tester,
      _handler(form: _asEmployee, onSave: (body) => saved = body),
    );

    // Untouched, and the Save is dead until something is.
    expect(_saveButton(tester).onPressed, isNull);

    await _type(tester, 'shirt_size_note', 'Prefers a jacket');
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(saved, {
      'answers': {'shirt_size_note': 'Prefers a jacket'},
    });

    // The date of birth was on screen with a value in it and was not sent. A
    // form that PATCHed everything it was holding would rewrite the other
    // side's answers under this person's name.
    expect((saved!['answers'] as Map).containsKey('date_of_birth'), isFalse);
  });

  // ── sensitive ──────────────────────────────────────────────────

  testWidgets('a sensitive answer is masked, says why, and is never sent back '
      'as the mask', (tester) async {
    Map<String, dynamic>? saved;

    await _pumpForm(
      tester,
      _handler(form: _asEmployee, onSave: (body) => saved = body),
    );

    // The box is EMPTY. That is what makes it impossible to save eight dots
    // over a bank account.
    expect(_box(tester, 'bank_account').controller!.text, '');

    // The mask is printed exactly once, and it is the server's string rather
    // than one composed here — this end never spells a mask.
    expect(find.text('••••••••'), findsOneWidget);
    expect(
      find.textContaining('Leave this blank to keep it as it is'),
      findsOneWidget,
      reason: 'An empty box over a stored answer reads as unanswered.',
    );

    // The sentence that makes somebody willing to type a bank account in.
    expect(find.textContaining('encrypted where it is stored'), findsOneWidget);
    expect(
      find.textContaining('recorded against their name'),
      findsOneWidget,
      reason: 'Who can see it, and what happens when they look.',
    );

    // Save something else, and the masked field must not travel.
    await _type(tester, 'shirt_size_note', 'A note');
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect((saved!['answers'] as Map).containsKey('bank_account'), isFalse);
  });

  testWidgets('revealing is a separate act, and says it is recorded', (
    tester,
  ) async {
    Map<String, dynamic>? revealed;

    await _pumpForm(
      tester,
      _handler(form: _asEmployee, onReveal: (body) => revealed = body),
    );

    expect(find.text('Revealing it is recorded against your name.'),
        findsOneWidget);

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Reveal'));

    // One field, one request — the server refuses a bulk reveal for the same
    // reason, and a client that asked for the whole record would make the audit
    // trail unable to say which value somebody actually looked at.
    expect(revealed, {'field_key': 'bank_account'});

    // Into the BOX, so it can be corrected rather than only stared at — and
    // printed nowhere else, because the same secret twice on one screen is one
    // more place for it to be read over somebody's shoulder.
    expect(
      _box(tester, 'bank_account').controller!.text,
      'GB29 NWBK 6016 1331 9268 19',
    );
    expect(find.text('GB29 NWBK 6016 1331 9268 19'), findsOneWidget);
    expect(find.textContaining('recorded against your name'), findsWidgets);
  });

  testWidgets('a revealed answer is not queued as a change', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Reveal'));

    // Reading a value is not changing it. A reveal that queued a write would
    // rewrite the provenance of an answer somebody only looked at.
    expect(_saveButton(tester).onPressed, isNull);
    expect(find.text('Everything on this page is saved.'), findsOneWidget);
  });

  // ── signing ────────────────────────────────────────────────────

  testWidgets('signing needs the consent AND the affirmative act', (
    tester,
  ) async {
    Map<String, dynamic>? signed;

    await _pumpForm(
      tester,
      _handler(form: _asEmployee, onSign: (body) => signed = body),
    );

    // The document is on the page, not behind a link. What they are agreeing to
    // is the thing whose fingerprint the signature record keeps.
    expect(find.textContaining('CONTRACT OF EMPLOYMENT'), findsOneWidget);

    // Shown exactly as it is stored, and said so. The renderer produces markup
    // and this box does not tidy it: the hash sent back is over these bytes, so
    // a neatened version would be a picture of a document nobody signed.
    expect(find.textContaining('kept as text/html'), findsOneWidget);

    // And that it is still a preview — answering another question changes it.
    expect(
      find.textContaining('Nothing has been signed yet, so this is rendered'),
      findsOneWidget,
    );

    // Both statements are the server's words, verbatim — they are stored on the
    // signature record as the evidence of what somebody was shown.
    expect(
      find.text(
        'I agree to sign this contract electronically rather than on paper.',
      ),
      findsOneWidget,
    );
    expect(
      find.text('Typing my name below signs this contract in my own hand.'),
      findsOneWidget,
    );

    expect(_signButton(tester).onPressed, isNull);
    expect(
      find.textContaining('Agree to sign electronically, and type your name'),
      findsOneWidget,
    );

    // Consent alone is not a signature.
    await _tap(tester, find.byType(CheckboxListTile));
    expect(_signButton(tester).onPressed, isNull);
    expect(find.text('Type your name above to sign.'), findsOneWidget);

    // And a name alone is not consent — ESIGN wants it given, not assumed.
    await _tap(tester, find.byType(CheckboxListTile));
    await _type(tester, null, 'Bea Okafor', label: 'Type your full name to sign');
    expect(
      _signButton(tester).onPressed,
      isNull,
      reason: 'Consent to transact electronically has to be a thing they DO.',
    );

    await _tap(tester, find.byType(CheckboxListTile));
    expect(_signButton(tester).onPressed, isNotNull);

    await _tap(tester, find.widgetWithText(FilledButton, 'Sign'));

    // The fingerprint of the document this screen displayed goes back with the
    // act. The server refuses without it, and a hash computed here rather than
    // received would be evidence of nothing.
    expect(signed, {
      'field_key': 'employee_signature',
      'consent': true,
      'affirmation': 'Bea Okafor',
      'document_hash': _hash,
    });
  });

  testWidgets('a signature that is not yours is shown and not offered', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployer));

    // The employer is not the one signing this one. It is still drawn, because
    // "what is this document waiting for" is the question an unsigned contract
    // raises.
    expect(find.text('Signature of the new starter'), findsOneWidget);
    expect(find.textContaining('not yours to sign'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Sign'), findsNothing);
  });

  testWidgets('with no document and no wording, signing is refused rather than '
      'improvised', (tester) async {
    await _pumpForm(tester, _handler(form: _signingWithNothingInIt));

    expect(
      find.textContaining('will not compose them itself'),
      findsOneWidget,
      reason:
          'What somebody is shown is what the signature record says they were '
          'shown. A sentence written in Dart would be evidence of something '
          'that did not happen.',
    );
    expect(find.widgetWithText(FilledButton, 'Sign'), findsNothing);
  });

  // ── refusals ───────────────────────────────────────────────────

  testWidgets('a refused save keeps what was typed and says why', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee, saveStatus: 422));

    await _type(tester, 'shirt_size_note', 'Prefers a jacket');
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(find.text('That answer is longer than the field allows.'),
        findsOneWidget);

    // Still in the box, and still queued. Clearing the pending answers over a
    // refusal would leave the text on screen and nothing behind it, so the next
    // Save would send nothing and report success over a form never written.
    expect(_box(tester, 'shirt_size_note').controller!.text, 'Prefers a jacket');
    expect(_saveButton(tester).onPressed, isNotNull);
    expect(find.text('1 answer not saved yet.'), findsOneWidget);
  });

  /// A record nobody may write draws no Save bar, and a refused reveal used to
  /// have nowhere to report — the only note on the page lived inside that bar.
  testWidgets('a refused reveal reports even where there is nothing to save', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asRecord, revealStatus: 403));

    expect(find.widgetWithText(FilledButton, 'Save'), findsNothing);

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Reveal'));

    expect(
      find.text('You may not see this value in the clear.'),
      findsOneWidget,
      reason:
          'A refusal with nowhere to appear is indistinguishable from a button '
          'that does nothing.',
    );
  });

  // ── the finished record ────────────────────────────────────────

  testWidgets('a finished record reads as one, signature and all', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asRecord));

    // One list, not two halves: with nothing writable there is no "yours".
    expect(find.text('THE ANSWERS'), findsOneWidget);
    expect(find.text('YOURS TO FILL IN'), findsNothing);
    expect(find.text('Nothing here is waiting on you. This is the record as it '
        'stands.'), findsOneWidget);

    // When it happened, which the status tag alone cannot carry.
    expect(find.textContaining('Completed 2026-08-12'), findsOneWidget);

    // The signature, as evidence rather than as a control: the label captured
    // at the moment of signing, and what they typed to sign.
    expect(find.text('Signed'), findsOneWidget);
    expect(
      find.textContaining('Bea Okafor <bea@example.com>'),
      findsOneWidget,
      reason: 'The signer is what the record says, not a name looked up now.',
    );
    expect(find.text('Signed by typing: Bea Okafor'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Sign'), findsNothing);

    // And the sensitive answer is still masked here, with the reveal still an
    // audited act. This is the screen where getting that wrong costs the most.
    expect(find.text('••••••••'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Reveal'), findsOneWidget);
  });

  // ── nothing compiled in ────────────────────────────────────────

  /// §2.1, and the test the whole feature turns on. The fixture's core is
  /// `shoe_size`, its type labels are nobody's guess, and its words for the two
  /// sides are "Head office" and "The new starter".
  testWidgets('the questions, the core and the words for both sides come from '
      'the server', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    // A common core no Dart list could have produced. The plan names six keys
    // and this fixture names one, which is not one of them.
    expect(find.text('Shoe size'), findsOneWidget);
    expect(
      find.textContaining('date of birth'),
      findsWidgets,
      reason: 'The contract asked for one; the CORE in this fixture did not.',
    );

    // Core identity lives on the profile, and writing it here writes it there.
    expect(
      find.textContaining('changing it here changes it everywhere'),
      findsOneWidget,
    );

    // A kind of question this build has never met is asked rather than dropped
    // from a form whose whole job is to be complete.
    expect(
      find.textContaining('does not know how “duration” is meant to be '),
      findsOneWidget,
    );
    expect(_box(tester, 'notice_period').enabled, isTrue);
  });

  testWidgets('a core question the employer may not touch says why', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployer));

    expect(_box(tester, 'shoe_size').enabled, isFalse);
    expect(
      find.text('It is theirs. Core identity belongs to the person it is about.'),
      findsOneWidget,
      reason: 'A greyed box with no reason beside it reads as a fault.',
    );
  });

  // ── the employer's list ────────────────────────────────────────

  testWidgets('the list says who is in flight and what is waiting on whom', (
    tester,
  ) async {
    await _pumpTab(tester, _handler());

    expect(find.text('Bea Okafor'), findsOneWidget);
    expect(find.text('In progress'), findsOneWidget);
    expect(find.text('Version 3'), findsOneWidget);

    // How far along, and then both sides' counts rather than one verdict word,
    // in the server's names for the sides: an onboarding stuck because nobody
    // set the wage and one stuck because the new starter has not opened it are
    // different problems with different owners.
    expect(
      find.text(
        '4 answered · 2 still with Head office · 1 still with The new starter',
      ),
      findsOneWidget,
    );

    // And the one that is done says so instead of counting to zero.
    expect(find.textContaining('Completed'), findsWidgets);

    // In flight can be stopped; a signed agreement cannot.
    expect(find.text('Cancel'), findsOneWidget);
  });

  testWidgets('an in-progress onboarding can be cancelled', (tester) async {
    var cancelled = false;
    await _pumpTab(tester, _handler(onCancel: () => cancelled = true));

    await _tap(tester, find.text('Cancel'));
    expect(find.text('Cancel this onboarding?'), findsOneWidget);

    await _tap(tester, find.text('Keep it'));
    expect(cancelled, isFalse);

    await _tap(tester, find.text('Cancel'));
    await _tap(tester, find.text('Cancel onboarding'));
    expect(cancelled, isTrue);
  });

  testWidgets('a reader who may not start one cannot cancel one either', (
    tester,
  ) async {
    await _pumpTab(tester, _handler(), permissions: const ['hr.view']);

    final cancel = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Cancel'),
    );
    expect(cancel.onPressed, isNull);
  });

  testWidgets('starting one picks the person and the contract, and opens it', (
    tester,
  ) async {
    Map<String, dynamic>? started;

    await _pumpTab(tester, _handler(onStart: (body) => started = body));

    await _tap(tester, find.text('Start an onboarding'));

    expect(_startButton(tester).onPressed, isNull);

    await _choose(
      tester,
      'Who is this for',
      'Kit Weaver (kit@example.com)',
    );

    expect(_startButton(tester).onPressed, isNotNull);

    await _tap(tester, find.widgetWithText(FilledButton, 'Start'));

    // An invitation and a contract, which is the whole of what the endpoint
    // takes: an invitation is how somebody arrives, and it already carries the
    // role and the place they are arriving into. The contract was chosen for
    // them, because there is only one and one choice is not a choice.
    expect(started, {'invitation_id': 'i-1', 'contract_id': 'c-1'});

    // Straight into the form: somebody who has just chosen a person and a
    // contract is here to fill in their half.
    expect(find.text('YOURS TO FILL IN'), findsOneWidget);
  });

  testWidgets('somebody already being onboarded is marked, not silently '
      'offered again', (tester) async {
    await _pumpTab(tester, _handler());

    await _tap(tester, find.text('Start an onboarding'));

    final subjects = _dropdownIn(tester, 'Who is this for');

    // Bea's invitation already carries onboarding o-1.
    expect(
      subjects.options.firstWhere((o) => o.value == 'i-2').enabled,
      isFalse,
    );
    expect(
      subjects.options.map((o) => o.label),
      contains('Bea Okafor (bea@example.com) — already onboarding'),
    );

    // One nobody has accepted is a valid subject: the employer can fill in the
    // wage before the person has an account at all.
    expect(
      subjects.options.map((o) => o.label),
      contains('Kit Weaver (kit@example.com)'),
    );

    // Withdrawn and lapsed are the two the server can never accept, so they are
    // not offered at all.
    expect(subjects.options.map((o) => o.value), isNot(contains('i-3')));
  });

  testWidgets('a reader who may not start one is told that, not shown an error',
      (tester) async {
    await _pumpTab(tester, _handler(), permissions: const ['hr.view']);

    expect(
      find.text(
        'You can see who is being taken on, but not start one yourself.',
      ),
      findsOneWidget,
    );

    // And the list is still there. Not being allowed to add to it does not make
    // it unreadable.
    expect(find.text('Bea Okafor'), findsOneWidget);
  });

  // ── the two other doors onto the same form ─────────────────────

  /// The new starter's own way in. They accept an invitation, which signs them
  /// in, and Human Resources is the employer's module — so without this they
  /// land in a shell with nothing in it and no way to find their own contract.
  testWidgets('somebody with paperwork of their own lands on it', (
    tester,
  ) async {
    await _pumpShell(tester, _handler(form: _asEmployee));

    expect(find.text('Your paperwork'), findsWidgets);

    // Not merely a destination in the list — the one they arrive on.
    expect(find.text('YOURS TO FILL IN'), findsOneWidget);
    expect(_box(tester, 'bank_account').enabled, isTrue);
  });

  testWidgets('Records opens what onboarding collected about somebody', (
    tester,
  ) async {
    await _pumpHr(tester, _handler(form: _asEmployer));

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Records'));
    // The list on the left, then her record, then its Onboarding section —
    // in the record's own strip, by key, so this is never the module's
    // Onboarding chip.
    await _tap(tester, find.text('Bea Okafor').first);
    await _tap(
      tester,
      find.descendant(
        of: find.byKey(RecordsTab.sectionsKey),
        matching: find.widgetWithText(ChoiceChip, 'Onboarding'),
      ),
    );
    await _tap(tester, find.byIcon(Icons.chevron_right).first);

    // The same form, so there is one renderer for these answers and one place
    // that decides a sensitive value is masked.
    expect(find.text('Technician, full time'), findsOneWidget);
    expect(find.textContaining('Filled in by Jose Silva'), findsWidgets);
    expect(find.text('Back to Bea Okafor'), findsOneWidget);
  });

  // ── formats, pickers and the contract beside the form ────────────
  //
  // Jose, 2026-10-01: "we can't just let them put anything anywhere" — his
  // screenshots had "20sdsadsa" for a wage and the same free text in Work days
  // and Work hours.

  testWidgets('a number takes digits and one decimal point', (tester) async {
    await _pumpForm(tester, _handler(form: _asEmployer));

    await _type(tester, 'hourly_wage', '20sdsadsa');
    expect(_box(tester, 'hourly_wage').controller!.text, '20');

    await _type(tester, 'hourly_wage', '1.2.3');
    expect(_box(tester, 'hourly_wage').controller!.text, '20');

    await _type(tester, 'hourly_wage', '30.75');
    expect(_box(tester, 'hourly_wage').controller!.text, '30.75');
  });

  testWidgets('a number stored before the filter says it is not one', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _catalogForm));

    const said = 'Not a number: digits and one decimal point only.';
    expect(find.text(said), findsOneWidget);

    await _type(tester, 'hourly_rate', '20');
    expect(find.text(said), findsNothing);
  });

  testWidgets('the county is searched in the chosen state, and a new state '
      'empties it', (tester) async {
    Map<String, dynamic>? saved;
    final asked = <Map<String, String>>[];

    await _pumpForm(
      tester,
      _handler(
        form: _catalogForm,
        onSave: (body) => saved = body,
        onCounties: asked.add,
      ),
    );

    // The answers on file say "FL"; the picker reads it as the state.
    expect(_dropdown(tester, 'state').value, 'Florida');
    expect(_dropdown(tester, 'salary_period').value, 'Week');

    await _type(tester, 'county', 'pa');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(asked.single, {'state': 'Florida', 'q': 'pa'});
    expect(find.text('Pick the county from the list to answer.'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('county-Palm Beach')));
    expect(_countyBox(tester).controller!.text, 'Palm Beach');

    await _tap(tester, find.byKey(answerKey('state')));
    await _tap(tester, find.text('Alaska').last);
    expect(_countyBox(tester).controller!.text, isEmpty);

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {'county': '', 'state': 'Alaska'},
    });
  });

  testWidgets('one schedule answers the work days and the work hours', (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pumpForm(
      tester,
      _handler(form: _catalogForm, onSave: (body) => saved = body),
    );

    // What was typed before the picker cannot be read as a week, and says so.
    expect(find.text('Work days and work hours'), findsOneWidget);
    expect(find.byKey(answerKey('work_days')), findsNothing);
    expect(find.textContaining('which is not a schedule'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('onboarding-schedule')));
    // Saturday and Sunday on, as the panel starts a day: 8 to 5.
    await _tap(tester, find.byType(Switch).at(5));
    await _tap(tester, find.byType(Switch).at(6));
    await _tap(tester, find.widgetWithText(FilledButton, 'Save').last);

    expect(find.text('Saturday to Sunday 8:00 AM to 5:00 PM'), findsOneWidget);

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {
        'work_hours': 'Saturday to Sunday 8:00 AM to 5:00 PM',
        'work_days': 'Saturday to Sunday',
      },
    });
  });

  test('the schedule text is the one the server reads, both ways', () {
    final week = <String, dynamic>{
      for (final day in ['monday', 'tuesday', 'wednesday', 'thursday'])
        day: {'start': '14:00', 'end': '20:00'},
      'friday': {'start': '14:00', 'end': '20:00'},
      'saturday': null,
      'sunday': {'start': '08:00', 'end': '20:00'},
    };

    final (days, hours) = scheduleText(week);
    expect(days, 'Monday to Friday, Sunday');
    expect(
      hours,
      'Monday to Friday 2:00 PM to 8:00 PM; Sunday 8:00 AM to 8:00 PM',
    );
    expect(scheduleFrom(hours), week);

    // Same days in a row with different hours are separate groups.
    week['tuesday'] = {'start': '00:00', 'end': '12:30'};
    expect(
      scheduleText(week).$2,
      'Monday 2:00 PM to 8:00 PM; Tuesday 12:00 AM to 12:30 PM; '
      'Wednesday to Friday 2:00 PM to 8:00 PM; Sunday 8:00 AM to 8:00 PM',
    );
    expect(scheduleFrom(scheduleText(week).$2), week);

    expect(scheduleFrom('Monday - Friday 2-8 , Sunday 8-8'), isNull);
  });

  testWidgets('the contract beside the form is drawn from the unsaved '
      'answers, and Sign waits for them to be saved', (tester) async {
    final previews = <Map<String, dynamic>>[];

    await _pumpForm(
      tester,
      _handler(
        form: _asEmployee,
        onPreview: (answers) {
          previews.add(answers);
          return '<h1>Contract</h1><p>Uniform: '
              '${answers['shirt_size_note'] ?? 'as saved'}</p>';
        },
      ),
    );

    expect(find.byKey(const Key('onboarding-contract')), findsOneWidget);
    expect(find.textContaining('Uniform: as saved'), findsOneWidget);
    // The stored bytes are one tap away rather than a second copy on screen.
    expect(find.textContaining('CONTRACT OF EMPLOYMENT'), findsNothing);
    await _tap(tester, find.byKey(const Key('onboarding-exact-text')));
    expect(find.textContaining('CONTRACT OF EMPLOYMENT'), findsOneWidget);

    await _tap(tester, find.byType(CheckboxListTile));
    await _type(tester, null, 'Bea Okafor', label: 'Type your full name to sign');
    expect(_signButton(tester).onPressed, isNotNull);

    await _type(tester, 'shirt_size_note', 'A jacket');
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pumpAndSettle();

    expect(previews.last, {'shirt_size_note': 'A jacket'});
    expect(find.textContaining('Uniform: A jacket'), findsOneWidget);
    expect(_signButton(tester).onPressed, isNull);
    expect(find.text('Save your answers first.'), findsOneWidget);
  });

  testWidgets('on a phone the form and the contract take turns', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee));
    tester.view.physicalSize = const Size(390, 2400);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('onboarding-contract')), findsNothing);
    await _tap(tester, find.text('Contract'));
    expect(find.byKey(const Key('onboarding-contract')), findsOneWidget);
    expect(find.byKey(answerKey('shirt_size_note')), findsNothing);
  });

  // HR 0c0884af, Jose: "some sections can be entirely voided. so they don't
  // appear in the contract. like the scheduled increase".
  testWidgets("a term that doesn't apply saves N/A, greys its box and loads "
      'back switched on', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(form: _voidForm, onSave: (body) => saved = body),
    );

    // Stored "N/A": the switch is on, the box is empty, and a number box does
    // not call it "not a number".
    final period = tester.widget<Switch>(find.byKey(const Key('void:raise_period')));
    expect(period.value, isTrue);
    // The interval is a picker (Hour/Week/Month/Year): nothing chosen.
    expect(_dropdown(tester, 'raise_period').value, isNull);

    final amount = tester.widget<Switch>(find.byKey(const Key('void:raise_amount')));
    expect(amount.value, isFalse);
    await _tap(tester, find.byKey(const Key('void:raise_amount')));
    expect(_box(tester, 'raise_amount').controller!.text, isEmpty);
    expect(find.text('Not a number: digits and one decimal point only.'), findsNothing);

    // Off again clears it.
    await _tap(tester, find.byKey(const Key('void:raise_period')));

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {'raise_amount': 'N/A', 'raise_period': ''},
    });
  });
}

TextField _countyBox(WidgetTester tester) => tester.widget<TextField>(
  find.descendant(
    of: find.byKey(answerKey('county')),
    matching: find.byType(TextField),
  ),
);

// ── driving the screens ────────────────────────────────────────────

Future<void> _pumpForm(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(client: MockClient(handler));

  await _sized(tester);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OnboardingForm(
          api: api,
          onboardingId: 'o-1',
          // Deliberately empty, which is what the EMPLOYEE's screen passes:
          // the catalogue belongs to the Contracts tab and their role does not
          // reach it. Everything the form says about kinds of question and
          // about the two sides therefore has to have arrived with the
          // onboarding, or these tests fail.
          vocabulary: ContractVocabulary.none,
          formats: DisplayFormats.fallback,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpTab(
  WidgetTester tester,
  MockClientHandler handler, {
  // Per-tab grants arrived in `08d98e07`: a chip needs `hr.<tab>.view` as well
  // as the module, so a default of module-only now draws no chips at all.
  List<String> permissions = const [
    'hr.view',
    'hr.create',
    'hr.edit',
    'hr.dashboard.view',
    'hr.hours.view',
    'hr.onboarding.view',
    'hr.records.view',
    'hr.invitations.view',
    'hr.pto.view',
    'hr.contracts.view',
  ],
}) async {
  final api = ApiClient(client: MockClient(_withMe(handler, permissions)));
  final session = Session(api);

  await _sized(tester);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: OnboardingTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpHr(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(client: MockClient(_withMe(handler, const [
    'hr.view',
    'hr.create',
    'hr.edit',
    // And the per-tab grants (`08d98e07`), without which the whole strip is
    // empty and the Records chip this test taps does not exist.
    'hr.dashboard.view',
    'hr.hours.view',
    'hr.onboarding.view',
    'hr.records.view',
    'hr.invitations.view',
    'hr.pto.view',
    'hr.contracts.view',
  ])));
  final session = Session(api);

  await _sized(tester);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: HrScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// The whole signed-in shell, which is where the new starter meets this.
Future<void> _pumpShell(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(
    // No modules at all, and no HR permission — a technician on their first
    // day. If the paperwork were a module destination it would not be here.
    client: MockClient(_withMe(handler, const [], withOnboarding: true)),
  );
  final session = Session(api);

  await _sized(tester);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _sized(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1400, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder.first,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first, warnIfMissed: false);
  await tester.pumpAndSettle();
}

/// Type into the box answering a field, or into the one carrying a label.
Future<void> _type(
  WidgetTester tester,
  String? fieldKey,
  String text, {
  String? label,
}) async {
  final finder = fieldKey != null
      ? find.byKey(answerKey(fieldKey))
      : find.widgetWithText(TextField, label!);

  await tester.ensureVisible(finder.first);
  await tester.pumpAndSettle();
  await tester.enterText(finder.first, text);
  await tester.pumpAndSettle();
}

Future<void> _choose(WidgetTester tester, String label, String option) async {
  await _tap(
    tester,
    find.widgetWithText(SearchPickerField<String>, label),
  );
  await _tap(tester, find.text(option).last);
}

/// The control answering one question, found by its field key.
///
/// Never by label: the wording is the tenant's, two questions may legitimately
/// carry the same words, and the label is not attached to the control — it sits
/// above it with the tags beside it. See [answerKey].
TextField _box(WidgetTester tester, String fieldKey) =>
    tester.widget<TextField>(find.byKey(answerKey(fieldKey)));

CalendarDayField _day(WidgetTester tester, String fieldKey) =>
    tester.widget<CalendarDayField>(find.byKey(answerKey(fieldKey)));

SearchPickerField<String> _dropdown(
  WidgetTester tester,
  String fieldKey,
) => tester.widget<SearchPickerField<String>>(
  find.byKey(answerKey(fieldKey)),
);

/// A form field's own DropdownButton, which is where the items live —
/// `DropdownButtonFormField` is a FormField and does not expose them.
SearchPickerField<String> _dropdownIn(WidgetTester tester, String label) =>
    tester.widget<SearchPickerField<String>>(
      find.widgetWithText(SearchPickerField<String>, label),
    );

FilledButton _saveButton(WidgetTester tester) =>
    tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Save'));

FilledButton _signButton(WidgetTester tester) =>
    tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Sign'));

FilledButton _startButton(WidgetTester tester) => tester.widget<FilledButton>(
  find.widgetWithText(FilledButton, 'Start'),
);

// ── the server, as these screens see it ────────────────────────────

MockClientHandler _handler({
  /// The employer's view unless a test says otherwise. Not a default parameter
  /// because the fixtures are built by a function and Dart wants a constant.
  Map<String, Object?>? form,
  void Function(Map<String, dynamic> body)? onSave,
  void Function(Map<String, dynamic> body)? onSign,
  void Function(Map<String, dynamic> body)? onReveal,
  void Function(Map<String, dynamic> body)? onStart,
  void Function()? onCancel,
  int saveStatus = 200,
  int revealStatus = 200,
  /// The contract the preview endpoint draws from the unsaved answers. Null
  /// leaves the preview unanswered, so the pane shows the document.
  String Function(Map<String, dynamic> answers)? onPreview,
  void Function(Map<String, String> query)? onCounties,
}) {
  final seen = form ?? _asEmployer;

  return (request) async {
    final path = request.url.path;
    final body = request.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(request.body) as Map<String, dynamic>;

    if (onPreview != null && path.endsWith('/hr/onboardings/o-1/preview')) {
      return _json({
        'data': {
          'content': onPreview(
            Map<String, dynamic>.from(body['answers'] as Map),
          ),
          'mime': 'text/html',
          'content_hash': 'preview',
        },
      });
    }

    if (path.endsWith('/places/counties')) {
      onCounties?.call(request.url.queryParameters);
      final q = request.url.queryParameters['q']!.toLowerCase();
      return _json({
        'data': [
          for (final county in ['Palm Beach', 'Pasco', 'Broward'])
            if (county.toLowerCase().startsWith(q)) county,
        ],
      });
    }

    if (path.endsWith('/hr/onboardings/o-1/document')) {
      return _json({
        'data': seen['status'] == 'completed'
            ? _asRecordDocument
            : _document(),
      });
    }

    // Revealing one sensitive answer: a GET at the field's own address, which
    // is what makes ASKING the reveal rather than opening the screen.
    if (path.contains('/hr/onboardings/o-1/answers/')) {
      final field = path.split('/').last;
      onReveal?.call({'field_key': field});

      if (revealStatus != 200) {
        return _json(
          {'message': 'You may not see this value in the clear.'},
          revealStatus,
        );
      }

      return _json({
        'data': {
          'field_key': field,
          'value': 'GB29 NWBK 6016 1331 9268 19',
          'sensitive': true,
          'revealed': true,
        },
      });
    }

    if (path.endsWith('/hr/onboardings/o-1/sign')) {
      onSign?.call(body);
      return _json({'data': seen});
    }

    if (request.method == 'DELETE' && path.contains('/hr/onboardings/')) {
      onCancel?.call();
      return _json({
        'data': {...seen, 'status': 'cancelled'},
      });
    }

    if (path.contains('/hr/onboardings/o-')) {
      if (request.method == 'PATCH') {
        onSave?.call(body);

        if (saveStatus != 200) {
          return _json({
            'message': 'The given data was invalid.',
            'errors': {
              'answers.shirt_size_note': [
                'That answer is longer than the field allows.',
              ],
            },
          }, saveStatus);
        }

        return _json({'data': seen});
      }

      return _json({'data': seen});
    }

    if (path.endsWith('/hr/onboardings')) {
      if (request.method == 'POST') {
        onStart?.call(body);
        return _json({'data': _inFlight.first}, 201);
      }

      // Filtered the way the endpoint is: the Records tab asks for one
      // person's onboardings, and everything else asks for all of them.
      final only = request.url.queryParameters['user_id'];

      final rows = only == null
          ? _list
          : _list
              .where((row) {
                final person = row['person'] as Map<String, Object?>?;
                return person?['user_id'] == only;
              })
              .toList();

      return _json({
        'data': rows,
        'meta': {
          'total': rows.length,
          'per_page': 50,
          'current_page': 1,
          'last_page': 1,
        },
      });
    }

    if (path.endsWith('/hr/contracts/catalogue')) {
      return _json({'data': _catalogue});
    }

    if (path.endsWith('/hr/contracts')) return _json({'data': _contracts});
    if (path.endsWith('/hr/invitations')) return _json({'data': _invitations});
    if (path.endsWith('/hr/people')) {
      return _json({
        'data': _people,
        'meta': {
          'total': _people.length,
          'per_page': 50,
          'current_page': 1,
          'last_page': 1,
        },
      });
    }

    if (path.endsWith('/hr/people/scope-options')) {
      return _json({'data': _scopeOptions});
    }

    if (request.method == 'GET' && RegExp(r'/hr/people/u-\d+$').hasMatch(path)) {
      final id = path.split('/').last;
      return _json({
        'data': {
          ..._people.firstWhere((person) => person['id'] == id),
          'phone': null,
          'address': null,
          'gender': null,
          'date_of_birth': null,
          'started_on': null,
          'pto': null,
        },
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// Wraps a handler with a `/me`, so a screen that needs a session gets one.
MockClientHandler _withMe(
  MockClientHandler inner,
  List<String> permissions, {
  bool withOnboarding = false,
}) {
  return (request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Jose Silva',
          'email': 'jose@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Administrator'},
          'permissions': permissions,
          'modules': withOnboarding
              ? <Map<String, Object?>>[]
              : [
                  {
                    'key': 'hr',
                    'label': 'Human Resources',
                    'available': true,
                    'reachable': true,
                  },
                ],
          'preferences': <String, dynamic>{},
          // The block the shell reads to decide there is paperwork waiting.
          if (withOnboarding)
            'onboarding': {
              'id': 'o-1',
              'status': 'in_progress',
              'contract': {'name': 'Technician, full time'},
            },
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
        },
      });
    }

    return inner(request);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// The vocabulary, in wording nobody would invent.
///
/// Three of the six real kinds, so a client carrying its own list would offer
/// the other three; labels that are not derivable from the keys; and the two
/// sides named "Head office" and "The new starter".
const _catalogue = {
  'field_types': [
    {'key': 'text', 'label': 'A line of words'},
    {'key': 'number', 'label': 'A figure'},
    {'key': 'date', 'label': 'A day'},
    {'key': 'choice', 'label': 'Pick from a list'},
    {'key': 'signature', 'label': 'A signature'},
  ],
  'filled_by': [
    {'key': 'employer', 'label': 'Head office'},
    {'key': 'employee', 'label': 'The new starter'},
    {'key': 'either', 'label': 'Whoever gets there first'},
  ],
};

/// One question of the pinned version, as `GET /hr/onboardings/{id}` sends it:
/// the field's own properties, the answer, and who set it, flat on one row.
Map<String, Object?> _field(
  String key,
  String label,
  String type, {
  required String filledBy,
  required bool writable,
  String source = 'contract',
  Object? value,
  bool sensitive = false,
  bool required = false,
  List<String> options = const [],
  String? setBy,
  String? setAt,
  String? lockedBecause,
}) => {
  'key': key,
  'label': label,
  'type': type,
  'options': options,
  'source': source,
  'filled_by': filledBy,
  'required': required,
  'sensitive': sensitive,
  'retired': false,
  'value': value,
  'writable': writable,
  'locked_because': lockedBecause,
  'set_by': setBy == null
      ? null
      : {'id': 'u-1', 'name': setBy, 'party': 'employer'},
  'set_at': setAt,
};

/// The seven questions, and the signature field among them.
///
/// `writable` is the ONLY thing that differs between the two views below. A
/// client working out who may write what from `filled_by` would have to
/// disagree with one of them.
///
/// [readOnly] is the third state — a finished record, which nobody may type
/// into and which the Records tab opens.
List<Map<String, Object?>> _fields({
  required bool asEmployer,
  bool readOnly = false,
}) {
  bool mine(bool employers) => !readOnly && employers;

  return [
  // The common core, as the server sends it — part of the same list, and
  // keyed by something no Dart list of six could have produced.
  _field(
    'shoe_size',
    'Shoe size',
    'text',
    source: 'core',
    filledBy: 'employee',
    writable: mine(!asEmployer),
    value: '43',
    lockedBecause: asEmployer
        ? 'It is theirs. Core identity belongs to the person it is about.'
        : null,
  ),
  _field(
    'hourly_wage',
    'Hourly wage',
    'number',
    filledBy: 'employer',
    writable: mine(asEmployer),
    required: true,
    value: '22.50',
    setBy: 'Jose Silva',
    setAt: '2026-08-10T09:00:00+00:00',
  ),
  _field(
    'date_of_birth',
    'Their date of birth',
    'date',
    filledBy: 'either',
    // Both. This is the row the owner's argument for `either` is about.
    writable: !readOnly,
    value: '1990-02-11',
    setBy: 'Jose Silva',
    setAt: '2026-08-10T09:05:00+00:00',
  ),
  _field(
    'bank_account',
    'Bank account',
    'text',
    filledBy: 'employee',
    writable: mine(!asEmployer),
    sensitive: true,
    // The MASK, which is what the server sends for a sensitive answer on every
    // ordinary load. The clear value only ever comes back from a reveal.
    value: '••••••••',
    setBy: 'Bea Okafor',
    setAt: '2026-08-10T10:00:00+00:00',
  ),
  _field(
    'shirt_size',
    'Shirt size',
    'choice',
    filledBy: 'employee',
    writable: mine(!asEmployer),
    options: const ['S', 'M', 'L'],
  ),
  _field(
    'shirt_size_note',
    'Anything else about the uniform',
    'text',
    filledBy: 'employee',
    writable: mine(!asEmployer),
  ),
  // A kind of question this build has never met. It must be ASKED, not dropped.
  _field(
    'notice_period',
    'Notice period',
    'duration',
    filledBy: 'employee',
    writable: mine(!asEmployer),
  ),
  // The signature field is one of the version's fields, as the server sends
  // it — never writable, because it is signed rather than typed. The form has
  // to lift it out of the questions and into the ceremony, and must not count
  // it as an outstanding answer.
  _field(
    'employee_signature',
    'Signature of the new starter',
    'signature',
    filledBy: 'employee',
    writable: false,
  ),
];
}

Map<String, Object?> _form({
  required bool asEmployer,
  bool readOnly = false,
}) => {
  'id': 'o-1',
  'status': readOnly ? 'completed' : 'in_progress',
  'person': {
    'user_id': 'u-2',
    'name': 'Bea Okafor',
    'email': 'bea@example.com',
  },
  'contract': {
    'name': 'Technician, full time',
    'version': 3,
    'version_id': 'cv-3',
  },
  'invitation_id': 'i-1',
  'started_at': '2026-08-10T09:00:00+00:00',
  'submitted_at': readOnly ? '2026-08-12T09:00:00+00:00' : null,
  'completed_at': readOnly ? '2026-08-12T09:30:00+00:00' : null,
  'parties': asEmployer ? ['employer'] : ['employee'],
  'started_by': 'Jose Silva',
  'fields': _fields(asEmployer: asEmployer, readOnly: readOnly),
  'missing': readOnly
      ? <String, String>{}
      : {'notice_period': 'Notice period'},
  'frozen': readOnly,
  // Travels with the form, because the person filling in the employee's half
  // has no permission to fetch the Contracts catalogue.
  'vocabulary': _catalogue,
  'signature_fields': [
    {
      'key': 'employee_signature',
      'label': 'Signature of the new starter',
      'filled_by': 'employee',
      'mine': !asEmployer,
      'signed': readOnly,
    },
  ],
  'signatures': readOnly
      ? [
          {
            'id': 'sig-1',
            'field_key': 'employee_signature',
            'party': 'employee',
            'signer': 'Bea Okafor <bea@example.com>',
            'affirmation': 'Bea Okafor',
            'signed_at': '2026-08-12T09:30:00+00:00',
            'consented_at': '2026-08-12T09:29:00+00:00',
            'content_hash': _hash,
            'hash_algorithm': 'sha256',
            'provider': 'ezbizi',
            'consent_statement':
                'I agree to sign this contract electronically rather than on '
                'paper.',
            'intent_statement':
                'Typing my name below signs this contract in my own hand.',
          },
        ]
      : <Map<String, Object?>>[],
  // The words the signing screen displays, so that what a person reads and
  // what the record says they read are the same string.
  'ceremony': {
    'consent_statement':
        'I agree to sign this contract electronically rather than on paper.',
    'intent_statement':
        'Typing my name below signs this contract in my own hand.',
  },
};

/// The fingerprint of the document below, as `GET .../document` sends it.
///
/// A real sha256 length, because the endpoint that takes it back validates the
/// length against the algorithm rather than against the number 64.
const _hash =
    '9f2c0a1d4b7e6538a0c1d2e3f4051627384950617283940a1b2c3d4e5f607182';

/// `GET /hr/onboardings/{id}/document` — the rendered contract and its hash.
Map<String, Object?> _document({bool stored = false}) => {
  'stored': stored,
  'is_preview': !stored,
  'revision': stored ? 1 : null,
  'content':
      'CONTRACT OF EMPLOYMENT\n\nBetween Everdoors and Bea Okafor.\n\n'
      'Hourly wage: 22.50.',
  'mime': 'text/html; charset=utf-8',
  'content_hash': _hash,
  'hash_algorithm': 'sha256',
  'content_length': 84,
};

final _asRecordDocument = _document(stored: true);

final _asEmployer = _form(asEmployer: true);
final _asEmployee = _form(asEmployer: false);

/// The employer's half of an A-1 contract: the catalog keys that get their own
/// controls, holding what was typed before they existed.
final _catalogForm = {
  ..._asEmployer,
  'fields': [
    _field(
      'hourly_rate',
      'Hourly rate',
      'number',
      filledBy: 'employer',
      writable: true,
      value: '20sdsadsa',
    ),
    for (final (key, label, value) in [
      ('salary_period', 'Salary period', 'per week'),
      ('state', 'Governing state', 'FL'),
      ('county', 'County for disputes', 'Broward'),
      ('work_days', 'Work days', 'Monday - Friday 2-8 , Sunday 8-8'),
      ('work_hours', 'Work hours', 'Monday - Friday 2-8 , Sunday 8-8'),
    ])
      _field(key, label, 'text', filledBy: 'employer', writable: true, value: value),
  ],
  'missing': <String, String>{},
};

/// A finished record, which is what the Records tab opens: nothing writable,
/// the signature already given.
final _asRecord = _form(asEmployer: true, readOnly: true);

/// A server that offered a signature and sent no wording to sign under.
final _signingWithNothingInIt = {
  ..._form(asEmployer: false),
  'ceremony': <String, Object?>{},
};

/// `GET /hr/onboardings` — the employer's list.
const _inFlight = [
  {
    'id': 'o-1',
    'invitation_id': 'i-2',
    'status': 'in_progress',
    'person': {
      'user_id': 'u-2',
      'name': 'Bea Okafor',
      'email': 'bea@example.com',
    },
    'contract': {'id': 'c-1', 'name': 'Technician, full time', 'version': 3},
    'progress': {
      'employer': {'answered': 1, 'outstanding': 2},
      'employee': {'answered': 3, 'outstanding': 1},
    },
    'started_at': '2026-08-10T09:00:00+00:00',
    'submitted_at': null,
    'completed_at': null,
  },
];

const _list = [
  ..._inFlight,
  // Finished. It says so rather than counting to zero.
  {
    'id': 'o-2',
    'invitation_id': 'i-4',
    'status': 'completed',
    'person': {
      'user_id': 'u-4',
      'name': 'Sam Reyes',
      'email': 'sam@example.com',
    },
    'contract': {'id': 'c-1', 'name': 'Technician, full time', 'version': 2},
    'progress': {
      'employer': {'answered': 3, 'outstanding': 0},
      'employee': {'answered': 4, 'outstanding': 0},
    },
    'started_at': '2026-07-01T09:00:00+00:00',
    'submitted_at': '2026-07-02T09:00:00+00:00',
    'completed_at': '2026-07-02T10:00:00+00:00',
  },
];

const _contracts = [
  {
    'id': 'c-1',
    'name': 'Technician, full time',
    'version': 3,
    'field_count': 6,
  },
];

const _people = [
  {
    'id': 'u-2',
    'name': 'Bea Okafor',
    'email': 'bea@example.com',
    'is_active': true,
    'role': {'id': 'role-2', 'name': 'Technician'},
    'assignments': <dynamic>[],
  },
  {
    'id': 'u-3',
    'name': 'Nadia Ruiz',
    'email': 'nadia@example.com',
    'is_active': true,
    'role': {'id': 'role-2', 'name': 'Technician'},
    'assignments': <dynamic>[],
  },
];

const _invitations = [
  // Sent and not accepted. A valid subject: the employer can fill in their half
  // before the person has an account.
  {
    'id': 'i-1',
    'email': 'kit@example.com',
    'first_name': 'Kit',
    'last_name': 'Weaver',
    'status': 'sent',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'franchise', 'id': 'fr-9', 'name': 'Connecticut'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'expires_at': '2026-08-18T09:00:00+00:00',
  },
  // Accepted, and already carrying onboarding o-1 — the server refuses a
  // second against the same invitation.
  {
    'id': 'i-2',
    'email': 'bea@example.com',
    'first_name': 'Bea',
    'last_name': 'Okafor',
    'status': 'completed',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'franchise', 'id': 'fr-9', 'name': 'Connecticut'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'expires_at': '2026-08-09T09:00:00+00:00',
  },
  // Withdrawn. Nobody is coming, and the server says so — so it is not offered.
  {
    'id': 'i-3',
    'email': 'lee@example.com',
    'first_name': 'Lee',
    'last_name': 'Nkemelu',
    'status': 'revoked',
    'role': {'id': 'role-2', 'name': 'Technician'},
    'scope': {'type': 'franchise', 'id': 'fr-9', 'name': 'Connecticut'},
    'invited_by': {'id': 'u-1', 'name': 'Jose Silva'},
    'expires_at': '2026-08-09T09:00:00+00:00',
  },
];

const _scopeOptions = {
  'organization': {'id': 'org-1', 'name': 'Everdoors'},
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
  'roles': [
    {'id': 'role-2', 'name': 'Technician', 'assignable': true},
  ],
};

/// Two employer terms the server lets be voided, one already "N/A".
final _voidForm = {
  ..._asEmployer,
  'fields': [
    {
      ..._field('raise_amount', 'Scheduled increase amount', 'number', filledBy: 'employer', writable: true, value: '0'),
      'voidable': true,
    },
    {
      ..._field('raise_period', 'Scheduled increase interval', 'text', filledBy: 'employer', writable: true, value: 'N/A'),
      'voidable': true,
    },
  ],
  'missing': <String, String>{},
};
