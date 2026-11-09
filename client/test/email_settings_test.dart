import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/email/consent_window.dart';
import 'package:ezbizi_client/screens/email/email_screen.dart';
import 'package:ezbizi_client/screens/email/mailbox_settings.dart';
import 'package:ezbizi_client/screens/email/settings_tab.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Email › Settings — the connections behind the mail.
///
/// ── What is actually being defended here ─────────────────────────
///
/// **A broken inbox has to say what broke.** The owner's words: *"it should
/// definitely display the message of revoked or expired."* Consent withdrawn
/// and a password changed are the same silence on screen and completely
/// different remedies, and a shared inbox that quietly stops delivering is a
/// customer who never got an answer. The sentence is the server's; this end
/// prints it, and the test asserts the sentence rather than a state name.
///
/// **When it last worked is half the answer.** "It broke just now" and "it has
/// been dead since Tuesday" are the same red dot. The line is asserted, and so
/// is its absence on a server that cannot yet answer the question — printing
/// "never" for a field nobody sent would be this screen inventing an outage.
///
/// **The credential goes one way.** There is no path on the server that hands a
/// stored mailbox password back — `Mailbox::mayReveal` answers false for
/// everybody — so the field is an empty box for a NEW value. The test feeds a
/// credential into the row anyway, as hostile input, and asserts it reaches no
/// pixel and no controller.
///
/// **A control that cannot finish is worse than none.** A sign-in button on an
/// installation with nothing behind it would be pressed, and waited on, before
/// anybody found that out. The server answers `usable` per provider and the
/// control exists exactly when that is true — never deduced from `needs_host`,
/// which is the guess that once drew a live password box for a transport this
/// server cannot speak.
///
/// **Coming back from the provider has to land somewhere.** A sign-in leaves
/// this application and something has to be true when it returns. The panel that
/// asked the question is the thing that answers it: it stays open on the
/// Settings tab, says it is waiting, and turns into either a connected inbox or
/// the server's own refusal. The refusal that matters most is deliberate — the
/// account somebody signed in with is not the address being connected — and it
/// names the account, which "something went wrong" does not.
///
/// **A button that appears to do nothing gets pressed twice.** For a password
/// that is one wasted round trip; for a sign-in it is two flows in flight
/// against one mailbox. Both roads say they are working, and both refuse the
/// second press while they are.
///
/// **The host is corrected where it is refused.** `RemoteHostGuard` runs inside
/// `connect`, not inside `store`, so the sentence about a mail server pointing
/// at a private network arrives on the connect panel. That panel therefore has
/// to hold the host, the port and the encryption — otherwise it is the one
/// screen that explains the problem and offers no way to fix it.
///
/// **Disconnecting takes an inbox away from everybody.** Not from the person
/// pressing it: from every role that was granted it, and reconnecting does not
/// bring the grants back. That is said in the sentence, before the request is
/// sent, and the test asserts nothing is sent until it has been read.
void main() {
  // ── the two sub-tabs ───────────────────────────────────────────

  testWidgets('Email has an Inbox tab and a Settings tab, and switching '
      'between them shows the other one', (tester) async {
    await _pump(tester, _handler());

    // The Inbox tab is what the module opens on, and it is what was there
    // before there were two of them. (Design 137: its search bar stands for
    // it; the old inbox bar is gone.)
    expect(find.byKey(const ValueKey('tour-email-search')), findsOneWidget);
    expect(find.byType(MailboxSettingsTab), findsNothing);

    await _openSettings(tester);

    expect(find.byType(MailboxSettingsTab), findsOneWidget);
    expect(find.text('Connected inboxes'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('tour-email-search')),
      findsNothing,
      reason:
          'The inbox is still being drawn under the Settings tab, so both '
          'pages are on screen at once.',
    );

    await _tap(tester, find.widgetWithText(ChoiceChip, 'Inbox'));

    expect(find.byKey(const ValueKey('tour-email-search')), findsOneWidget);
  });

  // ── marking read at the mail service ─────────────────────────────

  /// The switch is drawn only where the SERVER said the connection can do it.
  ///
  /// Offering it on Outlook or on a password-connected mail server would be a
  /// promise broken after the fact — and the fact, here, is a message already
  /// marked read for every colleague. That is not a refusal anybody can act on,
  /// which is why the capability is asked for rather than guessed from the
  /// provider name.
  testWidgets('the mail-service switch appears only where the server allows it', (
    tester,
  ) async {
    await _pump(tester, _handler(mailboxes: [
      _row(
        id: 'mb-1',
        address: 'info@a-1chimney.com',
        extra: const {'can_mark_read_at_source': true, 'mark_read_at_source': false},
      ),
      _row(
        id: 'mb-outlook',
        address: 'bookings@a-1chimney.com',
        extra: const {'can_mark_read_at_source': false, 'mark_read_at_source': false},
      ),
    ]));

    await _openSettings(tester);
    // Design 151: two inboxes, so neither is open until its row is tapped.
    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-1')));

    // One switch, not two: the second inbox cannot do it and is not offered it.
    expect(find.byType(SwitchListTile), findsOneWidget);
    expect(find.text('Keep read and unread in step with the mail service'), findsOneWidget);

    // The control: BOTH inboxes are on screen, so the missing switch is
    // "not offered for that one" rather than "the card failed to draw".
    expect(find.text('info@a-1chimney.com'), findsWidgets);
    expect(find.text('bookings@a-1chimney.com'), findsWidgets);
  });

  /// Off says what it costs to turn on; on says what it now does.
  ///
  /// Somebody switching this on for a shared inbox needs to read the
  /// consequence before the first customer is missed, not after.
  // ── "notify about new emails" (2026-10-06) ───────────────────

  // Jose: "build also a link on email settings, so it can be changed from
  // there as well like syncing with the automation".
  testWidgets('an inbox switches the new-email notification on and off', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>?>[];

    await _pump(tester, _handler(
      alerts: const {'addresses': [], 'editable': true, 'reason': null, 'may_change': true},
      onRequest: (method, path, body) {
        if (method == 'PUT') sent.add(body);
      },
    ));
    await _openSettings(tester);

    final toggle = find.widgetWithText(SwitchListTile, 'Notify about new emails');
    expect(tester.widget<SwitchListTile>(toggle).value, isFalse);

    await _tap(tester, toggle);
    expect(sent.last, {'on': true});
    expect(tester.widget<SwitchListTile>(toggle).value, isTrue, reason: 'The answer did not reach the switch.');

    await _tap(tester, toggle);
    expect(sent.last, {'on': false});
    expect(tester.widget<SwitchListTile>(toggle).value, isFalse);
  });

  // Somebody who may manage the inbox but not automations sees the state and
  // why it cannot be changed here -- not a switch the server would refuse.
  testWidgets('without permission to change automations the switch is read-only, with why', (
    tester,
  ) async {
    await _pump(tester, _handler(
      alerts: const {
        'addresses': ['info@a-1chimney.com'], 'editable': true, 'reason': null, 'may_change': false,
      },
    ));
    await _openSettings(tester);

    final toggle = tester.widget<SwitchListTile>(find.widgetWithText(SwitchListTile, 'Notify about new emails'));
    expect(toggle.value, isTrue);
    expect(toggle.onChanged, isNull);
    expect(find.text('Changing it takes permission to change automations.'), findsOneWidget);
  });

  testWidgets("a reshaped rule is shown read-only, in the server's words", (tester) async {
    await _pump(tester, _handler(
      alerts: const {
        'addresses': [], 'editable': false,
        'reason': 'This was changed in Automations. Edit it there.', 'may_change': true,
      },
    ));
    await _openSettings(tester);

    expect(
      tester.widget<SwitchListTile>(find.widgetWithText(SwitchListTile, 'Notify about new emails')).onChanged,
      isNull,
    );
    expect(find.text('This was changed in Automations. Edit it there.'), findsOneWidget);
  });

  testWidgets('a refused flip says why and leaves the switch where it was', (tester) async {
    await _pump(tester, _handler(
      alerts: const {'addresses': [], 'editable': true, 'reason': null, 'may_change': true},
      alertRefusal: 'Who should be told is taken from your new-lead notification, and there is not exactly one.',
    ));
    await _openSettings(tester);

    final toggle = find.widgetWithText(SwitchListTile, 'Notify about new emails');
    await _tap(tester, toggle);

    expect(find.textContaining('taken from your new-lead notification'), findsOneWidget);
    expect(tester.widget<SwitchListTile>(toggle).value, isFalse);
  });

  // ── who can open an inbox, person by person (2026-10-06) ──────

  // Jose: "I also want to be able to assign emails to specific people,
  // instead of just roles — maybe done via the email settings tab".
  testWidgets('a manager sees who can open an inbox, and adds and removes people', (
    tester,
  ) async {
    final calls = <String>[];

    await _pump(tester, _handler(
      mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
      people: const {
        'people': [
          {'id': 'u-ana', 'name': 'Ana Reis', 'email': 'ana@a1.test'},
        ],
        'roles': [
          {'id': 'r-1', 'name': 'Office'},
        ],
        'candidates': [
          {'id': 'u-bob', 'name': 'Bob Nunes', 'email': 'bob@a1.test'},
        ],
      },
      onRequest: (method, path, body) {
        if (path.contains('/people')) {
          calls.add('$method $path ${body?['user_id'] ?? ''}'.trim());
        }
      },
    ));

    await _openSettings(tester);

    expect(find.text('Who can open this inbox'), findsOneWidget);
    expect(find.textContaining('Everyone with the role Office'), findsOneWidget);
    expect(find.widgetWithText(InputChip, 'Ana Reis'), findsOneWidget);

    // Add Bob through the picker.
    await _tap(tester, find.text('Add a person'));
    await _tap(tester, find.text('Bob Nunes').last);

    expect(calls, contains('POST /api/v1/email/mailboxes/mb-1/people u-bob'));

    // Take Ana off.
    // By its tooltip, which is also what a screen reader announces.
    await _tap(tester, find.byTooltip('Take Ana Reis off this inbox'));

    expect(calls, contains('DELETE /api/v1/email/mailboxes/mb-1/people/u-ana'));
  });

  testWidgets('everybody who cannot be added is still listed, greyed, with why '
      '(Eve 11-09: cannot add anyone)', (tester) async {
    final calls = <String>[];
    await _pump(tester, _handler(
      mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
      people: const {
        'people': [],
        'roles': [],
        'candidates': [],
        'unavailable': [
          {
            'id': 'u-lucas',
            'name': 'Lucas Snyder',
            'email': 'lucas@a1.test',
            'why': "Lucas Snyder's role does not include the Email Inbox tab.",
          },
        ],
      },
      onRequest: (method, path, body) {
        if (method == 'POST' && path.contains('/people')) calls.add(path);
      },
    ));
    await _openSettings(tester);

    await _tap(tester, find.text('Add a person'));
    await tester.enterText(find.byType(TextField).last, 'lucas');
    await tester.pumpAndSettle();
    expect(find.text('Lucas Snyder'), findsOneWidget);
    expect(
      find.text("Lucas Snyder's role does not include the Email Inbox tab."),
      findsOneWidget,
    );
    await _tap(tester, find.text('Lucas Snyder'));
    expect(calls, isEmpty, reason: 'Somebody who cannot open it was added.');
  });

  // The control: somebody who may not manage the inbox is not shown the
  // section at all, and is not made to fetch it — the server would refuse.
  testWidgets('somebody who cannot manage an inbox is not shown who can open it', (
    tester,
  ) async {
    final calls = <String>[];

    await _pump(tester, _handler(
      mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com', mayManage: false)],
      onRequest: (method, path, body) {
        if (path.contains('/people')) calls.add(path);
      },
    ));

    await _openSettings(tester);

    expect(find.text('Who can open this inbox'), findsNothing);
    expect(calls, isEmpty);
  });

  testWidgets('the switch explains the consequence in both positions', (
    tester,
  ) async {
    await _pump(tester, _handler(mailboxes: [
      _row(
        id: 'mb-1',
        address: 'info@a-1chimney.com',
        extra: const {'can_mark_read_at_source': true, 'mark_read_at_source': false},
      ),
    ]));

    await _openSettings(tester);

    expect(
      find.textContaining('anyone looking at the mail service directly still sees the old state'),
      findsOneWidget,
    );

  });

  // And ON, which is the default now -- so the shared-inbox cost is said in
  // the position people will actually see it. The test above was named for
  // both positions and only ever checked one; this is the other. Its own test
  // rather than a second pump: the screen keeps its State across pumpWidget
  // and would never refetch the ON row.
  testWidgets('switched on, it says what a shared inbox costs', (tester) async {
    await _pump(tester, _handler(mailboxes: [
      _row(
        id: 'mb-1',
        address: 'info@a-1chimney.com',
        extra: const {'can_mark_read_at_source': true, 'mark_read_at_source': true},
      ),
    ]));
    await _openSettings(tester);

    expect(
      find.textContaining('one person reading it marks it read for the others too'),
      findsOneWidget,
    );
  });

  testWidgets('turning it on patches that inbox and nothing else', (tester) async {
    final calls = <String, Map<String, dynamic>>{};

    await _pump(tester, _handler(
      mailboxes: [
        _row(
        id: 'mb-1',
        address: 'info@a-1chimney.com',
        extra: const {'can_mark_read_at_source': true, 'mark_read_at_source': false},
      ),
      ],
      onRequest: (method, path, body) {
        if (method == 'PATCH') calls[path] = body ?? {};
      },
    ));

    await _openSettings(tester);
    await tester.tap(find.byType(SwitchListTile));
    await tester.pumpAndSettle();

    expect(calls.keys.single, '/api/v1/email/mailboxes/mb-1');
    expect(calls.values.single['mark_read_at_source'], true);
  });

  testWidgets('a pending Workspace is a numbered list with a Google Admin link', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: {
          'can_connect': false,
          'view': {
            'type': 'company',
            'id': 'co-1',
            'label': 'A1 Chimney',
          },
          'connections': [
            {
              'domain': 'a-1chimney.com',
              'admin_email': 'it-admin@a-1chimney.com',
              'status': 'pending_delegation',
              'status_reason':
                  'EzBizi cannot list or open mailboxes until Google Admin allows this app.',
              'scope_type': 'company',
              'scope_id': 'co-1',
              'scope_label': 'A1 Chimney',
              'is_current_view': true,
              'client_id_for_admin_console': '117502255825060957159',
              'scopes': const [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/admin.directory.user.readonly',
              ],
              'setup_steps': const [
                {
                  'title': 'Open Google Admin',
                  'detail':
                      'Sign in as a Super Admin of this company\'s Workspace.',
                  'url':
                      'https://admin.google.com/ac/owl/domainwidedelegation',
                  'copy': null,
                },
                {
                  'title': 'Add a new API client',
                  'detail': 'Paste this client ID.',
                  'url': null,
                  'copy': '117502255825060957159',
                },
                {
                  'title': 'Allow these three scopes',
                  'detail': 'Paste all three.',
                  'url': null,
                  'copy':
                      'https://www.googleapis.com/auth/gmail.readonly\nhttps://www.googleapis.com/auth/gmail.send',
                },
                {
                  'title': 'Authorize, then come back here',
                  'detail': 'Return to Email → Settings.',
                  'url': null,
                  'copy': null,
                },
              ],
            },
          ],
        },
      ),
    );

    await _openSettings(tester);

    expect(find.text('To-do in Google Admin'), findsOneWidget);
    expect(find.text('Open Google Admin'), findsWidgets);
    expect(find.text('117502255825060957159'), findsOneWidget);
    expect(find.text('A1 Chimney'), findsWidgets);
    expect(
      find.textContaining('Security → Access and data control'),
      findsNothing,
      reason: 'The old paragraph of path names is what this list replaces.',
    );
  });

  testWidgets('two Workspace cards taller than the page still scroll', (
    tester,
  ) async {
    const connection = {
      'domain': 'a-1chimney.com',
      'admin_email': 'it-admin@a-1chimney.com',
      'status': 'pending_delegation',
      'status_reason':
          'EzBizi cannot list or open mailboxes until Google Admin allows this app.',
      'scope_type': 'company',
      'scope_id': 'co-1',
      'scope_label': 'A1 Chimney',
      'is_current_view': true,
      'client_id_for_admin_console': '117502255825060957159',
      'scopes': [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/admin.directory.user.readonly',
      ],
      'setup_steps': [
        {
          'title': 'Open Google Admin',
          'detail': 'Sign in as a Super Admin of this company\'s Workspace.',
          'url': 'https://admin.google.com/ac/owl/domainwidedelegation',
          'copy': null,
        },
        {
          'title': 'Add a new API client',
          'detail': 'Paste this client ID.',
          'url': null,
          'copy': '117502255825060957159',
        },
        {
          'title': 'Allow these three scopes',
          'detail': 'Paste all three.',
          'url': null,
          'copy':
              'https://www.googleapis.com/auth/gmail.readonly\nhttps://www.googleapis.com/auth/gmail.send',
        },
        {
          'title': 'Authorize, then come back here',
          'detail': 'Return to Email → Settings.',
          'url': null,
          'copy': null,
        },
      ],
    };

    await _pumpSettings(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: {
          'can_connect': false,
          'view': {'type': 'organization', 'id': 'org-1', 'label': 'A1-Group'},
          'connections': [
            connection,
            {
              ...connection,
              'domain': 'ezbizi.com',
              'scope_label': 'EzBizi',
              'scope_id': 'co-2',
            },
          ],
        },
      ),
      window: null,
      size: const Size(1280, 640),
    );

    final list = find.byKey(const Key('email-settings-list'));
    expect(list, findsOneWidget);
    final scroller = find.descendant(
      of: list,
      matching: find.byType(Scrollable),
    ).first;
    final position = tester.state<ScrollableState>(scroller).position;
    expect(
      position.maxScrollExtent,
      greaterThan(0),
      reason: 'Workspace cards taller than the page must sit in a scrolling list.',
    );
    await tester.drag(list, const Offset(0, -800));
    await tester.pumpAndSettle();
    expect(position.pixels, greaterThan(0));
  });

  testWidgets('a connected Workspace names a missing Trash scope', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: {
          'can_connect': false,
          'view': {
            'type': 'company',
            'id': 'co-1',
            'label': 'A1 Chimney',
          },
          'connections': [
            {
              'domain': 'a-1chimney.com',
              'admin_email': 'it-admin@a-1chimney.com',
              'status': 'connected',
              'status_reason': null,
              'scope_type': 'company',
              'scope_id': 'co-1',
              'scope_label': 'A1 Chimney',
              'is_current_view': true,
              'client_id_for_admin_console': '117502255825060957159',
              'scopes': const [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/admin.directory.user.readonly',
              ],
              'health': {
                'status': 'needs_attention',
                'summary':
                    'Mail can be read and sent. Trash cannot run until Google Admin allows gmail.modify.',
                'probed': true,
                'scopes': const [
                  {
                    'id': 'gmail.readonly',
                    'label': 'Read mail',
                    'detail': 'List and open messages.',
                    'ok': true,
                  },
                  {
                    'id': 'gmail.send',
                    'label': 'Send mail',
                    'detail': 'Replies go out as the inbox.',
                    'ok': true,
                  },
                  {
                    'id': 'gmail.modify',
                    'label': 'Move to Trash',
                    'detail':
                        'Delete on EzBizi bins the thread in Gmail. Without this, listing still works.',
                    'ok': false,
                  },
                  {
                    'id': 'directory',
                    'label': 'List addresses',
                    'detail': 'The directory of users and aliases.',
                    'ok': true,
                  },
                ],
              },
              'setup_steps': const [
                {
                  'title': 'Open Google Admin',
                  'detail':
                      'Sign in as a Super Admin of this company\'s Workspace.',
                  'url':
                      'https://admin.google.com/ac/owl/domainwidedelegation',
                  'copy': null,
                },
                {
                  'title': 'Allow these four scopes',
                  'detail': 'Paste all four. The extra one is gmail.modify.',
                  'url': null,
                  'copy':
                      'https://www.googleapis.com/auth/gmail.modify',
                },
              ],
            },
          ],
        },
      ),
    );

    await _openSettings(tester);

    expect(find.text('Health'), findsOneWidget);
    expect(find.text('Move to Trash'), findsOneWidget);
    expect(
      find.textContaining('Trash cannot run until Google Admin allows gmail.modify'),
      findsOneWidget,
    );
    expect(find.text('To-do in Google Admin'), findsOneWidget);
    expect(find.text('Open Google Admin'), findsWidgets);
  });

  testWidgets('a Workspace with a Marketplace listing offers Install in Google', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: {
          'can_connect': false,
          'marketplace_listing_url':
              'https://workspace.google.com/marketplace/app/ezbizi/1',
          'view': {
            'type': 'company',
            'id': 'co-1',
            'label': 'A1 Chimney',
          },
          'connections': [
            {
              'domain': 'a-1chimney.com',
              'admin_email': 'it-admin@a-1chimney.com',
              'status': 'connected',
              'status_reason': null,
              'scope_type': 'company',
              'scope_id': 'co-1',
              'scope_label': 'A1 Chimney',
              'is_current_view': true,
              'marketplace_listing_url':
                  'https://workspace.google.com/marketplace/app/ezbizi/1',
              'health': {
                'status': 'needs_attention',
                'summary':
                    'Mail can be read and sent. Trash cannot run until Google Admin allows gmail.modify.',
                'probed': true,
                'scopes': const [
                  {
                    'id': 'gmail.modify',
                    'label': 'Move to Trash',
                    'detail': 'Add this in Google Admin.',
                    'ok': false,
                  },
                ],
              },
              'setup_steps': const [
                {
                  'title': 'Install EzBizi in Google',
                  'detail': 'Admin install, then Allow.',
                  'url':
                      'https://workspace.google.com/marketplace/app/ezbizi/1',
                  'copy': null,
                },
              ],
            },
          ],
        },
      ),
    );

    await _openSettings(tester);

    expect(find.text('Install EzBizi in Google'), findsWidgets);
    expect(find.text('Install in Google'), findsOneWidget);
  });

  // ── what a row says ────────────────────────────────────────────

  testWidgets('a broken inbox says what went wrong and when it last worked', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'broken',
            statusReason:
                'Google says the permission to read this address was taken '
                'away. Somebody removed EzBizi from that account’s connected '
                'apps, and connecting it again is what restores it.',
            canBeOpened: false,
            lastReachedAt: _agoIso(const Duration(days: 3)),
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Not working'), findsOneWidget);
    expect(
      find.textContaining('the permission to read this address was taken away'),
      findsOneWidget,
      reason:
          'The server said why this inbox stopped working and the screen did '
          'not print it. A state word on its own does not tell a withdrawn '
          'consent from an expired password.',
    );
    expect(
      find.textContaining('Last reached 3 days ago'),
      findsOneWidget,
      reason:
          '"It broke just now" and "it has been dead since Tuesday" are the '
          'same row without this.',
    );
  });

  testWidgets('a working inbox says so, and says how long ago it answered', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            lastReachedAt: _agoIso(const Duration(minutes: 20, seconds: 5)),
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Connected'), findsOneWidget);
    expect(find.textContaining('Last reached 20 minutes ago'), findsOneWidget);
  });

  testWidgets('an inbox nobody has connected yet says that, in words', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            unavailableReason:
                'The mail server details and the password for this address '
                'have not been entered yet, so EzBizi cannot open it.',
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.text('Waiting to be connected'), findsOneWidget);
    expect(
      find.textContaining('have not been entered yet'),
      findsOneWidget,
    );
    // The act on offer is the one that finishes the setup, not one that
    // implies something was working before.
    expect(find.widgetWithText(OutlinedButton, 'Connect'), findsOneWidget);
  });

  /// The parallel half of this work adds `last_reached_at` server-side. Until
  /// it lands the key is simply absent, and absent is not "never" — one is a
  /// server that cannot answer, the other is an address that has never once
  /// replied, and only the second is worth alarming somebody about.
  testWidgets('a server that does not send when it was last reached gets no '
      'line, rather than "never"', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            lastReachedKnown: false,
          ),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.textContaining('Last reached'), findsNothing);
    expect(find.textContaining('has not answered EzBizi yet'), findsNothing);

    // The control. Everything else about the row still drew, so the assertions
    // above are about the missing field and not about a card that failed to
    // render at all.
    expect(find.text('info@a-1chimney.com'), findsWidgets);
    expect(find.text('Connected'), findsOneWidget);
  });

  testWidgets('a server that says the address has never answered prints that', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com', lastReachedAt: null),
        ],
      ),
    );

    await _openSettings(tester);

    expect(find.textContaining('has not answered EzBizi yet'), findsOneWidget);
  });

  /// Two inboxes called info@ under two different companies is the case this
  /// app has already met. The address alone names either of them.
  testWidgets('each row says which part of the business owns it', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            scopeType: 'company',
            scopeId: 'co-1',
          ),
          _row(
            id: 'mb-2',
            address: 'info@a-1chimney.com',
            scopeType: 'franchise',
            scopeId: 'fr-1',
          ),
        ],
      ),
    );

    await _openSettings(tester);

    // Design 151: each inbox's card opens beside the table.
    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-1')));
    expect(find.text('Company · Everdoors'), findsOneWidget);
    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-2')));
    expect(
      find.text('Franchise · Everdoors › Connecticut'),
      findsOneWidget,
      reason:
          'A place is named by its whole path, because four companies in this '
          'business each operate a Connecticut.',
    );
  });

  // ── connecting ─────────────────────────────────────────────────

  testWidgets('the password box starts empty, is obscured, and no stored '
      'credential reaches the screen', (tester) async {
    await _pump(
      tester,
      _handler(imapUsable: true, 
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            // Hostile input. The server has no path that sends this and must
            // never gain one; if it ever did, nothing on this screen may draw
            // it.
            extra: const {'credentials': 'hunter2-must-never-appear'},
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(
      _passwordBox,
      findsOneWidget,
      reason: 'A shared mailbox password is being typed in an open-plan office, '
          'so exactly one box in this panel is obscured and it is this one.',
    );

    final field = tester.widget<TextField>(_passwordBox);

    expect(
      field.controller?.text,
      isEmpty,
      reason:
          'The box is for a NEW credential. Anything prefilled into it came '
          'from somewhere, and there is nowhere it could have come from that '
          'this application is allowed to read.',
    );
    expect(find.textContaining('hunter2'), findsNothing);
    expect(
      find.textContaining('never shown again'),
      findsOneWidget,
      reason:
          'Somebody typing a password nobody can ever read back needs telling '
          'that before they wonder where to check it.',
    );
  });

  testWidgets('the password is posted to the connect endpoint and the list '
      'is asked again', (tester) async {
    final requests = <String>[];
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(imapUsable: true, 
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
          ),
        ],
        onRequest: (method, path, body) {
          requests.add('$method $path');

          if (body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await tester.enterText(_passwordBox, 'the-mailbox-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(
      requests,
      contains('POST /api/v1/email/mailboxes/mb-1/connect'),
      reason: 'Sent: $requests',
    );
    expect(bodies.last['credential'], 'the-mailbox-password');
    expect(
      requests.where((r) => r == 'GET /api/v1/email/mailboxes').length,
      greaterThan(1),
      reason:
          'The list still shows the state from before the credential landed.',
    );
  });

  /// Fact D: the browser flow that turns somebody's consent into a token is
  /// not built. A button that opens it would be pressed.
  testWidgets('an address connected by signing in says what is missing '
      'instead of offering a button that cannot finish', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            provider: 'gmail_oauth',
            providerLabel: 'Google (recommended)',
            status: 'unconfigured',
            canBeOpened: false,
            unavailableReason:
                'EzBizi has no Google connection set up on this installation '
                'yet, so no Google mailbox can be opened. Nothing is wrong '
                'with this address — the missing piece is at EzBizi’s end.',
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(
      find.text('Not available here'),
      findsOneWidget,
      reason:
          'The badge used to read "Not built yet". The sign-in IS built now, '
          'and what is missing is this installation being registered with '
          'Google — an account somebody creates, not code somebody writes. The '
          'old badge sends an administrator to ask for a feature that exists.',
    );
    expect(
      find.textContaining('sign-in cannot be completed on this installation'),
      findsOneWidget,
    );
    // Inside the panel specifically. The card behind it carries the same
    // sentence as its state — which is right, and is why this is scoped rather
    // than counted.
    expect(
      find.descendant(
        of: find.byType(SidePanel),
        matching: find.textContaining(
          'no Google connection set up on this installation',
        ),
      ),
      findsOneWidget,
      reason:
          'The server knows something this screen does not — whether this '
          'installation is registered with that service at all.',
    );
    expect(
      // Inside the panel: the inbox search (design 151) sits behind it.
      find.descendant(of: find.byType(SidePanel), matching: find.byType(TextField)),
      findsNothing,
      reason: 'There is nothing anybody can usefully type here.',
    );
    expect(
      find.widgetWithText(FilledButton, 'Connect it'),
      findsNothing,
      reason:
          'A control that cannot complete is worse than none: it gets pressed '
          'first and understood afterwards.',
    );
    expect(
      find.textContaining('Continue with'),
      findsNothing,
      reason:
          'And the sign-in control is the same kind of control. It is offered '
          'when the server says the provider is usable, and this one says the '
          'opposite.',
    );
  });

  testWidgets('the same panel for an IMAP address does offer the fields — the '
      'control for the case above', (tester) async {
    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
          ),
        ],
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(_passwordBox, findsOneWidget);
    expect(find.widgetWithText(TextFormField, 'Incoming server'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Connect it'), findsOneWidget);
    expect(find.text('Not available here'), findsNothing);
  });

  testWidgets('a @gmail.com address fills the Gmail servers, so it is the '
      'address and an app password (Jose, 11-05: like A1 Tools)', (tester) async {
    await _pump(tester, _handler(imapUsable: true));

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));
    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Mail service'),
    );
    await _tap(tester, find.text('IMAP').last);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Address'),
      'acehomecaretx@gmail.com',
    );
    await tester.pump();

    expect(find.widgetWithText(TextFormField, 'imap.gmail.com'), findsOneWidget);
    expect(find.widgetWithText(TextFormField, 'smtp.gmail.com'), findsOneWidget);
  });

  // ── connecting by signing in ───────────────────────────────────

  /// The pair to "says what is missing instead of offering a button". Same
  /// address, same provider, one flag flipped — so what is being asserted is
  /// that the SERVER decides, not that a Google-shaped row is special.
  testWidgets('the sign-in control appears when the server says the provider '
      'is usable', (tester) async {
    await _pumpSettings(
      tester,
      _handler(googleUsable: true, mailboxes: [_google()]),
      window: _FakeConsentWindow(),
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
      findsOneWidget,
      reason:
          'The label is the server\'s own word for the service. Nothing in the '
          'client spells "Google", so a second provider finished by signing in '
          'needs no edit here.',
    );
    expect(find.text('Not available here'), findsNothing);
    expect(
      _passwordBox,
      findsNothing,
      reason: 'This road has nothing to type. A password box beside a sign-in '
          'button is an invitation to type the account password into EzBizi.',
    );
  });

  testWidgets('a Workspace inbox is not finished by signing in as the mailbox',
      (tester) async {
    final requests = <String>[];

    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            provider: 'gmail_workspace',
            providerLabel: 'Google Workspace',
            status: 'unconfigured',
            canBeOpened: false,
            mailServer: const {},
          ),
        ],
        onRequest: (method, path, _) => requests.add('$method $path'),
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(find.textContaining('picking it from the directory'), findsOneWidget);
    expect(
      find.textContaining('Continue with'),
      findsNothing,
      reason:
          'needs_host is false, which used to mean "sign in". Workspace has no '
          'host and is not finished by signing in as the inbox.',
    );
    expect(
      requests.where((r) => r.endsWith('/mailboxes/mb-1/oauth/start')),
      isEmpty,
    );
  });

  testWidgets('pressing it opens the provider\'s own window at the address the '
      'server minted, and claims the window before it asks', (tester) async {
    final window = _FakeConsentWindow();
    final requests = <String>[];

    /// What happened, in order, across two things that are usually counted
    /// separately. See the assertion at the bottom for why the order is the
    /// point.
    final order = <String>[];

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        onRequest: (method, path, _) {
          requests.add('$method $path');

          if (path.endsWith('/oauth/start')) order.add('asked the server');
        },
      ),
      window: window,
      onOpen: () => order.add('claimed the window'),
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    expect(
      window.sentTo,
      isEmpty,
      reason: 'Opening the panel is not consenting to anything.',
    );

    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    expect(
      requests,
      contains('POST /api/v1/email/mailboxes/mb-1/oauth/start'),
      reason: 'Sent: $requests',
    );
    expect(window.sentTo, [
      'https://accounts.google.com/o/oauth2/v2/auth?client_id=ez&state=s1',
    ]);
    expect(
      find.textContaining('read the mail in this mailbox'),
      findsOneWidget,
      reason:
          'The server publishes the scopes it is actually going to ask for, so '
          'that a client never describes a permission that is not the one being '
          'requested. A paragraph written in Dart would go on saying "read and '
          'reply" the day a third scope is added.',
    );
    expect(
      find.textContaining('send replies from this address'),
      findsOneWidget,
    );

    expect(
      order,
      ['claimed the window', 'asked the server'],
      reason:
          'This ordering is the whole flow working or not working, and it is '
          'invisible in a fake browser. A browser allows window.open while it '
          'is handling a click and refuses it afterwards — so a window opened '
          'AFTER the request for the sign-in address is a window the pop-up '
          'blocker eats, every time, for everybody. The empty window has to be '
          'claimed inside the gesture and pointed at the address when it '
          'arrives.',
    );
  });

  testWidgets('which account to choose is the server\'s answer, not this '
      'screen\'s guess', (tester) async {
    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        // Deliberately NOT the address on the row. The endpoint that will judge
        // the match is the one that sends this, so it is the one that decides
        // what somebody is told to pick — and a screen repeating the row
        // instead would be a second opinion able to disagree with the check.
        startBody: const {
          'data': {
            'authorization_url': 'https://accounts.google.com/o/oauth2/v2/auth?x=1',
            'sign_in_as': 'shared.info@a-1chimney.com',
            'scopes': ['https://www.googleapis.com/auth/gmail.readonly'],
          },
        },
      ),
      window: _FakeConsentWindow(),
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    expect(
      find.textContaining('Sign in as shared.info@a-1chimney.com'),
      findsOneWidget,
    );

    await _tap(tester, find.widgetWithText(TextButton, 'Stop waiting'));
  });

  testWidgets('while it is waiting it says so, and cannot be started a second '
      'time', (tester) async {
    final window = _FakeConsentWindow();
    final starts = <String>[];

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        onRequest: (method, path, _) {
          if (path.endsWith('/oauth/start')) starts.add(path);
        },
      ),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    expect(
      find.textContaining('Waiting for Google (recommended). Finish signing in'),
      findsOneWidget,
      reason:
          'An OAuth round trip is a person leaving the app. A panel that looks '
          'idle while it waits is a panel somebody presses again.',
    );
    expect(
      find.widgetWithText(TextButton, 'Stop waiting'),
      findsOneWidget,
      reason:
          'Cancel closes the panel, which would abandon a sign-in still running '
          'in another window with nothing left watching for it. Giving up '
          'waiting is a different act and needs its own control.',
    );

    final button = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Waiting for Google (recommended)…'),
    );

    expect(
      button.onPressed,
      isNull,
      reason: 'Two flows in flight against one mailbox is how the wrong one '
          'wins.',
    );

    // And pressing it anyway changes nothing, which is the fact rather than the
    // disabled flag being the fact.
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Waiting for Google (recommended)…'),
    );

    expect(starts, hasLength(1));

    // Drain the wait, so the panel is not disposed with a poll outstanding.
    await _tap(tester, find.widgetWithText(TextButton, 'Stop waiting'));
  });

  testWidgets('a status ask that never lands is asked again, not shown as an '
      'error', (tester) async {
    // Android 0.0.0503: the poll ran while the browser had the screen, its
    // socket was cut, and the sheet said "Could not reach the server" on a
    // sign-in that had worked.
    final rows = [_google()];
    var asks = 0;

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: rows,
        statusBodies: const [
          {
            'data': {'state': 'connected', 'message': null},
          },
        ],
        onRequest: (method, path, _) {
          if (!path.endsWith('/oauth/status')) return;
          if (++asks == 1) throw Exception('socket closed');
          rows[0] = _google(status: 'connected', canBeOpened: true);
        },
      ),
      window: _FakeConsentWindow(),
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );
    await _waitOneRound(tester);
    expect(find.textContaining('Could not reach the server'), findsNothing);

    await _waitOneRound(tester);
    await tester.pumpAndSettle();
    expect(find.byType(SidePanel), findsNothing);
    expect(find.text('Connected'), findsOneWidget);
  });

  testWidgets('coming back signed in lands on the Settings tab with the inbox '
      'connected', (tester) async {
    final window = _FakeConsentWindow();
    final rows = [_google()];
    final requests = <String>[];
    final asked = <Uri>[];

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: rows,
        // Nothing, then the answer. Two rounds rather than one is what gives
        // "it does not close on hope" a failing case to have.
        statusBodies: const [
          {
            'data': {'state': 'waiting', 'message': null},
          },
          {
            'data': {'state': 'connected', 'message': null},
          },
        ],
        onRequest: (method, path, _) {
          requests.add('$method $path');

          // The callback has run by the time the server says "connected", and
          // `Mailbox::connect()` has written `status = connected` on the row.
          // This is the fixture standing in for that, so the list the tab
          // refetches afterwards is a connected one.
          if (path.endsWith('/oauth/status')) {
            rows[0] = _google(status: 'connected', canBeOpened: true);
          }
        },
        onUrl: (url) {
          if (url.path.endsWith('/oauth/status')) asked.add(url);
        },
      ),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    // Nothing yet. The panel holds its place rather than closing on hope.
    await _waitOneRound(tester);

    // Settled, and not merely pumped three times. A Navigator.pop leaves the
    // panel in the tree for the length of its dismissal, so a bare `findsOne`
    // straight after a poll finds a panel that IS closing and calls it open —
    // which is how this assertion passed with the guard on it deleted.
    await tester.pumpAndSettle();

    expect(
      find.byType(SidePanel),
      findsOneWidget,
      reason:
          'One look that found nothing is not an answer. Closing on it would '
          'send somebody back to a list that still says "waiting to be '
          'connected" and leave them wondering which of the two screens to '
          'believe.',
    );

    // And now the token has landed.
    await _waitOneRound(tester);
    await tester.pumpAndSettle();

    expect(
      find.byType(SidePanel),
      findsNothing,
      reason: 'The question has been answered, so the panel that asked it goes.',
    );
    expect(
      find.text('Connected'),
      findsOneWidget,
      reason:
          'This is the whole return journey. Somebody who has just come back '
          'from Google is looking at the Settings tab, at the inbox they were '
          'connecting, and it says it is connected.',
    );
    expect(
      requests.where((r) => r == 'GET /api/v1/email/mailboxes').length,
      greaterThan(1),
      reason: 'The list still shows the state from before the sign-in landed.',
    );
    expect(
      asked.map((u) => u.queryParameters['flow']).toSet(),
      {'flow-1'},
      reason:
          'Every ask names WHICH attempt it is about. Without the flow the '
          'server can only answer "does this inbox have a credential", which is '
          'also true of the attempt somebody abandoned ten minutes ago — so a '
          'second press would be told about the first one\'s success and close '
          'on it.',
    );
    expect(
      window.closes,
      1,
      reason:
          'The consent window is left sitting on a finished callback page, and '
          'tidying it up is the last thing this flow owes.',
    );
  });

  /// ── The refusal this whole flow is shaped around ───────────────
  ///
  /// The server checks that the account somebody signed in with IS the address
  /// being connected, and refuses when it is not — because connecting them would
  /// hand everybody with a grant on this shared inbox the mail of somebody's
  /// personal account. MailOAuthRefused::wrongAccount names both addresses and
  /// says what to do, the callback records it against the flow, and the panel
  /// that is polling prints it.
  ///
  /// It is written on the callback PAGE as well, which is deliberate and not a
  /// duplicate: the consent screen opens beside the app, so the person may be
  /// looking at either window when it happens. Both say the same sentence
  /// because both come from the same exception.
  testWidgets('signing in with the wrong account shows the server\'s sentence, '
      'naming the account', (tester) async {
    final window = _FakeConsentWindow();

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        statusBodies: const [
          {
            'data': {
              'state': 'refused',
              'message':
                  'That sign-in was for jose.personal@gmail.com, but this '
                  'inbox is info@a-1chimney.com, so nothing has been connected '
                  'and the permission has already been handed back to Google. '
                  'If you have more than one Google account, sign out of the '
                  'others or use a private window, then connect the address '
                  'again and choose info@a-1chimney.com.',
            },
          },
        ],
      ),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );
    await _waitOneRound(tester);

    expect(
      find.descendant(
        of: find.byType(SidePanel),
        matching: find.textContaining('jose.personal@gmail.com'),
      ),
      findsOneWidget,
      reason:
          'Somebody who picked the wrong account from Google\'s chooser needs '
          'to be told WHICH account they picked. "Something went wrong" tells '
          'them to do exactly the same thing again.',
    );
    expect(
      find.byType(SidePanel),
      findsOneWidget,
      reason: 'A refusal is not a crash. The panel stays where it is.',
    );
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(
              FilledButton,
              'Continue with Google (recommended)',
            ),
          )
          .onPressed,
      isNotNull,
      reason: 'Trying again with the right account is the remedy, so the '
          'control that does it has to be live.',
    );
    expect(
      window.closes,
      1,
      reason:
          'And the window it was refused in is closed, so the next attempt does '
          'not reuse a page showing the last one\'s answer.',
    );
  });

  testWidgets('closing the provider\'s window stops the wait, and says nothing '
      'has changed', (tester) async {
    final window = _FakeConsentWindow();

    await _pumpSettings(
      tester,
      _handler(googleUsable: true, mailboxes: [_google()]),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    window.closedByHand();
    await _waitOneRound(tester);

    expect(
      find.textContaining('was closed before the sign-in finished'),
      findsOneWidget,
      reason:
          'Somebody who gave up should be told the inbox is unchanged, not left '
          'looking at a panel still waiting on a window that has gone.',
    );
    expect(
      find.textContaining('still waiting to be connected'),
      findsOneWidget,
      reason: 'And say plainly that nothing changed.',
    );
    expect(
      find.textContaining('Waiting for Google (recommended). Finish signing in'),
      findsNothing,
      reason:
          'The window has gone, so nothing is going to arrive. A panel still '
          'saying it is waiting is a panel waiting on a window that does not '
          'exist.',
    );
  });

  testWidgets('a wait nothing ever answers stops on its own, rather than '
      'asking forever', (tester) async {
    final window = _FakeConsentWindow();
    var asked = 0;

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        onRequest: (method, path, _) {
          if (path.endsWith('/oauth/status')) asked += 1;
        },
      ),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    // The real numbers rather than an injected pair: five minutes of asking
    // every two seconds. The window is left OPEN throughout and the server keeps
    // answering "waiting", so the only thing that can end this is the cap.
    for (var round = 0; round < 160; round++) {
      await _waitOneRound(tester);
    }

    expect(
      find.textContaining('nothing came back for 5 minutes'),
      findsOneWidget,
      reason:
          'Somebody leaves this open at lunchtime. Without a cap that is a '
          'request every two seconds until the tab is closed, and a panel still '
          'claiming to be waiting hours later.',
    );
    expect(
      asked,
      lessThanOrEqualTo(150),
      reason:
          'And it really stops asking. Sent $asked status requests, where the '
          'cap is five minutes of asking every two seconds.',
    );
    expect(
      find.textContaining('press the button again'),
      findsOneWidget,
      reason: 'A dead end is not an answer (R8). Somebody who finished signing '
          'in late needs to be told the way to pick that answer up.',
    );
  });

  testWidgets('the authorize call\'s own refusal is printed as the server '
      'wrote it', (tester) async {
    final window = _FakeConsentWindow();

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        // What GoogleMailAuthController::start answers when this installation
        // has no Google registration — the same sentence the catalogue
        // publishes as `unusable_because`, from the same reader.
        startBody: const {
          'message':
              'EzBizi has no Google connection set up on this installation '
              'yet, so no Google mailbox can be opened and signing in would '
              'not complete.',
        },
        startStatus: 501,
      ),
      window: window,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    expect(
      find.textContaining('no Google connection set up on this installation'),
      findsOneWidget,
    );
    expect(
      window.closes,
      1,
      reason:
          'The empty window was claimed inside the click and there is now '
          'nowhere to send it. Leaving a blank window open on a refusal is a '
          'second thing for somebody to tidy up.',
    );
    expect(window.sentTo, isEmpty);
  });

  testWidgets('a window that will not open is said out loud, and no sign-in is '
      'started behind it', (tester) async {
    final requests = <String>[];

    await _pumpSettings(
      tester,
      _handler(
        googleUsable: true,
        mailboxes: [_google()],
        onRequest: (method, path, _) => requests.add('$method $path'),
      ),
      // What consent_window_io.dart answers: the desktop and mobile builds have
      // no window to open, and a browser blocking it looks the same from here.
      window: null,
    );

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));
    await _tap(
      tester,
      find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
    );

    expect(find.textContaining('could not open the Google (recommended) sign-in window'), findsOneWidget);
    expect(
      requests.where((r) => r.endsWith('/oauth/start')),
      isEmpty,
      reason:
          'A sign-in nobody can reach should not be started at the server '
          'either — it would sit there pending, and the next attempt would be '
          'the second flow this panel refuses to have.',
    );
  });

  // ── connecting with a password ─────────────────────────────────

  testWidgets('the connect panel takes the server details and posts them with '
      'the password', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            // Nothing stored yet — the address was added and never finished.
            mailServer: const {},
          ),
        ],
        onRequest: (method, path, body) {
          if (path.endsWith('/connect') && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await tester.enterText(_passwordBox, 'the-mailbox-password');
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Incoming server'),
      'imap.a-1chimney.com',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Outgoing server'),
      'smtp.a-1chimney.com',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(bodies, hasLength(1));
    expect(bodies.single['credential'], 'the-mailbox-password');
    expect(bodies.single['mail_server'], {
      'imap_host': 'imap.a-1chimney.com',
      // The defaults, and they are the ones a mail provider actually publishes:
      // 993 is IMAP over TLS, 587 is SMTP submission over STARTTLS. Both come
      // from the list the SERVER published in `meta`, so a guard that changes
      // its mind changes this form with it.
      'imap_port': 993,
      'imap_encryption': 'tls',
      'smtp_host': 'smtp.a-1chimney.com',
      'smtp_port': 587,
      'smtp_encryption': 'starttls',
    });
  });

  testWidgets('server details already stored are filled in, and are what goes '
      'back', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'broken',
            statusReason: 'The password for this address was refused.',
            canBeOpened: false,
            mailServer: const {
              'imap_host': 'mail.a-1chimney.com',
              'imap_port': 143,
              'imap_encryption': 'starttls',
              'imap_username': 'info',
            },
          ),
        ],
        onRequest: (method, path, body) {
          if (path.endsWith('/connect') && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Reconnect'));

    expect(find.text('mail.a-1chimney.com'), findsOneWidget);
    expect(find.text('143'), findsOneWidget);
    expect(
      find.text('info'),
      findsOneWidget,
      reason:
          'The username travels with the rest of the block. If it were not '
          'drawn it would not be posted either, and reconnecting would quietly '
          'wipe it.',
    );

    await tester.enterText(_passwordBox, 'the-new-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(bodies.single['mail_server'], containsPair('imap_port', 143));
    expect(
      bodies.single['mail_server'],
      containsPair('imap_encryption', 'starttls'),
    );
    expect(bodies.single['mail_server'], containsPair('imap_username', 'info'));
  });

  testWidgets('the port can be changed to the other one the server allows', (
    tester,
  ) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            mailServer: const {'imap_host': 'imap.a-1chimney.com'},
          ),
        ],
        onRequest: (method, path, body) {
          if (path.endsWith('/connect') && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await _tap(tester, find.text('993'));
    await _tap(tester, find.text('143').last);

    await tester.enterText(_passwordBox, 'the-mailbox-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(bodies.single['mail_server'], containsPair('imap_port', 143));
  });

  testWidgets('a host the guard refuses shows the server\'s own sentence, on '
      'the screen that can correct it', (tester) async {
    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            mailServer: const {'imap_host': 'mail.internal'},
          ),
        ],
        // What MailboxesController::connect answers when RemoteHostGuard says
        // no: a 422, because it is a fact about the value they typed.
        connect: () async => _json(const {
          'message':
              'EzBizi will not connect to "mail.internal". It points at a '
              'private network rather than at a mail server on the internet, '
              'and a mail setting that could reach inside the network EzBizi '
              'runs in would be a way of reading things that are nothing to do '
              'with your email. Use the mail server name your provider gave '
              'you — something like imap.example.com.',
        }, 422),
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await tester.enterText(_passwordBox, 'the-mailbox-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(
      find.textContaining('It points at a private network'),
      findsOneWidget,
      reason:
          'The guard\'s sentence, verbatim. It names the category and not the '
          'address, which is deliberate at the server end, and rewording it '
          'here would throw away the part that says what to type instead.',
    );
    expect(
      find.widgetWithText(TextFormField, 'Incoming server'),
      findsOneWidget,
      reason:
          'This is the point of putting the host on this panel. Without it, the '
          'screen that explains what is wrong with the mail server name is the '
          'one screen with no way to change it.',
    );
    expect(find.byType(SidePanel), findsOneWidget);
  });

  testWidgets('while the password is in flight the panel says so, and takes '
      'nothing else', (tester) async {
    final held = Completer<http.Response>();

    await _pump(
      tester,
      _handler(
        imapUsable: true,
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            mailServer: const {'imap_host': 'imap.a-1chimney.com'},
          ),
        ],
        connect: () => held.future,
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

    await tester.enterText(_passwordBox, 'the-mailbox-password');
    await _tap(tester, find.widgetWithText(FilledButton, 'Connect it'));

    expect(
      find.textContaining('Signing in to imap.a-1chimney.com'),
      findsOneWidget,
      reason:
          'An IMAP login against a slow server takes seconds. A button that '
          'appears to do nothing gets pressed twice, and the second press is a '
          'second password on the wire.',
    );
    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Connecting…'))
          .onPressed,
      isNull,
    );
    expect(
      tester.widget<TextField>(_passwordBox).enabled,
      isFalse,
      reason: 'The value being sent is the value that was in the box. Editing '
          'it underneath the request makes the screen disagree with the wire.',
    );

    held.complete(_json({'data': _row(id: 'mb-1', address: 'info@a-1chimney.com')}));
    await tester.pumpAndSettle();
  });

  // ── disconnecting ──────────────────────────────────────────────

  testWidgets('disconnecting says what it takes away, and takes nothing away '
      'until it has been confirmed', (tester) async {
    final requests = <String>[];

    await _pump(
      tester,
      _handler(
        mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
        onRequest: (method, path, _) => requests.add('$method $path'),
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(TextButton, 'Disconnect'));

    expect(
      find.textContaining('will lose it'),
      findsOneWidget,
      reason:
          'Disconnecting removes the inbox for every role that was granted it, '
          'not for the person pressing the button.',
    );
    expect(find.textContaining('would have to be given it again'), findsOneWidget);
    expect(
      find.textContaining('Nothing happens to the mail itself'),
      findsOneWidget,
      reason:
          '"Disconnect" reads to a nervous administrator like it might delete '
          'their email.',
    );
    expect(
      requests.any((r) => r.startsWith('DELETE')),
      isFalse,
      reason: 'The inbox was disconnected by the act of asking about it.',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Disconnect it'));

    expect(requests, contains('DELETE /api/v1/email/mailboxes/mb-1'));
    expect(
      find.textContaining('no longer connected to EzBizi'),
      findsOneWidget,
      reason:
          'The server’s own sentence about what has just happened, kept on '
          'screen rather than flashed past in a bar.',
    );
  });

  // ── adding ─────────────────────────────────────────────────────

  testWidgets('adding an address posts it against the part of the business '
      'this person is working in', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        onRequest: (method, path, body) {
          if (method == 'POST' && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);

    expect(
      find.textContaining('No shared inbox is connected here yet'),
      findsOneWidget,
      reason: 'An empty list has to say what to do next.',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Address'),
      'bookings@a-1chimney.com',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'What to call it'),
      'Bookings',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Add the address'));

    expect(bodies, hasLength(1));
    expect(bodies.single['address'], 'bookings@a-1chimney.com');
    expect(bodies.single['display_name'], 'Bookings');
    expect(bodies.single['scope_type'], 'organization');
    expect(bodies.single['scope_id'], 'org-1');
  });

  /// §4: FILE ON THE VIEW, NOT ON REACH.
  ///
  /// ═══════════════════════════════════════════════════════════════
  ///  The test above proves the ORGANISATION view files on the
  ///  organisation, which is the easy half — there is only one answer
  ///  available, so a broken implementation passes it.
  ///
  ///  This is the half that can be wrong. Somebody who reaches TWO
  ///  companies stands in one of them and adds an inbox. The scope on
  ///  the wire must be the company they are LOOKING AT. A panel that
  ///  reached for `me.companies.first` — the shape AGENTS.md names,
  ///  and the shape that has bitten Inventory, Fleet and Phone — would
  ///  file A-1 Chimney's inbox against Everdoors, and every screen
  ///  afterwards would agree it belongs there.
  ///
  ///  The load-bearing part is that the sibling is REALLY in `/me`.
  ///  A fixture with one company cannot fail this test however the
  ///  filing is written, which is why the existing fixture could not
  ///  catch it.
  /// ═══════════════════════════════════════════════════════════════
  testWidgets('standing in one company, a new inbox is filed against THAT '
      'company and not a sibling', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        onRequest: (method, path, body) {
          if (method == 'POST' && body != null) bodies.add(body);
        },
      ),
      me: {
        // Two real companies, and the view is standing in the SECOND —
        // deliberately not the first, so that `.first` and "the view" are
        // different answers and the test can tell them apart.
        'companies': [
          {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
          {'id': 'co-2', 'name': 'A-1 Chimney', 'parent_company_id': null},
        ],
        'scope': {
          'type': 'company',
          'id': 'co-2',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney'],
        },
      },
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Address'),
      'bookings@a-1chimney.com',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'What to call it'),
      'Bookings',
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Add the address'));

    expect(bodies, hasLength(1));

    expect(
      bodies.single['scope_type'],
      'company',
      reason: 'A company view filed at a level it is not standing at.',
    );
    expect(
      bodies.single['scope_id'],
      'co-2',
      reason: 'The inbox was filed against a company the reader was not '
          'looking at. co-1 means it took the first company it could reach; '
          'org-1 means it ignored the view entirely.',
    );

    // Said as an absence too, because "co-2 appears somewhere in the body"
    // would also be true of a payload that carried both.
    expect(bodies.single.values, isNot(contains('co-1')));
  });

  testWidgets('Workspace addresses are searched, not dumped in a dropdown, '
      'and Google test-domain aliases never appear', (tester) async {
    final bodies = <Map<String, dynamic>>[];

    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        workspaceUsable: true,
        directory: const [
          {'address': 'info@a-1chimney.com', 'name': 'Info'},
          {
            'address': 'info@a-1chimney.com.test-google-a.com',
            'name': 'Info',
          },
          {'address': 'ana@ezbizi.com', 'name': 'Analorena Ventoso'},
          {
            'address': 'ana@ezbizi.com.test-google-a.com',
            'name': 'Analorena Ventoso',
          },
        ],
        onRequest: (method, path, body) {
          if (method == 'POST' && body != null) bodies.add(body);
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));

    await _tap(
      tester,
      find.widgetWithText(SearchPickerField<String>, 'Mail service'),
    );
    await _tap(tester, find.text('Google Workspace').last);

    expect(find.text('Address from the directory'), findsNothing);
    expect(find.text('Search the directory'), findsOneWidget);
    expect(
      find.text('ana@ezbizi.com'),
      findsNothing,
      reason: 'A directory of hundreds cannot be a dropdown. Nothing is listed '
          'until somebody searches.',
    );
    expect(find.textContaining('test-google-a.com'), findsNothing);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Search the directory'),
      'ana@',
    );
    await tester.pumpAndSettle();

    expect(find.text('ana@ezbizi.com · Analorena Ventoso'), findsOneWidget);
    expect(find.textContaining('test-google-a.com'), findsNothing);

    await _tap(tester, find.text('ana@ezbizi.com · Analorena Ventoso'));
    await _tap(tester, find.widgetWithText(FilledButton, 'Add the address'));

    expect(bodies, hasLength(1));
    expect(bodies.single['address'], 'ana@ezbizi.com');
    expect(bodies.single['provider'], 'gmail_workspace');
  });

  // Jose, 2026-10-06: "add ability to add emails by bulk as well, when
  // selecting workspace". Several ticked, one request, the panel closes.
  testWidgets('several Workspace addresses can be ticked and added in one go', (
    tester,
  ) async {
    final calls = <String, Map<String, dynamic>?>{};

    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        workspaceUsable: true,
        directory: const [
          {'address': 'office@nj.a-1chimney.com', 'name': 'NJ Office'},
          {'address': 'office@atl.a-1chimney.com', 'name': 'Atlanta Office'},
          {'address': 'info@a-1chimney.com', 'name': 'Info'},
        ],
        onRequest: (method, path, body) {
          if (method == 'POST') calls[path] = body;
        },
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));
    await _tap(tester, find.widgetWithText(SearchPickerField<String>, 'Mail service'));
    await _tap(tester, find.text('Google Workspace').last);

    await tester.enterText(find.widgetWithText(TextFormField, 'Search the directory'), 'office@');
    await tester.pumpAndSettle();

    // Every branch office in one tap, then one more from a second search --
    // typing again must not lose what was ticked.
    await _tap(tester, find.text('Select all 2 shown'));
    await tester.enterText(find.widgetWithText(TextFormField, 'Search the directory'), 'info@');
    await tester.pumpAndSettle();
    await _tap(tester, find.text('info@a-1chimney.com · Info'));

    expect(find.text('3 addresses selected. Search again to add more.'), findsOneWidget);
    // Several are named from the directory, so the single name field goes.
    expect(find.widgetWithText(TextFormField, 'What to call it'), findsNothing);

    await _tap(tester, find.widgetWithText(FilledButton, 'Add 3 addresses'));

    final sent = calls['/api/v1/email/mailboxes/workspace-batch'];
    expect(sent, isNotNull, reason: 'The batch endpoint was not called.');
    expect(calls.containsKey('/api/v1/email/mailboxes'), isFalse, reason: 'It added them one by one.');
    expect(
      [for (final row in sent!['addresses'] as List) (row as Map)['address']],
      unorderedEquals(['office@nj.a-1chimney.com', 'office@atl.a-1chimney.com', 'info@a-1chimney.com']),
    );
    expect(
      [for (final row in sent['addresses'] as List) (row as Map)['display_name']],
      containsAll(['NJ Office', 'Atlanta Office', 'Info']),
    );

    // All added: the panel has closed.
    expect(find.text('Search the directory'), findsNothing);
  });

  // Partial success stays on screen, in the server's words, with the ones that
  // did not go in still ticked -- not a closed panel and a silent loss.
  testWidgets('a batch that adds some says which were not added and why', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: const [],
        workspaceUsable: true,
        directory: const [
          {'address': 'office@nj.a-1chimney.com', 'name': 'NJ Office'},
          {'address': 'office@atl.a-1chimney.com', 'name': 'Atlanta Office'},
        ],
        batchSkips: const {'office@atl.a-1chimney.com': 'It has already been added.'},
      ),
    );

    await _openSettings(tester);
    await _tap(tester, find.widgetWithText(FilledButton, 'Add an inbox'));
    await _tap(tester, find.widgetWithText(SearchPickerField<String>, 'Mail service'));
    await _tap(tester, find.text('Google Workspace').last);
    await tester.enterText(find.widgetWithText(TextFormField, 'Search the directory'), 'office@');
    await tester.pumpAndSettle();
    await _tap(tester, find.text('Select all 2 shown'));
    await _tap(tester, find.widgetWithText(FilledButton, 'Add 2 addresses'));

    expect(find.textContaining('Added 1.'), findsOneWidget);
    expect(find.textContaining('office@atl.a-1chimney.com — It has already been added.'), findsOneWidget);
    expect(find.widgetWithText(InputChip, 'office@atl.a-1chimney.com'), findsOneWidget, reason: 'The skipped one should stay ticked.');
    expect(find.widgetWithText(InputChip, 'office@nj.a-1chimney.com'), findsNothing, reason: 'The added one should be unticked.');
    expect(find.widgetWithText(TextButton, 'Done'), findsOneWidget);
  });

  // ── gating ─────────────────────────────────────────────────────

  testWidgets('a role that may read the mail but not manage it gets no live '
      'manage controls, and a sentence saying why', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com', mayManage: false),
        ],
      ),
      // The Settings TAB and no action keys: somebody who may look at the
      // connections and may not change them. `email.view` alone stopped being
      // enough on 2026-09-17, when the tabs became separately granted -- with
      // it and nothing else this screen correctly draws its "no part of Email
      // has been given to your role" sentence instead, and every assertion
      // below looks for controls that were never built.
      permissions: const ['email.view', 'email.settings.view'],
    );

    await _openSettings(tester);

    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Add an inbox'))
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Reconnect'),
          )
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<TextButton>(find.widgetWithText(TextButton, 'Disconnect'))
          .onPressed,
      isNull,
    );

    // Still on screen, and saying which of the two things is missing — a
    // permission that lives on a role, so the person to ask is whoever edits
    // roles. The same words the server's own 403 uses.
    expect(
      find.byTooltip(
        'You do not have permission to add a shared inbox. That is part of a '
        'role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
    expect(
      find.byTooltip(
        'You do not have permission to disconnect this inbox. That is part of '
        'a role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a role that may manage gets the same controls, live — the '
      'control for the refusal above', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [_row(id: 'mb-1', address: 'info@a-1chimney.com')],
      ),
    );

    await _openSettings(tester);

    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Add an inbox'))
          .onPressed,
      isNotNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Reconnect'),
          )
          .onPressed,
      isNotNull,
    );
    expect(
      tester
          .widget<TextButton>(find.widgetWithText(TextButton, 'Disconnect'))
          .onPressed,
      isNotNull,
    );
  });

  // ── R7 ─────────────────────────────────────────────────────────

  /// A status list is exactly the shape that breaks on a phone: six facts per
  /// row, and the one somebody came for — the state — is the one that ends up
  /// off the right edge. 375 is a phone, 700 a tablet upright or a window
  /// dragged narrow, 1400 a laptop.
  for (final width in [375.0, 700.0, 1400.0]) {
    testWidgets('at ${width.toInt()}px the state, the owner and the sentence '
        'are all on screen and nothing overflows', (tester) async {
      await _pump(
        tester,
        _handler(
          mailboxes: [
            _row(
              id: 'mb-1',
              address: 'info@a-1chimney.com',
              scopeType: 'franchise',
              scopeId: 'fr-1',
              status: 'broken',
              statusReason:
                  'The password for this address was refused. It has probably '
                  'been changed where the mailbox lives; entering the new one '
                  'here reconnects it.',
              canBeOpened: false,
              lastReachedAt: _agoIso(const Duration(hours: 5)),
            ),
          ],
        ),
        size: Size(width, 900),
      );

      await _openSettings(tester);
      await _openInbox(tester, width);

      expect(tester.takeException(), isNull);
      expect(find.text('info@a-1chimney.com'), findsWidgets);
      expect(find.text('Not working'), findsOneWidget);
      expect(
        find.text('Franchise · Everdoors › Connecticut'),
        findsOneWidget,
      );
      expect(find.textContaining('has probably been changed'), findsOneWidget);
      expect(find.textContaining('Last reached 5 hours ago'), findsOneWidget);
    });

    testWidgets('at ${width.toInt()}px the password panel fits, and the server '
        'details fit under it', (tester) async {
      await _pump(
        tester,
        _handler(
          imapUsable: true,
          mailboxes: [
            _row(
              id: 'mb-1',
              address: 'info@a-1chimney.com',
              status: 'unconfigured',
              canBeOpened: false,
            ),
          ],
        ),
        size: Size(width, 900),
      );

      await _openSettings(tester);
      await _openInbox(tester, width);
      await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

      expect(tester.takeException(), isNull);
      expect(_passwordBox, findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Connect it'), findsOneWidget);

      // At 375 the panel IS the window, which leaves about 327 for a port and
      // an encryption side by side — and "STARTTLS" in a Material label does
      // not fit half of that. A Row answered that with an overflow stripe
      // across the encryption box.
      await tester.dragUntilVisible(
        find.widgetWithText(TextFormField, 'Outgoing server'),
        find.byType(SingleChildScrollView).first,
        const Offset(0, -80),
      );
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('STARTTLS'), findsWidgets);
    });

    testWidgets('at ${width.toInt()}px the sign-in panel fits and offers the '
        'one control it has', (tester) async {
      final window = _FakeConsentWindow();

      await _pumpSettings(
        tester,
        _handler(
          googleUsable: true,
          mailboxes: [
            _row(
              id: 'mb-1',
              address: 'info@a-1chimney.com',
              provider: 'gmail_oauth',
              providerLabel: 'Google (recommended)',
              status: 'unconfigured',
              canBeOpened: false,
            ),
          ],
        ),
        window: window,
        size: Size(width, 900),
      );

      await _openInbox(tester, width);
      await _tap(tester, find.widgetWithText(OutlinedButton, 'Connect'));

      expect(tester.takeException(), isNull);
      expect(
        find.widgetWithText(FilledButton, 'Continue with Google (recommended)'),
        findsOneWidget,
      );
      expect(
        find.textContaining('never reaches EzBizi'),
        findsOneWidget,
        reason:
            'The one thing somebody has to understand before pressing it: the '
            'password is typed on their page, not on ours.',
      );
    });
  }

  // ── the words, without a widget tree ───────────────────────────

  group('lastReachedSentence', () {
    final formats = DisplayFormats.fallback;
    final now = DateTime.utc(2026, 8, 13, 14, 0);

    MailboxConnection at(Object? iso, {bool known = true}) =>
        MailboxConnection.fromJson({
          'id': 'mb-1',
          'address': 'info@example.com',
          if (known) 'last_reached_at': iso,
        });

    test('an absent field draws no line at all', () {
      expect(lastReachedSentence(at(null, known: false), formats: formats), isNull);
    });

    test('a null field says the address has never answered', () {
      expect(
        lastReachedSentence(at(null), formats: formats),
        'This address has not answered EzBizi yet.',
      );
    });

    test('it carries the relative phrase AND the exact stamp', () {
      final sentence = lastReachedSentence(
        at('2026-08-13T13:40:00Z'),
        formats: formats,
        now: now,
      );

      expect(sentence, startsWith('Last reached 20 minutes ago · '));
      expect(
        sentence,
        contains('2026-08-13'),
        reason:
            'A clock that is out makes the relative half quietly false, and '
            'the stamp beside it is what lets a reader catch that.',
      );
    });

    test('one of anything is singular', () {
      expect(
        lastReachedSentence(
          at('2026-08-13T13:59:10Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached just now'),
      );
      expect(
        lastReachedSentence(
          at('2026-08-13T13:59:00Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached 1 minute ago'),
      );
      expect(
        lastReachedSentence(
          at('2026-08-12T14:00:00Z'),
          formats: formats,
          now: now,
        ),
        startsWith('Last reached 1 day ago'),
      );
    });
  });

  group('stateOf', () {
    MailboxConnection row(Map<String, Object?> json) =>
        MailboxConnection.fromJson({
          'id': 'mb-1',
          'address': 'info@example.com',
          'can_be_opened': true,
          ...json,
        });

    test('broken wins over an unusable reader, because it is the fact to act '
        'on first', () {
      final state = stateOf(
        row({
          'status': 'broken',
          'status_reason': 'Consent was withdrawn.',
          'can_be_opened': false,
          'unavailable_reason': 'Nobody has signed in yet.',
        }),
      );

      expect(state.word, 'Not working');
      expect(state.sentence, 'Consent was withdrawn.');
      expect(state.trouble, isTrue);
    });

    test('a mailbox marked broken with no reason still says something useful', () {
      final state = stateOf(row({'status': 'broken'}));

      expect(state.sentence, contains('was not told why'));
    });

    test('connected and openable needs no sentence', () {
      final state = stateOf(row({'status': 'connected'}));

      expect(state.word, 'Connected');
      expect(state.sentence, isNull);
      expect(state.trouble, isFalse);
    });

    test('connected but unopenable is trouble, in the server’s words', () {
      final state = stateOf(
        row({
          'status': 'connected',
          'can_be_opened': false,
          'unavailable_reason': 'EzBizi has no Google connection set up here.',
        }),
      );

      expect(state.word, 'Connected, but cannot be opened');
      expect(state.sentence, 'EzBizi has no Google connection set up here.');
      expect(state.trouble, isTrue);
    });

    test('a row with no status at all is treated as unfinished, never as '
        'working', () {
      expect(stateOf(row(const {})).word, 'Waiting to be connected');
    });
  });
  // ── design 151 options 1 and 4: summary, compact rows, inbox table ──

  testWidgets('the summary counts Workspaces, inboxes and what needs attention', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: _twoWorkspaces,
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com'),
          _row(id: 'mb-2', address: 'jobs@a-1chimney.com', status: 'broken'),
          _row(id: 'mb-3', address: 'help@a-1chimney.com'),
        ],
      ),
    );
    await _openSettings(tester);

    expect(find.text('2 Workspaces · 3 inboxes · '), findsOne);
    // One Workspace missing a permission, one inbox stopped.
    expect(find.text('2 need attention ›'), findsOne);
  });

  testWidgets('a Workspace row starts open only when a permission is missing', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(workspaceUsable: true, workspace: _twoWorkspaces),
    );
    await _openSettings(tester);

    expect(find.text('All 4 permissions'), findsOne);
    expect(find.text('1 missing · Update permissions'), findsOne);
    expect(find.byKey(const ValueKey('workspace-detail-healthy.example')), findsNothing);
    expect(find.byKey(const ValueKey('workspace-detail-a-1chimney.com')), findsOne);
    // The four permissions, with their meanings, only in the open row.
    expect(find.text('Move to Trash'), findsOne);
    expect(find.text('Delete on EzBizi bins the thread in Gmail.'), findsOne);

    // The healthy one opens on a tap, and shows all four too.
    await _tap(tester, find.byKey(const ValueKey('workspace-row-healthy.example')));
    expect(find.byKey(const ValueKey('workspace-detail-healthy.example')), findsOne);
    expect(find.text('Read mail'), findsNWidgets(2));
  });

  testWidgets('Update permissions on the row shows the Google Admin steps', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(workspaceUsable: true, workspace: _twoWorkspaces),
    );
    await _openSettings(tester);

    // Shut it (by its name, not the chip), then the chip opens it on the steps.
    await _tap(tester, find.text('a-1chimney.com'));
    expect(find.text('To-do in Google Admin'), findsNothing);
    await _tap(tester, find.text('1 missing · Update permissions'));
    expect(find.text('To-do in Google Admin'), findsOne);
    expect(find.text('Open Google Admin'), findsWidgets);
  });

  testWidgets('Disconnect Workspace sits under the row menu', (tester) async {
    await _pump(
      tester,
      _handler(workspaceUsable: true, workspace: _twoWorkspaces),
    );
    await _openSettings(tester);

    expect(find.text('Disconnect Workspace'), findsNothing);
    await _tap(tester, find.byKey(const ValueKey('workspace-menu-a-1chimney.com')));
    expect(find.text('Disconnect Workspace'), findsOne);
    await _tap(tester, find.text('Disconnect Workspace'));
    // The confirmation is the side panel it always was, not a dialog.
    expect(find.byType(SidePanel), findsOne);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('the inbox table lists each inbox with its status, and search '
      'narrows it', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(
            id: 'mb-1',
            address: 'info@a-1chimney.com',
            lastReachedAt: _agoIso(const Duration(hours: 2)),
          ),
          _row(
            id: 'mb-2',
            address: 'jobs@a-1chimney.com',
            status: 'broken',
            statusReason: 'The password was refused. Enter the new one.',
          ),
          _row(
            id: 'mb-3',
            address: 'help@a-1chimney.com',
            status: 'unconfigured',
            canBeOpened: false,
            scopeType: 'franchise',
            scopeId: 'fr-1',
          ),
        ],
      ),
    );
    await _openSettings(tester);

    for (final id in ['mb-1', 'mb-2', 'mb-3']) {
      expect(find.byKey(ValueKey('email-inbox-row-$id')), findsOne);
    }
    expect(find.text('Working'), findsOne);
    expect(find.text('Stopped · The password was refused'), findsOne);
    expect(find.text('Needs password'), findsOne);
    expect(find.text('2 h ago'), findsOne);
    // A branch is named with its company.
    expect(find.text('Everdoors · Connecticut'), findsOne);

    await tester.enterText(find.byKey(const ValueKey('email-inbox-search')), 'jobs');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('email-inbox-row-mb-2')), findsOne);
    expect(find.byKey(const ValueKey('email-inbox-row-mb-1')), findsNothing);
    expect(find.byKey(const ValueKey('email-inbox-row-mb-3')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('email-inbox-search')), 'nothing-like-it');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('email-inbox-none')), findsOne);
  });

  testWidgets('the company filter keeps one company\'s inboxes', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com'),
          _row(
            id: 'mb-2',
            address: 'ct@a-1chimney.com',
            scopeType: 'franchise',
            scopeId: 'fr-1',
          ),
        ],
      ),
    );
    await _openSettings(tester);

    await _tap(tester, find.byKey(const ValueKey('email-inbox-company')));
    await _tap(tester, find.byKey(const ValueKey('email-inbox-company-company:co-1')));
    expect(find.byKey(const ValueKey('email-inbox-row-mb-2')), findsOne);
    expect(find.byKey(const ValueKey('email-inbox-row-mb-1')), findsNothing);
  });

  testWidgets('a row opens that inbox\'s settings in the side pane', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com'),
          _row(id: 'mb-2', address: 'jobs@a-1chimney.com', mayManage: false),
        ],
      ),
    );
    await _openSettings(tester);

    expect(find.byKey(const ValueKey('email-inbox-pane')), findsNothing);
    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-1')));
    expect(find.byKey(const ValueKey('email-inbox-pane-mb-1')), findsOne);
    // The table stays beside it.
    expect(find.byKey(const ValueKey('email-inbox-row-mb-2')), findsOne);
    expect(find.widgetWithText(OutlinedButton, 'Reconnect'), findsOne);
    expect(find.text('Who can open this inbox'), findsOne);

    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-2')));
    expect(find.byKey(const ValueKey('email-inbox-pane-mb-2')), findsOne);
    expect(find.text('Who can open this inbox'), findsNothing);

    await _tap(tester, find.byKey(const ValueKey('email-inbox-pane-close')));
    expect(find.byKey(const ValueKey('email-inbox-pane')), findsNothing);
  });

  testWidgets('need attention › opens the first inbox in trouble', (tester) async {
    await _pump(
      tester,
      _handler(
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com'),
          _row(id: 'mb-2', address: 'jobs@a-1chimney.com', status: 'broken'),
        ],
      ),
    );
    await _openSettings(tester);

    await _tap(tester, find.byKey(const ValueKey('email-settings-attention')));
    expect(find.byKey(const ValueKey('email-inbox-pane-mb-2')), findsOne);
    expect(find.text('Not working'), findsOne);
  });

  testWidgets('phone: at 390 x 844 the rows stack and an inbox opens in place', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        workspaceUsable: true,
        workspace: _twoWorkspaces,
        mailboxes: [
          _row(id: 'mb-1', address: 'info@a-1chimney.com'),
          _row(
            id: 'mb-2',
            address: 'bookings-and-scheduling@a-1chimney.com',
            status: 'broken',
            statusReason:
                'Google withdrew consent for this address on the admin side.',
          ),
        ],
      ),
      size: const Size(390, 844),
    );
    await _openSettings(tester);

    expect(tester.takeException(), isNull);
    expect(find.text('1 missing · Update permissions'), findsOne);
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('email-inbox-row-mb-2')),
      300,
      scrollable: find
          .descendant(
            of: find.byKey(const Key('email-settings-list')),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('email-inbox-search')), findsOne);

    await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-2')));
    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('email-inbox-pane-mb-2')), findsOne);
    expect(find.byKey(const ValueKey('email-inbox-row-mb-1')), findsNothing);

    await _tap(tester, find.byKey(const ValueKey('email-inbox-pane-back')));
    expect(find.byKey(const ValueKey('email-inbox-pane')), findsNothing);
    expect(find.byKey(const ValueKey('email-settings-summary')), findsOne);
  });
}

