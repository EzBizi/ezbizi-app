import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/email_screen.dart';
import 'package:ezbizi_client/screens/email/message_html.dart';
import 'package:ezbizi_client/screens/email/signature_builder.dart';
import 'package:ezbizi_client/screens/email/workspace_tab.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Email › Google Workspace (UI queue 134, options 1 and 4).
///
/// Every name and address is invented.
void main() {
  testWidgets('one card per Workspace with its status; a card opens it', (
    tester,
  ) async {
    final server = _Server(cards: [_ready, _notReady, _noTemplate]);
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('tour-email-workspace-cards')), findsOne);
    expect(find.text('brightside.example'), findsOneWidget);
    expect(find.text('Brightside Home Services · company'), findsOneWidget);
    expect(find.text('Signatures ready'), findsOneWidget);
    expect(find.text('Needs a Google Admin step'), findsOneWidget);
    expect(find.text('No template yet'), findsOneWidget);
    expect(find.text('4 people'), findsOneWidget);
    expect(server.asked('GET', '/workspaces/ws-1/people'), isEmpty);

    await _tap(tester, find.byKey(const ValueKey('workspace-card-ws-1')));

    expect(server.asked('GET', '/workspaces/ws-1/people'), hasLength(1));
    expect(find.text('Dana Reyes'), findsWidgets);

    await _tap(tester, find.byKey(const ValueKey('workspace-back')));
    expect(find.byKey(const ValueKey('workspace-card-ws-2')), findsOne);
  });

  testWidgets('with exactly one Workspace, it opens without a card', (
    tester,
  ) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('workspace-card-ws-1')), findsNothing);
    expect(find.byKey(const ValueKey('workspace-back')), findsNothing);
    expect(find.byKey(const ValueKey('tour-email-workspace-people')), findsOne);
    expect(find.text('pat@brightside.example'), findsOneWidget);
  });

  testWidgets('a Workspace that cannot set signatures says why', (
    tester,
  ) async {
    final server = _Server(cards: [_notReady]);
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('workspace-not-ready')), findsOne);
    expect(find.text(_reason), findsOneWidget);
  });

  testWidgets('a directory that cannot be read shows the server sentence', (
    tester,
  ) async {
    const sentence =
        'Google would not list the people on this Workspace. Ask a Google '
        'Admin to allow directory access.';
    final server = _Server(cards: [_ready], peopleRefusal: sentence);
    await _pump(tester, server);

    expect(find.text(sentence), findsOneWidget);
    expect(
      find.byKey(const ValueKey('tour-email-workspace-people')),
      findsNothing,
    );
  });

  testWidgets('the A-1 standard opens in the builder, the server fills the '
      'preview, and saving sends the design with its HTML', (tester) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server, size: const Size(1400, 1000));

    expect(find.byKey(const ValueKey('workspace-no-templates')), findsOne);
    await _tap(tester, find.byKey(const ValueKey('workspace-new-a1-template')));
    expect(find.byKey(const ValueKey('sig-builder')), findsOne);

    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    final asked = server.asked('POST', '/signatures/preview');
    expect(asked, isNotEmpty);
    final html = '${asked.last!['html']}';
    expect(html, contains('{{name}}'));
    expect(html, contains('(888) 984-4344'));
    expect(html, contains('>A-1</strong>'));
    expect(html, contains('{{^photo_url}}'));
    expect(find.byKey(const ValueKey('sig-preview-html')), findsOne);
    // Drawn by a browser engine, the server's filled HTML whole.
    final view = tester.widget<MessageHtmlView>(find.byType(MessageHtmlView));
    expect(view.ownHtml, isTrue);
    expect(
      view.html,
      contains('<table><tr><td><b>Dana Reyes</b></td></tr></table>'),
    );
    expect(view.html, contains('--<br>'));

    await tester.enterText(
      find.byKey(const ValueKey('sig-name')),
      'A-1 standard',
    );
    await tester.ensureVisible(find.byKey(const ValueKey('sig-save')));
    await _tap(tester, find.byKey(const ValueKey('sig-save')));
    final saved = server.asked('POST', '/signature-templates').single!;
    expect(saved['name'], 'A-1 standard');
    expect(saved['html'], html);
    expect((saved['design'] as Map)['company_phone'], '(888) 984-4344');
    // Saved and closed; nothing was pushed to Gmail.
    expect(find.byKey(const ValueKey('sig-builder')), findsNothing);
    expect(server.asked('POST', '/signatures/apply'), isEmpty);
  });

  testWidgets('a setting changed in the builder changes the HTML it previews', (
    tester,
  ) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server, size: const Size(1400, 1000));
    await _tap(tester, find.byKey(const ValueKey('workspace-new-template')));
    await _tap(tester, find.byKey(const ValueKey('sig-layout-none')));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(
      '${server.asked('POST', '/signatures/preview').last!['html']}',
      isNot(contains('<img')),
    );

    // The code view shows the same HTML; typing there makes it code.
    await _tap(tester, find.byKey(const ValueKey('sig-view-code')));
    await tester.enterText(find.byKey(const ValueKey('sig-code')), '{{name}}');
    await tester.enterText(find.byKey(const ValueKey('sig-name')), 'Short');
    await tester.ensureVisible(find.byKey(const ValueKey('sig-save')));
    await _tap(tester, find.byKey(const ValueKey('sig-save')));
    expect(server.asked('POST', '/signature-templates').single, {
      'name': 'Short',
      'html': '{{name}}',
      'design': null,
    });
  });

  testWidgets('templates: make one the default, give ticked people another, '
      'and delete only after confirming', (tester) async {
    final server = _Server(cards: [_ready], templates: true);
    await _pump(tester, server, size: const Size(1400, 1000));

    expect(find.byKey(const ValueKey('workspace-template-default')), findsOne);
    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-default-template-t-short')),
    );
    expect(server.asked('PUT', '/signature-default').single, {
      'template_id': 't-short',
    });

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-tick-dana@brightside.example')),
    );
    await _tap(tester, find.byKey(const ValueKey('workspace-bulk-assign')));
    await _tap(tester, find.text('Short reply').last);
    expect(server.asked('PUT', '/signatures/assign').single, {
      'addresses': ['dana@brightside.example'],
      'template_id': 't-short',
    });
    // It says it took, and the picker keeps the choice (Jose 11-10: it reverted).
    expect(
      find.text('Given Short reply to 1 person. Set in Gmail sends it.'),
      findsOne,
    );
    expect(
      tester
          .widget<SearchPickerField<String>>(
            find.byKey(const ValueKey('workspace-bulk-assign')),
          )
          .value,
      't-short',
    );
    // And Set in Gmail sends it for the same people.
    await _tap(tester, find.byKey(const ValueKey('workspace-bulk-apply')));
    expect(server.asked('POST', '/signatures/apply').last, {
      'addresses': ['dana@brightside.example'],
    });

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-delete-template-t-short')),
    );
    expect(server.asked('DELETE', '/signature-templates/t-short'), isEmpty);
    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-delete-confirm-t-short')),
    );
    expect(
      server.asked('DELETE', '/signature-templates/t-short'),
      hasLength(1),
    );
  });

  test('an empty value hides its line: optional items are wrapped', () {
    final html = compileSignature(a1StandardDesign());
    expect(html, contains('{{#title}}'));
    expect(html, contains('{{#mobile}}'));
    expect(html, contains('{{#extension}} ext. {{extension}}{{/extension}}'));
    expect(html, isNot(contains('test')));
    expect(html.length, lessThan(10000));
    final none = compileSignature(mergeDesign({'layout': 'none'}));
    expect(none, isNot(contains('<img')));
  });

  /// A saved design comes back with JSON nulls; it must not draw an empty logo.
  test('a stored null logo or phone draws nothing', () {
    final html = compileSignature(
      mergeDesign({'logo_url': null, 'company_phone': null}),
    );
    expect(html, isNot(contains('max-height:40px')));
  });

  testWidgets('linking: same email, unlink, and picking a person', (
    tester,
  ) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server);

    expect(find.text('Dana Reyes ✓'), findsOneWidget);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-same-email-pat@brightside.example')),
    );
    expect(server.asked('PUT', '/people/pat@brightside.example/link'), [
      {'user_id': 'u-3'},
    ]);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-unlink-dana@brightside.example')),
    );
    expect(server.asked('PUT', '/people/dana@brightside.example/link'), [
      {'user_id': null},
    ]);

    await _tap(
      tester,
      find.descendant(
        of: find.byKey(
          const ValueKey('workspace-link-sales@brightside.example'),
        ),
        matching: find.byType(InkWell),
      ),
    );
    await _tap(tester, find.text('Robin Hale').last);
    expect(server.asked('PUT', '/people/sales@brightside.example/link'), [
      {'user_id': 'u-4'},
    ]);
  });

  testWidgets('ticked people are set together, and every failure is listed', (
    tester,
  ) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server);

    final suspended = tester.widget<Checkbox>(
      find.byKey(const ValueKey('workspace-tick-sam@brightside.example')),
    );
    expect(suspended.onChanged, isNull, reason: 'Suspended is not selectable.');

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-tick-dana@brightside.example')),
    );
    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-tick-pat@brightside.example')),
    );

    expect(find.text('2 selected'), findsOneWidget);
    await _tap(tester, find.byKey(const ValueKey('workspace-bulk-apply')));

    expect(server.asked('POST', '/signatures/apply'), [
      {
        'addresses': ['dana@brightside.example', 'pat@brightside.example'],
      },
    ]);
    expect(find.text('1 set, 1 failed'), findsOneWidget);
    expect(
      find.text(
        'pat@brightside.example: Google refused the change for this address.',
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('workspace-bulk-bar')), findsNothing);
  });

  testWidgets('the person pane: Gmail now beside what it will be, their own '
      'or the template, and set it now', (tester) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-row-pat@brightside.example')),
    );

    expect(find.text('In Gmail now'), findsOneWidget);
    expect(find.text('Will be'), findsOneWidget);
    expect(find.text('Pat\nSent from my phone', findRichText: true), findsOne);
    expect(
      find.text('Pat Lin\nTechnician', findRichText: true),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('workspace-own-field')), findsNothing);

    await _tap(tester, find.text('Give them their own'));
    await tester.enterText(
      find.byKey(const ValueKey('workspace-own-field')),
      'Pat at {{company}}',
    );
    await _tap(tester, find.byKey(const ValueKey('workspace-own-save')));
    expect(server.asked('PUT', '/people/pat@brightside.example/signature'), [
      {'html': 'Pat at {{company}}'},
    ]);

    await _tap(tester, find.text('Use a template'));
    expect(
      server.asked('PUT', '/people/pat@brightside.example/signature').last,
      {'html': null},
    );

    await _tap(tester, find.byKey(const ValueKey('workspace-person-set-now')));
    expect(server.asked('POST', '/signatures/apply').last, {
      'addresses': ['pat@brightside.example'],
    });
    expect(
      find.text('Google refused the change for this address.'),
      findsOneWidget,
    );
  });

  testWidgets('the person pane says where each value comes from', (
    tester,
  ) async {
    await _pump(tester, _Server(cards: [_ready]));

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-row-dana@brightside.example')),
    );

    final name = find.byKey(const ValueKey('workspace-fill-name'));
    final title = find.byKey(const ValueKey('workspace-fill-title'));
    expect(tester.widget<Text>(name).data, 'from EzBizi');
    expect(tester.widget<Text>(title).data, 'from Google');
  });

  testWidgets('a card that cannot set signatures offers Update permissions, '
      'which opens Google Admin', (tester) async {
    final opened = <Uri>[];
    await _pump(
      tester,
      _Server(cards: [_ready, _notReady, _noTemplate]),
      opened: opened,
    );

    expect(find.text('Update permissions'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('workspace-update-permissions-ws-1')),
      findsNothing,
    );
    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-update-permissions-ws-2')),
    );
    expect(opened, [Uri.parse(_authorize)]);
    // The button, not the card: the Workspace did not open.
    expect(find.byKey(const ValueKey('workspace-card-ws-1')), findsOne);
  });

  testWidgets('the not-ready banner carries Update permissions', (
    tester,
  ) async {
    final opened = <Uri>[];
    await _pump(tester, _Server(cards: [_notReady]), opened: opened);

    final button = find.descendant(
      of: find.byKey(const ValueKey('workspace-not-ready')),
      matching: find.text('Update permissions'),
    );
    expect(button, findsOneWidget);
    await _tap(tester, button);
    expect(opened, [Uri.parse(_authorize)]);
  });

  testWidgets('a card with may_edit false is read-only even with email.edit', (
    tester,
  ) async {
    await _pump(
      tester,
      _Server(
        cards: [
          {..._ready, 'may_edit': false},
        ],
      ),
    );

    expect(find.byType(Checkbox), findsNothing);
    expect(find.byKey(const ValueKey('workspace-save-template')), findsNothing);
    expect(find.text('Same email · Link'), findsNothing);
    expect(find.text('Unlink'), findsNothing);
  });

  testWidgets('without email.edit nothing can be changed', (tester) async {
    await _pump(
      tester,
      _Server(cards: [_ready]),
      permissions: const ['email.view', 'email.workspace.view'],
    );

    expect(find.byType(Checkbox), findsNothing);
    expect(find.byKey(const ValueKey('workspace-new-template')), findsNothing);
    expect(find.text('Same email · Link'), findsNothing);
    expect(find.text('Unlink'), findsNothing);
    expect(find.byType(SearchPickerField<String>), findsNothing);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-row-pat@brightside.example')),
    );
    expect(find.text('In Gmail now'), findsOneWidget);
    expect(find.text('Give them their own'), findsNothing);
    expect(
      find.byKey(const ValueKey('workspace-person-set-now')),
      findsNothing,
    );
  });

  testWidgets('phone: people are cards, long-press selects, and the template '
      'and a person open full screen with back', (tester) async {
    final server = _Server(cards: [_ready]);
    await _pump(tester, server, size: const Size(390, 844));
    expect(tester.takeException(), isNull);

    expect(
      find.byKey(
        const ValueKey('workspace-card-person-pat@brightside.example'),
      ),
      findsOne,
    );
    expect(
      find.byKey(const ValueKey('tour-email-workspace-people')),
      findsNothing,
    );

    await tester.longPress(
      find.byKey(
        const ValueKey('workspace-card-person-pat@brightside.example'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('1 selected'), findsOneWidget);

    // A suspended card does not select.
    final samInk = tester.widget<InkWell>(
      find.descendant(
        of: find.byKey(
          const ValueKey('workspace-card-person-sam@brightside.example'),
          skipOffstage: false,
        ),
        matching: find.byType(InkWell),
        skipOffstage: false,
      ),
    );
    expect(samInk.onLongPress, isNull);

    // While selecting, a tap toggles.
    await _tap(
      tester,
      find.byKey(
        const ValueKey('workspace-card-person-pat@brightside.example'),
      ),
    );
    expect(find.byKey(const ValueKey('workspace-bulk-bar')), findsNothing);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-phone-template-open')),
    );
    expect(find.byKey(const ValueKey('workspace-phone-template')), findsOne);
    expect(find.byKey(const ValueKey('workspace-new-template')), findsOne);
    expect(tester.takeException(), isNull);
    await _tap(tester, find.byTooltip('Back'));
    expect(
      find.byKey(const ValueKey('workspace-phone-template')),
      findsNothing,
    );

    await _tap(
      tester,
      find.byKey(
        const ValueKey('workspace-card-person-dana@brightside.example'),
      ),
    );
    expect(find.byKey(const ValueKey('workspace-person-pane')), findsOne);
    expect(find.text('In Gmail now'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await _tap(tester, find.byTooltip('Back'));
    expect(find.byKey(const ValueKey('workspace-person-pane')), findsNothing);
  });

  testWidgets('Email draws a Google Workspace tab for email.workspace.view', (
    tester,
  ) async {
    final server = _Server(cards: [_ready]);
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient(
        server.handler(const [
          'email.view',
          'email.inbox.view',
          'email.workspace.view',
          'email.edit',
        ]),
      ),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: EmailScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(server.asked('GET', '/email/workspaces'), isEmpty);
    await _tap(tester, find.widgetWithText(ChoiceChip, 'Google Workspace'));
    expect(find.byType(WorkspaceTab), findsOneWidget);
    expect(server.asked('GET', '/email/workspaces'), hasLength(1));
  });

  test('signature HTML reads as text', () {
    expect(
      signatureAsText(
        '<div><b>Dana&nbsp;Reyes</b></div><div>Office manager &amp; '
        'more</div><p>(555) 010-0142</p><script>x()</script>',
      ),
      'Dana Reyes\nOffice manager & more\n(555) 010-0142',
    );
    expect(signatureAsText('a<br>b<br/>  c'), 'a\nb\nc');
    final runs = signatureRuns('<strong>Pat</strong> Lin');
    expect(runs.first, (text: 'Pat', bold: true, italic: false));
    expect(runs.last.bold, isFalse);
  });
  // ── design 151 option 7: compact rows ────────────────────────────

  testWidgets('each row carries its one next step for its state', (
    tester,
  ) async {
    final opened = <Uri>[];
    final server = _Server(cards: [_ready, _notReady, _noTemplate]);
    await _pump(tester, server, opened: opened);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workspace-card-ws-1')),
        matching: find.text('Set signatures ›'),
      ),
      findsOne,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workspace-card-ws-2')),
        matching: find.text('Update permissions'),
      ),
      findsOne,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('workspace-card-ws-3')),
        matching: find.text('Write a template'),
      ),
      findsOne,
    );
    // One step per row, never two.
    expect(find.text('Set signatures ›'), findsOne);
    expect(find.text('Write a template'), findsOne);

    await _tap(
      tester,
      find.byKey(const ValueKey('workspace-update-permissions-ws-2')),
    );
    expect(opened, [Uri.parse(_authorize)]);
    expect(server.asked('GET', '/workspaces/ws-2/people'), isEmpty);

    await _tap(tester, find.byKey(const ValueKey('workspace-next-ws-3')));
    expect(server.asked('GET', '/workspaces/ws-3/people'), hasLength(1));
  });

  testWidgets('the signature bar paints the counts the server sent', (
    tester,
  ) async {
    await _pump(
      tester,
      _Server(
        cards: [
          {..._ready, 'signatures_set': 3, 'signatures_total': 4},
          _notReady,
        ],
      ),
    );

    expect(find.text('3 of 4 set'), findsOne);
    final bar = find.descendant(
      of: find.byKey(const ValueKey('workspace-progress-ws-1')),
      matching: find.byType(LinearProgressIndicator),
    );
    expect(tester.widget<LinearProgressIndicator>(bar).value, 0.75);
    final size = tester.getSize(bar);
    expect(size.width, greaterThan(100), reason: 'The bar has no width.');
    expect(size.height, 6);
    // Painted, not merely built: the indicator's render box is on screen.
    expect(tester.renderObject<RenderBox>(bar).hasSize, isTrue);
    expect(
      find.byKey(const ValueKey('workspace-progress-ws-2')),
      findsNothing,
      reason: 'No counts sent and never opened: the bar is left off, not 0.',
    );
  });

  testWidgets('without counts, the bar comes from the people read on open', (
    tester,
  ) async {
    await _pump(tester, _Server(cards: [_ready, _notReady]));
    expect(find.byKey(const ValueKey('workspace-progress-ws-1')), findsNothing);

    await _tap(tester, find.byKey(const ValueKey('workspace-card-ws-1')));
    await _tap(tester, find.byKey(const ValueKey('workspace-back')));

    final active = _people.where((p) => p['suspended'] != true);
    final set = active.where((p) => p['signature_applied_at'] != null).length;
    expect(find.text('$set of ${active.length} set'), findsOne);
  });

  testWidgets('phone: the Workspace rows stack at 390 x 844 without overflow', (
    tester,
  ) async {
    await _pump(
      tester,
      _Server(
        cards: [
          {..._ready, 'signatures_set': 1, 'signatures_total': 4},
          _notReady,
          _noTemplate,
        ],
      ),
      size: const Size(390, 844),
    );
    expect(tester.takeException(), isNull);
    expect(find.text('Update permissions'), findsOne);
    expect(find.text('Write a template'), findsOne);
    expect(find.text('1 of 4 set'), findsOne);
    expect(
      find.text('Brightside Home Services · company · 4 people'),
      findsOne,
    );
  });
}

