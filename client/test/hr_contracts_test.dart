import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
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
    ]);

    // The real server declares six. A client with the list compiled in would
    // offer the other three against a catalogue that never mentioned them.
    expect(find.text('Number'), findsNothing);
    expect(find.text('A signature'), findsNothing);
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
  void Function()? onPublish,
  Map<String, Object?> diff = _diff,
  int catalogueStatus = 200,
}) {
  return (request) async {
    final path = request.url.path;

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
    },
    {
      'key': 'choice',
      'label': 'Pick from a list',
      'description': 'One of yours.',
    },
    {'key': 'date', 'label': 'A day', 'description': 'A day on a calendar.'},
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
  // The endpoint sends `is` — a phrase completing "it is …" — and `reason`, the
  // whole refusal. There is no `label`, so what the screen shows for one of
  // these is the server's phrase and never a title-cased key. `filled_by` is
  // absent too, which is why both of these turn up on both sides of the
  // preview: the employer may fill them in and does not have to.
  'reserved_keys': [
    {
      'key': 'email',
      'is': 'Work email address',
      'reason': 'The invitation is addressed to it.',
    },
    {
      'key': 'shoe_size',
      'is': 'Shoe size',
      'reason': 'Invented for this fixture, and that is the point.',
    },
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