Map<String, Object?> _workspaceLink(String domain, {required bool missing}) => {
  'domain': domain,
  'admin_email': 'it@$domain',
  'status': 'connected',
  'status_reason': null,
  'scope_type': 'company',
  'scope_id': 'co-1',
  'scope_label': 'Everdoors',
  'is_current_view': true,
  'scopes': const <String>[],
  'health': {
    'status': missing ? 'needs_attention' : 'ok',
    'summary': missing
        ? 'Mail can be read and sent. Trash cannot run yet.'
        : 'Everything EzBizi needs is allowed.',
    'scopes': [
      {'id': 'gmail.readonly', 'label': 'Read mail', 'detail': 'List and open messages.', 'ok': true},
      {'id': 'gmail.send', 'label': 'Send mail', 'detail': 'Replies go out as the inbox.', 'ok': true},
      {
        'id': 'gmail.modify',
        'label': 'Move to Trash',
        'detail': 'Delete on EzBizi bins the thread in Gmail.',
        'ok': !missing,
      },
      {'id': 'directory', 'label': 'List addresses', 'detail': 'The directory of users.', 'ok': true},
    ],
  },
  'setup_steps': const [
    {
      'title': 'Open Google Admin',
      'detail': 'Sign in as a Super Admin.',
      'url': 'https://admin.google.com/ac/owl/domainwidedelegation',
      'copy': null,
    },
  ],
};