const _template = '<b>{{name}}</b><br>{{title}} · {{company}}';
const _authorize =
    'https://admin.google.com/ac/owl/domainwidedelegation?clientScopeToAdd=x';
const _reason =
    'Signatures need one Google Admin step: allow EzBizi to change Gmail '
    'settings for your users.';

const _ready = {
  'id': 'ws-1',
  'domain': 'brightside.example',
  'scope_label': 'Brightside Home Services · company',
  'status': 'connected',
  'signatures_ready': true,
  'signatures_reason': null,
  'has_template': true,
  'people_count': 4,
};

const _notReady = {
  'id': 'ws-2',
  'domain': 'brightside-air.example',
  'scope_label': 'Brightside Air · company',
  'status': 'connected',
  'signatures_ready': false,
  'signatures_reason': _reason,
  'authorize_url': _authorize,
  'has_template': true,
  'people_count': 6,
};

const _noTemplate = {
  'id': 'ws-3',
  'domain': 'riverton-home.example',
  'scope_label': 'Riverton · branch',
  'status': 'connected',
  'signatures_ready': true,
  'signatures_reason': null,
  'has_template': false,
  'people_count': null,
};

Map<String, Object?> _fill(String value, String? from) => {
  'value': value,
  'from': from,
};

final _people = [
  {
    'address': 'dana@brightside.example',
    'name': 'Dana Reyes',
    'title': 'Office manager',
    'phone': '(555) 010-0142',
    'suspended': false,
    'last_login_at': null,
    'aliases': <String>[],
    'user': {'id': 'u-2', 'name': 'Dana Reyes'},
    'link': 'linked',
    'has_own_signature': false,
    'signature_applied_at': '2026-10-20T15:00:00Z',
    'fills': {
      'name': _fill('Dana Reyes', 'ezbizi'),
      'first_name': _fill('Dana', 'ezbizi'),
      'last_name': _fill('Reyes', 'ezbizi'),
      'phone': _fill('(555) 010-0142', 'ezbizi'),
      'title': _fill('Office manager', 'google'),
      'email': _fill('dana@brightside.example', 'google'),
    },
  },
  {
    'address': 'pat@brightside.example',
    // As PHP sends an empty array: [] not {}. The pane once cast it and threw (Jose 11-10).
    'fields': <Object>[],
    'name': 'Pat Lin',
    'title': 'Technician',
    'phone': '',
    'suspended': false,
    'last_login_at': null,
    'aliases': <String>[],
    'user': {'id': 'u-3', 'name': 'Pat Lin'},
    'link': 'same_email',
    'has_own_signature': false,
    'signature_applied_at': null,
    'fills': {'name': _fill('Pat Lin', 'ezbizi'), 'phone': _fill('', null)},
  },
  {
    'address': 'sales@brightside.example',
    'name': 'Sales',
    'title': '',
    'phone': '',
    'suspended': false,
    'last_login_at': null,
    'aliases': <String>[],
    'user': null,
    'link': null,
    'has_own_signature': true,
    'signature_applied_at': null,
    'fills': {'name': _fill('Sales', 'google')},
  },
  {
    'address': 'sam@brightside.example',
    'name': 'Sam Ortiz',
    'title': '',
    'phone': '',
    'suspended': true,
    'last_login_at': null,
    'aliases': <String>[],
    'user': null,
    'link': null,
    'has_own_signature': false,
    'signature_applied_at': null,
    'fills': <String, Object?>{},
  },
];

