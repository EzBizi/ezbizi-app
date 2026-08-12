import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/sending_identity_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// "Mail from this business goes out as info@everdoors.com" — and whether it
/// works.
///
/// ── The four things being defended ───────────────────────────────
///
/// The first is INHERITANCE, and it is the whole point. A place showing
/// `info@a-1chimney.com` must say whether that address is its own or its
/// company's showing through, because somebody who cannot tell will "fix" one
/// franchise by editing what turns out to be the parent — changing every place
/// under it. The fixtures below are the owner's real structure: A1-Group over
/// A-1 Chimney over Connecticut, with Connecticut inheriting until it does not.
///
/// The second is that a franchise CANNOT change a parent's address even by
/// accident, which is structural rather than a check: the write carries the
/// scope being looked at, so pressing Save on an inherited address creates the
/// franchise's own and leaves the company's alone. That is asserted on the wire.
///
/// The third is that the verification state is honest. Unverified is the normal
/// state on the way in and pending is a thing working correctly; neither may be
/// drawn as a fault. The states and their words are the SERVER's — the fixtures
/// use wording nobody would guess, so a client carrying its own vocabulary
/// cannot pass — and the client picks only the mark beside them.
///
/// The fourth is that a refusal is the server's sentence, never one composed
/// here, and never a wire name (R8).
void main() {
  // ── which identity is in force, and where it came from ───────────

  testWidgets('an inherited address says whose it is', (tester) async {
    await _pumpCard(tester, identity: _inherited());

    // The control. Everything below is about a sentence being present or
    // absent, and all of it would pass against a card that drew nothing.
    expect(find.text('info@a-1chimney.com'), findsOneWidget);

    expect(find.text('Inherited from A-1 Chimney'), findsOneWidget);
    expect(
      find.text('Set here'),
      findsNothing,
      reason:
          'Connecticut holds no address of its own. Saying it does is how '
          'somebody edits the company thinking they are editing the place.',
    );
  });

  testWidgets('an address set at this level is not labelled inherited', (
    tester,
  ) async {
    await _pumpCard(tester, identity: _own());

    expect(find.text('info@ct.a-1chimney.com'), findsOneWidget);
    expect(find.text('Set here'), findsOneWidget);
    expect(find.textContaining('Inherited from'), findsNothing);
  });

  /// Nothing set anywhere in the cascade is a third state, not a rounding of
  /// the second. What is on screen is the platform's address, and nobody in
  /// this organization chose it.
  testWidgets('an address nobody has set says it is the platform’s', (
    tester,
  ) async {
    await _pumpCard(
      tester,
      identity: _identity(
        address: 'no-reply@ezbizi.com',
        name: 'EzBizi',
        source: null,
        verification: null,
      ),
    );

    expect(find.text('no-reply@ezbizi.com'), findsOneWidget);
    expect(find.text('EzBizi default'), findsOneWidget);
    expect(find.textContaining('Inherited from'), findsNothing);
    expect(find.text('Set here'), findsNothing);
  });

  /// The sentence that stops the mistake, spelled out where the editing is.
  testWidgets('the panel says that editing here leaves the company alone', (
    tester,
  ) async {
    await _pumpCard(tester, identity: _inherited());
    await _openPanel(tester);

    expect(
      find.textContaining(
        'That address belongs to A-1 Chimney, not to Connecticut',
      ),
      findsOneWidget,
    );

    // And the box is EMPTY rather than pre-filled with the company's address.
    // A field already carrying `info@a-1chimney.com` invites one keystroke and
    // a Save that quietly gives Connecticut its own copy of somebody else's
    // identity.
    expect(
      tester.widget<TextField>(_addressField).controller?.text,
      isEmpty,
      reason:
          'The parent’s address is pre-filled here, which reads as a value '
          'this place has already chosen.',
    );

    // It is still visible as the hint, so the box is not a blank with no
    // context — that is what it would keep doing if nothing is typed.
    expect(
      tester.widget<TextField>(_addressField).decoration?.hintText,
      'info@a-1chimney.com',
    );
  });

  /// §6, and the rule that is already enforced everywhere else: the level you
  /// are standing at is the only one you can write.
  testWidgets('saving from a place writes that place, never the company it '
      'inherits from', (tester) async {
    Uri? wrote;

    await _pumpCard(
      tester,
      identity: _inherited(),
      handler: (request) async {
        if (request.method == 'PATCH') {
          wrote = request.url;
          return _json({'data': _own()});
        }

        return _json({'message': 'Unexpected request.'}, 500);
      },
    );

    await _openPanel(tester);
    await tester.enterText(_addressField, 'info@ct.a-1chimney.com');
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(wrote, isNotNull, reason: 'Save sent nothing at all.');
    expect(wrote!.path, endsWith('/mail/sending-identity'));
    expect(wrote!.queryParameters['scope_type'], 'franchise');
    expect(
      wrote!.queryParameters['scope_id'],
      'fr-ct',
      reason:
          'The write named the company the address was inherited FROM. That is '
          'a franchise-level user changing every place under A-1 Chimney.',
    );
  });

  // ── the verification state, honestly ─────────────────────────────

  /// Every state renders, in the server's own word for it. The labels in these
  /// fixtures are deliberately not words a client would invent.
  testWidgets('each verification state renders the server’s own words', (
    tester,
  ) async {
    for (final (state, label) in const [
      ('unverified', 'Not proved yet'),
      ('pending', 'Waiting on your DNS'),
      ('verified', 'Proved and in use'),
      ('failed', 'The last look did not find it'),
    ]) {
      await _pumpCard(
        tester,
        identity: _identity(
          verification: _verification(state: state, label: label),
        ),
      );

      expect(
        find.text(label),
        findsOneWidget,
        reason: 'The “$state” state did not print the label the server sent.',
      );
    }
  });

  /// A state this build has never heard of still says what the server called
  /// it, under a neutral mark, rather than vanishing.
  testWidgets('a state this build does not know still shows its label', (
    tester,
  ) async {
    await _pumpCard(
      tester,
      identity: _identity(
        verification: _verification(
          state: 'held_by_the_provider',
          label: 'Held by the provider',
        ),
      ),
    );

    expect(find.text('Held by the provider'), findsOneWidget);
    expect(find.byIcon(Icons.info_outline), findsOneWidget);
  });

  /// The one that matters most. Waiting for DNS to propagate is a thing working
  /// correctly, and drawing it in the error colour with the error mark sends
  /// somebody to support over a system that is fine.
  testWidgets('pending does not look like broken, and failed does', (
    tester,
  ) async {
    await _pumpCard(
      tester,
      identity: _identity(
        verification: _verification(
          state: 'pending',
          label: 'Waiting on your DNS',
        ),
      ),
    );

    expect(find.byIcon(Icons.schedule), findsOneWidget);
    expect(
      find.byIcon(Icons.error_outline),
      findsNothing,
      reason: 'Pending is wearing the mark of a fault.',
    );
    expect(
      _colourOf(tester, 'Waiting on your DNS'),
      isNot(EzBiziTheme.light().colorScheme.error),
    );

    await _pumpCard(
      tester,
      identity: _identity(
        verification: _verification(
          state: 'failed',
          label: 'The last look did not find it',
        ),
      ),
    );

    // The other half of the same claim: something genuinely wrong is marked as
    // wrong. Without this, "pending is not an error" would also pass on a card
    // that never marks anything.
    expect(find.byIcon(Icons.error_outline), findsOneWidget);
    expect(
      _colourOf(tester, 'The last look did not find it'),
      EzBiziTheme.light().colorScheme.error,
    );
  });

  /// What is actually needed, in a form somebody can put into another system,
  /// and what happens after they do.
  testWidgets('the DNS records are shown, copyable, and say what happens next',
      (tester) async {
    final copied = _watchTheClipboard(tester);

    await _pumpCard(tester, identity: _pendingWithRecords());
    await _openPanel(tester);

    expect(find.text('DNS records'), findsOneWidget);
    expect(find.text('TXT record'), findsOneWidget);
    expect(find.text('ezbizi._domainkey.ct.a-1chimney.com'), findsOneWidget);
    expect(find.text(_dkim), findsOneWidget);

    // Per record, in words rather than in a colour: one is there, one is not,
    // and neither is a failure.
    expect(find.text('Found'), findsOneWidget);
    expect(find.text('Not there yet'), findsOneWidget);

    // The sentence that stops "pending" reading as "stuck".
    expect(
      find.text(
        'Add these where your domain is hosted. We look again every hour, and '
        'nothing else is needed from you.',
      ),
      findsOneWidget,
    );

    await tester.tap(find.byTooltip('Copy the Value').first);
    await tester.pumpAndSettle();

    expect(
      copied,
      contains(_dkim),
      reason:
          'A record somebody cannot copy is one they have to retype, and a '
          'mistyped DKIM key fails in a way nothing on this screen explains.',
    );
  });

  /// Verified is the state with nothing to do, and it must not still be showing
  /// somebody a list of records to add.
  testWidgets('a verified identity is not still asking for records', (
    tester,
  ) async {
    await _pumpCard(
      tester,
      identity: _identity(
        source: _source('franchise', 'fr-ct', 'Connecticut', false),
        verification: _verification(
          state: 'verified',
          label: 'Proved and in use',
          detail: 'Checked an hour ago. Everything this place sends is signed.',
        ),
      ),
    );
    await _openPanel(tester);

    // The control: the panel IS open and IS showing this verification, so the
    // absence below is about the records rather than about an empty screen.
    expect(find.text('Proved and in use'), findsWidgets);
    expect(
      find.text('Checked an hour ago. Everything this place sends is signed.'),
      findsOneWidget,
    );

    expect(find.text('DNS records'), findsNothing);
    expect(find.text('Check now'), findsNothing);
  });

  // ── what is actually wrong, when something is ────────────────────

  /// R8. The sentence is the server's, and it is on screen rather than only in
  /// a tooltip, because a panel has the room and this is the whole of what
  /// somebody stuck here needs.
  testWidgets('a refusal renders the server’s sentence, not one invented here',
      (tester) async {
    const refusal =
        'Only somebody who can edit this company’s details may change where '
        'its mail comes from. Whoever administers A-1 Chimney can do it, or '
        'add it to your role.';

    await _pumpCard(
      tester,
      identity: _identity(
        source: _source('franchise', 'fr-ct', 'Connecticut', false),
        mayEdit: false,
        mayEditReason: refusal,
      ),
    );
    await _openPanel(tester);

    expect(find.text(refusal), findsOneWidget);

    // Dead, and dead for a stated reason rather than silently.
    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Save'))
          .onPressed,
      isNull,
    );

    // Not a permission key, not a class name, not a field off the wire.
    for (final wireName in [
      'may_edit',
      'may_edit_reason',
      'mail.sending_identity',
      'SendingIdentityPanel',
    ]) {
      expect(
        find.textContaining(wireName),
        findsNothing,
        reason: '“$wireName” is a wire name on a customer’s screen (R8).',
      );
    }
  });

  /// The control for the test above: with permission, the same button is live
  /// and no sentence is invented to fill the space.
  testWidgets('with permission the save is live and says nothing about roles', (
    tester,
  ) async {
    await _pumpCard(tester, identity: _own());
    await _openPanel(tester);

    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Save'))
          .onPressed,
      isNotNull,
    );
    expect(find.textContaining('do not have permission'), findsNothing);
  });

  /// A refused write, and the sentence somebody can act on — which for a 422 is
  /// under `errors`, not the "the given data was invalid" at the top.
  testWidgets('a refused save shows what the server said was wrong', (
    tester,
  ) async {
    const refusal =
        'ct.a-1chimney.com is already proved by another organization. Ask them '
        'to release it, or use an address on a domain you hold.';

    await _pumpCard(
      tester,
      identity: _inherited(),
      handler: (request) async => request.method == 'PATCH'
          ? _json({
              'message': 'The given data was invalid.',
              'errors': {
                'from_address': [refusal],
              },
            }, 422)
          : _json({'message': 'Unexpected request.'}, 500),
    );

    await _openPanel(tester);

    // Nothing is claiming a failure before one has happened.
    expect(find.text(refusal), findsNothing);

    await tester.enterText(_addressField, 'info@ct.a-1chimney.com');
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(find.text(refusal), findsOneWidget);
    expect(
      find.text('The given data was invalid.'),
      findsNothing,
      reason:
          'That is the wrapper around the real answer, and it tells nobody '
          'anything.',
    );

    // Still open, still holding what was typed. A panel that closed on a
    // refusal would take both the message and the half-typed address with it.
    expect(find.text('Email from this business'), findsWidgets);
    expect(
      tester.widget<TextField>(_addressField).controller?.text,
      'info@ct.a-1chimney.com',
    );
  });

  /// The answer to a write is the new state, and both surfaces take it.
  testWidgets('a saved address turns into records to add, and the card follows',
      (tester) async {
    await _pumpCard(
      tester,
      identity: _inherited(),
      handler: (request) async => request.method == 'PATCH'
          ? _json({'data': _pendingWithRecords()})
          : _json({'message': 'Unexpected request.'}, 500),
    );

    await _openPanel(tester);
    await tester.enterText(_addressField, 'info@ct.a-1chimney.com');
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    // The moment somebody most needs to be looking at the records is the moment
    // they are produced, so the panel stays open on them.
    expect(find.text('Waiting on your DNS'), findsWidgets);
    expect(find.text(_dkim), findsOneWidget);

    await tester.tap(find.widgetWithText(TextButton, 'Close'));
    await tester.pumpAndSettle();

    // And the card behind it is not still describing the world before the save.
    expect(find.text('Set here'), findsOneWidget);
    expect(find.text('info@ct.a-1chimney.com'), findsOneWidget);
    expect(find.text('Inherited from A-1 Chimney'), findsNothing);
  });

  // ── the rest of the promises this card makes ─────────────────────

  /// A setting that saves, audits, inherits and changes nothing yet says so —
  /// the same admission, in the same words, as the rows beside it.
  testWidgets('an identity nothing sends from yet admits it', (tester) async {
    await _pumpCard(
      tester,
      identity: _identity(
        notYetInEffect:
            'Newsletter is the first thing that will send from it, and it has '
            'not shipped.',
      ),
    );

    expect(
      find.text(
        'Not in effect yet — Newsletter is the first thing that will send from '
        'it, and it has not shipped.',
      ),
      findsOneWidget,
    );
  });

  /// Every feature wires into automations, and what this one offers is the
  /// server's answer rather than a list compiled into Dart.
  testWidgets('what automations can run on comes from the server', (
    tester,
  ) async {
    await _pumpCard(
      tester,
      identity: _identity(
        automation: {
          'emits': [
            {'key': 'sending_identity.verified', 'label': 'a domain proving'},
            {'key': 'sending_identity.failed', 'label': 'a check failing'},
          ],
        },
      ),
    );
    await _openPanel(tester);

    expect(
      find.text('Automations can run on: a domain proving, a check failing.'),
      findsOneWidget,
    );
  });

  testWidgets('a server offering no automations yet says why, in its words', (
    tester,
  ) async {
    const note =
        'Nothing runs on this yet. It starts emitting when Newsletter ships '
        'and there is something for a rule to react to.';

    await _pumpCard(
      tester,
      identity: _identity(automation: {'emits': <dynamic>[], 'note': note}),
    );
    await _openPanel(tester);

    expect(find.text(note), findsOneWidget);
  });

  /// The platform's own mail is not this, and the card says so where somebody
  /// would otherwise assume it.
  testWidgets('the card says that signing in and joining still come from us', (
    tester,
  ) async {
    await _pumpCard(tester, identity: _own());

    expect(
      find.textContaining(
        'Signing in, password resets and joining messages always come from '
        'EzBizi',
      ),
      findsOneWidget,
    );
  });

  // ── R7: it has to work on a phone ────────────────────────────────

  /// The card and the panel, at the three widths, with the widest thing either
  /// of them ever holds: a DKIM key that is three hundred characters with no
  /// space in it to break on.
  for (final width in [375.0, 700.0, 1400.0]) {
    testWidgets('it lays out at ${width.toInt()}px without overflowing', (
      tester,
    ) async {
      await _pumpCard(
        tester,
        identity: _pendingWithRecords(),
        size: Size(width, 900),
      );

      expect(
        tester.takeException(),
        isNull,
        reason: 'The card overflowed at ${width.toInt()}px.',
      );

      await _openPanel(tester);

      expect(
        tester.takeException(),
        isNull,
        reason: 'The panel overflowed at ${width.toInt()}px.',
      );

      // Not merely "nothing threw". The record is on screen, and it is inside
      // the window rather than running off the right edge of it — which is
      // what a Row of monospace values does before it grows tall enough to
      // throw.
      final value = tester.getRect(find.text(_dkim));

      expect(
        value.width,
        lessThanOrEqualTo(width),
        reason:
            'The DKIM key is ${value.width}px wide in a ${width.toInt()}px '
            'window, so it is off the edge rather than wrapped.',
      );
      expect(value.left, greaterThanOrEqualTo(0));

      // And it is a copy away, not a select-and-drag away, at every width.
      expect(find.byTooltip('Copy the Value'), findsWidgets);
    });
  }
}