final Map<String, Object?> _twoWorkspaces = {
  'can_connect': false,
  'view': {'type': 'organization', 'id': 'org-1', 'label': 'A-1 Chimney'},
  'connections': [
    _workspaceLink('healthy.example', missing: false),
    _workspaceLink('a-1chimney.com', missing: true),
  ],
};

// ── driving the screen ─────────────────────────────────────────────

/// The one obscured box in the connect panel — the mailbox's password.
///
/// Deliberately not `find.byType(TextField)`. The server-detail fields are
/// `TextFormField`s and each of those builds a `TextField` of its own, so the
/// bare type finder counts five boxes in this panel and calls whichever comes
/// first the password. It did, and the assertion "the box is empty" then passed
/// by looking at the incoming server name.
final _passwordBox = find.byWidgetPredicate(
  (widget) => widget is TextField && widget.obscureText,
  description: 'the obscured password box',
);

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1500, 1000),
  List<String> permissions = const [
    'email.view',
    'email.inbox.view',
    'email.settings.view',
    'email.create',
    'email.edit',
  ],
  Map<String, dynamic>? me,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_withMe(handler, permissions, me: me)));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: EmailScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _openSettings(WidgetTester tester) =>
    _tap(tester, find.widgetWithText(ChoiceChip, 'Settings'));

