import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/contracts.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/contract_body_editor.dart';
import 'package:ezbizi_client/screens/hr/contract_document_page.dart';
import 'package:ezbizi_client/screens/hr/contract_form_preview.dart';
import 'package:ezbizi_client/screens/hr/contracts_tab.dart';
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
    expect(find.text('Version 3'), findsOneWidget);

    // The server's count is of the questions still ASKED — the template carries
    // four and one is retired — so the wording has to say which number it is,
    // or a template that has been tidied up reads as one that shrank.
    expect(find.textContaining('3 questions asked today'), findsOneWidget);
  });

  /// §2.1, and the whole point of this screen. The catalogue's types are
  /// deliberately three, in wording nobody would invent.
  testWidgets('the kinds of question come from the server, all of them', (
    tester,
  ) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    final types = _dropdown(tester, 'Kind of answer');

    expect(types.items!.map((i) => (i.child as Text).data), [
      'A line of words',
      'Pick from a list',
      'A day',
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
      'fields': [
        {
          'key': 'shoe_size_2',
          'label': 'Shoe size',
          'type': 'text',
          'options': <String>[],
          'filled_by': 'employee',
          'required': false,
          'sensitive': false,
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
      contains('is made on {{date_started}}'),
      reason: 'The version’s body has to arrive in the editor, or every save '
          'would wipe the contract that was already written.',
    );

    // And the page, beside it.
    expect(find.byType(ContractDocumentPage), findsOneWidget);

    final writing = tester.getRect(_writingBox);
    final page = tester.getRect(find.byType(ContractDocumentPage));

    expect(
      writing.right,
      lessThanOrEqualTo(page.left + 1),
      reason: 'The two are meant to be side by side, not stacked.',
    );
    expect(
      (writing.width - page.width).abs(),
      lessThan(2),
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
  /// the end.
  testWidgets('a tag nothing can fill in is marked in the page and named '
      'underneath it', (tester) async {
    await _pump(tester, _handler());
    await _tap(tester, find.text('Design'));

    // In the page, where it is, so somebody can see WHERE it is.
    expect(_inPage('{{overtime_rate}}'), findsOneWidget);

    // And said out loud, with what to do about it.
    expect(
      find.textContaining('Nothing answers {{overtime_rate}}'),
      findsOneWidget,
    );
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
      'Made on {{date_started}} between the parties.',
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

    expect(body.text, '{{trading_name}}');

    await _settle(tester);
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

  /// R7. Half and half is a desktop layout; 50/50 at 375px is two unusable
  /// columns.
  testWidgets('on a narrow screen it is one pane at a time, never two '
      'columns', (tester) async {
    await _pumpEditor(tester, size: const Size(375, 900));

    // Writing first, and the page is not built at all — not built narrow, not
    // built off screen.
    expect(_writingBox, findsOneWidget);
    expect(find.byType(ContractDocumentPage), findsNothing);

    await tester.tap(find.text('The page'));
    await tester.pumpAndSettle();

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
    await _pumpEditor(tester, theme: EzBiziTheme.dark());

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
      Theme.of(tester.element(find.byType(ContractBodyEditor))).brightness,
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
    body.text = 'Made between {{business_name}} and the person named below.';
    await _settle(tester);

    expect(_inPage('Made between'), findsOneWidget);
    expect(
      _inPage('{{business_name}}'),
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
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  List<String> permissions = const ['hr.view', 'hr.create', 'hr.edit'],
  Size size = const Size(1500, 3200),
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
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ContractsTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The body editor on its own, for the two things that are about the editor
/// rather than about the designer: the narrow layout, and the colour of the
/// page.
///
/// Pumped directly because both are questions about a surface of a given SIZE,
/// and driving them through the whole designer would mean asserting the layout
/// of five other steps at 375px to get at this one.
Future<TextEditingController> _pumpEditor(
  WidgetTester tester, {
  Size size = const Size(1200, 900),
  ThemeData? theme,
  String? contractId = 'c-1',
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final controller = TextEditingController();
  addTearDown(controller.dispose);

  await tester.pumpWidget(
    MaterialApp(
      theme: theme ?? EzBiziTheme.light(),
      home: Scaffold(
        body: ContractBodyEditor(
          api: ApiClient(client: MockClient(_handler())),
          vocabulary: ContractVocabulary.fromJson(
            Map<String, dynamic>.from(_catalogue),
          ),
          body: controller,
          contractId: contractId,
          fields: () => const [],
          payload: () => const [],
          canEdit: true,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return controller;
}

/// The writing surface — the one text box inside the body editor. Everything
/// else in that half is a chip.
final Finder _writingBox = find.descendant(
  of: find.byType(ContractBodyEditor),
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
    find.widgetWithText(DropdownButtonFormField<String>, label),
  );
  await _tap(tester, find.text(option).last);
}

DropdownButton<String> _dropdown(WidgetTester tester, String label) =>
    tester.widget<DropdownButton<String>>(
      find
          .descendant(
            of: find.widgetWithText(DropdownButtonFormField<String>, label),
            matching: find.byType(DropdownButton<String>),
          )
          .first,
    );

// ── the server, as this screen sees it ─────────────────────────────

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onPreview,
  void Function(Map<String, dynamic> body)? onRender,
  void Function()? onPublish,
  Map<String, Object?> diff = _diff,
  int catalogueStatus = 200,
}) {
  return (request) async {
    final path = request.url.path;

    // The document, rendered. Ahead of the `/hr/contracts/` catch-all below,
    // and ahead of `/versions`, because it is a different act: `/versions`
    // previews a PUBLISH, this renders a PAGE.
    if (path.endsWith('/preview')) {
      onRender?.call(jsonDecode(request.body) as Map<String, dynamic>);

      return _json({
        'data': {'html': _rendered, 'unresolved': ['overtime_rate']},
      });
    }

    if (path.endsWith('/hr/contracts/catalogue')) {
      return catalogueStatus == 200
          ? _json({'data': _catalogue})
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

      return _json({'data': _templates});
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
  // The facts a contract's WORDING can merge in, beyond its own questions and
  // the common core. Named nothing like the obvious guesses on purpose: a
  // client carrying `organization_name` and `today` in Dart would offer two
  // tags this server has never heard of, and they would render in an
  // employment contract as themselves.
  'document_tags': [
    {
      'key': 'trading_name',
      'label': 'What the business trades as',
      'note': 'The name over the door, which is not always the legal one.',
    },
    {'key': 'agreement_date', 'label': 'The day it is drawn up'},
  ],
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
      'This Agreement is made on {{date_started}} between the business and '
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

/// `POST /hr/contracts/{id}/preview` — the document, rendered, with sample
/// answers already merged in.
///
/// A whole HTML page, because that is what the server renders and stores
/// (DocumentRenderer). It carries the three things the editor has to cope with:
/// a `<head>` full of things that are not prose, an escaped entity in the
/// middle of a sentence, and a merge tag the server could not resolve and
/// therefore left exactly where it was.
const _rendered = '''
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Technician, full time</title>
<style>body{font:14px/1.6 Georgia,serif}</style></head><body>
<header><p class="org">Bell &amp; Sons Chimneys</p>
<h1>Technician, full time</h1></header>
<p>This Agreement is made on 4 August 2026 between Bell &amp; Sons Chimneys
and Alex Fielder.</p>
<p>The hourly wage is 14.50, paid monthly.</p>
<p>Overtime is paid at {{overtime_rate}} of the above.</p>
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
