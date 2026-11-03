import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/fleet_screen.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Fleet is a register, not a placeholder: list, 25/75 add, no modal.
void main() {
  // General (Mia, 2026-10-02): the module opens on it, its figures are the
  // server's, and a figure opens the register filtered to it.
  Map<String, dynamic> fleetSummary({bool money = true}) => {
    'health': {
      'empty': false,
      'vehicles': 24,
      'active': 20,
      'in_shop': 3,
      'out_of_service': 1,
      'owned': 22,
      'rented': 2,
      'unassigned': 21,
      'links': {
        'vehicles': '',
        'active': 'status=active',
        'in_shop': 'status=in_shop',
        'out_of_service': 'status=out_of_service',
        'owned': 'ownership=owned',
        'rented': 'ownership=rented',
        'unassigned': 'unassigned=1',
      },
    },
    'warnings': [
      {
        'key': 'service_overdue',
        'count': 2,
        'sentence': '2 vehicles are past their service date or mileage.',
        'link': 'due_soon=1',
      },
    ],
    if (money)
      'spending': {
        'period': 'month',
        'currency': 'USD',
        'total_minor': 125000,
        'categories': [
          {
            'key': 'maintenance',
            'label': 'Maintenance',
            'amount_minor': 125000,
          },
        ],
        'buckets': ['2026-09-28'],
        'points': [125000],
      },
  };

  testWidgets('Fleet opens on General with the server\'s figures', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      stayOnGeneral: true,
      onRequest: (request) => request.url.path.endsWith('/vehicles/summary')
          ? _json(fleetSummary())
          : null,
    );

    expect(find.byKey(const Key('fleet-general-vehicles')), findsOneWidget);
    expect(
      find.text('2 vehicles are past their service date or mileage.'),
      findsOneWidget,
    );
    expect(find.text('\$1,250.00'), findsWidgets);
  });

  testWidgets('without financials Fleet General has no spending block', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      stayOnGeneral: true,
      onRequest: (request) => request.url.path.endsWith('/vehicles/summary')
          ? _json(fleetSummary(money: false))
          : null,
    );

    expect(find.byKey(const Key('fleet-general-spending')), findsNothing);
    expect(find.text('Needs attention'), findsOneWidget);
  });

  testWidgets('the No insurance chip asks the register for uninsured', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) asked.add(request.url);
        return null;
      },
    );

    await tester.tap(find.text('No insurance'));
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters['uninsured'], '1');
  });

  testWidgets('the Insurance expiring chip asks for insurance_expiring', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) asked.add(request.url);
        return null;
      },
    );

    await tester.tap(find.text('Insurance expiring'));
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters['insurance_expiring'], '1');
  });

  testWidgets('In shop on General opens the register filtered to it', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pumpScreen(
      tester,
      stayOnGeneral: true,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles/summary')) {
          return _json(fleetSummary());
        }
        if (request.url.path.endsWith('/vehicles')) asked.add(request.url);
        return null;
      },
    );

    await tester.tap(find.byKey(const Key('fleet-general-in-shop')));
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters['status'], 'in_shop');
    expect(find.text('Add vehicle'), findsWidgets);
  });
  testWidgets('a granted Fleet tab is the register, not Coming soon', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(FleetScreen), findsOneWidget);
    expect(find.byType(TextField), findsWidgets);
    expect(find.text('Add vehicle'), findsOneWidget);

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('search is on the list, and Add opens the ownership card', (
    tester,
  ) async {
    await _pumpScreen(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(
      find.widgetWithText(TextField, 'Name, plate, VIN, number or driver'),
      findsOneWidget,
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(find.text('New vehicle'), findsOneWidget);
    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.text('Owned'), findsWidgets);
    expect(find.text('Rented'), findsWidgets);
    expect(find.text('Bought from'), findsNothing);
    expect(find.text('Rented from'), findsNothing);
    expect(find.text('Started on'), findsNothing);
    expect(find.text('Save'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await tester.ensureVisible(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.pumpAndSettle();

    expect(find.text('Bought from'), findsOneWidget);
    expect(find.text('Started on'), findsOneWidget);
  });

  testWidgets('Add dates follow the organization date format', (tester) async {
    await _pumpScreen(
      tester,
      me: {
        'display': {'date_format': 'D/M/YYYY', 'time_format': '24h'},
      },
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    final order = tester
        .widgetList<TextField>(
          find.descendant(
            of: find.byType(CalendarDayField).first,
            matching: find.byType(TextField),
          ),
        )
        .map((f) => f.decoration?.labelText)
        .toList();

    expect(order.take(3), ['Day', 'Month', 'Year']);
  });

  testWidgets('a failed load is the request failed, not an empty list', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('There are no vehicles.'), findsNothing);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('an empty success says there are no vehicles', (tester) async {
    await _pumpScreen(tester);

    expect(find.text('There are no vehicles.'), findsOneWidget);
    expect(find.text('Add vehicle'), findsOneWidget);
  });

  testWidgets('Add is not drawn without create', (tester) async {
    await _pumpScreen(
      tester,
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
      ],
    );

    expect(find.text('Add vehicle'), findsNothing);
    expect(find.text('Assign'), findsNothing);
  });

  testWidgets('Assign is not drawn without assign', (tester) async {
    await _pumpScreen(
      tester,
      vehicles: [_van],
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
        'fleet_management.create',
        'fleet_management.edit',
      ],
    );

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('Assign'), findsNothing);
  });

  testWidgets('at 1280 a selected vehicle keeps the list at 25%', (
    tester,
  ) async {
    await _pumpScreen(tester, vehicles: [_van], size: const Size(1280, 900));

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('2022 Ford Transit · ABC-1234'), findsWidgets);
    expect(find.text('There are no vehicles.'), findsNothing);
    expect(find.byTooltip('Back to the list'), findsNothing);

    final list = tester.getRect(find.byKey(const Key('fleet-list')));
    final pane = tester.getRect(find.byKey(const Key('fleet-pane')));
    expect(pane.width, closeTo(list.width * 3, 8));
  });

  testWidgets('at 375 the pane is the whole body, with Back', (tester) async {
    await _pumpScreen(tester, size: const Size(375, 800));

    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.text('Add vehicle'));
    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.byKey(const Key('fleet-list')), findsNothing);
    expect(find.byTooltip('Back to the list'), findsOneWidget);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsNothing);
    expect(find.byKey(const Key('fleet-list')), findsOneWidget);
  });

  // The note, search and nine chips used to stand over the rows and take
  // nearly half of a phone; only the rows below them scrolled.
  testWidgets('on a phone the search and chips scroll away with the rows', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      size: const Size(411, 800),
      vehicles: [
        for (var i = 0; i < 20; i++) {..._van, 'id': 'v-$i', 'label': 'Van $i'},
      ],
    );

    expect(find.byType(TextField), findsOneWidget);
    expect(find.text('Due soon'), findsOneWidget);

    // The chips now wrap past the first rows, so drag from a chip itself.
    await tester.drag(find.text('Due soon'), const Offset(0, -800));
    await tester.pumpAndSettle();

    // A finder skips what a viewport has scrolled out of view.
    expect(find.byType(TextField), findsNothing);
    expect(find.text('Due soon'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  // Records and forms are drawn inside the screen, each with its own arrow,
  // and Android's Back used to ignore the arrow and close the app.
  testWidgets(
    'on a phone Back closes the page inside the screen, not the app',
    (tester) async {
      final exits = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'SystemNavigator.pop') exits.add(call.method);
          return null;
        },
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          SystemChannels.platform,
          null,
        ),
      );

      tester.view.physicalSize = const Size(375, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      final api = ApiClient(client: MockClient(_handler()));
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
      await tester.tap(find.byTooltip('Open navigation menu'));
      await tester.pumpAndSettle();

      // Back with the drawer open closes the drawer.
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byType(Drawer), findsNothing);
      expect(exits, isEmpty);

      await tester.tap(find.byTooltip('Open navigation menu'));
      await tester.pumpAndSettle();
      await tester.tap(
        find.descendant(
          of: find.byType(Drawer),
          matching: find.text('Fleet Management'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Vehicles'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Add vehicle'));
      await tester.pumpAndSettle();
      expect(find.byTooltip('Back to the list'), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();

      expect(find.byTooltip('Back to the list'), findsNothing);
      expect(find.text('Add vehicle'), findsOneWidget);
      expect(exits, isEmpty);

      // Nothing left to go back from: now it leaves.
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(exits, ['SystemNavigator.pop']);
    },
  );

  testWidgets('Cancel leaves nothing', (tester) async {
    await _pumpScreen(tester);

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();
    expect(find.text('How do we hold it?'), findsOneWidget);

    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsNothing);
    expect(find.byKey(const Key('fleet-pane')), findsNothing);
  });

  testWidgets('Save posts identity and the first ownership period', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/vehicles')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              ..._van,
              'id': 'v-new',
              'label': '2022 Ford Transit · NEW-1',
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Year').first,
      '2022',
    );
    await tester.enterText(find.widgetWithText(TextField, 'Make'), 'Ford');
    await tester.enterText(find.widgetWithText(TextField, 'Model'), 'Transit');
    await tester.enterText(find.widgetWithText(TextField, 'Plate'), 'NEW-1');
    await tester.ensureVisible(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!.containsKey('company_id'), isFalse);
    expect(posted!['year'], 2022);
    expect(posted!['make'], 'Ford');
    expect(posted!['model'], 'Transit');
    expect(posted!['plate'], 'NEW-1');
    expect(posted!['ownership'], isA<Map>());
    expect((posted!['ownership'] as Map)['kind'], 'owned');
  });

  testWidgets('no status chip means the list is asked without status', (
    tester,
  ) async {
    final queries = <Map<String, String>>[];

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) {
          queries.add(request.url.queryParameters);
        }
        return null;
      },
    );

    expect(queries, isNotEmpty);
    expect(queries.first.containsKey('status'), isFalse);
    expect(find.widgetWithText(FilterChip, 'Owned'), findsOneWidget);
    expect(find.widgetWithText(FilterChip, 'Due soon'), findsOneWidget);
  });

  // Company is the FIRST field inside the add pane's scroll view, so its
  // floating label rises toward the view's top edge — the one place a clip can
  // cut it. That happened once and was fixed in 0873bf98 with two things: top
  // padding and Clip.none. c1d7401a removed Clip.none (it let the form slide
  // under the Save bar on a phone), leaving the padding as the only thing
  // keeping the label whole. Clipping is painted, so no finder can see it;
  // this measures the label's box against the viewport it is clipped to.
  testWidgets('the Company label stays inside the add pane on a phone', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      size: const Size(375, 812),
      me: {
        ..._me,
        'organization': {
          ..._me['organization'] as Map<String, dynamic>,
          'name': 'A-1 Group',
        },
        'scope': {
          'type': 'organization',
          'id': 'org-1',
          'label': 'A-1 Group',
          'path': ['A-1 Group'],
        },
        'companies': [
          {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
        ],
      },
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    final label = find.text('Company').first;
    final viewport = find
        .ancestor(of: label, matching: find.byType(SingleChildScrollView))
        .first;

    // Control: without both, the comparison below would compare nothing.
    expect(label, findsOneWidget);
    expect(viewport, findsOneWidget);

    final labelTop = tester.getTopLeft(label).dy;
    final viewportTop = tester.getTopLeft(viewport).dy;

    expect(
      labelTop,
      greaterThanOrEqualTo(viewportTop),
      reason: 'the Company label starts above the scroll view and is clipped',
    );
  });

  testWidgets('Add names General, not Company pool, and offers the group', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      me: {
        ..._me,
        'organization': {
          ..._me['organization'] as Map<String, dynamic>,
          'name': 'A-1 Group',
        },
        'scope': {
          'type': 'organization',
          'id': 'org-1',
          'label': 'A-1 Group',
          'path': ['A-1 Group'],
        },
        'companies': [
          {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {'id': 'co-2', 'name': 'Premier', 'parent_company_id': null},
        ],
      },
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(find.text('Company pool'), findsNothing);
    expect(find.text('A-1 Group'), findsWidgets);
    expect(find.textContaining('General'), findsWidgets);

    await tester.tap(find.byType(SearchPickerField<String>).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('A-1 Chimney').last);
    await tester.pumpAndSettle();

    expect(find.text('General'), findsWidgets);
    expect(find.text('Company pool'), findsNothing);
  });
  // A sibling company is really in /me here, so findsNothing proves the picker
  // filters by view rather than proving the fixture was empty. Scope is a
  // parent company so `offersCompanyChoice` is true and the field is drawn:
  // a hidden field would pass this test without filtering anything.
  testWidgets('a company view does not offer a sibling company on Add', (
    tester,
  ) async {
    await _pumpScreen(tester, me: _companyView);

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    await tester.tap(find.byType(SearchPickerField<String>).first);
    await tester.pumpAndSettle();

    expect(find.text('Everdoors'), findsWidgets);
    expect(find.text('Premier Garage Repair'), findsWidgets);
    expect(find.text('A-1 Chimney'), findsNothing);
    expect(find.text('A-1 Group'), findsNothing);
  });

  testWidgets('a company view does not offer a sibling company on Identity', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
        'fleet_management.edit',
      ],
      vehicles: const [_subtreeVan],
      me: _companyView,
    );

    await tester.tap(find.text(_subtreeVan['label']! as String).first);
    await tester.pumpAndSettle();

    // Open it: a closed dropdown only builds its selected item, so asserting
    // on the collapsed field would pass against the very leak it claims.
    final picker = find.byKey(const ValueKey('company-co-ever'));
    await tester.ensureVisible(picker);
    await tester.pumpAndSettle();
    await tester.tap(picker);
    await tester.pumpAndSettle();

    expect(find.text('Everdoors'), findsWidgets);
    expect(find.text('Premier Garage Repair'), findsWidgets);
    expect(find.text('A-1 Chimney'), findsNothing);
    expect(find.text('A-1 Group'), findsNothing);
  });

  // ── Identity card, layout 2 ────────────────────────────────────────────
  //
  // The card is five grouped cards rather than one twenty-one field column.
  // The rules that are easy to break later: nothing hides because it is empty,
  // the fold is the reader's, and money stays behind financials.

  testWidgets('Identity is five groups, not one column', (tester) async {
    await _pumpIdentity(tester);

    expect(find.text('Identity'), findsOneWidget);
    expect(find.text('Plates & registration'), findsOneWidget);
    expect(find.text('Odometer & service'), findsOneWidget);
    expect(find.text('Tolls'), findsOneWidget);
    expect(find.text('Notes'), findsOneWidget);
  });

  testWidgets('an empty field is still drawn, because it has to be fillable', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    // Trim is empty on every one of A-1's 24 vehicles. A field that hid when
    // empty could never be filled in, so it stays and the group counts it.
    expect(find.widgetWithText(TextField, 'Trim'), findsOneWidget);
    expect(find.textContaining('of 9 filled'), findsOneWidget);
  });

  testWidgets('a state with no number reads as one incomplete plate', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    // The finding the redesign exists for: 24 vehicles carry a state and 9 a
    // number, so two separate fields showed "CT" beside an empty box.
    expect(find.text('Plate number missing'), findsOneWidget);
    expect(find.text('State and number are one plate.'), findsOneWidget);
  });

  testWidgets('Tolls starts folded when empty and stays open once opened', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    expect(find.text('Nothing recorded'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Toll plan'), findsNothing);

    final fold = find.text('Open to fill these in');
    await tester.ensureVisible(fold);
    await tester.pumpAndSettle();
    await tester.tap(fold);
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Toll plan'), findsOneWidget);
    expect(find.text('Open to fill these in'), findsNothing);
  });

  testWidgets('Toll flat rate is disabled without financials, not hidden', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    await tester.ensureVisible(find.text('Open to fill these in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open to fill these in'));
    await tester.pumpAndSettle();

    // A hidden money control reads as a missing value. It stays on screen and
    // says why, the way every other refused control in this app does.
    expect(find.text('Flat rate needs Financials'), findsOneWidget);
    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Toll flat rate'),
    );
    expect(field.enabled, isFalse);
  });

  testWidgets('with financials the same control is live', (tester) async {
    await _pumpIdentity(
      tester,
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
        'fleet_management.edit',
        'fleet_management.financials',
      ],
    );

    await tester.ensureVisible(find.text('Open to fill these in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open to fill these in'));
    await tester.pumpAndSettle();

    expect(find.text('Flat rate needs Financials'), findsNothing);
    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Toll flat rate'),
    );
    expect(field.enabled, isTrue);
  });

  // ── Missing-field highlights and counters (Analorena, 2026-10-01) ──────
  //
  // The look HR's record and Web Management's SEO already use: an expected
  // box that is empty reads amber "Not filled in", and the list row carries
  // the server's count of them. Copied, not reinvented.

  /// "Not filled in" inside the text box labelled [label].
  Finder amberText(String label) => find.descendant(
    of: find.widgetWithText(TextField, label),
    matching: find.text('Not filled in'),
  );

  /// "Not filled in" under the date field labelled [label].
  Finder amberDay(String label) => find.descendant(
    of: find.byWidgetPredicate(
      (w) => w is CalendarDayField && w.label == label,
    ),
    matching: find.text('Not filled in'),
  );

  testWidgets('expected empty fields read Not filled in; others do not', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    // Expected and empty on this van: amber.
    expect(amberText('Plate number'), findsOneWidget);
    expect(amberDay('Registration expires on'), findsOneWidget);

    // Expected and filled: not amber.
    expect(amberText('Colour'), findsNothing);
    expect(amberText('State'), findsNothing);

    // Empty but NOT expected — the case that proves the subset rule. Most
    // vans have no trim and many states never inspect; flagging them would
    // keep the count above zero for ever, which is HR's reason for a subset.
    expect(find.widgetWithText(TextField, 'Trim'), findsOneWidget);
    expect(amberText('Trim'), findsNothing);
    expect(
      find.byWidgetPredicate(
        (w) => w is CalendarDayField && w.label == 'Inspection expires on',
      ),
      findsOneWidget,
    );
    expect(amberDay('Inspection expires on'), findsNothing);
  });

  testWidgets('no next service at all is amber on both of its boxes', (
    tester,
  ) async {
    await _pumpIdentity(tester);

    expect(amberDay('Next service on'), findsOneWidget);
    expect(amberText('Next service at (miles)'), findsOneWidget);
  });

  testWidgets('a mileage alone answers next service: neither box is amber', (
    tester,
  ) async {
    await _pumpIdentity(
      tester,
      vehicle: {..._platedVan, 'next_service_miles': 15000},
    );

    // Control: the date box is drawn and empty, so absence of amber on it
    // is the rule, not a missing widget.
    expect(
      find.byWidgetPredicate(
        (w) => w is CalendarDayField && w.label == 'Next service on',
      ),
      findsOneWidget,
    );
    expect(amberDay('Next service on'), findsNothing);
    expect(amberText('Next service at (miles)'), findsNothing);
  });

  testWidgets('the list row carries the server count, and nothing at zero', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      vehicles: [
        {..._platedVan, 'id': 'v-gaps', 'missing': 4},
        {
          ..._platedVan,
          'id': 'v-full',
          'label': '2024 Ram ProMaster',
          'missing': 0,
        },
      ],
    );

    expect(find.byTooltip('4 fields not filled in'), findsOneWidget);
    expect(find.byTooltip('0 fields not filled in'), findsNothing);
    expect(find.byTooltip('1 field not filled in'), findsNothing);
  });

  // ── Every state, in sections (Jose, 2026-11-03) ───────────────────────

  testWidgets('the list opens on every state in sections; chips switch state', (
    tester,
  ) async {
    final asked = <String?>[];
    final ct = {
      ..._van,
      'id': 'v-ct',
      'label': 'Connecticut van',
      'franchise': {'id': 'fr-ct', 'name': 'Connecticut'},
    };
    final nj = {
      ..._van,
      'id': 'v-nj',
      'label': 'New Jersey van',
      'franchise': {'id': 'fr-nj', 'name': 'New Jersey'},
    };

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (!request.url.path.endsWith('/vehicles')) return null;
        final place = request.url.queryParameters['franchise_id'];
        asked.add(place);

        final rows = switch (place) {
          'fr-ct' => [ct],
          'fr-nj' => [nj],
          _ => [ct, nj],
        };

        return _json({
          'data': rows,
          'meta': {
            'total': rows.length,
            'last_page': 1,
            'places': [
              {'id': 'fr-ct', 'name': 'Connecticut', 'count': 1},
              {'id': 'fr-nj', 'name': 'New Jersey', 'count': 1},
            ],
          },
        });
      },
    );

    // One request, every state, a header per branch with its count.
    expect(asked, [null]);
    expect(find.text('Connecticut van'), findsOneWidget);
    expect(find.text('New Jersey van'), findsOneWidget);
    expect(find.text('Everdoors · Connecticut  1'), findsOneWidget);
    expect(find.text('Everdoors · New Jersey  1'), findsOneWidget);
    expect(find.text('Connecticut  1'), findsOneWidget);
    expect(find.text('All states  2'), findsOneWidget);

    await tester.tap(find.text('New Jersey  1'));
    await tester.pumpAndSettle();

    expect(asked.last, 'fr-nj');
    expect(find.text('New Jersey van'), findsOneWidget);
    expect(find.text('Connecticut van'), findsNothing);
    // One state open: no section headers.
    expect(find.text('Everdoors · New Jersey  1'), findsNothing);

    await tester.tap(find.text('All states  2'));
    await tester.pumpAndSettle();

    expect(asked.last, isNull);
    expect(find.text('Connecticut van'), findsOneWidget);
    expect(find.text('New Jersey van'), findsOneWidget);
  });

  testWidgets('a register in one state draws no state chips', (tester) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (!request.url.path.endsWith('/vehicles')) return null;
        return _json({
          'data': [_van],
          'meta': {
            'total': 1,
            'last_page': 1,
            'places': [
              {'id': 'fr-1', 'name': 'Connecticut', 'count': 1},
            ],
          },
        });
      },
    );

    expect(find.text('All states  1'), findsNothing);
    expect(find.text('Connecticut  1'), findsNothing);
    // Control: the list itself drew.
    expect(find.text(_van['label']! as String), findsOneWidget);
  });
}