/// Design 151: a lone inbox opens by itself only where its pane fits beside
/// the table (1000 and wider); narrower, its row is tapped.
Future<void> _openInbox(WidgetTester tester, double width) async {
  if (width >= 1000) return;
  await _tap(tester, find.byKey(const ValueKey('email-inbox-row-mb-1')));
}

/// The Settings tab on its own, with a fake window in place of the browser's.
///
/// ── Why this one skips the Email screen around it ────────────────
///
/// Because the sign-in road cannot be walked in `flutter test` otherwise. The
/// suite runs on the VM, where consent_window.dart's stub correctly answers "no
/// window here" — so without somewhere to hand a fake in, the one flow somebody
/// cannot recover from unaided is the one flow nothing asserts.
///
/// It is still the real tab, the real panel and the real API client; only the
/// browser is stood in for. The two-sub-tab wiring around it is [_pump]'s
/// business and is asserted there.
Future<void> _pumpSettings(
  WidgetTester tester,
  MockClientHandler handler, {
  required _FakeConsentWindow? window,
  VoidCallback? onOpen,
  Size size = const Size(1500, 1000),
  List<String> permissions = const [
    'email.view',
    'email.inbox.view',
    'email.settings.view',
    'email.create',
    'email.edit',
  ],
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_withMe(handler, permissions)));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: MailboxSettingsTab(
          api: api,
          session: session,
          openConsent: () {
            onOpen?.call();

            return window;
          },
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// Let one poll of `oauth/status` happen, and let the panel rebuild on it.
///
/// Two pumps, and both are needed. The first moves the fake clock past the
/// interval so the timer fires; the timer's body then awaits an HTTP call, and
/// the second pump is what lets that resolve and the frame that follows be
/// built.
Future<void> _waitOneRound(WidgetTester tester, {Duration every = const Duration(seconds: 2)}) async {
  await tester.pump(every);
  await tester.pump();
  await tester.pump();
}

/// The browser window this suite has instead of a browser.
///
/// Only the three things ConsentWindow actually exposes, because that is all the
/// panel is allowed to know: an opener may not read anything else about a window
/// that has navigated to somebody else's origin.
class _FakeConsentWindow implements ConsentWindow {
  /// Every address it was pointed at. One, in a flow that behaved.
  final sentTo = <String>[];

  bool _closed = false;
  int closes = 0;

  /// The person closed it themselves, halfway through.
  void closedByHand() => _closed = true;

  @override
  void goTo(String url) => sentTo.add(url);

  @override
  bool get closed => _closed;

  @override
  void close() {
    closes += 1;
    _closed = true;
  }
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first, warnIfMissed: false);
  await tester.pumpAndSettle();
}