// ── harness ────────────────────────────────────────────────────────

/// The address field in the panel, as opposed to the display-name one beside
/// it.
final Finder _addressField = find.widgetWithText(
  TextField,
  'The address mail comes from',
);

/// A DKIM public key: long, and with nothing in it a line break can land on.
/// This is the value that overflows anything laid out sideways.
const String _dkim =
    'v=DKIM1;k=rsa;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA5Kx2Qm1nT8vB'
    'jL4dW9fYpR3sHc7NzVaXeUgOiK6bM0lQrTtE8yZwPuAdFhJnCgS1oVxIeD4mBqLZ7RkYtXvN'
    'aG3JwUcPfOb9SsMlH2iQeTz0xArKdVnCyBpEuF6mLjWgXhZtNoIdRq8SvYkAcMbP1eJfUgHl'
    'TnDxwQIDAQAB';

Color? _colourOf(WidgetTester tester, String text) =>
    tester.widget<Text>(find.text(text)).style?.color;

/// Put the card on screen with a canned server behind it, and give it somewhere
/// to keep the state a write hands back.
Future<void> _pumpCard(
  WidgetTester tester, {
  required Map<String, dynamic> identity,
  MockClientHandler? handler,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return handler == null
          ? _json({'message': 'Unexpected request.'}, 500)
          : handler(request);
    }),
  );

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
        body: _Holder(api: api, session: session, identity: identity),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The settings screen's job in three lines: hold the answer, and take the new
