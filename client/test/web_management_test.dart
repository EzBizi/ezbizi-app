import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/web/seo_helper_pane.dart';
import 'package:ezbizi_client/screens/web/seo_tab.dart';
import 'package:ezbizi_client/widgets/address_lookup.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management: the tab opens the SEO panel, and the panel obeys the rules.
///
/// Web Management left the coming-soon set on 2026-08-28, so the two tests that
/// asserted it drew a placeholder moved here and became tests of what it draws
/// instead.
///
/// Two of these are about house rules rather than about websites: §6 forbids
/// pop-ups, and §3 says a screen files on the VIEW rather than on reach. Both
/// are the kind of rule that passes review by inspection and then regresses, so
/// each has a test that fails when it does.
void main() {
  testWidgets('the tab opens the SEO panel and lists the company websites', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('SEO'), findsOneWidget);
    // No second headline under the module's own title (UI/UX, 2026-09-28).
    expect(find.text('Websites'), findsNothing);
    expect(find.byKey(const ValueKey('seo-sections')), findsOneWidget);
    // The selected host appears twice by design — once in the rail, once as
    // the detail heading. That is the split working, not a duplicate.
    expect(find.text('a-1chimney.com'), findsWidgets);
    expect(find.text('nj.a-1chimney.com'), findsOneWidget);
  });

  testWidgets('a subdomain is listed as its own website, connected or not', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.textContaining('Connected'), findsWidgets);
    expect(find.textContaining('Waiting for the plugin'), findsWidgets);
  });

  testWidgets('view alone cannot add a website', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('Add a website'), findsNothing);
  });

  testWidgets('opening a website shows its fields and their shortcodes', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    expect(find.text('Phone number'), findsOneWidget);
    expect(find.textContaining('[ezbizi_phone]'), findsOneWidget);
  });

  /*
   * The first-run deadlock, from the client's side.
   *
   * PHP encodes an empty associative array as `[]`, so a site with no values
   * answered with `"values":[]` where a populated one answered `{...}`. This
   * screen cast that field to a Map, the cast threw, and the throw escaped
   * before the loading flag was cleared — so the panel span forever. You could
   * not open it until the site had values and could not add values without
   * opening it. Every newly registered site hit it.
   *
   * The fixture above had always returned a POPULATED map, which is why the
   * suite was green while the feature was unusable. This serves the shape the
   * old server really sent.
   */
  testWidgets('a website with no values yet still opens', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      emptyValues: true,
    );

    // The panel is open and usable rather than spinning.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Phone number'), findsOneWidget);
  });

  /// The panel must SHOW the browser key, and must not let it be confused with
  /// the plugin token.
  ///
  /// This test exists because of a real incident: the public key shipped in the
  /// API with no UI behind it, so the only credential this screen displayed was
  /// the secret `ezb_` token. Asked for the browser key, the only reasonable
  /// thing to hand over was the token — which is a screen defect, not a user
  /// mistake, and it put a live secret somewhere it should never have gone.
  testWidgets('a site offers its browser key, distinguished from the token', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    await tester.ensureVisible(find.text('Browser snippet'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Browser snippet'));
    await tester.pumpAndSettle();

    expect(find.textContaining('pk_fixture_browser_key'), findsWidgets);

    // The snippet points at the route that is actually served — public/ is not
    // a web root on this host, so /ezbizi.js at the domain root is not it.
    expect(find.textContaining('/api/v1/web/ezbizi.js'), findsOneWidget);

    // And it is ABSOLUTE. A relative src resolves against whatever site the
    // snippet was pasted into, which is never this one.
    expect(find.textContaining('src="https://'), findsOneWidget);

    // And it says which key is safe in public, because that is the whole
    // distinction somebody has to get right.
    expect(find.textContaining('ezb_'), findsOneWidget);
  });

  /// The split has a narrow half, and it is the half most likely to rot.
  ///
  /// The old one-column screen worked at every width by construction. A split
  /// does not: below the breakpoint the rail becomes the screen and the detail
  /// is PUSHED, which is a second code path nobody looks at on a desktop.
  testWidgets('on a phone the list is the screen and a site pushes', (
    tester,
  ) async {
    await _pumpSeoTab(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      size: const Size(390, 844),
    );

    // No detail beside the list at this width — just the list.
    expect(find.text('a-1chimney.com'), findsOneWidget);
    expect(find.text('Phone number'), findsNothing);

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    // Pushed a screen, not opened a modal.
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Phone number'), findsOneWidget);
  });

  testWidgets('the wide layout shows a site without being asked', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    // The first site is selected for you rather than leaving an empty pane.
    expect(find.text('Phone number'), findsOneWidget);
    expect(find.textContaining('[ezbizi_phone]'), findsOneWidget);
  });

  /// A section shows its own fields and nobody else's.
  ///
  /// The sections come from the server so that adding one costs a line in
  /// SeoFields and nothing in Dart. That only holds if the client filters on
  /// what the server sent rather than on a list of its own, which is what this
  /// pins: a field the fixture puts in `social` must be absent from General and
  /// present in Social links.
  testWidgets('a section shows only its own fields', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    expect(find.text('Phone number'), findsOneWidget);
    expect(find.text('Facebook'), findsNothing);

    await tester.tap(find.text('Social links'));
    await tester.pumpAndSettle();

    expect(find.text('Facebook'), findsOneWidget);
    expect(find.textContaining('[ezbizi_facebook]'), findsOneWidget);

    // General's fields are gone, and so is the address block that belongs to
    // them — a section that leaked its neighbour's fields would still pass the
    // line above.
    expect(find.text('Phone number'), findsNothing);
    expect(find.text('Address'), findsNothing);
  });

  /// One address, typed once.
  ///
  /// The five boxes each carry a shortcode, which is why this screen cannot
  /// just use the Settings widget wholesale — it shares the search half and
  /// keeps its own boxes. Picking a suggestion must fill them, or the split
  /// address is five things to type instead of one.
  testWidgets('picking a full address fills the parts', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    final search = find.descendant(
      of: find.byType(AddressLookup),
      matching: find.byType(TextField),
    );

    await tester.ensureVisible(search);
    await tester.pumpAndSettle();
    await tester.enterText(search, '1 Elm');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    await tester.tap(find.text('1 Elm St, Newark, NJ 07102'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, '1 Elm St'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Newark'), findsOneWidget);
    expect(find.widgetWithText(TextField, '07102'), findsOneWidget);
  });

  /// Pages and Posts are sections of SEO, not tabs beside it.
  ///
  /// They shipped as top-level tabs for one commit; Jose moved them on
  /// 2026-09-15. Asserted on the SERVER's own section list too, because these
  /// two are appended by the client — the server's sections are groups of
  /// fields and these are not fields.
  testWidgets('pages and posts sit in the SEO strip', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('General'), findsOneWidget);
    expect(find.text('Social links'), findsOneWidget);
    expect(find.text('Pages'), findsOneWidget);
    expect(find.text('Posts'), findsOneWidget);

    /*
     * Cities, beside Pages, since 2026-09-18. It is a PANE and not a tab on
     * purpose: it mints no permission, because a key per pane produces a roles
     * editor nobody can read.
     */
    expect(find.text('Cities'), findsOneWidget);
  });

  /// Cities in the strip reaches the Cities pane and not the Pages one.
  ///
  /// The two panes read different endpoints and the same page must never come
  /// back from both, so the wiring is worth a line here even though the pane's
  /// own behaviour is pinned in `web_cities_test.dart`. Asserted through the
  /// pane's empty state, which only the cities endpoint can produce.
  testWidgets('the Cities section opens the Cities pane', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    await tester.tap(find.text('Cities'));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('publishes nothing about a city'),
      findsOneWidget,
    );
  });

  /// SEO has sections now, and General is the one that exists.
  testWidgets('SEO opens on its General section', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('General'), findsOneWidget);
  });

  /// An inherited value must be VISIBLE, or the screen lies by omission.
  ///
  /// On a branch site most boxes are legitimately empty because the primary
  /// answers for them. An empty box that publishes a phone number would have
  /// somebody clear a field, watch it stay blank, and find the old number still
  /// on their website. So the field shows what it will publish and says where
  /// it came from.
  /// Jose, 2026-09-25: "add a counter ... on the sidebar which shows the
  /// pages with the amount of missing fields". The branch has none missing,
  /// so it carries no badge at all rather than a zero.
  testWidgets('each website shows how many fields it is missing', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.byTooltip('4 general fields not filled in'), findsOneWidget);
    expect(find.byTooltip('0 general fields not filled in'), findsNothing);
  });

  /// "It's still hard to differentiate what is a filled field vs what is a
  /// placeholder." An empty field says so in words; a filled one does not.
  testWidgets('an empty field says it is not filled in', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    // Company name is empty and inherits nothing; the phone has a value.
    final companyName = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Company name'),
    );
    final phone = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Phone number'),
    );

    expect(companyName.decoration?.hintText, 'Not filled in');
    expect(companyName.decoration?.filled, isTrue);
    expect(phone.decoration?.hintText, isNull);
    expect(phone.decoration?.filled, isFalse);
    expect(
      companyName.decoration?.floatingLabelBehavior,
      FloatingLabelBehavior.always,
    );
  });

  testWidgets('an inherited value is not marked missing', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      emptyValues: true,
    );

    final companyName = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Company name'),
    );

    expect(companyName.decoration?.hintText, 'A-1 Chimney');
    expect(companyName.decoration?.filled, isFalse);
  });

  testWidgets('a blank field says what it inherits and from where', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      emptyValues: true,
    );

    // The fixture's branch has nothing of its own and inherits two values.
    expect(find.textContaining('From '), findsWidgets);
  });

  /// Hours is a grid, not a box. Jose asked for pickers and closed switches,
  /// and a text field would let somebody type a string that renders on a
  /// public page with a typo in it.
  testWidgets('hours is seven days of controls, never a line of text', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    expect(find.text('Monday'), findsOneWidget);
    expect(find.text('Sunday'), findsOneWidget);

    // One switch per day, and nothing to type into.
    expect(find.byType(Switch), findsNWidgets(7));
  });

  /// Design 26: the several-websites editor opens from under the hours, and
  /// only for somebody who may change them.
  testWidgets('hours offers the several-websites editor to an editor', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    final open = find.byKey(const Key('seo-bulk-hours'));
    await tester.ensureVisible(open);
    await tester.pumpAndSettle();
    await tester.tap(open);
    await tester.pumpAndSettle();

    expect(find.text('Hours on several websites'), findsOneWidget);
    expect(find.text('Which places'), findsOneWidget);

    // In the tab, beside the app's sidebar: a pushed full-screen page broke
    // containment the day it shipped.
    expect(find.byType(NavigationSidebar), findsOneWidget);

    await tester.tap(find.byTooltip('Back to the website'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('seo-bulk-hours')), findsOneWidget);
  });

  /// Rodrigo, 2026-09-29: an hours Save on aus.a-1chimney.com was refused with
  /// "This build does not publish about_us_careers_field_1, …" — General sent
  /// every page's text back as site-wide.
  testWidgets('a General save sends only General fields, and offers a zone', (
    tester,
  ) async {
    _puts.clear();
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    expect(find.byKey(const Key('seo-hours-zone')), findsOneWidget);

    final save = find.widgetWithText(FilledButton, 'Save');
    await tester.ensureVisible(save);
    await tester.pumpAndSettle();
    await tester.tap(save);
    await tester.pumpAndSettle();

    final values = _puts.last['values'] as Map<String, dynamic>;
    expect(values.keys, contains('hours'));
    expect(values.keys, isNot(contains('about_us_field_1')));
    expect(values.keys, isNot(contains('template_1')));
    expect(_puts.last.containsKey('hours_timezone'), isFalse);
  });

  /// Jose, 2026-10-05: a Branch picker per website, its own company's only.
  testWidgets('a website picks its branch, from its own company only', (
    tester,
  ) async {
    _puts.clear();
    _patches.clear();
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    final picker = find.byKey(const Key('seo-branch'));
    await tester.ensureVisible(picker);
    await tester.pumpAndSettle();
    await tester.tap(picker);
    await tester.pumpAndSettle();

    expect(find.text('Everdoors North'), findsNothing);
    // Jose 11-16: the null choice reads as the company's own level, not as a gap.
    expect(find.text('Company Level (A-1 Chimney)'), findsWidgets);
    // "Company · Branch", like every branch picker.
    await tester.tap(find.text('A-1 Chimney · A1 - Chimney MA').last);
    await tester.pumpAndSettle();

    expect(_patches.last, {'franchise_id': 'fr-ma'});
  });

  testWidgets('on a phone the editor opens in the tab, not behind the site', (
    tester,
  ) async {
    await _pumpSeoTab(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      size: const Size(390, 844),
    );

    // A phone pushes the site's own page first.
    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();
    final open = find.byKey(const Key('seo-bulk-hours'));
    await tester.ensureVisible(open);
    await tester.pumpAndSettle();
    await tester.tap(open);
    await tester.pumpAndSettle();

    // That page is gone, so the editor is what is on screen.
    expect(find.byType(AppBar), findsNothing);
    expect(find.text('Which places'), findsOneWidget);
  });

  // Design 29: the SEO Helper is a section of the SEO strip, and opens in the
  // pane beside the websites.
  testWidgets('the SEO strip has the SEO Helper, and it opens in the pane', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    final section = find.descendant(
      of: find.byKey(const ValueKey('seo-sections')),
      matching: find.text('SEO Helper'),
    );
    await tester.ensureVisible(section);
    await tester.pumpAndSettle();
    await tester.tap(section);
    await tester.pumpAndSettle();

    expect(find.byType(SeoHelperPane), findsOneWidget);
    expect(find.byType(NavigationSidebar), findsOneWidget);
  });

  testWidgets('view alone is not offered the several-websites editor', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('Monday'), findsOneWidget);
    expect(find.byKey(const Key('seo-bulk-hours')), findsNothing);
  });

  /// Choosing the primary changes what every OTHER site publishes, so it is
  /// gated on edit like any other change to a value.
  testWidgets('view alone cannot make a site primary', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('Make primary'), findsNothing);
  });

  /// The plugin download must point at the directory, not at our own box.
  ///
  /// `/web/plugin` builds a zip from whatever release is deployed, so it lags
  /// every plugin release: Jose downloaded 1.0.0 from a button sitting beside a
  /// panel that described 1.0.2. wordpress.org serves the stable tag and is
  /// current the moment an SVN release lands.
  test('the plugin comes from wordpress.org, not from us', () {
    expect(pluginDownloadUrl.host, 'downloads.wordpress.org');
    expect(pluginDownloadUrl.scheme, 'https');
    expect(pluginDownloadUrl.path, contains('ezbizi'));
  });

  /// The pasted snippet must carry an ABSOLUTE url.
  ///
  /// It shipped relative — `src="/api/v1/web/ezbizi.js"` — because the
  /// same-origin web build leaves ApiClient.baseUrl empty on purpose. That
  /// resolves against whichever site the snippet was pasted into, so it 404d
  /// on www.ezbizi.com and worked only on app.ezbizi.com, the single host that
  /// will never need it. Verified against production at the time: www gave 404,
  /// app gave 200 application/javascript.
  group('the snippet url a customer pastes', () {
    test('uses the configured base when there is one', () {
      expect(
        snippetApiBase(
          configured: 'https://app.ezbizi.com',
          pageUrl: Uri.parse('https://irrelevant.example/'),
        ),
        'https://app.ezbizi.com',
      );
    });

    test('falls back to the origin serving the app, never to nothing', () {
      expect(
        snippetApiBase(
          configured: '',
          pageUrl: Uri.parse('https://app.ezbizi.com/web_management'),
        ),
        'https://app.ezbizi.com',
      );
    });

    test('never returns a relative base, even off the web', () {
      // A `file:` Uri has no origin and would throw; a blank result here is
      // what produced the original bug.
      final base = snippetApiBase(
        configured: '',
        pageUrl: Uri.parse('file:///C:/build/app'),
      );

      expect(base, isNotEmpty);
      expect(base, startsWith('https://'));
    });
  });

  /// The browser key must be rotatable FROM THE SCREEN, not only from the API.
  ///
  /// The doc promised the public key "rotates independently" before any
  /// endpoint existed; the endpoint was then built and still had no control
  /// behind it. Same defect, one layer up — a capability nobody can reach is
  /// indistinguishable from one that does not exist.
  testWidgets('a browser key can be reissued, inline and never in a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    await tester.ensureVisible(find.text('Browser snippet'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Browser snippet'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Issue a new browser key'));
    await tester.pumpAndSettle();

    // The panel closed on the way out: the confirmation is drawn on the page
    // behind it, and leaving the panel up would hide the question being asked.
    expect(find.text('Browser snippet'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.textContaining('Issue a new browser key?'), findsOneWidget);
    expect(find.text('Cancel'), findsOneWidget);
  });

  testWidgets('view alone cannot reissue a browser key', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    await tester.ensureVisible(find.text('Browser snippet'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Browser snippet'));
    await tester.pumpAndSettle();

    // The key itself is readable — proving the panel opened, so the absence
    // below is the button missing rather than the panel never appearing.
    expect(find.textContaining('pk_fixture_browser_key'), findsWidgets);
    expect(find.text('Issue a new browser key'), findsNothing);
  });

  /// The reference material is behind a button, not on the page.
  ///
  /// Five numbered steps and a script tag used to sit open above and below the
  /// fields somebody came here to edit. They are read once per website and
  /// never again.
  testWidgets('the guide and the snippet are behind buttons', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    expect(find.text('Connection guide'), findsOneWidget);
    expect(find.textContaining('Get the EzBizi plugin'), findsNothing);
    expect(find.textContaining('/api/v1/web/ezbizi.js'), findsNothing);

    await tester.ensureVisible(find.text('Connection guide'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Connection guide'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Get the EzBizi plugin'), findsOneWidget);
  });

  // §6 — no pop-ups, ever. Adding is a form in place, not a dialog.
  testWidgets('adding a website opens a form in place and never a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    await tester.tap(find.text('Add a website'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Website address'), findsOneWidget);
  });

  // §6 again — removing asks in a row inside the card, not in a dialog.
  testWidgets('removing a website confirms inline and never in a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
    );

    // The actions sit below eight fields in a scrolling pane now, so they have
    // to be brought into view before they can be tapped.
    await tester.ensureVisible(find.text('Remove website'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove website'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.textContaining('Remove a-1chimney.com?'), findsOneWidget);
  });

  /// §3 — file on the view, not on reach.
  ///
  /// The `/me` payload here really does carry a second company the person can
  /// reach, and the view really is scoped to the first. The load-bearing part
  /// is that the sibling is PRESENT and still never drawn: a fixture with one
  /// company would pass this test while the screen looped over raw reach.
  testWidgets('a reachable sibling company is not offered under a company view', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      scopedToCompany: true,
    );

    await tester.tap(find.text('Add a website'));
    await tester.pumpAndSettle();

    // The form really did open — without this, the two findsNothing below
    // would pass just as happily against a screen that rendered nothing at all.
    expect(find.text('Website address'), findsOneWidget);

    expect(find.text('Everdoors Roofing'), findsNothing);
    // One company in view leaves no choice to offer, so no picker is drawn.
    expect(find.text('Company'), findsNothing);
  });
}

