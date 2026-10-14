import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/contract_body_editor.dart';
import 'package:ezbizi_client/screens/hr/contract_cover_panel.dart';
import 'package:ezbizi_client/screens/hr/contract_document_page.dart';
import 'package:ezbizi_client/screens/hr/contract_form_preview.dart';
import 'package:ezbizi_client/screens/hr/contracts_tab.dart';
import 'package:ezbizi_client/widgets/numbered_step.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Contracts tab — the template designer, and the schema for everything
/// else in Human Resources.
///
/// ── What is actually being defended here ─────────────────────────
///
/// Four things, and they fail in different ways.
///
/// The first is that NONE of the vocabulary is compiled into Dart. The kinds of
/// question, the words for them, who a question can be filled by and the common
/// core all come from `GET /hr/contracts/catalogue`, and the fixture below is
/// written so that a client carrying its own copy cannot pass: the types are
/// three rather than six, every label is one nobody would guess, and the common
/// core contains `shoe_size` and does NOT contain `date_of_birth`. A screen
/// showing "Number" or "Date of birth" is a screen reading a list in Dart.
///
/// The second is that `filled_by` is legible. It decides who sees what, it is
/// the property a person will get wrong, and the answer this screen gives is to
/// draw the two forms — so the preview is tested as a feature, because it is
/// one.
///
/// The third is that a removed field is RETIRED and stays visible. Records
/// already carry answers to it, and a designer that made it disappear would be
/// describing a deletion the server refuses to perform.
///
/// The fourth is that publishing shows what it is about to do, and does nothing
/// until somebody has read it.
void main() {
  testWidgets('the list says what each contract is and what it asks', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Technician, full time'), findsOneWidget);
    expect(find.text('v3'), findsOneWidget);

    // The server's count is of the questions still ASKED — the template carries
    // four and one is retired — so the wording has to say which number it is,
    // or a template that has been tidied up reads as one that shrank.
    // Under a Questions heading now (layout A, Mia 2026-10-02).
    expect(find.text('3 asked today'), findsOneWidget);
  });

  /// *"We are missing something critical which is the contract writing itself"*
  /// — a template with no wording produces a dump of the answers, which is a
  /// record and not an agreement. `has_body` is on every row for this, and the
  /// list read past it, so four templates looked identical whether or not
  /// anybody had written them.
  testWidgets('the list says which templates have been written and which are '
      'still only questions', (tester) async {
    await _pump(tester, _handler(templates: _twoTemplates));

    // A Wording column, and a tile counting each (layout A, Mia 2026-10-02).
    expect(find.text('Written'), findsOneWidget);
    expect(find.text('Questions only'), findsOneWidget);

    await tester.tap(find.byKey(const Key('contracts-tile-questions')));
    await tester.pumpAndSettle();
    expect(find.text('Written'), findsNothing);
    expect(find.text('Questions only'), findsOneWidget);
  });

  /// Jose, 2026-10-14: "we lack searchbars on the 3 last human resources
  /// tabs". Every contract is loaded, so the search is the client's.
  testWidgets('the search box narrows contracts by name or description', (
    tester,
  ) async {
    await _pump(tester, _handler(templates: _twoTemplates));

    await tester.enterText(
      find.byKey(const ValueKey('tour-hr-contracts-search')),
      'vans',
    );
    await tester.pumpAndSettle();
    expect(find.text('Technician, full time'), findsOneWidget);
    expect(find.text('Weekend cover'), findsNothing);
  });

  /// §2.1, and the whole point of this screen. The catalogue's types are
  /// deliberately three, in wording nobody would invent.
  testWidgets('the kinds of question come from the server, all of them', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    final types = _dropdown(tester, 'Kind of answer');

    expect(types.options.map((o) => o.label), [
      'A line of words',
      'Pick from a list',
      'A day',
      'A scrawl',
      'One of the shifts',
    ]);

    // The real server declares six. A client with the list compiled in would
    // offer the other three against a catalogue that never mentioned them.
    expect(find.text('Number'), findsNothing);
    expect(find.text('A signature'), findsNothing);
  });

  /// §2.1 again, one property along. Which kinds of question carry a list is
  /// the server's answer, and the designer used to decide it by comparing the
  /// type to the literal `'choice'` in Dart.
  testWidgets('the options editor follows what the server says takes a list, '
      'not the word “choice”', (tester) async {
    await _pump(tester, _handler());

    await _tap(tester, find.text('New contract'));
    await _tap(tester, find.text('Add a question'));

    const box = 'The answers to choose from';

    // Nothing chosen yet, so nothing to type into.
    expect(find.widgetWithText(TextField, box), findsNothing);

    // `rota` is not `choice`, and the catalogue says it carries a list. This is
    // the seventh type: a client reading its own literal offers it no editor.
    await _choose(tester, 'Kind of answer', 'One of the shifts');
    expect(
      find.widgetWithText(TextField, box),
      findsOneWidget,
      reason:
          'The catalogue declared takes_options on this kind and the box is '
          'not there, so the screen is reading a list of its own.',
    );

    // And a kind that does not carry one gets no box, whatever it is called.
    await _choose(tester, 'Kind of answer', 'A day');
    expect(find.widgetWithText(TextField, box), findsNothing);
  });

  /// The 422 nobody could act on: the options box goes off screen when the kind
  /// changes, the text stays in its controller, and it used to be sent anyway —
  /// so the server refused the save over options the person could not see.
  testWidgets('options typed for one kind of question are not sent for '
      'another', (tester) async {
    Map<String, dynamic>? created;

    await _pump(tester, _handler(onCreate: (body) => created = body));

    await _tap(tester, find.text('New contract'));
    await _type(tester, 'Name', 'Contractor');
    await _tap(tester, find.text('Add a question'));
    await _type(tester, 'The question', 'Uniform');

    await _choose(tester, 'Kind of answer', 'Pick from a list');
    await _type(tester, 'The answers to choose from', 'Small\nMedium\nLarge');

    // Changed their mind. The box disappears; the typing does not.
    await _choose(tester, 'Kind of answer', 'A line of words');
    expect(find.widgetWithText(TextField, 'The answers to choose from'),
        findsNothing);

    // Changing back restores what was typed rather than having thrown it away —
    // which is why the answer is to stop SENDING the options rather than to
    // clear them when the kind changes.
    await _choose(tester, 'Kind of answer', 'Pick from a list');
    expect(find.text('Small\nMedium\nLarge'), findsOneWidget);

    await _choose(tester, 'Kind of answer', 'A line of words');
    await _tap(tester, find.widgetWithText(ChoiceChip, 'The new starter'));
    await _tap(tester, find.text('Create the contract'));

    expect(
      (created!['fields'] as List).single['options'],
      isEmpty,
      reason:
          'A text field carrying three options is refused by the server, and '
          'the person cannot see the options it is complaining about.',
    );
  });

  testWidgets('who fills a question in is three visible answers, in the '
      'server’s words', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // All three on screen at once rather than behind a dropdown: it is a
    // three-way decision and hiding two thirds of it is how it gets made wrong.
    for (final label in [
      'Head office',
      'The new starter',
      'Whoever gets there first',
    ]) {
      expect(
        find.widgetWithText(ChoiceChip, label),
        findsWidgets,
        reason: '“$label” came from the catalogue and is not on screen.',
      );
    }

    // And the consequence of the chosen one, also the server's sentence.
    expect(
      find.text('Somebody at head office answers it.'),
      findsWidgets,
      reason: 'The chip says which; this says what it means.',
    );
  });

  /// The plan's one-home rule, and the reason it is drawn at all: without it
  /// somebody adds "First name" of their own and wonders why the form asks
  /// twice.
  testWidgets('the common core is shown, locked, and comes from the server', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    expect(find.text('Always asked'), findsOneWidget);

    // The fixture's core, which no Dart list could have produced. `findsWidgets`
    // because step 4 draws the same core questions again as part of the form —
    // which is the point of step 4.
    expect(find.text('Work email address'), findsWidgets);
    expect(find.text('Shoe size'), findsWidgets);

    // The plan names six and the fixture names two. A client reading the plan
    // instead of the server would show the other four.
    expect(find.text('Date of birth'), findsNothing);
    expect(find.text('First name'), findsNothing);

    // Not editable: there is no box to type into for either of them, and the
    // only text boxes on the page belong to the template's own questions.
    expect(find.widgetWithText(TextField, 'Work email address'), findsNothing);
  });

  /// The endpoint's `is` is a phrase completing "it is …", and it was the only
  /// string it published — so the client, having nothing better, used it as a
  /// caption. The designer drew a locked chip reading "their middle name" and
  /// the preview titled a box "the email address on their account".
  ///
  /// Sixteen tests passed over that, because the fixture put title-case nouns
  /// in `is` and the real server never does.
  testWidgets('a reserved key is captioned with its label, never with the '
      'phrase that describes it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    expect(find.text('Work email address'), findsWidgets);

    // The phrase is a sentence fragment. It belongs inside a sentence, and
    // there is no caption on this screen it could correctly be.
    for (final phrase in [
      'the work email address on their account',
      'the shoe size they gave us',
      'their national insurance number',
    ]) {
      expect(
        find.textContaining(phrase),
        findsNothing,
        reason: '“$phrase” completes “it is …”; it is not a name for a field.',
      );
    }

    // With no label sent, the key itself — an identifier, visibly one. A reader
    // seeing `nino` knows the server put no wording on it; a reader seeing
    // "their national insurance number" believes somebody chose that caption.
    expect(find.text('nino'), findsWidgets);
  });

  testWidgets('a question added takes its key from the wording, and cannot '
      'collide with the core', (tester) async {
    Map<String, dynamic>? created;

    await _pump(tester, _handler(onCreate: (body) => created = body));

    await _tap(tester, find.text('New contract'));
    await _type(tester, 'Name', 'Contractor');
    await _tap(tester, find.text('Add a question'));

    await _type(tester, 'The question', 'Shoe size');

    // `shoe_size` belongs to the common core — as the SERVER declared it, in a
    // fixture that invented the field — so the derived key steps around it
    // rather than producing a version the server would refuse.
    expect(find.text('Stored as shoe_size_2'), findsOneWidget);

    await _choose(tester, 'Kind of answer', 'A line of words');
    await _tap(tester, find.widgetWithText(ChoiceChip, 'The new starter'));

    await _tap(tester, find.text('Create the contract'));

    expect(created, {
      'name': 'Contractor',
      'description': null,
      // Nothing was written, so there is no wording — the same null the
      // description gets, rather than an empty string that would be a second
      // spelling of the same emptiness.
      'body': null,
      // No cover, sent as a value rather than left out. A cover is part of the
      // version, so version 1 states what its front page is even when the
      // answer is "none" — an absent key would leave the server deciding
      // between "no cover" and "the client is too old to have an opinion".
      'cover': null,
      'fields': [
        {
          'key': 'shoe_size_2',
          'label': 'Shoe size',
          'type': 'text',
          'options': <String>[],
          'filled_by': 'employee',
          'required': false,
          'sensitive': false,
          'is_expiry': false,
          'retired': false,
        },
      ],
    });
  });

  testWidgets('questions can be reordered, and the order is what gets sent', (
    tester,
  ) async {
    Map<String, dynamic>? previewed;

    await _pump(tester, _handler(onPreview: (body) => previewed = body));
    await _tap(tester, find.text('Design'));

    // The wage is asked first; move it below the bank account.
    await _tap(tester, find.byTooltip('Ask this one later').at(0));
    await _tap(tester, find.text('Review and publish'));

    expect(
      (previewed!['fields'] as List).map((f) => f['key']).toList(),
      ['bank_account', 'wage', 'date_started', 'tshirt'],
      reason: 'The order of the list is the order of the form.',
    );
  });

  /// Fields are never deleted. A record already carrying an answer would
  /// otherwise lose the question it was given to.
  testWidgets('removing a published question retires it, visibly', (
    tester,
  ) async {
    Map<String, dynamic>? previewed;

    await _pump(tester, _handler(onPreview: (body) => previewed = body));
    await _tap(tester, find.text('Design'));

    // The word on the button is "Retire", not "Delete" — a promise the server
    // would refuse.
    expect(find.byTooltip('Retire this question'), findsWidgets);
    expect(find.byTooltip('Delete this question'), findsNothing);

    await _tap(tester, find.byTooltip('Retire this question').at(0));

    // Still on the list, marked, and saying why it is still there.
    expect(find.text('Hourly wage'), findsWidgets);
    expect(find.text('Retired'), findsNWidgets(2));
    expect(find.textContaining('still shows the answer'), findsWidgets);

    await _tap(tester, find.text('Review and publish'));

    final fields = (previewed!['fields'] as List).cast<Map<String, dynamic>>();

    expect(fields.firstWhere((f) => f['key'] == 'wage')['retired'], isTrue);
    expect(
      fields.map((f) => f['key']),
      containsAll(['wage', 'bank_account', 'date_started', 'tshirt']),
      reason: 'Retiring must not drop the field from the version.',
    );
  });

  /// A field that arrived retired is drawn as retired rather than left out, and
  /// can be brought back.
  testWidgets('a field that is already retired is shown, not hidden', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    expect(find.text('T-shirt size'), findsWidgets);
    expect(find.text('Retired'), findsOneWidget);
    expect(find.text('Ask it again'), findsOneWidget);
  });

  /// `filled_by` drawn rather than written down. A question in the wrong list
  /// here is a question in the wrong list on the day somebody starts.
  testWidgets('the preview shows each side only what that side is asked', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // It opens on the employee's side — the one the designer cannot otherwise
    // see, and the one the plan asks for.
    expect(_inPreview('Bank account number'), findsOneWidget);
    expect(_inPreview('Hourly wage'), findsNothing);

    // `either` is in both forms, and says so.
    expect(_inPreview('First day'), findsOneWidget);
    expect(_inPreview('Either of you'), findsWidgets);

    // The reserved keys name no side, so they are on both forms — which is the
    // plan's rule for them: the employer MAY fill them in when inviting and
    // does not have to, and whatever is left blank the person completes.
    expect(_inPreview('Work email address'), findsOneWidget);
    expect(_inPreview('Shoe size'), findsOneWidget);

    // Retired questions are not asked, which is the whole of what retiring is.
    expect(_inPreview('T-shirt size'), findsNothing);

    await _tap(tester, find.text('What you fill in'));

    expect(_inPreview('Hourly wage'), findsOneWidget);
    expect(_inPreview('Bank account number'), findsNothing);
    expect(_inPreview('First day'), findsOneWidget);
    expect(_inPreview('Work email address'), findsOneWidget);
  });

  testWidgets('ticking sensitive says what it commits you to, and what it does '
      'not do yet', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // The bank account arrives already ticked, so the consequences are on
    // screen without anybody having to discover them.
    expect(find.textContaining('encrypted where it is stored'), findsWidgets);
    expect(find.textContaining('left out of exports'), findsWidgets);
    expect(find.textContaining('data inventory'), findsWidgets);

    // And the sentence that keeps it honest: onboarding is what asks these
    // questions, and onboarding does not exist.
    expect(
      find.textContaining('Nothing is being collected yet'),
      findsWidgets,
      reason:
          'Four behaviours stated as though they were running today would be '
          'a claim about code that does not exist, over a bank account number.',
    );
  });

  testWidgets('publishing shows the diff first, and publishes nothing until '
      'it is confirmed', (tester) async {
    var published = false;

    await _pump(tester, _handler(onPublish: () => published = true));
    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Review and publish'));

    expect(find.text('Publish version 4'), findsOneWidget);

    // Added, retired, changed and reordered — all four, in the server's words.
    expect(find.text('National insurance number'), findsOneWidget);
    expect(
      find.text(
        'A line of words · The new starter · has to be answered · '
        'sensitive',
      ),
      findsOneWidget,
    );
    expect(find.text('T-shirt size'), findsWidgets);
    expect(
      find.text('Who fills it in: Head office → The new starter'),
      findsOneWidget,
      reason:
          'The diff’s values are put through the catalogue, not printed '
          'raw — the wire says `employer`.',
    );
    expect(
      find.text('The questions are asked in a different order.'),
      findsOneWidget,
    );

    expect(
      published,
      isFalse,
      reason: 'Reading the diff is not agreeing to it.',
    );

    await _tap(tester, find.text('Publish it'));

    expect(published, isTrue);
  });

  testWidgets('a publish that changes nothing says so and offers no button', (
    tester,
  ) async {
    await _pump(tester, _handler(diff: _emptyDiff));
    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Review and publish'));

    expect(find.textContaining('Nothing has changed'), findsOneWidget);
    expect(find.text('Publish it'), findsNothing);
  });

  testWidgets('a contract with no questions of its own cannot be created', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await _tap(tester, find.text('New contract'));
    await _type(tester, 'Name', 'Contractor');

    // The button says no; the sentence beside it says why. The server refuses
    // an empty list too, and its refusal would arrive as "the fields field is
    // required" over a form somebody has just filled in.
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'Create the contract'),
          )
          .onPressed,
      isNull,
    );
    expect(find.textContaining('Add at least one question'), findsOneWidget);
  });

  /// Somebody whose role opens this tab and does not write to it. That is the
  /// ordinary answer for them, not a failure, and it must not be dressed as one.
  testWidgets('a reader who may not design is told so, and can still read', (
    tester,
  ) async {
    await _pump(tester, _handler(), permissions: const ['hr.view']);

    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'New contract'),
          )
          .onPressed,
      isNull,
    );
    expect(
      find.text('You can read what the contracts ask, but not change them.'),
      findsOneWidget,
    );

    // The list is still there, and the row invites reading rather than
    // designing.
    expect(find.text('Technician, full time'), findsOneWidget);
    expect(find.text('Read'), findsOneWidget);
  });

  /// Read-only means the whole thing is drawn and none of it can be moved.
  /// Hiding it instead would leave somebody unable to find out what their own
  /// onboarding asks.
  testWidgets('a reader can open a contract and change nothing in it', (
    tester,
  ) async {
    await _pump(tester, _handler(), permissions: const ['hr.view']);
    await _tap(tester, find.text('Read'));

    // Everything is on screen — the questions, and the form they produce.
    expect(find.text('Hourly wage'), findsWidgets);
    expect(_inPreview('Bank account number'), findsOneWidget);

    // And nothing can be added, moved or retired.
    expect(find.text('Add a question'), findsNothing);
    expect(find.byTooltip('Retire this question'), findsNothing);
    expect(find.byTooltip('Ask this one later'), findsNothing);
    expect(
      find.text('Your role can read contracts here and not change them.'),
      findsOneWidget,
    );

    // The contract itself is READABLE and not writable — read-only rather than
    // disabled, so a four-page agreement can still be scrolled through and a
    // clause copied out of it. Nothing offers to insert a tag into a document
    // this person cannot save.
    final writing = tester.widget<TextField>(_writingBox);

    expect(writing.readOnly, isTrue);
    expect(writing.controller!.text, contains('This Agreement is made on'));
    expect(find.byType(ActionChip), findsNothing);
  });

  // ── the contract body: writing it, and the page beside it ────────
  //
  // The owner's two sentences, which is what all of this is:
  //
  //   "We are missing something critical which is the contract writing itself,
  //    you have the shortcodes but nowhere to put them."
  //
  //   "on Contracts it should be 50% on one side and 50% showing the actual
  //    contract, and how it looks with white background as that's the color of
  //    pages"

  testWidgets('the contract is written on one half and drawn on the other', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // The wording the version already carries, in a box somebody can type in.
    expect(
      tester.widget<TextField>(_writingBox).controller!.text,
      contains('is made on <<date_started>>'),
      reason: 'The version’s body has to arrive in the editor, or every save '
          'would wipe the contract that was already written.',
    );

    // And the page, beside it.
    expect(find.byType(ContractDocumentPage), findsOneWidget);

    final writing = tester.getRect(_writingBox);
    final pane = tester.getRect(find.byType(ContractPagePane));

    expect(
      writing.right,
      lessThanOrEqualTo(pane.left + 1),
      reason: 'The two are meant to be side by side, not stacked.',
    );

    // “50% on one side and 50% showing the actual contract” — of the TAB. The
    // writing box is a control inside the left half with the form's padding
    // around it, so it is deliberately not measured here; the halves are.
    expect(
      pane.width,
      closeTo(1500 / 2, 4),
      reason: '“50% on one side and 50% showing the actual contract”.',
    );
  });

  /// The page is the server's rendering, not a merge worked out in Dart.
  testWidgets('the page shows the document the server rendered, with the '
      'sample answers merged in', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // Merged values, which only the server knows: it filled the tags in. And
    // `&amp;` reads as an ampersand — the business is called "Bell & Sons",
    // and a page showing its own escaping is not a picture of the document.
    // Twice, because the rendering names the business in the header and again
    // in the first line, which is what a contract does.
    expect(_inPage('Bell & Sons Chimneys'), findsNWidgets(2));
    expect(_inPage('The hourly wage is 14.50'), findsOneWidget);

    // Read as a document rather than shown as markup — the paragraph tags are
    // not on screen, and the stylesheet in the head is not prose.
    expect(_inPage('<p>'), findsNothing);
    expect(find.textContaining('font:14px'), findsNothing);
  });

  /// A mistyped tag is a problem while somebody is typing, not a refusal at
  /// the end — and the server is the one that says so.
  ///
  /// `preview()` validates the body before it renders anything, so a tag naming
  /// nothing comes back as a 422 quoting the tag. There is no document in that
  /// answer and never an `unresolved` list. What this screen used to do was
  /// read an invented `unresolved` key, compose its own sentence about it, and
  /// — when the refusal did arrive — leave the previous page on screen with
  /// nothing saying it had stopped keeping up.
  testWidgets('a refused preview shows the server’s own refusal, and admits '
      'the page underneath is stale', (tester) async {
    await _pump(tester, _handler(previewRefusal: '<<overtime_rate>>'));
    await _tap(tester, find.text('Design'));
    await _settle(tester);

    // The good page, from the wording the version already carries.
    expect(_inPage('The hourly wage is 14.50'), findsOneWidget);

    final body = tester.widget<TextField>(_writingBox).controller!;
    body.text = 'Overtime is paid at <<overtime_rate>> of the above.';
    await _settle(tester);

    // The server's sentence, naming the tag. Not one composed here.
    expect(
      find.textContaining('<<overtime_rate>> names nothing this contract can '
          'fill in'),
      findsOneWidget,
    );

    // And the admission. The page below is the one from before the typo, and
    // saying nothing about that is showing somebody a contract that is not
    // the one beside it.
    expect(_inPage('The hourly wage is 14.50'), findsOneWidget);
    expect(
      find.textContaining('the last one that rendered, from before that '
          'change'),
      findsOneWidget,
    );
  });

  /// §2.1. Which questions can be merged is the server's answer, published on
  /// the type as `merges` — not `type == 'signature'` written in Dart.
  testWidgets('a kind of question the server does not merge is never offered '
      'as a tag', (tester) async {
    await _pump(tester, _handler());

    await _tap(tester, find.text('New contract'));
    await _tap(tester, find.text('Add a question'));
    await _type(tester, 'The question', 'Their mark');
    await _choose(tester, 'Kind of answer', 'A scrawl');
    await _settle(tester);

    expect(
      find.widgetWithText(ActionChip, 'Their mark'),
      findsNothing,
      reason: 'The catalogue says this kind does not merge. Offering it puts a '
          'tag in the wording that is refused, by name, at publish.',
    );

    // The control: the same question under a kind that DOES merge is offered,
    // so this is the property being read and not the chips being broken.
    await _choose(tester, 'Kind of answer', 'A line of words');
    await _settle(tester);

    expect(find.widgetWithText(ActionChip, 'Their mark'), findsOneWidget);
  });

  /// The insertable core is `merge_tags.core`, which is NOT `reserved_keys`.
  ///
  /// The reserved list is what a template may not DECLARE — eight keys on the
  /// real server, two of which onboarding never asks. The editor offered that
  /// list, so clicking two of its chips inserted a tag the server refuses with
  /// a paragraph saying there is nothing to merge.
  testWidgets('the always-asked chips are the ones that merge, not the ones '
      'that are reserved', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));
    await _settle(tester);

    expect(
      find.widgetWithText(ActionChip, 'The name they go by'),
      findsOneWidget,
    );

    // Reserved, and not asked. A template cannot declare it and nothing fills
    // it in, so it is not a tag.
    expect(find.widgetWithText(ActionChip, 'Shoe size'), findsNothing);
    expect(find.widgetWithText(ActionChip, 'Work email address'), findsNothing);
  });

  /// The whole of the owner's complaint: the shortcodes existed and there was
  /// nowhere to put them.
  testWidgets('clicking a field drops its tag in at the cursor', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    final body = tester.widget<TextField>(_writingBox).controller!;

    body.text = 'Made on  between the parties.';
    body.selection = const TextSelection.collapsed(offset: 8);
    await tester.pump();

    // The chip carries the question's WORDING. Nobody should have to remember
    // that "First day" is stored as `date_started` — that is the entire point.
    await _tap(tester, find.widgetWithText(ActionChip, 'First day'));

    expect(
      body.text,
      'Made on <<date_started>> between the parties.',
      reason: 'At the cursor, not appended to the end.',
    );

    await _settle(tester);
  });

  /// The page does not only depend on the wording. A tag is unresolvable right
  /// up until somebody adds the question that answers it — in step 3, which
  /// never touches the body — and a pane that waited for the next keystroke
  /// would keep accusing them of a mistake they had just fixed.
  testWidgets('adding a question re-renders the page against it', (
    tester,
  ) async {
    final rendered = <Map<String, dynamic>>[];

    await _pump(tester, _handler(onRender: rendered.add));
    await _tap(tester, find.text('Design'));
    await _settle(tester);

    final before = rendered.length;

    await _tap(tester, find.text('Add a question'));
    await tester.enterText(
      find.widgetWithText(TextField, 'The question').last,
      'Overtime rate',
    );
    await _settle(tester);

    expect(rendered.length, greaterThan(before));
    expect(
      (rendered.last['fields'] as List).map((field) => field['key']),
      contains('overtime_rate'),
      reason: 'The server has to be re-asked with the question that answers '
          'the tag, or the page still says nothing fills it in.',
    );
  });

  /// §2.1. The facts a contract can merge in are the server's list, and this
  /// fixture's are ones nobody would guess.
  testWidgets('the always-available facts are the server’s, never a list in '
      'Dart', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    expect(find.widgetWithText(ActionChip, 'What the business trades as'),
        findsOneWidget);
    expect(find.widgetWithText(ActionChip, 'The day it is drawn up'),
        findsOneWidget);

    // The obvious guesses a client compiling its own list would offer. The
    // catalogue never mentioned them, so nothing may put them in a contract.
    expect(find.widgetWithText(ActionChip, 'Organization name'), findsNothing);
    expect(find.widgetWithText(ActionChip, 'Today’s date'), findsNothing);

    final body = tester.widget<TextField>(_writingBox).controller!;
    body.text = '';
    await tester.pump();

    await _tap(
      tester,
      find.widgetWithText(ActionChip, 'What the business trades as'),
    );

    expect(body.text, '<<trading_name>>');

    // And they are the REGISTRY's words, not the catalogue's. On the real
    // server those are the same rows; in this fixture the catalogue's half is
    // worded differently on purpose, so that a chip drawn from the wrong source
    // is visible instead of indistinguishable.
    expect(
      find.widgetWithText(ActionChip, 'The name over the door'),
      findsNothing,
    );

    await _settle(tester);
  });

  /// The catalogue's half of the registry — `merge_tags.core` and
  /// `merge_tags.facts` — is read on exactly ONE path: creating a contract,
  /// where there is no `{contract}` to preview against and no detail response
  /// to have loaded either.
  ///
  /// Every other test of these chips taps Design, which is the EDIT path, and
  /// there they come from the whole registry the server sends. So the two tests
  /// written to defend this node were defending a different one: replacing
  /// `core` and `facts` with empty lists in `MergeTagSyntax.fromJson` left all
  /// of them green.
  testWidgets('while the contract does not exist yet, the always-asked and '
      'always-available chips are the catalogue’s own lists', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('New contract'));

    for (final label in [
      'The first name on their record',
      'The name over the door',
      'The date at the top of the page',
    ]) {
      expect(
        find.widgetWithText(ActionChip, label),
        findsOneWidget,
        reason: '“$label” is the catalogue’s wording, and the catalogue is the '
            'only thing that has answered on this path.',
      );
    }

    // The wording the other two responses give the same keys. Neither has been
    // asked here — there is no contract — so a chip carrying their words would
    // mean these came from somewhere they cannot have come from.
    expect(
      find.widgetWithText(ActionChip, 'The name they go by'),
      findsNothing,
    );
    expect(
      find.widgetWithText(ActionChip, 'What the business trades as'),
      findsNothing,
    );

    // And it inserts, which is the only reason it is on screen.
    final body = tester.widget<TextField>(_writingBox).controller!;

    await _tap(
      tester,
      find.widgetWithText(ActionChip, 'The date at the top of the page'),
    );

    expect(body.text, '<<agreement.day>>');

    await _settle(tester);
  });

  /// An entry the server sent no `tag` for is an entry it has said nothing
  /// about how to write. This end used to wrap the key itself in the published
  /// delimiters — a second place in the client that assembles a tag, twelve
  /// lines below a docblock promising there is exactly one.
  testWidgets('a tag the server did not write is not offered, rather than '
      'assembled here', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('New contract'));

    expect(
      find.widgetWithText(ActionChip, 'What everybody calls them'),
      findsNothing,
      reason: 'The catalogue sent that entry with a key and no tag. Writing one '
          'here puts characters into somebody’s employment contract that no '
          'server chose.',
    );

    // The control: the entry beside it in the same list does carry a tag and is
    // offered, so this is the missing tag being refused rather than the core
    // list being dropped.
    expect(
      find.widgetWithText(ActionChip, 'The first name on their record'),
      findsOneWidget,
    );
  });

  /// The phrase under a chip is the server's `is`. `note` is a spelling no
  /// endpoint sends, and it sat in the parser as a fallback beside it — in the
  /// file whose own comments argue at length that a fallback to a name nothing
  /// uses is the same silence with more code in front of it.
  testWidgets('the sentence under a chip is the server’s `is`, and no other '
      'spelling of it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('New contract'));

    // This entry carries `note` and no `is`, so the tooltip is the tag alone.
    expect(find.byTooltip('<<ledger.year>>'), findsOneWidget);
    expect(
      find.byTooltip(
        '<<ledger.year>>\ninvented for this fixture, and read by nothing',
      ),
      findsNothing,
    );

    // The control: an entry that does carry `is` shows it under the tag.
    expect(
      find.byTooltip('<<given_name>>\nthe first name they gave us'),
      findsOneWidget,
    );
  });

  /// `MergeTags::resolvable()` is published on THREE responses, and the third
  /// was unread: `GET /hr/contracts/{contract}` carries it for the version in
  /// force, in the same payload the fields and the body arrive in.
  ///
  /// Until it was read, the own-question chips were assembled in Dart out of
  /// the questions on screen — from the moment the designer opened until the
  /// first preview answered, and PERMANENTLY for a contract whose preview is
  /// refused. Which is this test, and it is not a corner: a refused preview is
  /// exactly when somebody reaches for a chip to fix the tag that was refused.
  testWidgets('the chips are the server’s registry from the moment the '
      'contract opens, not only once a preview answers', (tester) async {
    // The wording this version already carries names `date_started`, so the
    // first preview is refused and no second source ever arrives.
    await _pump(tester, _handler(previewRefusal: '<<date_started>>'));
    await _tap(tester, find.text('Design'));
    await _settle(tester);

    expect(
      find.textContaining('names nothing this contract can fill in'),
      findsOneWidget,
      reason: 'The preview has to have been refused, or this proves nothing.',
    );

    for (final label in [
      'The wage, per hour',
      'The account wages are paid into',
      'The day the job starts',
    ]) {
      expect(
        find.widgetWithText(ActionChip, label),
        findsOneWidget,
        reason: '“$label” is the registry’s wording, out of the response this '
            'screen opened with.',
      );
    }

    // What a list built in Dart would say instead, because a field's `label` is
    // the only thing it could put on a chip. Scoped to the chips, since those
    // words are legitimately on screen in the cards that edit the questions.
    expect(find.widgetWithText(ActionChip, 'Hourly wage'), findsNothing);
    expect(find.widgetWithText(ActionChip, 'First day'), findsNothing);

    // The other two groups come from the same response as well, and not from
    // the catalogue's half.
    expect(
      find.widgetWithText(ActionChip, 'The name they go by'),
      findsOneWidget,
    );
    expect(
      find.widgetWithText(ActionChip, 'The first name on their record'),
      findsNothing,
    );
  });

  /// The counter went in with no test at all. Silent over a half-written
  /// clause, and a plain count once the wording is inside the last tenth of
  /// what the server will accept.
  testWidgets('the length counter is silent until the cap is close, then says '
      'where you are', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('New contract'));

    final body = tester.widget<TextField>(_writingBox).controller!;

    body.text = 'a' * 300;
    await tester.pump();

    expect(find.textContaining('of 400 characters'), findsNothing);

    body.text = 'a' * 365;
    await tester.pump();

    expect(find.text('365 of 400 characters.'), findsOneWidget);
    expect(
      find.textContaining('Publishing will be refused'),
      findsNothing,
      reason: 'Inside the cap is not a warning.',
    );

    await _settle(tester);
  });

  /// The cap is the server's number and so is the MEASURE.
  ///
  /// `MergeTags::validate` refuses on `mb_strlen`, which counts code points.
  /// This counted grapheme clusters — the defensible answer to a different
  /// question — so a body of combining marks or joined emoji could read as
  /// comfortably inside the cap and be refused anyway, quoting a number the
  /// person had just watched a counter contradict.
  testWidgets('the length counter counts what the server counts', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('New contract'));

    final body = tester.widget<TextField>(_writingBox).controller!;

    // Sixty of these: four emoji joined by three zero-width joiners, which is
    // ONE grapheme cluster and SEVEN code points. So 60 by the measure this
    // used and 420 by the server's, against a cap of 400.
    // Written as escapes, deliberately: the joiners are invisible, and a
    // fixture whose whole point is a character count must not depend on
    // somebody's editor preserving three characters they cannot see.
    const family =
        '\u{1F468}\u200D\u{1F469}\u200D\u{1F467}\u200D\u{1F466}';

    expect(family.characters.length, 1);
    expect(family.runes.length, 7);

    body.text = family * 60;
    await tester.pump();

    expect(
      find.textContaining(
        'This wording is 420 characters and the limit is 400',
      ),
      findsOneWidget,
      reason: 'mb_strlen refuses this body. A counter saying there are 340 '
          'characters to spare is one the server contradicts at publish.',
    );

    await _settle(tester);
  });

  /// The other thing that went in untested. A preview is of the NEXT version by
  /// definition, the answer carries its number, and the pane says it — because
  /// "the page" over a document four edits ahead of the one in force is a
  /// picture with no date on it.
  testWidgets('the pane says which version the draft would be', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));
    await _settle(tester);

    expect(find.textContaining('A draft of version 4'), findsOneWidget);
    expect(
      find.textContaining('The page, with sample answers merged in by the '
          'server.'),
      findsNothing,
      reason: 'That is the sentence for an answer that named no version, and '
          'this one named 4.',
    );
  });

  /// A shape no endpoint sends, refused rather than half-read — see
  /// [_mapShapedCatalogue] for what the branch that read it got wrong.
  testWidgets('a field vocabulary sent as a map is refused, not read with the '
      'safety property missing', (tester) async {
    await _pump(tester, _handler(catalogue: _mapShapedCatalogue));

    // The templates still list. A vocabulary that cannot be read does not take
    // away the ability to see what already exists.
    expect(find.text('Technician, full time'), findsOneWidget);

    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'New contract'),
          )
          .onPressed,
      isNull,
    );
    expect(
      find.textContaining('did not say what kinds of question'),
      findsOneWidget,
    );
  });

  /// The wording is part of the version, so publishing has to carry it and say
  /// that it moved.
  testWidgets('the wording is published with the questions, and the panel '
      'says the contract itself changed', (tester) async {
    Map<String, dynamic>? previewed;

    await _pump(
      tester,
      _handler(onPreview: (body) => previewed = body, diff: _rewrittenDiff),
    );
    await _tap(tester, find.text('Design'));

    tester.widget<TextField>(_writingBox).controller!.text =
        'Rewritten from scratch.';
    await _settle(tester);

    await _tap(tester, find.text('Review and publish'));

    expect(previewed!['body'], 'Rewritten from scratch.');
    expect(
      find.textContaining('The text of the contract itself has changed'),
      findsOneWidget,
    );
  });

  /// The split is the TAB's, not step 4's: the page gets half the screen and
  /// keeps it while the form beside it is scrolled.
  /// The writing surface grows with the contract, which is only safe because
  /// nothing sits under it. Both halves of that are asserted here: a box that
  /// stopped growing would silently cap the document, and a box that grew with
  /// steps beneath it would walk them off the bottom of the page.
  /// The chip palette must not be clipped part-way through a group.
  ///
  /// It was capped at 152px with a scroll view inside, and the owner reported
  /// the result as an overlay: the last group's heading was sliced in half and
  /// the writing surface below looked like it was sitting on top of it. Nothing
  /// overlaid anything — a clip with no scrollbar and no fade simply reads as
  /// damage.
  ///
  /// This asserts the LAST group is fully inside the palette, because the first
  /// two fitted inside the old cap and a test that only looked at those would
  /// have passed against the bug.
  testWidgets('every group of insertable fields is drawn in full', (
    tester,
  ) async {
    await _pumpDesign(tester);

    final heading = find.text('THE BUSINESS AND THE DATE');

    expect(
      heading,
      findsOneWidget,
      reason: 'The last group is missing entirely, so the assertion below '
          'would pass by having nothing to measure.',
    );

    // Measured against the WRITING SURFACE, not against ContractWriting: that
    // widget is the whole bordered box — palette, divider and text field — so
    // its bottom sits far below a clipped palette and the comparison would
    // pass against the bug. It did, on the first draft of this test.
    final writing = tester.getRect(_writingBox);
    final last = tester.getRect(heading);

    expect(
      last.bottom,
      lessThanOrEqualTo(writing.top),
      reason:
          'The last group runs past the top of the writing surface, so it is '
          'cut off — which is what the owner saw and reported as the writing '
          'field overlaying the section above it.',
    );

    // And the chips under that heading, not just the heading itself — a cap
    // one line lower would still slice the row of fields off.
    final chip = tester.getRect(
      find.widgetWithText(ActionChip, 'What the business trades as'),
    );

    expect(chip.bottom, lessThanOrEqualTo(writing.top));
  });

  testWidgets('the writing surface is the last step and grows with the text', (
    tester,
  ) async {
    await _pumpDesign(tester);

    final writing = tester.getRect(_writingBox);

    for (final earlier in ['What each side sees', 'History']) {
      expect(
        tester.getRect(find.text(earlier)).top,
        lessThan(writing.top),
        reason:
            '“$earlier” is below the writing surface, so a growing surface '
            'pushes it further down the page with every paragraph typed — '
            'which is the reason the surface was moved last.',
      );
    }

    tester.widget<TextField>(_writingBox).controller!.text =
        List.filled(40, 'A clause that goes on for a while.').join('\n');
    await _settle(tester);

    expect(
      tester.getRect(_writingBox).height,
      greaterThan(writing.height),
      reason:
          'The surface did not grow, so a long contract is being written '
          'through a fixed-height window into it.',
    );
  });

  testWidgets('the contract takes half the tab, beside the form rather than '
      'inside it', (tester) async {
    await _pumpDesign(tester);

    final form = tester.getRect(find.byType(NumberedStep).first);
    final pane = tester.getRect(find.byType(ContractPagePane));

    expect(
      pane.left,
      greaterThanOrEqualTo(form.right),
      reason: 'The page is beside the form, not inside a step of it.',
    );

    // Half each, give or take the divider between them.
    expect(pane.width, closeTo(1500 / 2, 4));
    expect(pane.right, closeTo(1500, 1));

    // And it is the height of the tab rather than a box in the middle of it.
    // Measured against the form beside it, so this does not have to know what
    // the footer is: the two halves run the same distance.
    final scroller = tester.getRect(
      find.ancestor(
        of: find.byType(NumberedStep).first,
        matching: find.byType(SingleChildScrollView),
      ).first,
    );

    expect(pane.top, closeTo(scroller.top, 1));
    expect(pane.bottom, closeTo(scroller.bottom, 1));
    expect(
      pane.height,
      greaterThan(800),
      reason: 'A 1000px window, less the footer — not a 620px box in a form.',
    );
  });

  /// The reason it is worth the half: scrolling to the last step does not
  /// scroll the contract away, which is exactly what a panel inside the form
  /// did.
  testWidgets('scrolling the form leaves the contract where it is', (
    tester,
  ) async {
    await _pumpDesign(tester);

    final before = tester.getRect(find.byType(ContractDocumentPage));

    await tester.drag(
      find.byType(NumberedStep).first,
      const Offset(0, -400),
    );
    await tester.pumpAndSettle();

    expect(tester.getRect(find.byType(ContractDocumentPage)), before);
  });

  /// R7. Half and half is a desktop layout; 50/50 at 375px is two unusable
  /// columns.
  testWidgets('on a narrow screen it is one pane at a time, never two '
      'columns', (tester) async {
    await _pumpDesign(tester, size: const Size(375, 900));

    // The form first, and the page is not built at all — not built narrow, not
    // built off screen.
    expect(_writingBox, findsOneWidget);
    expect(find.byType(ContractDocumentPage), findsNothing);

    await _tap(tester, find.text('The contract'));

    expect(find.byType(ContractDocumentPage), findsOneWidget);
    expect(_writingBox, findsNothing);

    final page = tester.getRect(find.byType(ContractDocumentPage));

    expect(
      page.width,
      greaterThan(300),
      reason: 'The one pane on screen gets the whole width.',
    );
  });

  /// The one surface in this app that does not follow the theme. A page is
  /// white, and a dark-mode preview would be a preview of a document that does
  /// not exist.
  testWidgets('the page is white in dark mode', (tester) async {
    await _pumpDesign(tester, theme: EzBiziTheme.dark());

    final paper = tester.widget<ColoredBox>(
      find
          .descendant(
            of: find.byType(ContractDocumentPage),
            matching: find.byType(ColoredBox),
          )
          .first,
    );

    expect(paper.color, const Color(0xFFFFFFFF));

    // And the chrome around it did follow the theme, so this is a deliberate
    // exception rather than a screen that ignores the theme entirely.
    expect(
      Theme.of(tester.element(find.byType(ContractPagePane))).brightness,
      Brightness.dark,
    );
  });

  /// A contract being created has no id, so there is no endpoint to render it.
  /// It says so rather than resolving anything locally — a second
  /// implementation of the merge is a preview that agrees with the document
  /// until the day it does not.
  testWidgets('before the contract exists the page shows the wording unmerged, '
      'and says so', (tester) async {
    await _pump(tester, _handler());

    await _tap(tester, find.text('New contract'));

    final body = tester.widget<TextField>(_writingBox).controller!;
    body.text = 'Made between <<business_name>> and the person named below.';
    await _settle(tester);

    expect(_inPage('Made between'), findsOneWidget);
    expect(
      _inPage('<<business_name>>'),
      findsOneWidget,
      reason: 'Nothing may be filled in here. The server does the merging.',
    );
    expect(find.textContaining('not yet merged'), findsOneWidget);
  });

  /// Without the vocabulary there is nothing to build a question out of, and
  /// the screen says that instead of drawing empty dropdowns.
  testWidgets('with no catalogue the list still reads and designing is '
      'refused', (tester) async {
    await _pump(tester, _handler(catalogueStatus: 500));

    expect(find.text('Technician, full time'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'New contract'),
          )
          .onPressed,
      isNull,
    );
    expect(find.textContaining('could not be loaded'), findsOneWidget);
  });

  // ══ Step 2 asks what onboarding asks ═══════════════════════════════
  //
  // *"remove gender from the always asked questions, and make middle name
  //  optional, as some people dont have a middle name."*
  //
  // The owner was reading a step headed "Always asked" that listed the EIGHT
  // keys a template may not declare. Onboarding asks six. Two of the eight —
  // the display name and the middle name — are reserved precisely so that no
  // template can store a second copy of them, and are never asked of anybody.
  // Nothing was demanding a middle name of people who do not have one. This
  // screen was saying it would.
  //
  // The fixture below labels the same key DIFFERENTLY in the two lists, which
  // no real server does, so the words on screen say which list was read.

  testWidgets('the always-asked step draws what onboarding asks, not the keys '
      'a template may not declare', (tester) async {
    await _pump(tester, _handler(catalogue: _askedCatalogue));
    await _tap(tester, find.text('Design'));

    // The asked list, in the asked list's own wording.
    expect(_inCoreStep('The first name on their record'), findsOneWidget);
    expect(_inCoreStep('The name of their dog'), findsOneWidget);

    // Reserved and NOT asked. A key a template may not use is a different fact
    // from a question everybody answers, and it belongs to the collision check
    // two steps down rather than to a list headed "always asked".
    expect(_inCoreStep('Work email address'), findsNothing);
    expect(_inCoreStep('Shoe size'), findsNothing);

    // Nor the reserved list's own wording for a key that IS asked — which is
    // what separates "reads the right list" from "filters the wrong one".
    expect(_inCoreStep('RESERVED given name'), findsNothing);

    // CONTROL. The reserved list is still published, still parsed, and still
    // drawn elsewhere on this screen — so the assertions above are about which
    // list step 2 chose and not about a catalogue that failed to arrive.
    expect(find.text('Work email address'), findsWidgets);
  });

  /// The other half of the owner's third request: a question that will not be
  /// demanded should look like one before somebody wonders whether the platform
  /// is about to insist on a fact a person does not have.
  testWidgets('an asked question the server calls optional is marked, and the '
      'ones it does not are left alone', (tester) async {
    await _pump(tester, _handler(catalogue: _askedCatalogue));
    await _tap(tester, find.text('Design'));

    expect(
      find.descendant(
        of: _coreChip('The name of their dog'),
        matching: find.text('Optional'),
      ),
      findsOneWidget,
    );

    // CONTROL, and the one that matters: a step that marked everything, or
    // that read the flag off the wrong entry, would put a second one here.
    expect(
      find.descendant(
        of: _coreChip('The first name on their record'),
        matching: find.text('Optional'),
      ),
      findsNothing,
    );
  });

  /// A catalogue that says nothing about requiredness is the one shipping
  /// today. It must draw the list plainly rather than inventing a default in
  /// either direction — a chip claiming a question is optional when nobody
  /// said so is a promise this end has no business making.
  testWidgets('a server that has not said what is optional gets no marks, not '
      'a guess', (tester) async {
    await _pump(tester, _handler(catalogue: _silentCatalogue));
    await _tap(tester, find.text('Design'));

    expect(_inCoreStep('The first name on their record'), findsOneWidget);
    expect(_inCoreStep('The name of their dog'), findsOneWidget);
    expect(_inCoreStep('Optional'), findsNothing);
  });

  // ══ The cover ══════════════════════════════════════════════════════
  //
  // *"we also need the ability to create a cover for the contract as well,
  //  which means being able to use either the company logo that's already been
  //  uploaded or a new file altogether."*

  testWidgets('a contract with no cover says so, as an answer rather than a '
      'gap', (tester) async {
    await _pump(tester, _withCoverEndpoints(_handler()));
    await _tap(tester, find.text('Design'));

    expect(find.text('Give it a cover'), findsOneWidget);

    // Not an empty space where a picture would be. "No cover" is what nearly
    // every contract answers, and it is drawn as an answer.
    expect(_inCoverStep('No cover'), findsOneWidget);
    expect(
      _inCoverStep('starts on the first page of the agreement'),
      findsOneWidget,
    );

    // CONTROL: the step really is on screen and really is empty-handed, rather
    // than these two lines coming from a step that failed to build.
    expect(_inCoverStep('Choose a cover'), findsOneWidget);
  });

  testWidgets('the cover step says a cover is part of the version before '
      'anybody presses anything', (tester) async {
    await _pump(tester, _withCoverEndpoints(_handler()));
    await _tap(tester, find.text('Design'));

    expect(_inCoverStep('part of the version'), findsOneWidget);
    expect(_inCoverStep('publishes a new one'), findsOneWidget);
  });

  testWidgets('the picker offers the logo already uploaded and a file of your '
      'own', (tester) async {
    await _pump(tester, _withCoverEndpoints(_handler(), logo: _pixel));
    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Choose a cover'));

    expect(find.text('The logo you have already uploaded'), findsOneWidget);
    expect(find.text('A file of your own'), findsOneWidget);

    // The one press. The mark is REFERENCED, not copied — so there is nothing
    // to upload for it and a rebrand reaches every contract using it.
    expect(find.textContaining('It is not copied'), findsOneWidget);

    // And the consequence, in the panel as well as on the step, because this
    // is the sentence that has to arrive before the press.
    expect(find.textContaining('writes a new version'), findsOneWidget);
  });

  /// An organization with no mark must not be handed a one-press option that
  /// would publish a blank first page — nor a dead control. R8: a sentence
  /// saying what to do.
  testWidgets('with no logo uploaded the easy option says where to get one, '
      'and cannot be chosen', (tester) async {
    await _pump(tester, _withCoverEndpoints(_handler()));
    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Choose a cover'));

    expect(
      find.textContaining('Add one on the Organization screen'),
      findsOneWidget,
    );

    await _tap(tester, find.text('The logo you have already uploaded'));

    // Still nothing chosen: the button is the one it was, and the option did
    // not select itself.
    expect(find.text('Leave it with no cover'), findsOneWidget);
    expect(find.text('Use this cover'), findsNothing);
  });

  testWidgets('choosing the logo becomes the cover, and the cover travels with '
      'the version', (tester) async {
    Map<String, dynamic>? published;

    await _pump(
      tester,
      _watchPublish(
        _withCoverEndpoints(_handler(), logo: _pixel),
        (body) => published = body,
      ),
    );

    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Choose a cover'));
    await _tap(tester, find.text('The logo you have already uploaded'));
    await _tap(tester, find.text('Use this cover'));

    expect(_inCoverStep('Your organization’s logo'), findsOneWidget);

    // It is a draft until it is published, and the step says so rather than
    // letting somebody believe the picture is already on the document.
    expect(_inCoverStep('not on the published version yet'), findsOneWidget);

    await _tap(tester, find.text('Review and publish'));
    await _tap(tester, find.text('Publish it'));

    expect(published?['cover'], {'source': 'logo'});
  });

  testWidgets('a file of your own is uploaded and becomes the cover, by the id '
      'the server answered with', (tester) async {
    ContractCover? chosen;

    await _pumpPicker(
      tester,
      onDone: (cover) => chosen = cover,
      // Real PNG bytes, because the panel draws what was picked and an
      // undecodable handful would fail as an image rather than as a cover.
      pickImage: () async => PickedBytes('front-page.png', _pixel),
      upload: (_) async => _uploaded('up-7', 'front-page.png'),
    );

    await _tap(tester, find.text('Choose an image'));

    expect(find.text('front-page.png'), findsOneWidget);

    await _tap(tester, find.text('Use this cover'));

    expect(chosen?.source, CoverSource.file);

    // The server's id, and only what the version needs — never the filename or
    // the path, which are the server's own answers to different questions.
    expect(chosen?.toJson(), {'source': 'file', 'upload_id': 'up-7'});
  });

  /// A refused upload must not leave a cover that looks chosen. The first
  /// anybody would know is a signed contract with a blank first page.
  testWidgets('an upload the server refuses is said out loud, and nothing '
      'becomes the cover', (tester) async {
    ContractCover? chosen;

    await _pumpPicker(
      tester,
      onDone: (cover) => chosen = cover,
      pickImage: () async => PickedBytes('huge.png', _pixel),
      upload: (_) async => _refused('That image is larger than 8 MB.'),
    );

    await _tap(tester, find.text('Choose an image'));

    // The server's own words, kept on screen rather than flashed in a snackbar
    // over a panel somebody is still reading.
    expect(find.text('That image is larger than 8 MB.'), findsOneWidget);

    await _tap(tester, find.text('Leave it with no cover'));

    expect(chosen, ContractCover.none);
  });

  /// "None" is a state, not an omission — so it comes back as an answer and
  /// closing the panel does not.
  testWidgets('leaving it with no cover is an answer, and cancelling is not', (
    tester,
  ) async {
    ContractCover? chosen;
    var answered = false;

    await _pumpPicker(
      tester,
      cover: ContractCover.logo,
      onDone: (cover) {
        answered = true;
        chosen = cover;
      },
    );

    await _tap(tester, find.text('No cover'));
    await _tap(tester, find.text('Leave it with no cover'));

    expect(answered, isTrue);
    expect(chosen, ContractCover.none);

    // CONTROL. Cancelling answers nothing, which is what makes the line above
    // a decision rather than the absence of one.
    answered = false;
    chosen = null;

    await _pumpPicker(
      tester,
      cover: ContractCover.logo,
      onDone: (cover) {
        answered = true;
        chosen = cover;
      },
    );

    await _tap(tester, find.text('Cancel'));

    expect(answered, isTrue);
    expect(chosen, isNull);
  });

  /// The cover is part of the version, which has to mean a cover ON ITS OWN is
  /// a version. The server answers that beside the diff rather than inside it,
  /// so a screen reading only the diff would show "nothing has changed" and no
  /// button — for the exact act the owner asked for.
  testWidgets('a cover changed on its own is a version, and the publish is '
      'offered for it', (tester) async {
    Map<String, dynamic>? published;

    await _pump(
      tester,
      _coverOnlyPublish(
        _withCoverEndpoints(_handler(), logo: _pixel),
        (body) => published = body,
      ),
    );

    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Choose a cover'));
    await _tap(tester, find.text('The logo you have already uploaded'));
    await _tap(tester, find.text('Use this cover'));
    await _tap(tester, find.text('Review and publish'));

    expect(find.text('Publish it'), findsOneWidget);

    // And it does not contradict itself while offering the button. The diff
    // says the questions and the wording are the same, which is true; "there
    // is no new version to publish" is not.
    expect(find.textContaining('Nothing has changed'), findsNothing);

    await _tap(tester, find.text('Publish it'));

    expect(published?['cover'], {'source': 'logo'});
  });

  /// R7. Desktop today, phones next, and a picker that only works at 1500px is
  /// a picker that works on one of them.
  testWidgets('the cover picker fits a phone', (tester) async {
    await _pump(
      tester,
      _withCoverEndpoints(_handler(), logo: _pixel),
      size: const Size(375, 2400),
    );

    await _tap(tester, find.text('Design'));
    await _tap(tester, find.text('Choose a cover'));

    // An overflow throws in a widget test, so reaching these lines at 375px is
    // half the assertion; all three answers still being readable is the rest.
    expect(find.text('No cover'), findsWidgets);
    expect(find.text('The logo you have already uploaded'), findsOneWidget);
    expect(find.text('A file of your own'), findsOneWidget);
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  List<String> permissions = const ['hr.view', 'hr.create', 'hr.edit'],
  Size size = const Size(1500, 3200),
  ThemeData? theme,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_withMe(handler, permissions)));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: theme ?? EzBiziTheme.light(),
      home: Scaffold(
        body: ContractsTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The designer, open on an existing contract, at a stated size.
///
/// For the questions that are about the LAYOUT of the tab — where the two
/// halves are, and what happens when there is only room for one. Driven
/// through the real screen rather than by mounting the halves side by side in
/// the test, because the arrangement of those halves is the thing under test
/// and a helper that arranged them itself would pass whatever the screen did.
///
/// An existing contract rather than a new one: only a contract with an id has
/// an endpoint to render the page from, and the draft path is covered
/// separately.
Future<void> _pumpDesign(
  WidgetTester tester, {
  Size size = const Size(1500, 1000),
  ThemeData? theme,
}) async {
  await _pump(tester, _handler(), size: size, theme: theme);
  await _tap(tester, find.text('Design'));
}

/// The writing surface — the one text box in the writing half. Everything else
/// there is a chip.
final Finder _writingBox = find.descendant(
  of: find.byType(ContractWriting),
  matching: find.byType(TextField),
);

/// Everything on the page, and nothing in the chrome around it.
Finder _inPage(String text) => find.descendant(
  of: find.byType(ContractDocumentPage),
  matching: find.textContaining(text),
);

/// Past the preview's debounce and through the request it then makes.
///
/// Needed after anything that changes the body or the questions: the editor
/// waits before asking the server, and a test that ended on the wait would
/// leave a live timer behind it.
///
/// Twice round, and the second round is not superstition. A change to the
/// QUESTIONS is noticed while the editor rebuilds, so the debounce is started
/// during a frame — and `pumpAndSettle` returns as soon as no frame is
/// scheduled, which a pending timer does not do. One round fires whatever was
/// waiting and starts the new wait; the second serves it.
Future<void> _settle(WidgetTester tester) async {
  for (var round = 0; round < 2; round++) {
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
  }
}

/// Everything inside the form preview, and nothing above it.
///
/// The designer draws each question's wording twice — once in the card that
/// edits it, once in the form it produces — so an unscoped `find.text` cannot
/// tell "the employee is asked this" from "this question exists".
///
/// `textContaining` rather than `text`, because the preview marks a required
/// question with an asterisk the way a form does, and matching the whole string
/// would make every assertion here about the punctuation.
Finder _inPreview(String text) => find.descendant(
  of: find.byType(ContractFormPreview),
  matching: find.textContaining(text),
);

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

/// Type into the box carrying a label, and let the form react — `enterText`
/// returns without a frame, so anything asserted straight after would be
/// reading the tree as it was before the keystroke.
Future<void> _type(WidgetTester tester, String label, String text) async {
  await tester.enterText(find.widgetWithText(TextField, label).first, text);
  await tester.pumpAndSettle();
}

Future<void> _choose(WidgetTester tester, String label, String option) async {
  await _tap(
    tester,
    find.widgetWithText(SearchPickerField<String>, label),
  );
  await _tap(tester, find.text(option).last);
}

SearchPickerField<String> _dropdown(WidgetTester tester, String label) =>
    tester.widget<SearchPickerField<String>>(
      find.widgetWithText(SearchPickerField<String>, label).first,
    );

// ── the server, as this screen sees it ─────────────────────────────

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onPreview,
  void Function(Map<String, dynamic> body)? onRender,
  void Function()? onPublish,
  Map<String, Object?> diff = _diff,
  int catalogueStatus = 200,
  Map<String, Object?> catalogue = _catalogue,
  String? previewRefusal,
  List<Map<String, Object?>> templates = _templates,
}) {
  return (request) async {
    final path = request.url.path;

    // The document, rendered. Ahead of the `/hr/contracts/` catch-all below,
    // and ahead of `/versions`, because it is a different act: `/versions`
    // previews a PUBLISH, this renders a PAGE.
    if (path.endsWith('/preview')) {
      final sent = jsonDecode(request.body) as Map<String, dynamic>;

      onRender?.call(sent);

      // The refusal the real endpoint gives, and the reason there is a branch
      // here at all: `preview()` validates the body BEFORE it renders a
      // character of it, so a tag naming nothing is a 422 quoting the tag —
      // never a 200 carrying an `unresolved` list, which is what this fixture
      // used to invent and the client used to read.
      if (previewRefusal != null &&
          '${sent['body']}'.contains(previewRefusal)) {
        return _json({
          'message': 'The given data was invalid.',
          'errors': {
            'body': [
              '$previewRefusal names nothing this contract can fill in. Check '
                  'the spelling, or add a field with that key to this version.',
            ],
          },
        }, 422);
      }

      // `content`, `version`, `is_preview` and `merge_tags` — every key the
      // endpoint sends, spelt the way it sends them.
      return _json({
        'data': {
          'version': 4,
          'content': _rendered,
          'mime': 'text/html; charset=utf-8',
          'is_preview': true,
          'merge_tags': _resolvable,
        },
      });
    }

    if (path.endsWith('/hr/contracts/catalogue')) {
      return catalogueStatus == 200
          ? _json({'data': catalogue})
          : _json({
              'message': 'The catalogue is unavailable.',
            }, catalogueStatus);
    }

    if (path.endsWith('/versions')) {
      if (request.method == 'GET') return _json({'data': _versions});

      final body = jsonDecode(request.body) as Map<String, dynamic>;

      if (body['preview'] == true) {
        onPreview?.call(body);
        return _json({'data': diff});
      }

      onPublish?.call();
      return _json({'data': diff}, 201);
    }

    if (path.endsWith('/hr/contracts')) {
      if (request.method == 'POST') {
        onCreate?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': _template}, 201);
      }

      return _json({'data': templates});
    }

    if (path.contains('/hr/contracts/')) return _json({'data': _template});

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// `/me` answered first, whatever else the handler does, so every test states
/// only the part it cares about.
MockClientHandler _withMe(MockClientHandler inner, List<String> permissions) {
  return (request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
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

/// `GET /hr/contracts/catalogue`, in the shape HrContractsController answers
/// with: `field_types`, `filled_by`, `reserved_keys`.
///
/// Deliberately not the vocabulary the real server declares. Three types where
/// there are six, labels nobody would guess, and a reserved list containing
/// `shoe_size` and no date of birth. Any of the real words appearing on screen
/// means the client is reading a list of its own.
const _catalogue = {
  'field_types': [
    {
      'key': 'text',
      'label': 'A line of words',
      'description': 'Anything typed.',
      'takes_options': false,
    },
    {
      'key': 'choice',
      'label': 'Pick from a list',
      'description': 'One of yours.',
      'takes_options': true,
    },
    {
      'key': 'date',
      'label': 'A day',
      'description': 'A day on a calendar.',
      'takes_options': false,
    },
    // A kind the server says is NOT merged into the wording. `signature` on the
    // real server, and named nothing like it here on purpose: an editor that
    // decided this by comparing the type to `'signature'` in Dart would offer
    // this one as an insertable tag and the publish would be refused.
    {
      'key': 'scrawl',
      'label': 'A scrawl',
      'description': 'Invented for this fixture, and that is the point.',
      'takes_options': false,
      'merges': false,
    },
    // A SECOND kind that carries a list, which the real server does not have.
    // It is here to be the seventh type: a client gating its options editor on
    // `type == 'choice'` offers this one nowhere to type any answers, and says
    // nothing about why.
    {
      'key': 'rota',
      'label': 'One of the shifts',
      'description': 'Invented for this fixture, and that is the point.',
      'takes_options': true,
    },
  ],
  'filled_by': [
    {
      'key': 'employer',
      'label': 'Head office',
      'description': 'Somebody at head office answers it.',
    },
    {
      'key': 'employee',
      'label': 'The new starter',
      'description': 'They answer it themselves.',
    },
    {
      'key': 'either',
      'label': 'Whoever gets there first',
      'description': 'One of you answers it and the other can correct it.',
    },
  ],
  // The endpoint sends three strings per key and they are not interchangeable:
  // `label` is the name that goes on a control, `is` is a phrase completing
  // "it is …" for use inside a sentence, and `reason` is the whole refusal.
  //
  // This fixture used to give `is` a title-case noun — "Work email address" —
  // which is not what the server sends, and it is the reason sixteen tests
  // passed over a screen captioning a locked field "the email address on their
  // account". The phrases below read the way the real ones do.
  //
  // `filled_by` is absent, which is why these turn up on both sides of the
  // preview: the employer may fill them in and does not have to.
  'reserved_keys': [
    {
      'key': 'email',
      'label': 'Work email address',
      'is': 'the work email address on their account',
      'reason': 'The invitation is addressed to it.',
    },
    {
      'key': 'shoe_size',
      'label': 'Shoe size',
      'is': 'the shoe size they gave us',
      'reason': 'Invented for this fixture, and that is the point.',
    },
    // Deliberately unlabelled, to pin what happens when the server offers no
    // name: the key, shown as the identifier it is. Never the phrase.
    {
      'key': 'nino',
      'is': 'their national insurance number',
      'reason': 'Also invented.',
    },
  ],
  // ── `merge_tags`, IN THE SHAPE THE ENDPOINT ACTUALLY SENDS ───────
  //
  // This fixture used to publish a `document_tags` block, which no server has
  // ever sent, and a test asserted that the facts appeared in the editor. It
  // passed, because the client read the same invented key. Both ends were green
  // against one fiction and the feature did not exist in production — the exact
  // way a hand-written fixture hides a wire break instead of catching one.
  //
  // So: the real names, and the real nesting. `core` and `facts` are separate
  // lists of `{key, tag, label, is}`, the delimiters are their own strings, and
  // the tag is assembled by the server so that no client has to know how.
  //
  // The delimiters are DELIBERATELY not braces. A client that writes `{{` in
  // Dart passes against a fixture that says braces and fails against this one,
  // which is the whole point of a fixture that does not agree with the guess.
  //
  // `core` is deliberately NOT the same list as `reserved_keys` above, for the
  // same reason the server keeps them apart: `shoe_size` is reserved so a
  // template cannot declare it, and it is not something onboarding asks, so a
  // tag naming it would be refused. An editor offering the reserved list is
  // offering tags that cannot be published.
  //
  // ── And the wording here differs from the other two responses ────
  //
  // On the real server these entries and the ones in `_resolvable` below are
  // the same `resolvable()` rows, so the labels match. This fixture makes them
  // DIFFER, because two sources that say the same thing cannot be told apart by
  // a test — and that was not hypothetical. Both tests written to pin these
  // lists tap Design, which is the EDIT path, where the chips come from the
  // registry the server sends whole; replacing `core` and `facts` with empty
  // lists in `MergeTagSyntax.fromJson` left every test in this file green.
  // Creating a contract is the one path that reads the catalogue's half, and
  // these are the words it has to read.
  'merge_tags': {
    'open': '<<',
    'close': '>>',
    'example': '<<given_name>>',
    'max_body_length': 400,
    'core': [
      {
        'key': 'given_name',
        'tag': '<<given_name>>',
        'label': 'The first name on their record',
        'is': 'the first name they gave us',
      },
      // A key with NO `tag`. `resolvable()` writes one for every entry it
      // publishes, so an entry arriving without one is a server that has said
      // nothing about how this tag is written — and this end used to write it
      // anyway, wrapping the key in the published delimiters. That was a second
      // place in the client that assembles a tag, twelve lines under a docblock
      // saying there is exactly one.
      {'key': 'nickname', 'label': 'What everybody calls them'},
    ],
    // Named nothing like the obvious guesses on purpose: a client carrying
    // `organization_name` and `today` in Dart would offer two tags this server
    // has never heard of, and they would render in an employment contract as
    // themselves. The dotted one is here because a real fact key is dotted.
    'facts': [
      {
        'key': 'trading_name',
        'tag': '<<trading_name>>',
        'label': 'The name over the door',
        'is': 'the name over the door, which is not always the legal one',
      },
      {
        'key': 'agreement.day',
        'tag': '<<agreement.day>>',
        'label': 'The date at the top of the page',
      },
      // `note` is a spelling NO endpoint sends — the phrase is published as
      // `is`. It sat in the client as a fallback beside it, which is the same
      // habit that read `document_tags` and `html`: a fallback to a name
      // nothing uses is not a fallback, it is the same silence with more code
      // in front of it. Here it would put words under a chip that no server
      // wrote.
      {
        'key': 'ledger.year',
        'tag': '<<ledger.year>>',
        'label': 'The trading year',
        'note': 'invented for this fixture, and read by nothing',
      },
    ],
  },
  'limits': {'max_body_length': 400},
};

/// The same catalogue with the field vocabulary sent as a MAP of key to a
/// nested object, which is a shape no endpoint has ever sent.
///
/// `ContractVocabulary._choices` had a branch for it, and the branch parsed
/// `takes_options` out of the nested object and never parsed `merges` — so this
/// catalogue, which says a scrawl does not merge, would have been accepted with
/// that missing and the signature chips would have come back in the body editor
/// to be refused by name at publish. The branch is gone: a tolerance that is
/// wrong about the one property that takes a tag AWAY is worse than no
/// tolerance, and a shape this cannot read is now refused where somebody can
/// see it.
final Map<String, Object?> _mapShapedCatalogue = {
  for (final entry in _catalogue.entries)
    if (entry.key != 'field_types') entry.key: entry.value,
  'field_types': {
    'text': {'label': 'A line of words', 'takes_options': false},
    'scrawl': {'label': 'A scrawl', 'takes_options': false, 'merges': false},
  },
};

const _templates = [
  {
    'id': 'c-1',
    'name': 'Technician, full time',
    'description': 'Vans and ladders',
    'version': 3,
    'versions_count': 3,
    // The server counts the LIVE fields — the four below, minus the retired
    // one.
    'field_count': 3,
    // Whether anybody has WRITTEN this template, as opposed to defining its
    // questions. Sent on every row for exactly this, and read past until now.
    'has_body': true,
  },
];

/// The same list with a template nobody has written yet beside the one they
/// have. Its own fixture so that every other test keeps its single row.
const _twoTemplates = [
  ..._templates,
  {
    'id': 'c-2',
    'name': 'Weekend cover',
    'description': 'Questions, and nothing written yet',
    'version': 1,
    'versions_count': 1,
    'field_count': 2,
    'has_body': false,
  },
];

const _template = {
  'id': 'c-1',
  'name': 'Technician, full time',
  'description': 'Vans and ladders',
  'version': 3,
  'versions_count': 3,
  'field_count': 3,
  // The wording, which belongs to the version the same way the fields do. A
  // designer that loaded the questions and not this would wipe the contract on
  // the next publish.
  'body':
      'This Agreement is made on <<date_started>> between the business and '
      'the person named in it.',
  'fields': [
    {
      'key': 'wage',
      'label': 'Hourly wage',
      'type': 'text',
      'options': <String>[],
      'filled_by': 'employer',
      'required': true,
      'sensitive': false,
      'retired': false,
    },
    {
      'key': 'bank_account',
      'label': 'Bank account number',
      'type': 'text',
      'options': <String>[],
      'filled_by': 'employee',
      'required': true,
      'sensitive': true,
      'retired': false,
    },
    {
      'key': 'date_started',
      'label': 'First day',
      'type': 'date',
      'options': <String>[],
      'filled_by': 'either',
      'required': false,
      'sensitive': false,
      'retired': false,
    },
    {
      'key': 'tshirt',
      'label': 'T-shirt size',
      'type': 'choice',
      'options': ['S', 'M', 'L'],
      'filled_by': 'employee',
      'required': false,
      'sensitive': false,
      'retired': true,
    },
  ],
  // ── The registry, on the THIRD response that publishes it ────────
  //
  // `MergeTags::resolvable($current->fieldSet())`, in the payload the designer
  // opens with. It was unread, and the consequence was not theoretical: from
  // the moment an existing contract opened until the first preview came back —
  // and for good when the preview is refused — the chips for this contract's
  // own questions were assembled in Dart out of the fields on screen, which is
  // the four server rules restated on the client that the registry exists to
  // keep off it.
  //
  // The field labels here are deliberately NOT the field labels above. A list
  // built in Dart has nothing to label a chip with EXCEPT `label` on the field,
  // so if these said "Hourly wage" no test could tell the registry from the
  // guess. The core and fact entries do match `_resolvable` below, because
  // those two halves really are the same rows from the same function and
  // nothing about a contract changes them.
  'merge_tags': [
    {
      'key': 'wage',
      'tag': '<<wage>>',
      'label': 'The wage, per hour',
      'source': 'field',
      'is': null,
    },
    {
      'key': 'bank_account',
      'tag': '<<bank_account>>',
      'label': 'The account wages are paid into',
      'source': 'field',
      'is': null,
    },
    {
      'key': 'date_started',
      'tag': '<<date_started>>',
      'label': 'The day the job starts',
      'source': 'field',
      'is': null,
    },
    {
      'key': 'given_name',
      'tag': '<<given_name>>',
      'label': 'The name they go by',
      'source': 'core',
      'is': 'the first name they gave us',
    },
    {
      'key': 'trading_name',
      'tag': '<<trading_name>>',
      'label': 'What the business trades as',
      'source': 'fact',
      'is': 'the name over the door, which is not always the legal one',
    },
    {
      'key': 'agreement.day',
      'tag': '<<agreement.day>>',
      'label': 'The day it is drawn up',
      'source': 'fact',
      'is': null,
    },
  ],
  // In the shape `present()` sends it, which is the point of it being here:
  // `id`, `version`, `notes`, `created_at` — and NO body. The wording is at the
  // top of this payload, above. The designer read a body out of this node as a
  // fallback, and out of `version` beside it, which is the integer three lines
  // from the top; neither branch could ever have run.
  'current_version': {
    'id': 'v-3',
    'version': 3,
    'notes': 'Wage band corrected.',
    'created_at': '2026-07-02T09:00:00+00:00',
  },
};

const _versions = [
  {
    'id': 'v-3',
    'version': 3,
    'created_at': '2026-07-02T09:00:00+00:00',
    'published_by': 'Jose Silva',
    'is_current': true,
  },
  {
    'id': 'v-2',
    'version': 2,
    'created_at': '2026-05-11T09:00:00+00:00',
    'published_by': 'Jose Silva',
    'is_current': false,
  },
];

/// `POST /hr/contracts/{id}/versions` with `preview: true` — the server
/// previewing its own publish.
const _diff = {
  'version': 4,
  'diff': {
    'added': [
      {
        'key': 'nino',
        'label': 'National insurance number',
        'type': 'text',
        'filled_by': 'employee',
        'required': true,
        'sensitive': true,
      },
    ],
    'retired': [
      {'key': 'tshirt', 'label': 'T-shirt size'},
    ],
    'unretired': <Map<String, Object?>>[],
    'changed': [
      {
        'key': 'bank_account',
        'label': 'Bank account number',
        // The wire says `employer`; the panel has to say "Head office".
        'properties': {
          'filled_by': {'from': 'employer', 'to': 'employee'},
        },
      },
    ],
    'reordered': true,
    'is_empty': false,
  },
};

/// The same publish, with the wording rewritten.
///
/// `body` is the SERVER's answer, and its real shape:
/// `'added' | 'edited' | 'removed' | null`. This fixture said `body_changed:
/// true`, a key the server has never sent — so the client's parser looked for
/// it, found nothing, and the publish panel never mentioned the wording. Both
/// ends were green against different fictions, which is the specific way a
/// hand-written fixture can hide a wire break rather than catch one.
///
/// Kept as the server's answer rather than computed here: this end holds both
/// versions of the text and could compare them, and that would be the second
/// implementation the rest of this screen refuses to be.
const _rewrittenDiff = {
  'version': 4,
  'diff': {
    'added': <Map<String, Object?>>[],
    'retired': <Map<String, Object?>>[],
    'unretired': <Map<String, Object?>>[],
    'changed': <Map<String, Object?>>[],
    'reordered': false,
    'body': 'edited',
    'is_empty': false,
  },
};

/// `merge_tags` out of the preview answer — `MergeTags::resolvable()`, whole.
///
/// ── The registry, and why the editor has to read THIS ────────────
///
/// It is the one list that knows the template's own questions, because the
/// preview request is the one that carries them. Four server rules decide what
/// is in it, and the editor restated three of them wrongly before it read this:
/// a field is offered only while it is LIVE, a signature field is not offered
/// at all, the core is what onboarding ASKS rather than what it reserves, and a
/// fact key is dotted.
///
/// So `tshirt` — retired, and in the template below — is absent here, and an
/// editor building its own list from the fields on screen would show it. Every
/// entry carries a ready-made `tag`, in the delimiters the catalogue declared,
/// because the client wraps nothing itself.
const _resolvable = [
  {
    'key': 'wage',
    'tag': '<<wage>>',
    'label': 'Hourly wage',
    'source': 'field',
    'is': null,
  },
  {
    'key': 'bank_account',
    'tag': '<<bank_account>>',
    'label': 'Bank account number',
    'source': 'field',
    'is': null,
  },
  {
    'key': 'date_started',
    'tag': '<<date_started>>',
    'label': 'First day',
    'source': 'field',
    'is': null,
  },
  {
    'key': 'given_name',
    'tag': '<<given_name>>',
    'label': 'The name they go by',
    'source': 'core',
    'is': 'the first name they gave us',
  },
  {
    'key': 'trading_name',
    'tag': '<<trading_name>>',
    'label': 'What the business trades as',
    'source': 'fact',
    'is': 'the name over the door, which is not always the legal one',
  },
  {
    'key': 'agreement.day',
    'tag': '<<agreement.day>>',
    'label': 'The day it is drawn up',
    'source': 'fact',
    'is': null,
  },
];

/// `POST /hr/contracts/{id}/preview` — the document, rendered, with sample
/// answers already merged in.
///
/// A whole HTML page, because that is what the server renders and stores
/// (DocumentRenderer). It carries the two things the editor has to cope with: a
/// `<head>` full of things that are not prose, and an escaped entity in the
/// middle of a sentence.
///
/// It carries NO surviving merge tag, and that is a correction. It used to end
/// on `{{overtime_rate}}`, on the theory that the server renders a tag it could
/// not resolve — but `preview()` validates before it renders, so an
/// unresolvable tag is a 422 and this response never happens. A fixture that
/// showed one was testing a state the endpoint cannot produce.
const _rendered = '''
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Technician, full time</title>
<style>body{font:14px/1.6 Georgia,serif}</style></head><body>
<header><p class="org">Bell &amp; Sons Chimneys</p>
<h1>Technician, full time</h1></header>
<p>This Agreement is made on 4 August 2026 between Bell &amp; Sons Chimneys
and Alex Fielder.</p>
<p>The hourly wage is 14.50, paid monthly.</p>
<p>Overtime is paid at 21.75 of the above.</p>
</body></html>
''';

const _emptyDiff = {
  'version': 4,
  'diff': {
    'added': <Map<String, Object?>>[],
    'retired': <Map<String, Object?>>[],
    'unretired': <Map<String, Object?>>[],
    'changed': <Map<String, Object?>>[],
    'reordered': false,
    'is_empty': true,
  },
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view'],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

// ══ Step 2, and the cover ═══════════════════════════════════════════
//
// Everything below belongs to two changes: the always-asked step reading the
// list of what onboarding ASKS instead of the list of keys a template may not
// DECLARE, and the cover picker. The fixtures are their own rather than edits
// to `_catalogue`, which forty other tests share — and the question here needs
// the two lists to OVERLAP the way a real catalogue's do.

/// Step 2, and nothing else on the screen.
///
/// It has to be scoped. The form preview two steps down draws the reserved
/// keys under a heading of its own, so an unscoped `find.text` cannot tell
/// "step 2 lists this" from "this exists somewhere on the designer" — and that
/// is exactly the distinction every assertion here is about.
final Finder _coreStep = find.ancestor(
  of: find.text('Always asked'),
  matching: find.byType(NumberedStep),
);

Finder _inCoreStep(String text) =>
    find.descendant(of: _coreStep, matching: find.textContaining(text));

/// One locked chip in step 2 — the whole chip, so a mark can be pinned to the
/// question it belongs to rather than merely to the step it sits in.
Finder _coreChip(String label) => find.ancestor(
  of: find.descendant(of: _coreStep, matching: find.text(label)),
  matching: find.byType(Tooltip),
);

final Finder _coverStep = find.ancestor(
  of: find.text('Give it a cover'),
  matching: find.byType(NumberedStep),
);

Finder _inCoverStep(String text) =>
    find.descendant(of: _coverStep, matching: find.textContaining(text));

/// A catalogue whose asked list and reserved list overlap, as a real one's do.
///
/// `_catalogue` makes them disjoint deliberately, to prove the body editor does
/// not offer reserved keys as insertable tags. That is the right fixture for
/// that question and the wrong one for this: `CommonCore::ASKED` is a SUBSET of
/// `CommonCore::RESERVED` on the real server, and requiredness is published on
/// the reserved entry.
///
/// The two lists label the same key differently, which no server does. That is
/// the point — the words on screen say which list was read.
///
/// `asked` on every reserved entry and a pre-filtered `core` beside them are
/// the endpoint's own shape. Neither is read by this build — `ContractVocabulary`
/// takes `reserved_keys` and stops — and they are in the fixture anyway, so that
/// the payload these tests run against is the payload the server sends rather
/// than a trimmed one that could hide a misreading.
final List<Map<String, Object?>> _askedReserved = [
  for (final entry in _catalogue['reserved_keys'] as List)
    {...(entry as Map).cast<String, Object?>(), 'asked': false, 'required': false},
  // Asked, and required by saying nothing about it — which is what a server
  // too old to publish requiredness sends for every key it has.
  {
    'key': 'given_name',
    'label': 'RESERVED given name',
    'is': 'the first name they gave us',
    'reason': 'Invented for this fixture.',
    'asked': true,
  },
  // Asked, and explicitly optional. This is the entry the owner's third
  // request is about: a question nobody will be made to answer.
  {
    'key': 'pet_name',
    'label': 'RESERVED pet name',
    'is': 'the name of their dog',
    'reason': 'Also invented.',
    'asked': true,
    'required': false,
  },
];

final Map<String, Object?> _askedCatalogue = {
  for (final entry in _catalogue.entries)
    if (entry.key != 'reserved_keys' && entry.key != 'merge_tags')
      entry.key: entry.value,
  'reserved_keys': _askedReserved,
  // The endpoint's pre-filtered asked list, filtered from the same array the
  // way the endpoint filters it. Shadowed by `reserved_keys` in
  // `ContractVocabulary.fromJson`'s fallback chain and therefore read by
  // nothing — it is here so the payload is the real one.
  'core': [
    for (final entry in _askedReserved)
      if (entry['asked'] == true) entry,
  ],
  'merge_tags': {
    ...(_catalogue['merge_tags'] as Map).cast<String, Object?>(),
    'core': [
      {
        'key': 'given_name',
        'tag': '<<given_name>>',
        'label': 'The first name on their record',
        'is': 'the first name they gave us',
      },
      {
        'key': 'pet_name',
        'tag': '<<pet_name>>',
        'label': 'The name of their dog',
        'is': 'the name of their dog',
      },
    ],
  },
};

/// The same catalogue with every `required` stripped — the one shipping today.
final Map<String, Object?> _silentCatalogue = {
  ..._askedCatalogue,
  'reserved_keys': [
    for (final entry in _askedReserved)
      {
        for (final property in entry.entries)
          if (property.key != 'required') property.key: property.value,
      },
  ],
};

/// A 1x1 PNG — a real one, because the picker decodes what it fetches and a
/// handful of arbitrary bytes is a decode failure rather than a logo.
final List<int> _pixel = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAE'
  'hQGAhKmMIQAAAABJRU5ErkJggg==',
);

/// The two endpoints the cover picker reaches that `_handler` knows nothing
/// about: the organization's mark, and where a cover file is stored.
///
/// A wrapper rather than more branches inside `_handler`, which every other
/// test in this file shares.
MockClientHandler _withCoverEndpoints(
  MockClientHandler inner, {
  List<int>? logo,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/branding/organization')) {
      // 404 rather than an empty 200: `getBytes` answers null on any non-200,
      // and "there is no logo" has to be tellable from "here is an empty one".
      return logo == null
          ? http.Response('', 404)
          : http.Response.bytes(
              logo,
              200,
              headers: {'content-type': 'image/png'},
            );
    }

    // Ahead of `_handler`'s `/hr/contracts/` catch-all, which would answer a
    // whole template here and hand the panel a body with no upload id in it.
    if (path.endsWith('/hr/contracts/covers')) {
      return _json({
        'data': {'upload_id': 'up-1', 'filename': 'cover.png'},
      }, 201);
    }

    return inner(request);
  };
}

/// Captures the body of a real publish, which `_handler`'s own `onPublish` hook
/// does not carry.
MockClientHandler _watchPublish(
  MockClientHandler inner,
  void Function(Map<String, dynamic> body) onBody,
) {
  return (request) async {
    if (request.method == 'POST' && request.url.path.endsWith('/versions')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;

      if (body['preview'] != true) onBody(body);
    }

    return inner(request);
  };
}

/// The preview a cover-only publish gets back, in the shape the endpoint sends
/// it: `cover` BESIDE `diff`, never inside it.
///
/// The server keeps them apart on purpose — `VersionDiff` compares field lists
/// and bodies and is also what the version-history screen renders — so
/// `is_empty` here is TRUE while there is genuinely a version to publish. A
/// client reading only the diff offers no button for the one act the owner
/// asked for.
MockClientHandler _coverOnlyPublish(
  MockClientHandler inner,
  void Function(Map<String, dynamic> body) onBody,
) {
  return (request) async {
    if (request.method == 'POST' && request.url.path.endsWith('/versions')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;

      if (body['preview'] == true) {
        return _json({
          'data': {
            'diff': {
              'added': <Object?>[],
              'retired': <Object?>[],
              'unretired': <Object?>[],
              'changed': <Object?>[],
              'reordered': false,
              'body': null,
              'is_empty': true,
            },
            'cover': {'changed': true, 'from': null, 'to': 'logo'},
            'version': 4,
          },
        });
      }

      onBody(body);
    }

    return inner(request);
  };
}

ApiResponse _uploaded(String id, String filename) => ApiResponse(
  statusCode: 201,
  body: {
    'data': {'upload_id': id, 'filename': filename},
  },
);

ApiResponse _refused(String sentence) => ApiResponse(
  statusCode: 422,
  body: {
    'message': 'The given data was invalid.',
    'errors': {
      'cover': [sentence],
    },
  },
);

/// The cover picker on its own, opened the way the designer opens it.
///
/// Through `showSidePanel` rather than mounted bare, because the panel answers
/// by popping a route — and what it pops, or does not, is half of what these
/// tests are about.
///
/// The upload is handed in rather than left to the real one. `postFile` builds
/// an `http.MultipartRequest` and `BaseRequest.send()` makes a `Client()` of
/// its own, so the MockClient below never sees an upload — see
/// [ContractCoverPanel.upload].
Future<void> _pumpPicker(
  WidgetTester tester, {
  required void Function(ContractCover? cover) onDone,
  ContractCover cover = ContractCover.none,
  Future<PickedBytes?> Function()? pickImage,
  Future<ApiResponse> Function(PickedBytes picked)? upload,
}) async {
  tester.view.physicalSize = const Size(900, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async => http.Response('', 404)),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () async => onDone(
              await showSidePanel<ContractCover>(
                context,
                builder: (_) => ContractCoverPanel(
                  api: api,
                  cover: cover,
                  pickImage: pickImage ?? () async => null,
                  upload: upload,
                ),
              ),
            ),
            child: const Text('open the picker'),
          ),
        ),
      ),
    ),
  );

  await _tap(tester, find.text('open the picker'));
}
