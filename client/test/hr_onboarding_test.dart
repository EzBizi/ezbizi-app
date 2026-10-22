import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/contracts.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/hr/onboarding_form.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/pdf_view_io.dart';
import 'package:ezbizi_client/screens/hr/onboarding_tab.dart';
import 'package:ezbizi_client/screens/hr_screen.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:ezbizi_client/screens/hr/records_tab.dart';
import 'package:ezbizi_client/screens/hr/tax_form_panel.dart';
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
    expect(_box(tester, 'hourly_wage').enabled, isTrue);

    // The other half is on the Employee tab (design 135, section 7).
    await _onSide(tester, 'Employee');
    expect(find.text('NOT YOURS TO ANSWER'), findsOneWidget);

    // Theirs. Drawn, so the employer can see what is still outstanding, and
    // dead, because it is not their answer to give.
    expect(_box(tester, 'bank_account').enabled, isFalse);
    // A short list is its options in sight (Jose, 2026-10-06).
    expect(_chip(tester, 'shirt_size').onSelected, isNull);

    // And it says whose it is, in the SERVER's word for that side.
    expect(find.text('The new starter'), findsWidgets);
  });

  // Jose, 2026-10-14: "highlight missing fields inside the onboarding".
  testWidgets('a required question still empty is marked until answered', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    expect(find.byKey(const Key('missing-notice_period')), findsOneWidget);
    expect(find.byKey(const Key('missing-hourly_wage')), findsNothing);

    await tester.enterText(find.byKey(answerKey('notice_period')), '2 weeks');
    await tester.pump();

    expect(find.byKey(const Key('missing-notice_period')), findsNothing);
  });

  testWidgets('the employee fills in theirs, and sees what the employer owes', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    expect(_box(tester, 'bank_account').enabled, isTrue);
    expect(_chip(tester, 'shirt_size').onSelected, isNotNull);

    // The wage is the employer's and stays theirs — but it is on the page, so a
    // wage nobody has set is visibly outstanding rather than invisible.
    await _onSide(tester, 'Employer');
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
    expect(find.text('Either of you can answer'), findsWidgets);

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
    expect(_day(tester, 'date_of_birth').controller.text, '1990-02-11');
    await _onSide(tester, 'Employer');
    expect(_box(tester, 'hourly_wage').controller!.text, '22.50');
    await _onSide(tester, 'Employee');

    // Counted, because "your progress is saved" with no number behind it is
    // what every form says and nobody believes.
    expect(find.textContaining('4 of 7 answered'), findsOneWidget);
    expect(find.textContaining('Last answered'), findsOneWidget);
    expect(
      find.textContaining('do not have to finish this in one go'),
      findsOneWidget,
    );
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

    expect(
      find.text('Revealing it is recorded against your name.'),
      findsOneWidget,
    );

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
      find.textContaining(
        'Agree to sign electronically, type your name and draw your signature',
      ),
      findsOneWidget,
    );
    // Nothing saved, so nothing to reuse.
    expect(find.byKey(const Key('signature-use-saved')), findsNothing);

    // Consent alone is not a signature.
    await _tap(tester, find.byType(CheckboxListTile));
    expect(_signButton(tester).onPressed, isNull);
    expect(find.text('Type your name above to sign.'), findsOneWidget);

    // And a name alone is not consent — ESIGN wants it given, not assumed.
    await _tap(tester, find.byType(CheckboxListTile));
    await _type(
      tester,
      null,
      'Bea Okafor',
      label: 'Type your full name to sign',
    );
    expect(
      _signButton(tester).onPressed,
      isNull,
      reason: 'Consent to transact electronically has to be a thing they DO.',
    );

    await _tap(tester, find.byType(CheckboxListTile));

    // And consent and a name are not yet a signature drawn by hand (Jose,
    // 2026-10-01: "as in A1 Tools").
    expect(_signButton(tester).onPressed, isNull);
    expect(find.text('Draw your signature above to sign.'), findsOneWidget);

    await _draw(tester);
    expect(_signButton(tester).onPressed, isNotNull);

    // Clear takes it back to an empty pad.
    await _tap(tester, find.byKey(const Key('signature-clear')));
    expect(_signButton(tester).onPressed, isNull);

    await _draw(tester);
    await _pressSign(tester, () => signed != null);

    // The fingerprint of the document this screen displayed goes back with the
    // act. The server refuses without it, and a hash computed here rather than
    // received would be evidence of nothing.
    final drawing = signed!.remove('drawing') as String;
    expect(signed, {
      'field_key': 'employee_signature',
      'consent': true,
      'affirmation': 'Bea Okafor',
      'document_hash': _hash,
    });

    // The drawing, as a PNG: the file's own first bytes say so.
    expect(drawing, startsWith('data:image/png;base64,'));
    final png = base64Decode(
      drawing.substring('data:image/png;base64,'.length),
    );
    expect(png.sublist(0, 4), [0x89, 0x50, 0x4E, 0x47]);

    // With the stroke on it: some pixel is not transparent.
    final inked = await tester.runAsync(() async {
      final image = await decodeImageFromList(png);
      final rgba = await image.toByteData(format: ui.ImageByteFormat.rawRgba);
      final pixels = rgba!.buffer.asUint8List();
      for (var alpha = 3; alpha < pixels.length; alpha += 4) {
        if (pixels[alpha] != 0) return true;
      }
      return false;
    });
    expect(inked, isTrue);
  });

  // A1 Tools: the employer drew once and reused it on every contract.
  testWidgets('a saved signature can stand in for drawing, and a new drawing '
      'can be saved as one', (tester) async {
    Map<String, dynamic>? signed;
    final saves = <Map<String, dynamic>>[];
    await _pumpForm(
      tester,
      _handler(
        form: _asEmployee,
        savedSignature: 'data:image/png;base64,${base64Encode(_png)}',
        onSign: (body) => signed = body,
        onSaveSignature: saves.add,
      ),
    );
    await _tap(tester, find.byType(CheckboxListTile));
    await _type(
      tester,
      null,
      'Bea Okafor',
      label: 'Type your full name to sign',
    );
    expect(_signButton(tester).onPressed, isNull);

    // The stored one takes the pad's place, and is enough to sign with.
    await _tap(tester, find.byKey(const Key('signature-use-saved')));
    expect(find.byKey(const Key('signature-pad')), findsNothing);
    final shown = tester.widget<Image>(
      find.descendant(
        of: find.byKey(const Key('signature-saved')),
        matching: find.byType(Image),
      ),
    );
    expect((shown.image as MemoryImage).bytes, _png);
    expect(_signButton(tester).onPressed, isNotNull);

    // Clear goes back to drawing; a new drawing is saved over it.
    await _tap(tester, find.byKey(const Key('signature-clear')));
    expect(_signButton(tester).onPressed, isNull);
    await _draw(tester);
    await _tapUntil(
      tester,
      find.byKey(const Key('signature-save')),
      () => saves.isNotEmpty,
    );
    final fresh = saves.single['drawing'] as String;
    expect(fresh, startsWith('data:image/png;base64,'));
    expect(fresh, isNot('data:image/png;base64,${base64Encode(_png)}'));
    expect(find.text('Saved as your signature.'), findsOneWidget);

    // Using the saved one now uses the new one, and Sign sends exactly it.
    await _tap(tester, find.byKey(const Key('signature-clear')));
    await _tap(tester, find.byKey(const Key('signature-use-saved')));
    await _pressSign(tester, () => signed != null);
    expect(signed!['drawing'], fresh);
  });

  // HR's A1-style stylesheet sizes in points, and flutter_html reads a point
  // as a pixel: 12pt headings came out smaller than the 15px body.
  test('the contract is drawn at screen size, its headings above its body', () {
    final html = contractOnScreen(
      '<style>body{font-size:9.5pt}h2{font-size:12pt;margin:10pt 0 4pt}</style>'
      '<p>A notice in 12pt type stays as written.</p>',
    );
    expect(html, contains('body{font-size:15.00px}'));
    expect(html, contains('h2{font-size:18.95px;margin:15.79px 0 6.32px}'));
    expect(html, contains('<p>A notice in 12pt type stays as written.</p>'));
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

    expect(
      find.text('That answer is longer than the field allows.'),
      findsOneWidget,
    );

    // Still in the box, and still queued. Clearing the pending answers over a
    // refusal would leave the text on screen and nothing behind it, so the next
    // Save would send nothing and report success over a form never written.
    expect(
      _box(tester, 'shirt_size_note').controller!.text,
      'Prefers a jacket',
    );
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

    await _onSide(tester, 'Employee');
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

  // Mia, 2026-10-02: Eve finished a technician's onboarding and could not
  // download the signed contract.
  testWidgets('a signed contract can be fetched as a PDF to download', (
    tester,
  ) async {
    var asked = 0;
    await _pumpForm(
      tester,
      _handler(form: _asRecord, onSignedPdf: () => asked++),
    );

    final button = find.byKey(const Key('onboarding-download-pdf'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();

    expect(asked, 1);
    expect(find.byKey(const Key('onboarding-save-pdf')), findsOneWidget);
  });

  testWidgets('a finished record reads as one, signature and all', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asRecord));

    // One list, not two halves: with nothing writable there is no "yours".
    expect(find.text('THE ANSWERS'), findsOneWidget);
    expect(find.text('YOURS TO FILL IN'), findsNothing);
    expect(
      find.text(
        'Nothing here is waiting on you. This is the record as it '
        'stands.',
      ),
      findsOneWidget,
    );

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
    await _onSide(tester, 'Employee');
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
    await _onSide(tester, 'Employee');

    expect(_box(tester, 'shoe_size').enabled, isFalse);
    expect(
      find.text(
        'It is theirs. Core identity belongs to the person it is about.',
      ),
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
    // The version rides beside the contract's name in its column now.
    expect(find.textContaining('v3'), findsOneWidget);

    // What is missing, in amber and by side, in the server's names for the
    // sides, as Records marks a record (Jose, 2026-10-14): an onboarding stuck
    // because nobody set the wage and one stuck because the new starter has
    // not opened it are different problems with different owners.
    expect(find.text('Head office: 2 missing'), findsOneWidget);
    expect(find.text('The new starter: 1 missing'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);

    // And the one that is done says so instead of counting to zero.
    expect(find.textContaining('Completed'), findsWidgets);

    // Either can be stopped, signed or not (Jose, 2026-10-05: "regardless of
    // anything").
    expect(find.text('Cancel'), findsNWidgets(2));
  });

  // Andy's Suggestion, built on Jose's word (2026-10-01): "a seperate
  // archieved for all the forms that are already done".
  // Jose, 2026-10-05: "make a button on onboarding to download them faster".
  testWidgets('a signed one offers its PDF from the list', (tester) async {
    await _pumpTab(tester, _handler());

    expect(find.byKey(const Key('onboarding-download-o-2')), findsOneWidget);
    expect(find.byKey(const Key('onboarding-download-o-1')), findsNothing);
  });

  testWidgets('finished onboardings are their own list', (tester) async {
    final asked = <Map<String, String>>[];

    await _pumpTab(tester, _handler(onList: asked.add));
    expect(asked.last['state'], 'open');

    // The archive is the Completed and Cancelled tiles now (layout A, Mia
    // 2026-10-02): one status each, and the ongoing list is still the default.
    await _tap(tester, find.byKey(const Key('onboardings-tile-completed')));
    expect(asked.last['status'], 'completed');
    expect(asked.last.containsKey('state'), isFalse);

    // Tapping the picked tile again goes back to what is still under way.
    await _tap(tester, find.byKey(const Key('onboardings-tile-completed')));
    expect(asked.last['state'], 'open');
  });

  /// Jose, 2026-10-14: "we lack searchbars on the 3 last human resources
  /// tabs". The list pages on the server, so the search goes there.
  testWidgets('the search box asks the server once typing stops', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pumpTab(tester, _handler(onList: asked.add));

    await tester.enterText(
      find.byKey(const ValueKey('tour-hr-onboarding-search')),
      'Ruiz',
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(asked.last.containsKey('search'), isFalse);
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(asked.last['search'], 'Ruiz');
    expect(asked.last['state'], 'open');
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

    for (final cancel in tester.widgetList<TextButton>(
      find.widgetWithText(TextButton, 'Cancel'),
    )) {
      expect(cancel.onPressed, isNull);
    }
  });

  // Ana's Suggestion, on Jose's word (2026-10-01): starting one can choose the
  // organization or a company — the contract's employer, where it is filed,
  // where the person is assigned.
  testWidgets('starting one can choose what it is under', (tester) async {
    Map<String, dynamic>? started;

    await _pumpTab(tester, _handler(onStart: (body) => started = body));
    await _tap(tester, find.text('Start an onboarding'));
    await _choose(tester, 'Who is this for', 'Kit Weaver (kit@example.com)');
    await _tap(tester, find.byKey(const ValueKey('onboarding-under')));
    await _tap(tester, find.text('Everdoors').last);
    await _tap(tester, find.widgetWithText(FilledButton, 'Start'));

    expect(started, {
      'invitation_id': 'i-1',
      'contract_id': 'c-1',
      'company_id': null,
    });
  });

  testWidgets('starting one picks the person and the contract, and opens it', (
    tester,
  ) async {
    Map<String, dynamic>? started;

    await _pumpTab(tester, _handler(onStart: (body) => started = body));

    await _tap(tester, find.text('Start an onboarding'));

    expect(_startButton(tester).onPressed, isNull);

    await _choose(tester, 'Who is this for', 'Kit Weaver (kit@example.com)');

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

  testWidgets('a reader who may not start one is told that, not shown an error', (
    tester,
  ) async {
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

  // Jose, 2026-10-05: Eve typed Cook, the search found nothing, and "she
  // should be able to just save, even if the finder does not find anything".
  // Jose, 2026-10-05: "a final approval is pending from the employer who
  // sent it out".
  testWidgets('signed paperwork waits for the sender, who approves it', (
    tester,
  ) async {
    var approved = 0;
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._asEmployer,
          'status': 'awaiting_approval',
          'approval': {'by': 'Eve Sarfaty', 'mine': true},
        },
        onApprove: () => approved++,
      ),
    );

    expect(find.byKey(const Key('onboarding-approval')), findsOneWidget);
    await _tap(tester, find.byKey(const Key('onboarding-approve')));
    expect(approved, 1);
    expect(find.byKey(const Key('onboarding-approval')), findsNothing);
  });

  testWidgets('the new starter is told whose approval it waits for', (
    tester,
  ) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._asEmployee,
          'status': 'awaiting_approval',
          'approval': {'by': 'Eve Sarfaty', 'mine': false},
        },
      ),
    );

    expect(
      find.text('Signed by both sides. Waiting for Eve Sarfaty to approve it.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('onboarding-approve')), findsNothing);
  });

  // Jose, 2026-10-05: tools are the technician's, and "look at how a1tools
  // did it": tick, a quantity, and one not on the list.
  testWidgets('the tools checklist is ticks with a quantity', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._asEmployee,
          'fields': [
            _field(
              'tools',
              'Tools issued',
              'checklist',
              filledBy: 'employee',
              writable: true,
              options: const ['Tool Bag', 'Hammer'],
            ),
          ],
        },
        onSave: (body) => saved = body,
      ),
    );

    await _tap(tester, find.byKey(const Key('checklist-tools-Hammer')));
    await _tap(tester, find.byTooltip('One more'));
    await _tap(tester, find.byKey(const Key('checklist-add-tools')));
    await tester.enterText(
      find.byKey(const Key('checklist-add-name')),
      'Ladder',
    );
    await _tap(tester, find.widgetWithText(TextButton, 'Add'));

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    final rows = jsonDecode(saved!['answers']['tools'] as String) as List;
    expect(
      [for (final r in rows) (r['item'], r['quantity'])],
      [('Hammer', '2'), ('Ladder', '1')],
    );
    expect((rows.first as Map)['received'], isNotEmpty);
  });

  // Jose, 2026-10-06: "she always gets the 1 missing field, even tho
  // everything is saved". The saved value again is not an unsaved answer.
  testWidgets('giving back the saved value leaves nothing unsaved', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _asEmployee));

    await _type(tester, 'shoe_size', '44');
    expect(find.text('1 answer not saved yet.'), findsOneWidget);
    await _type(tester, 'shoe_size', '43');
    expect(find.text('Everything on this page is saved.'), findsOneWidget);
  });

  // Jose, 2026-10-06: Eve wanted "to be determined", options she can see,
  // and pay basis off without everything under it going too.
  testWidgets(
    'pay terms: TBD, visible options, and a basis that does not apply',
    (tester) async {
      Map<String, dynamic>? saved;
      await _pumpForm(
        tester,
        _handler(
          form: {
            ..._asEmployer,
            'fields': [
              {
                ..._field(
                  'pay_basis',
                  'Pay basis',
                  'choice',
                  filledBy: 'employer',
                  writable: true,
                  options: const ['Hourly', 'Salary'],
                  value: 'N/A',
                ),
                'voidable': true,
              },
              {
                ..._field(
                  'hourly_rate',
                  'Hourly rate',
                  'number',
                  filledBy: 'employer',
                  writable: true,
                ),
                'voidable': true,
                'shown_when': {'key': 'pay_basis', 'is': 'hourly'},
              },
              {
                ..._field(
                  'comp_method',
                  'Compensation method',
                  'text',
                  filledBy: 'employer',
                  writable: true,
                ),
                'voidable': true,
              },
            ],
          },
          onSave: (body) => saved = body,
        ),
      );

      // Pay basis does not apply, and the hourly rate under it is still there.
      expect(find.text('Hourly rate'), findsOneWidget);

      await _tap(tester, find.byKey(const Key('tbd:hourly_rate')));
      expect(find.text('To be determined'), findsOneWidget);
      await _tap(
        tester,
        find.byKey(const Key('comp-method-Hourly plus commission')),
      );

      await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
      expect(saved, {
        'answers': {
          'hourly_rate': 'To be determined',
          'comp_method': 'Hourly plus commission',
        },
      });
    },
  );

  // Jose, 2026-10-06: the I-9 is signed in the app, and Section 2 is the
  // employer's, on the page the new hire signed.
  testWidgets('a signed I-9 offers the employer Section 2', (tester) async {
    Map<String, Object?> i9(bool done) => {
      ..._asEmployer,
      'i9': {'section2': done, 'mine': true},
      'fields': [
        {
          ..._field(
            'i9_form',
            'Form I-9, signed',
            'file',
            filledBy: 'employee',
            writable: false,
            value: 'att-9',
          ),
          'tax_form_kind': 'i9',
        },
      ],
    };

    await _pumpForm(tester, _handler(form: i9(false)));
    await _onSide(tester, 'Employee');
    expect(find.byKey(const Key('i9-section2-open')), findsOneWidget);
    expect(find.textContaining('Section 2 is the employer'), findsOneWidget);
  });

  testWidgets('an I-9 with both sections signed says so', (tester) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._asEmployer,
          'i9': {'section2': true, 'mine': true},
          'fields': [
            {
              ..._field(
                'i9_form',
                'Form I-9, signed',
                'file',
                filledBy: 'employee',
                writable: false,
                value: 'att-9',
              ),
              'tax_form_kind': 'i9',
            },
          ],
        },
      ),
    );
    await _onSide(tester, 'Employee');
    expect(find.byKey(const Key('i9-section2-open')), findsNothing);
    expect(find.text('Signed I-9 attached, both sections.'), findsOneWidget);
  });

  // The standard pay terms retire pay basis (2026-10-06): not asked again,
  // and shown only where an earlier answer is on record.
  testWidgets('a retired question with no answer is not on the form', (
    tester,
  ) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._asEmployer,
          'fields': [
            {
              ..._field(
                'pay_basis',
                'Pay basis',
                'choice',
                filledBy: 'employer',
                writable: false,
                options: const ['Hourly', 'Salary'],
              ),
              'retired': true,
            },
            _field(
              'hourly_rate',
              'Hourly rate',
              'number',
              filledBy: 'employer',
              writable: true,
            ),
          ],
        },
      ),
    );
    expect(find.text('Pay basis'), findsNothing);
    expect(find.text('Hourly rate'), findsOneWidget);
  });

  testWidgets('a county the search cannot find is saved as typed', (
    tester,
  ) async {
    Map<String, dynamic>? saved;

    await _pumpForm(
      tester,
      _handler(form: _catalogForm, onSave: (body) => saved = body),
    );

    await _type(tester, 'county', 'Cook');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(
      find.text('Not in the list. What you typed is saved as the county.'),
      findsOneWidget,
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {'county': 'Cook'},
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

    // What was typed before the picker cannot be read as a week, so it opens
    // as typed availability.
    expect(find.text('Work days and work hours'), findsOneWidget);
    expect(find.byKey(answerKey('work_days')), findsNothing);
    expect(find.byKey(const Key('onboarding-schedule-typed')), findsOneWidget);
    expect(find.byKey(const Key('onboarding-schedule')), findsNothing);

    await _tap(tester, find.text('Set days and hours'));
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

  // Jose, 2026-10-05: some technicians have availability rather than set
  // hours; "it should be one or the other, never both".
  testWidgets(
    'availability can be typed instead, and the days then do not apply',
    (tester) async {
      Map<String, dynamic>? saved;

      await _pumpForm(
        tester,
        _handler(form: _catalogForm, onSave: (body) => saved = body),
      );

      await _tap(tester, find.text('Set days and hours'));
      await _tap(tester, find.text('Type availability'));
      await tester.enterText(
        find.byKey(const Key('onboarding-schedule-typed')),
        'Mornings, Monday to Thursday',
      );
      await tester.pumpAndSettle();

      await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
      expect(saved, {
        'answers': {
          'work_hours': 'Mornings, Monday to Thursday',
          'work_days': 'N/A',
        },
      });
    },
  );

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
    await _type(
      tester,
      null,
      'Bea Okafor',
      label: 'Type your full name to sign',
    );
    await _draw(tester);
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
      'back marked so', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(form: _voidForm, onSave: (body) => saved = body),
    );

    // Stored "N/A": greyed and still, the interval picker showing nothing.
    expect(_frozen(tester, 'raise_period'), isTrue);
    expect(_dropdown(tester, 'raise_period').value, isNull);

    // ✗ voids the amount, and a number box does not call it "not a number".
    expect(_frozen(tester, 'raise_amount'), isFalse);
    await _tap(tester, find.byKey(const Key('void:raise_amount')));
    expect(_frozen(tester, 'raise_amount'), isTrue);
    expect(_box(tester, 'raise_amount').controller!.text, isEmpty);
    expect(
      find.text('Not a number: digits and one decimal point only.'),
      findsNothing,
    );

    // ✓ brings the interval back, empty.
    await _tap(tester, find.byKey(const Key('apply:raise_period')));
    expect(_frozen(tester, 'raise_period'), isFalse);

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {'raise_amount': 'N/A', 'raise_period': ''},
    });
  });

  // Jose: "voiding Scheduled increase interval voids itself and scheduled
  // increase amount". HR sends the group as `void_group`.
  testWidgets('voiding one term of a group voids the group, and ✓ brings '
      'it all back', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(form: _groupForm, onSave: (body) => saved = body),
    );

    await _tap(tester, find.byKey(const Key('void:raise_period')));
    expect(_frozen(tester, 'raise_amount'), isTrue);
    expect(_frozen(tester, 'raise_period'), isTrue);

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {'raise_amount': 'N/A', 'raise_period': 'N/A'},
    });

    await _tap(tester, find.byKey(const Key('apply:raise_amount')));
    expect(_frozen(tester, 'raise_amount'), isFalse);
    expect(_frozen(tester, 'raise_period'), isFalse);
  });

  // Jose, 2026-10-01: "the employer should also be able to null the employee
  // side stuff too, like ... photo in uniform". HR 4c43b180 sends the
  // employee's questions voidable, not writable, in the employer's view.
  testWidgets('the employer marks an employee question as not applying, '
      'files and their group included', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(form: _employerVoidsForm, onSave: (body) => saved = body),
    );
    await _onSide(tester, 'Employee');

    expect(_frozen(tester, 'drivers_license_back'), isFalse);
    await _tap(tester, find.byKey(const Key('void:drivers_license_front')));
    expect(_frozen(tester, 'drivers_license_front'), isTrue);
    expect(_frozen(tester, 'drivers_license_back'), isTrue);
    expect(_frozen(tester, 'uniform_photo'), isFalse);
    expect(find.text("Doesn't apply."), findsNWidgets(2));

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {
        'drivers_license_front': 'N/A',
        'drivers_license_back': 'N/A',
      },
    });
  });

  testWidgets('a finished record offers nothing to mark as not applying', (
    tester,
  ) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._employerVoidsForm,
          'fields': [
            for (final f in _employerVoidsForm['fields'] as List)
              {...(f as Map<String, Object?>), 'writable': false},
          ],
        },
      ),
    );

    expect(find.byKey(const Key('void:uniform_photo')), findsNothing);
    expect(find.byKey(const Key('void:drivers_license_front')), findsNothing);
  });

  // Jose, 2026-10-01: "this is not something he should upload, it should be
  // signed within the app as well, check how a1tools did it".
  testWidgets('the W-4 is filled in and signed in a panel, then shows as '
      'attached', (tester) async {
    final drawn = pdfPages;
    addTearDown(() => pdfPages = drawn);
    pdfPages = (pdf) => Stream.value(_png);

    final calls = <(Map<String, String>, Map<String, dynamic>)>[];
    await _pumpForm(
      tester,
      _handler(
        form: _taxFormForm,
        onTaxForm: (query, body) => calls.add((query, body)),
      ),
    );

    expect(find.text('Not signed yet.'), findsOneWidget);
    await _tap(tester, find.byKey(const Key('tax-form-open')));
    final panel = find.byType(TaxFormPanel);
    expect(panel, findsOneWidget);

    // The page as it would print, drawn before anything is chosen; the SSN
    // on file is not asked again.
    expect(calls.single.$1, {'preview': '1'});
    expect(calls.single.$2['kind'], 'w4');
    expect((calls.single.$2['values'] as Map).containsKey('ssn'), isFalse);
    expect(
      find.descendant(of: panel, matching: find.byKey(const Key('pdf-page-0'))),
      findsOneWidget,
    );

    FilledButton sign() =>
        tester.widget<FilledButton>(find.byKey(const Key('tax-form-sign')));
    expect(sign().onPressed, isNull);

    await _tap(tester, find.byKey(const Key('tax-form-status-single')));
    final pad = find.descendant(
      of: panel,
      matching: find.byKey(const Key('signature-pad')),
    );
    await tester.ensureVisible(pad);
    await tester.pumpAndSettle();
    await tester.drag(pad, const Offset(60, 20));
    await tester.pumpAndSettle();
    expect(sign().onPressed, isNotNull);

    // Waits for the sign request itself, not a fixed time: the drawing is
    // encoded off the fake clock, and on a loaded machine 300 ms was not
    // always enough (desk, 0.0.0374 pre-check).
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('tax-form-sign')));
      final deadline = DateTime.now().add(const Duration(seconds: 20));
      while (!calls.any((call) => call.$1.isEmpty) &&
          DateTime.now().isBefore(deadline)) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
    });
    await tester.pumpAndSettle();

    final signed = calls.lastWhere((call) => call.$1.isEmpty);
    expect(signed.$1, isEmpty);
    expect(signed.$2['kind'], 'w4');
    expect((signed.$2['values'] as Map)['filing_status'], 'single');
    expect(signed.$2['drawing'], startsWith('data:image/png;base64,'));

    expect(find.byType(TaxFormPanel), findsNothing);
    expect(find.text('Signed W-4 attached.'), findsOneWidget);
    expect(find.byKey(const Key('tax-form-view')), findsOneWidget);

    // The signed form is a picture of the page now (HR caae3171).
    await _tap(tester, find.byKey(const Key('tax-form-view')));
    expect(find.byKey(const Key('tax-form-signed-page')), findsOneWidget);
  });

  testWidgets('a server that does not sign the tax form here still takes an '
      'upload', (tester) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._taxFormForm,
          'vocabulary': {
            ..._catalogue,
            'field_types': [
              ...(_catalogue['field_types']! as List),
              {'key': 'file', 'label': 'A file'},
            ],
          },
          'fields': [
            for (final f in _taxFormForm['fields'] as List)
              (f as Map<String, Object?>)['key'] == 'tax_form'
                  ? ({...f}..remove('tax_form_kind'))
                  : f,
          ],
        },
      ),
    );

    expect(find.byKey(const Key('tax-form-open')), findsNothing);
    expect(find.text('Choose a file'), findsOneWidget);
  });

  testWidgets('before the form is known the tax form waits for the tax ID', (
    tester,
  ) async {
    await _pumpForm(
      tester,
      _handler(
        form: {
          ..._taxFormForm,
          'fields': [
            for (final f in _taxFormForm['fields'] as List)
              (f as Map<String, Object?>)['key'] == 'tax_form'
                  ? {...f, 'tax_form_kind': null}
                  : f,
          ],
        },
      ),
    );

    expect(find.byKey(const Key('tax-form-open')), findsNothing);
    expect(find.text('Answer your tax ID question first.'), findsOneWidget);
  });

  testWidgets('the state comes before the county, whatever the contract says', (
    tester,
  ) async {
    await _pumpForm(tester, _handler(form: _countyFirstForm));

    final state = tester.getTopLeft(find.byKey(answerKey('state')));
    final county = tester.getTopLeft(find.byKey(answerKey('county')));
    expect(state.dy, lessThan(county.dy));
  });

  // Jose: "like in the rest of the app should have separate fields, with full
  // address being the box where u start typing and it suggests".
  testWidgets('the home address is answered in its parts, and they go out '
      'under its own key, apart from the governing state', (tester) async {
    Map<String, dynamic>? saved;
    await _pumpForm(
      tester,
      _handler(form: _addressForm, onSave: (body) => saved = body),
    );

    final address = find.byKey(answerKey('address'));
    Finder box(String label) => find.descendant(
      of: address,
      matching: find.widgetWithText(TextField, label),
    );
    String text(String label) =>
        tester.widget<TextField>(box(label)).controller!.text;

    expect(text('Street'), '41 Elm St');
    expect(text('ST'), 'CT');
    expect(text('Country'), 'US');

    // The server composes the stored line its own way (no country here), and
    // a tap that only moves the cursor is not an answer.
    await _tap(tester, box('Street'));
    expect(find.text('Everything on this page is saved.'), findsOneWidget);

    await tester.ensureVisible(box('City'));
    await tester.enterText(box('City'), 'Norwalk');
    await tester.pumpAndSettle();
    expect(find.text('1 answer not saved yet.'), findsOneWidget);

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));
    expect(saved, {
      'answers': {
        'address': {
          'address_line1': '41 Elm St',
          'address_line2': '',
          'city': 'Norwalk',
          'state': 'CT',
          'postal_code': '06901',
          'country': 'US',
        },
      },
    });
  });

  // Jose: "PDF (Final Result view)".
  testWidgets('PDF shows the contract as it would print, redrawn as the '
      'answers change, and Live goes back to the text', (tester) async {
    final drawn = pdfPages;
    addTearDown(() => pdfPages = drawn);
    final rasterized = <String>[];
    pdfPages = (pdf) {
      rasterized.add(utf8.decode(pdf));
      return Stream.fromIterable([_png, _png]);
    };

    final asked = <Map<String, dynamic>>[];
    await _pumpForm(
      tester,
      _handler(
        form: _asEmployee,
        onPreview: (answers) => '<p>Uniform: as typed</p>',
        onPdf: (body) {
          asked.add(body);
          return Uint8List.fromList(
            utf8.encode('%PDF-1.7 draft ${asked.length}'),
          );
        },
      ),
    );

    Finder view(String label) => find.descendant(
      of: find.byKey(const Key('contract-view')),
      matching: find.text(label),
    );

    expect(find.textContaining('Uniform: as typed'), findsOneWidget);
    expect(asked, isEmpty);

    await _tap(tester, view('PDF'));
    expect(asked, hasLength(1));
    expect(find.byKey(const Key('pdf-page-0')), findsOneWidget);
    expect(find.byKey(const Key('pdf-page-1')), findsOneWidget);
    expect(find.textContaining('Uniform: as typed'), findsNothing);

    await _type(tester, 'shirt_size_note', 'A jacket');
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pumpAndSettle();
    expect(asked, hasLength(2));
    expect(asked.last['answers'], containsPair('shirt_size_note', 'A jacket'));
    expect(rasterized, ['%PDF-1.7 draft 1', '%PDF-1.7 draft 2']);

    await _tap(tester, view('Live'));
    expect(find.byKey(const Key('pdf-page-0')), findsNothing);
    expect(find.textContaining('Uniform: as typed'), findsOneWidget);
  });

  testWidgets('a server that answers the PDF with something else is said '
      'so, not drawn', (tester) async {
    await _pumpForm(
      tester,
      _handler(
        form: _asEmployee,
        onPreview: (answers) => '<p>Uniform: as typed</p>',
        onPdf: (body) => Uint8List.fromList(utf8.encode('{"data":{}}')),
      ),
    );

    await _tap(
      tester,
      find.descendant(
        of: find.byKey(const Key('contract-view')),
        matching: find.text('PDF'),
      ),
    );
    expect(find.byKey(const Key('pdf-page-0')), findsNothing);
    expect(
      find.textContaining('The PDF view is not available yet.'),
      findsOneWidget,
    );
  });

  // Jose: "Dispatcher Master 5000 should be highlighted on the contract".
  testWidgets('the preview is asked to mark what changed since the page '
      'opened', (tester) async {
    final bodies = <Map<String, dynamic>>[];
    await _pumpForm(
      tester,
      _handler(
        form: _asEmployee,
        onPreview: (answers) => '<p>Uniform</p>',
        onPreviewBody: bodies.add,
      ),
    );

    expect(bodies.first['highlight'], isEmpty);

    await _type(tester, 'shirt_size_note', 'A jacket');
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pumpAndSettle();
    expect(bodies.last['highlight'], ['shirt_size_note']);
  });
}