class _Server {
  _Server({required this.cards, this.peopleRefusal, this.templates = false});

  final bool templates;

  final List<Map<String, Object?>> cards;
  final String? peopleRefusal;
  final List<(String, String, Map<String, dynamic>?)> requests = [];
  String? _savedTemplate;
  final Map<String, String?> _own = {};

  /// Bodies of the requests whose decoded path ends with [suffix].
  List<Map<String, dynamic>?> asked(String method, String suffix) => [
    for (final (m, path, body) in requests)
      if (m == method && path.endsWith(suffix)) body,
  ];

  MockClientHandler handler(List<String> permissions) => (request) async {
    final path = Uri.decodeComponent(request.url.path);
    final body = request.body.isEmpty
        ? null
        : jsonDecode(request.body) as Map<String, dynamic>;
    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }
    requests.add((request.method, path, body));

    if (path.endsWith('/email/workspaces')) return _json({'data': cards});

    final match = RegExp(r'/email/workspaces/([^/]+)(/.*)$').firstMatch(path);
    if (match == null) return _json({'data': <Object>[]});
    final card = cards.firstWhere((c) => c['id'] == match.group(1));
    final rest = match.group(2)!;

    if (rest == '/people' && request.method == 'GET') {
      if (peopleRefusal != null) {
        return _json({'message': peopleRefusal}, 422);
      }
      return _json({
        'data': {
          ...card,
          'template':
              _savedTemplate ??
              (card['has_template'] == true ? _template : null),
          'placeholders': [
            'name',
            'first_name',
            'last_name',
            'email',
            'phone',
            'title',
            'company',
          ],
          'templates': [
            if (templates) ...[
              {
                'id': 't-a1',
                'name': 'A-1 standard',
                'design': null,
                'html': '{{name}}',
                'is_default': true,
              },
              {
                'id': 't-short',
                'name': 'Short reply',
                'design': null,
                'html': '{{first_name}}',
                'is_default': false,
              },
            ],
          ],
          'max_length': 10000,
          'people': [
            for (final p in _people)
              {
                ...p,
                if (_own.containsKey(p['address']))
                  'has_own_signature': _own[p['address']] != null,
              },
          ],
          'candidates': [
            {
              'id': 'u-2',
              'name': 'Dana Reyes',
              'email': 'dana@brightside.example',
            },
            {'id': 'u-3', 'name': 'Pat Lin', 'email': 'pat@brightside.example'},
            {
              'id': 'u-4',
              'name': 'Robin Hale',
              'email': 'robin@brightside.example',
            },
          ],
        },
      });
    }
    if (rest.endsWith('/link')) {
      return _json({
        'data': {'address': rest.split('/')[2], 'user': null},
      });
    }
    if (rest == '/signature-template') {
      _savedTemplate = body?['html'] as String?;
      return _json({
        'data': {'template': body?['html']},
      });
    }
    if (rest.endsWith('/signature') && request.method == 'GET') {
      return _json({
        'data': {
          'address': rest.split('/')[2],
          'current': '<div>Pat<br>Sent from my phone</div>',
          'own': _own[rest.split('/')[2]],
          'preview': '<b>Pat Lin</b><br>Technician',
        },
      });
    }
    if (rest.endsWith('/signature')) {
      _own[rest.split('/')[2]] = body?['html'] as String?;
      return _json({
        'data': {'has_own_signature': body?['html'] != null},
      });
    }
    if (rest == '/signatures/preview') {
      return _json({
        'data': {
          'address': body?['address'],
          'html':
              // Tables, as the builder makes them: the renderer once drew none.
              '<table><tr><td><b>Dana Reyes</b></td></tr></table>',
        },
      });
    }
    if (rest.startsWith('/signature-templates') ||
        rest == '/signature-default' ||
        rest == '/signatures/assign') {
      return _json({
        'data': {'id': 't-new'},
      }, request.method == 'POST' ? 201 : 200);
    }
    if (rest == '/signatures/apply') {
      final results = [
        for (final a in (body?['addresses'] as List).cast<String>())
          a.startsWith('pat@')
              ? {
                  'address': a,
                  'ok': false,
                  'message': 'Google refused the change for this address.',
                }
              : {'address': a, 'ok': true, 'message': null},
      ];
      return _json({
        'data': {
          'set': results.where((r) => r['ok'] == true).length,
          'results': results,
        },
      });
    }
    return _json({'data': <Object>[]});
  };
}

Future<void> _pump(
  WidgetTester tester,
  _Server server, {
  Size size = const Size(1400, 1000),
  List<String> permissions = const [
    'email.view',
    'email.workspace.view',
    'email.edit',
  ],
  List<Uri>? opened,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(server.handler(permissions)));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: WorkspaceTab(
          api: api,
          session: session,
          openUrl: (uri) async => opened?.add(uri),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  // The first preview waits 400 ms.
  await tester.pump(const Duration(milliseconds: 450));
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder.first);
  await tester.pumpAndSettle();
  await tester.tap(finder.first, warnIfMissed: false);
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Robin Hale',
  'email': 'robin@brightside.example',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'modules': [
    {'key': 'email', 'label': 'Email', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Home Services',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
  'companies': [
    {
      'id': 'co-1',
      'name': 'Brightside Home Services',
      'parent_company_id': null,
    },
  ],
  'franchises': <Object>[],
};