const _van = {
  'id': 'v-1',
  'number': 'V-00001',
  'label': '2022 Ford Transit · ABC-1234',
  'year': 2022,
  'make': 'Ford',
  'model': 'Transit',
  'plate': 'ABC-1234',
  'vin': '1FTNE2CM5HKA12345',
  'status': 'active',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'ownership': {'kind': 'owned'},
  'assignment': null,
};

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler()));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

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

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text('Fleet Management'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();

  // General opens first now; these tests are about the register.
  await tester.tap(find.text('Vehicles'));
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.general.view',
    'fleet_management.vehicles.view',
    'fleet_management.create',
  ],
  List<Map<String, dynamic>> vehicles = const [],
  Map<String, dynamic>? me,
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
  bool stayOnGeneral = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        vehicles: vehicles,
        me: me,
        onRequest: onRequest,
      ),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FleetScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  if (stayOnGeneral) return;

  // General opens first now; these tests are about the register.
  await tester.tap(find.text('Vehicles'));
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.general.view',
    'fleet_management.vehicles.view',
    'fleet_management.create',
  ],
  List<Map<String, dynamic>> vehicles = const [],
  Map<String, dynamic>? me,
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, ...?me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/vehicles')) {
      return _json({
        'data': vehicles,
        'meta': {'total': vehicles.length, 'last_page': 1},
      });
    }

    if (RegExp(r'/vehicles/[^/]+$').hasMatch(path)) {
      final id = path.split('/').last;
      Map<String, dynamic>? match;
      for (final v in vehicles) {
        if (v['id'] == id) {
          match = v;
          break;
        }
      }
      match ??= vehicles.isEmpty ? null : vehicles.first;
      if (match != null) return _json({'data': match});
    }

    if (path.endsWith('/attachments')) {
      return _json({
        'data': <Map<String, dynamic>>[],
        'limits': {
          'max_kilobytes': 20480,
          'max_readable': '20 MB',
          'extensions': ['pdf', 'jpg', 'png'],
        },
      });
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'fleet_management',
      'label': 'Fleet Management',
      'available': true,
      'reachable': true,
    },
  ],
};