/// one when a write produces it.
class _Holder extends StatefulWidget {
  const _Holder({
    required this.api,
    required this.session,
    required this.identity,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> identity;

  @override
  State<_Holder> createState() => _HolderState();
}

class _HolderState extends State<_Holder> {
  late Map<String, dynamic> _identity = widget.identity;

  /// A test that pumps a second fixture into the same tree gets the second
  /// fixture. Without this the State keeps the first one — the widget rebuilds,
  /// the field does not, and every later assertion is made against the previous
  /// test case while appearing to pass or fail on this one.
  @override
  void didUpdateWidget(_Holder old) {
    super.didUpdateWidget(old);

    if (!identical(widget.identity, old.identity)) {
      _identity = widget.identity;
    }
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      SendingIdentitySection(
        api: widget.api,
        session: widget.session,
        identity: _identity,
        onChanged: (next) => setState(() => _identity = next),
      ),
    ],
  );
}

Future<void> _openPanel(WidgetTester tester) async {
  final open = find.byType(OutlinedButton).first;

  await tester.tap(open);
  await tester.pumpAndSettle();
}

/// Catch what the app puts on the clipboard, without a platform behind it.
List<String> _watchTheClipboard(WidgetTester tester) {
  final copied = <String>[];

  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async {
      if (call.method == 'Clipboard.setData') {
        copied.add((call.arguments as Map)['text'] as String);
      }

      return null;
    },
  );