/// Pump the SEO tab on its own, at a chosen size.
///
/// `_pumpDestination` goes through HomeShell, which replaces NavigationSidebar
/// with a different navigation on a phone — so a narrow test driven through the
/// shell ends up testing the shell. This mounts the tab directly, which is what
/// the narrow-layout tests are actually about.
Future<void> _pumpSeoTab(
  WidgetTester tester, {
  required List<String> permissions,
  required Size size,
  bool emptyValues = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(_respond(permissions, false, emptyValues)),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SeoTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpDestination(
  WidgetTester tester, {
  required List<String> permissions,
  bool scopedToCompany = false,
  bool emptyValues = false,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(_respond(permissions, scopedToCompany, emptyValues)),
  );
  final session = Session(api);

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

  await tester.tap(
    find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Web Management'),
    ),
  );
  await tester.pumpAndSettle();
}

Future<http.Response> Function(http.Request) _respond(
  List<String> permissions,
  bool scopedToCompany,
  bool emptyValues,
) {
  return (request) async {
    Map<String, Object?>? body;

    /*
     * Matched on the WHOLE path, not on a suffix. A suffix match passed
     * happily while the screen was asking for `/api/v1/api/v1/web/sites` —
     * ApiClient already prefixes `/api/v1`, and every path in this module was
     * doubled. A mock that cannot tell those apart is not a test of anything.
     */
    final path = request.url.path;
    if (request.method == 'PUT') _puts.add(jsonDecode(request.body) as Map<String, dynamic>);
    if (request.method == 'PATCH') {
      _patches.add(jsonDecode(request.body) as Map<String, dynamic>);
      return http.Response(jsonEncode({'data': _sites.first}), 200,
          headers: {'content-type': 'application/json'});
    }

    if (path == '/api/v1/me') {
      body = {'data': _me(permissions, scopedToCompany)};
    } else if (path == '/api/v1/address-lookup') {
      body = {
        'data': [
          {
            'label': '1 Elm St, Newark, NJ 07102',
            'address_line1': '1 Elm St',
            'city': 'Newark',
            'state': 'NJ',
            'postal_code': '07102',
          },
        ],
      };
    } else if (path == '/api/v1/web/sites') {
      body = {
        'data': _sites,
        'sections': const [
          {'key': 'general', 'label': 'General'},
          {'key': 'social', 'label': 'Social links'},
        ],
      };
    } else if (RegExp(r'^/api/v1/web/sites/[^/]+/cities$').hasMatch(path)) {
      // Its own endpoint, so that a page cannot be answered by both this and
      // the pages list. Empty here — the pane's own behaviour is pinned in
      // `web_cities_test.dart`; this fixture only has to let it mount.
      body = {'cities': const <Object?>[]};
    } else if (RegExp(r'^/api/v1/web/sites/[^/]+/values$').hasMatch(path)) {
      body = {
        'data': {
          'fields': [
            {
              'key': 'facebook',
              'label': 'Facebook',
              'hint': 'The full link to the company page on Facebook.',
              'shortcode': '[ezbizi_facebook]',
              'section': 'social',
            },
            {
              'key': 'phone',
              'label': 'Phone number',
              'hint': 'The number this site should show.',
              'shortcode': '[ezbizi_phone]',
            },
            {
              'key': 'company_name',
              'label': 'Company name',
              'hint': '',
              'shortcode': '[ezbizi_company_name]',
            },
            {
              'key': 'address_line1',
              'label': 'Street address',
              'hint': '',
              'shortcode': '[ezbizi_address_line1]',
            },
            {
              'key': 'city',
              'label': 'City',
              'hint': '',
              'shortcode': '[ezbizi_city]',
            },
            {
              'key': 'state',
              'label': 'State',
              'hint': '',
              'shortcode': '[ezbizi_state]',
            },
            {
              'key': 'postal_code',
              'label': 'ZIP',
              'hint': '',
              'shortcode': '[ezbizi_postal_code]',
            },
            {
              'key': 'hours',
              'label': 'Hours of operation',
              'hint': '',
              'shortcode': '[ezbizi_hours]',
            },
            // A page's text and a template: other screens' fields, which a
            // General save must never send back as site-wide.
            {
              'key': 'about_us_field_1',
              'label': 'About us — field 1',
              'hint': '',
              'shortcode': '[ezbizi_about_us_field_1]',
              'section': 'page',
              'path': '/about-us',
            },
            {
              'key': 'template_1',
              'label': 'Template 1',
              'hint': '',
              'shortcode': '[ezbizi_template_1]',
              'section': 'template',
            },
          ],
          // An empty map arrives from PHP as `[]`, not `{}` — the shape that
          // used to deadlock this panel. Serving the real thing is the only
          // way this test means anything.
          'values': emptyValues ? <Object?>[] : {'phone': '555-0100'},
          'pages': emptyValues ? <Object?>[] : <String, Object?>{},
          // What this site would publish if it said nothing. A branch usually
          // has more of these than answers of its own.
          'inherited': emptyValues
              ? {'phone': '555-0100', 'company_name': 'A-1 Chimney'}
              : <String, Object?>{},
        },
      };
    }

    return http.Response(
      jsonEncode(body ?? {'message': 'Unexpected $path'}),
      body == null ? 500 : 200,
      headers: {'content-type': 'application/json'},
    );
  };
}

/// Every PUT body the screen sent, newest last.
final List<Map<String, dynamic>> _puts = [];

/// Every PATCH body the screen sent, newest last.
final List<Map<String, dynamic>> _patches = [];

const List<Map<String, Object?>> _sites = [
  {
    'id': 'site-1',
    'host': 'a-1chimney.com',
    'label': null,
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
    'connected_at': '2026-08-28T10:00:00+00:00',
    'last_seen_at': '2026-08-28T11:00:00+00:00',
    'plugin_version': '1.0.0',
    'public_key': 'pk_fixture_browser_key',
    'missing': 4,
  },
  {
    'id': 'site-2',
    'host': 'nj.a-1chimney.com',
    'label': 'New Jersey',
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
    'connected_at': null,
    'last_seen_at': null,
    'plugin_version': null,
    'public_key': 'pk_fixture_branch_key',
  },
];

Map<String, Object?> _me(List<String> permissions, bool scopedToCompany) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': permissions,
  'modules': [
    {
      'key': 'web_management',
      'label': 'Web Management',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': scopedToCompany
      ? {
          'type': 'company',
          'id': 'co-1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney Group', 'A-1 Chimney'],
        }
      : {
          'type': 'organization',
          'id': 'org-1',
          'label': 'A-1 Chimney Group',
          'path': ['A-1 Chimney Group'],
        },
  // Two top-level companies, both reachable. The view names one of them.
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
    {'id': 'co-2', 'name': 'Everdoors Roofing', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[
    {'id': 'fr-ma', 'name': 'A1 - Chimney MA', 'company_id': 'co-1'},
    // Another company's branch: never offered for an A-1 Chimney website.
    {'id': 'fr-ev', 'name': 'Everdoors North', 'company_id': 'co-2'},
  ],
};