/// A company view of Everdoors, which has two Sub Companies, while A-1 Chimney
/// sits beside it in reach. The subtree is what may be filed on; the sibling is
/// the thing that must never appear.
const _companyView = {
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'company',
    'id': 'co-ever',
    'label': 'Everdoors',
    'path': ['A-1 Group', 'Everdoors'],
  },
  'companies': [
    {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
    {
      'id': 'co-premier',
      'name': 'Premier Garage Repair',
      'parent_company_id': 'co-ever',
    },
    {
      'id': 'co-speedy',
      'name': 'Speedy Garage Doors',
      'parent_company_id': 'co-ever',
    },
    {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-ever', 'name': 'Ohio', 'company_id': 'co-ever'},
    {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
  ],
};

const _subtreeVan = {
  'id': 'v-2',
  'number': 'V-00002',
  'label': '2021 Ram ProMaster · XYZ-9876',
  'year': 2021,
  'make': 'Ram',
  'model': 'ProMaster',
  'plate': 'XYZ-9876',
  'vin': '3C6TRVAG1ME512345',
  'status': 'active',
  'company': {'id': 'co-ever', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-ever', 'name': 'Ohio'},
  'ownership': {'kind': 'owned'},
  'assignment': null,
};

/// Open one vehicle and land on its Identity groups.
Future<void> _pumpIdentity(
  WidgetTester tester, {
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.general.view',
    'fleet_management.vehicles.view',
    'fleet_management.edit',
  ],
  Map<String, dynamic> vehicle = _platedVan,
}) async {
  await _pumpScreen(
    tester,
    permissions: permissions,
    vehicles: [vehicle],
    size: const Size(1280, 2400),
  );

  await tester.tap(find.text(vehicle['label']! as String).first);
  await tester.pumpAndSettle();
}

/// A state and no number, which is fifteen of A-1's twenty-four vehicles, and
/// no tolls at all, which is every one of them.
const _platedVan = {
  'id': 'v-3',
  'number': 'V-00019',
  'label': '2017 Toyota Tacoma',
  'year': 2017,
  'make': 'Toyota',
  'model': 'Tacoma',
  'trim': null,
  'plate': null,
  'plate_state': 'CT',
  'vin': '5TFAX5GN1HX091234',
  'colour': 'White',
  'status': 'active',
  'body_type': 'truck',
  'fuel_type': 'gas',
  'odometer_miles': 84210,
  'toll_device_number': null,
  'toll_plan': null,
  'notes': null,
  'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
  'franchise': {'id': 'fr-1', 'name': 'New Jersey'},
  'ownership': {'kind': 'owned'},
  'assignment': null,
};