/// Whether a question's control is held still because it does not apply.
bool _frozen(WidgetTester tester, String key) => tester
    .widget<IgnorePointer>(
      find
          .ancestor(
            of: find.byKey(answerKey(key)),
            matching: find.byType(IgnorePointer),
          )
          .first,
    )
    .ignoring;

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
      home: Scaffold(
        body: OnboardingTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpHr(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(
    client: MockClient(
      _withMe(handler, const [
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
      ]),
    ),
  );
  final session = Session(api);

  await _sized(tester);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: HrScreen(api: api, session: session),
      ),
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

/// Open the Employer or Employee tab over the form (design 135, section 7).
Future<void> _onSide(WidgetTester tester, String side) async {
  await _tap(
    tester,
    find.descendant(
      of: find.byKey(const Key('onboarding-sides')),
      matching: find.textContaining('$side ·'),
    ),
  );
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

/// A stroke across the signature pad.
Future<void> _draw(WidgetTester tester) async {
  final pad = find.byKey(const Key('signature-pad'));
  await tester.ensureVisible(pad);
  await tester.pumpAndSettle();
  final page = Scrollable.of(tester.element(pad)).position;
  final at = page.pixels;
  // Mostly downward, which is the stroke a scrolling page would take. Read
  // before the next frame: a hint going away below can shorten the page.
  await tester.drag(pad, const Offset(40, 80));
  expect(page.pixels, at, reason: 'The stroke scrolled the page.');
  await tester.pumpAndSettle();
}

/// Sign, pressed for real: drawing the PNG needs the engine, and a fake
/// clock never lets that finish.
Future<void> _pressSign(WidgetTester tester, bool Function() sent) async {
  final sign = find.widgetWithText(FilledButton, 'Sign');
  await tester.ensureVisible(sign);
  await tester.pumpAndSettle();
  await _tapUntil(tester, sign, sent);
}

/// Tap, then wait off the fake clock until [done] holds: the request the tap
/// starts, not a fixed time, which a loaded machine outran (desk, 0.0.0374).
Future<void> _tapUntil(
  WidgetTester tester,
  Finder target,
  bool Function() done,
) async {
  await tester.runAsync(() async {
    await tester.tap(target);
    final deadline = DateTime.now().add(const Duration(seconds: 20));
    while (!done() && DateTime.now().isBefore(deadline)) {
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
  });
  await tester.pumpAndSettle();
}

/// Type into the box answering a field, or into the one carrying a label.
ChoiceChip _chip(WidgetTester tester, String key) => tester.widget<ChoiceChip>(
  find
      .descendant(
        of: find.byKey(answerKey(key)),
        matching: find.byType(ChoiceChip),
      )
      .first,
);

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
  await _tap(tester, find.widgetWithText(SearchPickerField<String>, label));
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

SearchPickerField<String> _dropdown(WidgetTester tester, String fieldKey) =>
    tester.widget<SearchPickerField<String>>(find.byKey(answerKey(fieldKey)));

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

FilledButton _startButton(WidgetTester tester) =>
    tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Start'));

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
  void Function(Map<String, dynamic> body)? onPreviewBody,

  /// The draft PDF `POST …/preview?format=pdf` answers with.
  Uint8List Function(Map<String, dynamic> body)? onPdf,

  /// `GET …/document/pdf`, the signed copy: called with nothing, counts asks.
  void Function()? onSignedPdf,

  /// The signer's saved signature, `GET /me/signature`; null when none.
  String? savedSignature,
  void Function(Map<String, dynamic> body)? onSaveSignature,

  /// `POST …/tax-form`: the query and body of each call, previews included.
  void Function(Map<String, String> query, Map<String, dynamic> body)?
  onTaxForm,
  void Function(Map<String, String> query)? onCounties,
  void Function(Map<String, String> query)? onList,
  void Function()? onApprove,
}) {
  final seen = form ?? _asEmployer;

  return (request) async {
    final path = request.url.path;
    final body = request.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(request.body) as Map<String, dynamic>;

    if (path.endsWith('/hr/onboardings/o-1/tax-form')) {
      onTaxForm?.call(request.url.queryParameters, body);
      if (request.url.queryParameters['preview'] == '1') {
        return http.Response.bytes(
          utf8.encode('%PDF-1.7 ${body['kind']}'),
          200,
          headers: {'content-type': 'application/pdf'},
        );
      }
      return _json({
        'data': {
          ...seen,
          'fields': [
            for (final f in seen['fields']! as List)
              (f as Map)['key'] == 'tax_form' ? {...f, 'value': 'att-9'} : f,
          ],
        },
      });
    }

    if (path.endsWith('/hr/onboardings/o-1/approve')) {
      onApprove?.call();
      return _json({
        'data': {...seen, 'status': 'completed', 'approval': null},
      });
    }

    if (path.endsWith('/attachments/att-9')) {
      return http.Response.bytes(
        _png,
        200,
        headers: {'content-type': 'image/png'},
      );
    }

    if (path.endsWith('/me/signature')) {
      if (request.method == 'PUT') {
        onSaveSignature?.call(body);
        return _json({
          'data': {'drawing': body['drawing']},
        });
      }
      return _json({
        'data': {'drawing': savedSignature},
      });
    }

    if (onPdf != null &&
        path.endsWith('/hr/onboardings/o-1/preview') &&
        request.url.queryParameters['format'] == 'pdf') {
      return http.Response.bytes(
        onPdf(body),
        200,
        headers: {'content-type': 'application/pdf'},
      );
    }

    if (onPreview != null && path.endsWith('/hr/onboardings/o-1/preview')) {
      onPreviewBody?.call(body);
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

    if (path.endsWith('/hr/onboardings/o-1/document/pdf')) {
      onSignedPdf?.call();
      return http.Response.bytes(
        utf8.encode('%PDF-1.4 signed'),
        200,
        headers: {'content-type': 'application/pdf'},
      );
    }

    if (path.endsWith('/hr/onboardings/o-1/document')) {
      return _json({
        'data': seen['status'] == 'completed' ? _asRecordDocument : _document(),
      });
    }

    // Revealing one sensitive answer: a GET at the field's own address, which
    // is what makes ASKING the reveal rather than opening the screen.
    if (path.contains('/hr/onboardings/o-1/answers/')) {
      final field = path.split('/').last;
      onReveal?.call({'field_key': field});

      if (revealStatus != 200) {
        return _json({
          'message': 'You may not see this value in the clear.',
        }, revealStatus);
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

      onList?.call(request.url.queryParameters);

      // Filtered the way the endpoint is: the Records tab asks for one
      // person's onboardings, and everything else asks for all of them.
      final only = request.url.queryParameters['user_id'];

      final rows = only == null
          ? _list
          : _list.where((row) {
              final person = row['person'] as Map<String, Object?>?;
              return person?['user_id'] == only;
            }).toList();

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

    if (request.method == 'GET' &&
        RegExp(r'/hr/people/u-\d+$').hasMatch(path)) {
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
    {'key': 'checklist', 'label': 'Items received'},
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
  'missing': readOnly ? <String, String>{} : {'notice_period': 'Notice period'},
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
      _field(
        key,
        label,
        'text',
        filledBy: 'employer',
        writable: true,
        value: value,
      ),
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
    'downloadable': true,
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
      ..._field(
        'raise_amount',
        'Scheduled increase amount',
        'number',
        filledBy: 'employer',
        writable: true,
        value: '0',
      ),
      'voidable': true,
    },
    {
      ..._field(
        'raise_period',
        'Scheduled increase interval',
        'text',
        filledBy: 'employer',
        writable: true,
        value: 'N/A',
      ),
      'voidable': true,
    },
  ],
  'missing': <String, String>{},
};

/// One white pixel, standing in for a drawn page.
final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
);

/// The raise's two terms in one void group, both answered.
final _groupForm = {
  ..._asEmployer,
  'fields': [
    {
      ..._field(
        'raise_amount',
        'Scheduled increase amount',
        'number',
        filledBy: 'employer',
        writable: true,
        value: '0',
      ),
      'voidable': true,
      'void_group': 'raise',
    },
    {
      ..._field(
        'raise_period',
        'Scheduled increase interval',
        'text',
        filledBy: 'employer',
        writable: true,
        value: 'Year',
      ),
      'voidable': true,
      'void_group': 'raise',
    },
  ],
  'missing': <String, String>{},
};

/// The employee's half: their home address in its parts, beside the
/// contract's own governing state.
final _addressForm = {
  ..._asEmployee,
  'fields': [
    {
      ..._field(
        'address',
        'Home address',
        'text',
        source: 'core',
        filledBy: 'employee',
        writable: true,
        value: '41 Elm St, Stamford, CT 06901',
      ),
      'parts': {
        'address_line1': '41 Elm St',
        'address_line2': null,
        'city': 'Stamford',
        'state': 'CT',
        'postal_code': '06901',
        'country': 'US',
      },
    },
    _field(
      'state',
      'Governing state',
      'text',
      filledBy: 'employer',
      writable: false,
      value: 'FL',
    ),
  ],
  'missing': <String, String>{},
};

/// The employer's view of an employee's files: theirs to void, not to
/// upload, beside one term of the employer's own.
final _employerVoidsForm = {
  ..._asEmployer,
  'vocabulary': {
    ..._catalogue,
    'field_types': [
      ...(_catalogue['field_types']! as List),
      {'key': 'file', 'label': 'A file'},
    ],
  },
  'fields': [
    _field(
      'hourly_wage',
      'Hourly wage',
      'number',
      filledBy: 'employer',
      writable: true,
      value: '22.50',
    ),
    {
      ..._field(
        'uniform_photo',
        'Photo in uniform',
        'file',
        filledBy: 'employee',
        writable: false,
      ),
      'voidable': true,
      'void_group': 'uniform_photo',
    },
    {
      ..._field(
        'drivers_license_front',
        "Driver's licence, front",
        'file',
        filledBy: 'employee',
        writable: false,
        value: 'att-1',
      ),
      'voidable': true,
      'void_group': 'drivers_license',
    },
    {
      ..._field(
        'drivers_license_back',
        "Driver's licence, back",
        'file',
        filledBy: 'employee',
        writable: false,
      ),
      'voidable': true,
      'void_group': 'drivers_license',
    },
  ],
  'missing': <String, String>{},
};

/// The new hire's half with the SSN answered and the W-4 still to sign.
final _taxFormForm = {
  ..._asEmployee,
  'fields': [
    _field(
      'ssn',
      'Social Security number',
      'text',
      filledBy: 'employee',
      writable: true,
      value: '123-45-6789',
    ),
    {
      ..._field(
        'tax_form',
        'W-4 (employee) or W-9 (contractor), signed',
        'file',
        filledBy: 'employee',
        writable: true,
      ),
      'tax_form_kind': 'w4',
    },
  ],
  'missing': <String, String>{},
};

/// A contract version that asks for the county first.
final _countyFirstForm = {
  ..._asEmployer,
  'fields': [
    _field(
      'county',
      'County for disputes',
      'text',
      filledBy: 'employer',
      writable: true,
      value: 'Broward',
    ),
    _field(
      'state',
      'Governing state',
      'text',
      filledBy: 'employer',
      writable: true,
      value: 'FL',
    ),
  ],
  'missing': <String, String>{},
};