// ── the server, as this tab reads it ───────────────────────────────
//
// `last_reached_at` is the field being added server-side in parallel; every
// other key below is what MailboxesController::row already answers with.

typedef _Watcher =
    void Function(String method, String path, Map<String, dynamic>? body);

/// [imapUsable] and [googleUsable] pretend this installation can speak each
/// transport.
///
/// Neither can today — PHP 8.4 dropped the imap extension, and there is no
/// Google registration on this installation — and the defaults here say so,
/// because that is what the real server publishes and it is what stops a live
/// form being drawn for a connection that could never be attempted.
///
/// The tests that exercise either road pass true. They are about the PANEL's
/// behaviour — write-only, posted to the right endpoint, laid out at three
/// widths, honest while it waits — and that code becomes reachable the day the
/// extension is installed or the registration is done. Deleting them because
/// today's box cannot reach the panel would leave that behaviour unpinned for
/// the day it can.
///
/// [startBody] / [startStatus] are what `POST …/{mailbox}/oauth/start` answers —
/// the shape GoogleMailAuthController::start returns.
///
/// [statusBodies] are what `GET …/{mailbox}/oauth/status` answers, one per ask,
/// the last repeating. That is what a real wait looks like: nothing, nothing,
/// then an answer. `state` is one of the three
/// App\Support\Email\GoogleOAuthSignIns publishes — `waiting`, `connected`,
/// `refused` — and on a refusal `message` carries the server's own sentence.
///
/// [connect] replaces the ordinary success from `POST …/connect`, so a test can
/// hold that response open and look at the panel while it is in flight.
MockClientHandler _handler({
  List<Map<String, Object?>>? mailboxes,
  _Watcher? onRequest,

  /// The whole URL, query and all. Separate from [onRequest] because that one
  /// takes a bare path and eight call sites read it that way — and the query is
  /// exactly what one assertion here is about.
  void Function(Uri url)? onUrl,
  bool imapUsable = false,
  bool googleUsable = false,
  bool workspaceUsable = false,
  Object startBody = const {
    'data': {
      'authorization_url':
          'https://accounts.google.com/o/oauth2/v2/auth?client_id=ez&state=s1',
      'flow': 'flow-1',
      'expires_at': '2026-08-13T14:10:00+00:00',
      'sign_in_as': 'info@a-1chimney.com',
      'scopes': [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
      ],
    },
  },
  int startStatus = 200,
  List<Object> statusBodies = const [
    {
      'data': {'state': 'waiting', 'message': null},
    },
  ],
  Future<http.Response> Function()? connect,
  Map<String, Object?>? workspace,
  List<Map<String, Object?>> directory = const [],

  /// What `…/people` answers: who can open the inbox, by person (2026-10-06).
  Map<String, Object?> people = const {'people': [], 'roles': [], 'candidates': []},

  /// Addresses the batch add should report as skipped, address => reason.
  Map<String, String> batchSkips = const {},

  /// What `…/new-mail-alerts` answers; null leaves the route unanswered.
  Map<String, Object?>? alerts,

  /// The sentence a switch flip is refused with (422), when set.
  String? alertRefusal,
}) {
  final rows = mailboxes ?? [_row(id: 'mb-1', address: 'info@a-1chimney.com')];

  // Asked repeatedly, and the answers are consumed in order — the last one
  // repeats. That is what a real wait looks like: nothing, nothing, then an
  // answer.
  var asked = 0;

  return (request) async {
    final path = request.url.path;
    final body = request.body.isEmpty
        ? null
        : jsonDecode(request.body) as Map<String, dynamic>;

    onRequest?.call(request.method, path, body);
    onUrl?.call(request.url);

    if (path.endsWith('/people') || path.contains('/people/')) {
      return _json({'data': people});
    }

    if (alerts != null && path.endsWith('/new-mail-alerts')) {
      return _json({'data': alerts});
    }

    if (alerts != null && path.endsWith('/new-mail-alert')) {
      if (alertRefusal != null) return _json({'message': alertRefusal}, 422);

      final address = rows.firstWhere((r) => path.contains('/${r['id']}/'))['address'];
      final addresses = [...(alerts!['addresses'] as List)]..remove(address);
      if (body?['on'] == true) addresses.add(address);
      alerts = {...alerts!, 'addresses': addresses};

      return _json({'data': alerts});
    }

    if (path.endsWith('/workspace-batch')) {
      final asked = [
        for (final row in (body?['addresses'] as List? ?? const []))
          '${(row as Map)['address']}',
      ];
      final added = [
        for (final a in asked)
          if (!batchSkips.containsKey(a)) _row(id: 'mb-$a', address: a),
      ];

      return _json({
        'data': {
          'added': added,
          'skipped': [
            for (final a in asked)
              if (batchSkips.containsKey(a)) {'address': a, 'reason': batchSkips[a]},
          ],
        },
      }, added.isEmpty ? 422 : 201);
    }

    if (path.endsWith('/oauth/start')) {
      return _json(startBody, startStatus);
    }

    if (path.endsWith('/oauth/status')) {
      final at = asked < statusBodies.length ? asked : statusBodies.length - 1;

      asked += 1;

      return _json(statusBodies[at]);
    }

    if (path.endsWith('/connect') && connect != null) {
      return connect();
    }

    if (request.method == 'DELETE') {
      return _json({
        'message':
            'info@a-1chimney.com is no longer connected to EzBizi. Nothing has '
            'happened to the mail itself — the address still receives '
            'everything it did, and it can be read wherever it was read '
            'before.',
      });
    }

    if (path.endsWith('/connect')) {
      return _json({'data': rows.first});
    }

    if (path.endsWith('/email/mailboxes')) {
      if (request.method == 'POST') {
        return _json({'data': rows.isEmpty ? _row(id: 'mb-9', address: 'x@y.z') : rows.first}, 201);
      }

      return _json({
        'data': rows,
        'meta': {
          'providers': [
            // Declaration order, as MailboxProvider::all() returns it: the one
            // to steer people to first, and the one that stores a password
            // second.
            // `usable` is what the real server publishes, and BOTH are false
            // on this installation today: the Google sign-in flow is not built,
            // and PHP 8.4 dropped the imap extension the IMAP reader needs.
            //
            // This fixture said neither, which mattered: with `usable` absent
            // the client treats a provider as usable (an older server cannot
            // answer), so IMAP drew a live password form for a connection that
            // could never be attempted — the defect the flag exists to stop.
            {
              'value': 'gmail_oauth',
              'label': 'Google (recommended)',
              'needs_host': false,
              'connects_by': 'sign_in',
              'caution': null,
              'usable': googleUsable,
              'unusable_because': googleUsable
                  ? null
                  : 'EzBizi has no Google connection set up on this '
                        'installation yet, so no Google mailbox can be opened '
                        'and signing in would not complete.',
            },
            {
              'value': 'gmail_workspace',
              'label': 'Google Workspace',
              'needs_host': false,
              'connects_by': 'directory',
              'caution':
                  'Connect the company\'s Google Workspace once, then pick '
                  'shared addresses like info@ from the directory.',
              'usable': workspaceUsable,
              'unusable_because': workspaceUsable
                  ? null
                  : 'EzBizi has no Google Workspace connection set up on this '
                        'installation yet, so a company Workspace cannot be '
                        'connected.',
            },
            {
              'value': 'microsoft_oauth',
              'label': 'Microsoft 365',
              'needs_host': false,
              'connects_by': 'sign_in',
              'caution':
                  'Connect a shared Outlook address, not a person\'s own mailbox.',
              'usable': false,
              'unusable_because':
                  'EzBizi has no Microsoft 365 connection set up on this '
                  'installation yet.',
            },
            {
              'value': 'imap',
              'label': 'IMAP',
              'needs_host': true,
              'connects_by': 'password',
              'caution':
                  'Connecting by IMAP means storing the mailbox password here, '
                  'and for most mail services that is the account password.',
              'usable': imapUsable,
              'unusable_because': imapUsable
                  ? null
                  : 'EzBizi cannot speak to IMAP mail servers on this '
                        'installation yet - the PHP extension it needs is not '
                        'present. Entering a password here would not connect '
                        'anything.',
            },
          ],
          'imap_ports': [143, 993],
          'smtp_ports': [587, 465],
        },
      });
    }

    // The Inbox tab, which this file does not test but does render.
    if (path.endsWith('/messages')) {
      return _json({
        'data': const [],
        'meta': {'page': 1, 'last_page': 1},
      });
    }

    if (path.endsWith('/email/workspace/directory')) {
      return _json({'data': directory});
    }

    if (path.endsWith('/email/workspace')) {
      if (request.method == 'DELETE') {
        return _json({
          'message':
              'a-1chimney.com is no longer connected to EzBizi. Shared inboxes '
              'that were picked from that directory are still listed.',
        });
      }

      return _json({
        'data': workspace ??
            {
              'can_connect': true,
              'view': {
                'type': 'organization',
                'id': 'org-1',
                'label': 'Everdoors',
              },
              'connections': const [],
            },
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

MockClientHandler _withMe(
  MockClientHandler inner,
  List<String> permissions, {
  Map<String, dynamic>? me,
}) {
  return (request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {..._me, ...?me, 'permissions': permissions},
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

/// One row of `GET /email/mailboxes`, in the shape the controller answers with.
///
/// [lastReachedKnown] false leaves the key OUT, which is what a server without
/// that column sends and is a different fact from a null.
Map<String, Object?> _row({
  required String id,
  required String address,
  String displayName = 'Info',
  String provider = 'imap',
  String providerLabel = 'IMAP',
  String scopeType = 'organization',
  String scopeId = 'org-1',
  String status = 'connected',
  String? statusReason,
  bool canBeOpened = true,
  String? unavailableReason,
  String? lastReachedAt,
  bool lastReachedKnown = true,
  bool mayManage = true,
  Map<String, Object?> mailServer = const {
    'imap_host': 'imap.example.com',
    'imap_port': 993,
  },
  Map<String, Object?> extra = const {},
}) => {
  'id': id,
  'address': address,
  'display_name': displayName,
  'provider': provider,
  'provider_label': providerLabel,
  'scope_type': scopeType,
  'scope_id': scopeId,
  'status': status,
  'status_reason': statusReason,
  'can_be_opened': canBeOpened,
  'unavailable_reason': unavailableReason,
  if (lastReachedKnown) 'last_reached_at': lastReachedAt,
  'mail_server': mailServer,
  'may_read': true,
  'may_read_reason': null,
  'may_manage': mayManage,
  'read_state_shared': true,
  'read_state_note': null,
  ...extra,
};

/// One address set up on the provider that is finished by signing in.
Map<String, Object?> _google({
  String status = 'unconfigured',
  bool canBeOpened = false,
  String? statusReason,
}) => _row(
  id: 'mb-1',
  address: 'info@a-1chimney.com',
  provider: 'gmail_oauth',
  providerLabel: 'Google (recommended)',
  status: status,
  statusReason: statusReason,
  canBeOpened: canBeOpened,
  // A mailbox finished by signing in has no mail server, and the controller
  // nulls the block for a provider that needs no host.
  mailServer: const {},
);

/// A stamp that will still read as [ago] by the time the widget builds.
String _agoIso(Duration ago) =>
    DateTime.now().toUtc().subtract(ago).toIso8601String();

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  // Both tabs, named one at a time and never by pattern.
  //
  // A fixture that granted `email.*` would be shorter and would make this
  // suite unable to show the thing the split exists for -- that a tab nobody
  // granted does not appear. The test that proves it lives in
  // email_screen_test.dart; this list is what keeps the rest of the suite
  // about the screens rather than about the gate.
  'permissions': [
    'email.view',
    'email.inbox.view',
    'email.settings.view',
    'email.create',
    'email.edit',
  ],
  'modules': [
    {'key': 'email', 'label': 'Email', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