  addTearDown(
    () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    ),
  );

  return copied;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

// ── fixtures, in the owner's real structure ────────────────────────

/// Connecticut, with nothing of its own, showing A-1 Chimney's address.
Map<String, dynamic> _inherited() => _identity(
  address: 'info@a-1chimney.com',
  name: 'A-1 Chimney',
  source: _source('company', 'co-a1', 'A-1 Chimney', true),
  verification: _verification(
    state: 'verified',
    label: 'Proved and in use',
    detail: 'A-1 Chimney proved a-1chimney.com in June.',
  ),
);

/// Connecticut, having set its own.
Map<String, dynamic> _own() => _identity(
  address: 'info@ct.a-1chimney.com',
  name: 'A-1 Chimney Connecticut',
  source: _source('franchise', 'fr-ct', 'Connecticut', false),
  verification: _verification(
    state: 'verified',
    label: 'Proved and in use',
    detail: 'Checked an hour ago.',
  ),
);

/// The state right after somebody saves one: real, waiting, and with the two
/// records that make it work.
Map<String, dynamic> _pendingWithRecords() => _identity(
  address: 'info@ct.a-1chimney.com',
  name: 'A-1 Chimney Connecticut',
  source: _source('franchise', 'fr-ct', 'Connecticut', false),
  verification: _verification(
    state: 'pending',
    label: 'Waiting on your DNS',
    detail: 'We looked twenty minutes ago and one of the two was not there.',
    nextStep:
        'Add these where your domain is hosted. We look again every hour, and '
        'nothing else is needed from you.',
    records: [
      {
        'type': 'TXT',
        'name': 'ezbizi._domainkey.ct.a-1chimney.com',
        'value': _dkim,
        'found': false,
      },
      {
        'type': 'CNAME',
        'name': 'mail.ct.a-1chimney.com',
        'value': 'relay.ezbizi.com',
        'found': true,
      },
    ],
  ),
);

/// "Say nothing and take the default" — distinct from passing null, which for
/// `source` and `verification` are the two states this card has to draw
/// differently from every other: nothing set anywhere in the cascade, and
/// nothing to verify.
const Object _unsaid = Object();

Map<String, dynamic> _identity({
  String? address = 'info@a-1chimney.com',
  String? name = 'A-1 Chimney',
  Object? source = _unsaid,
  Object? verification = _unsaid,
  bool mayEdit = true,
  String? mayEditReason,
  String? notYetInEffect,
  Map<String, dynamic>? transport,
  Map<String, dynamic>? automation,
}) => {
  'from_address': address,
  'from_name': name,
  'source': identical(source, _unsaid)
      ? _source('company', 'co-a1', 'A-1 Chimney', true)
      : source,
  'may_edit': mayEdit,
  'may_edit_reason': mayEditReason,
  'not_yet_in_effect': notYetInEffect,
  'verification': identical(verification, _unsaid)
      ? _verification(state: 'verified', label: 'Proved and in use')
      : verification,
  'transport': transport,
  'automation': automation,
};

Map<String, dynamic> _source(
  String type,
  String id,
  String name,
  bool inherited,
) => {'type': type, 'id': id, 'name': name, 'inherited': inherited};

Map<String, dynamic> _verification({
  required String state,
  required String label,
  String? detail,
  String? nextStep,
  List<Map<String, dynamic>> records = const [],
}) => {
  'state': state,
  'label': label,
  'detail': detail,
  'next_step': nextStep,
  'records': records,
};

/// Signed in at Connecticut — a place under A-1 Chimney under A1-Group, which
/// is where inheritance is visible and therefore where getting it wrong shows.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-a1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-ct',
    'label': 'Connecticut',
    'path': ['A1-Group', 'A-1 Chimney', 'Connecticut'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
