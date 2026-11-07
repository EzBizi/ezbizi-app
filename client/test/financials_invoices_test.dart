import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Financials › Invoices (design 128, options 1 and 4), against a stubbed
/// API. Every name and number is invented.
void main() {
  testWidgets('the Invoices tab is drawn only when the module is reachable', (
    tester,
  ) async {
    await _pump(tester, invoicesModule: false);
    expect(_hostTab('Invoices'), findsNothing);
    expect(_hostTab('Bills'), findsOneWidget);

    await _pump(tester);
    expect(_hostTab('Invoices'), findsOneWidget);
  });

  testWidgets('each sub-tab is its own tick', (tester) async {
    await _pump(
      tester,
      permissions: const ['invoices.view', 'invoices.invoices.view'],
    );
    await _openInvoices(tester);
    final strip = find.byKey(const ValueKey('tour-invoices-tabs'));
    expect(
      find.descendant(of: strip, matching: find.text('Charge sheets')),
      findsNothing,
    );
    expect(
      find.descendant(of: strip, matching: find.text('Settings')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('invoice-total-unpaid')), findsOneWidget);
  });

  testWidgets('the sheet grid totals each column, each row and the whole', (
    tester,
  ) async {
    await _pump(tester);
    await _openInvoices(tester);

    expect(find.text('Springfield · Brightside'), findsOneWidget);
    expect(_textOf(tester, 'sheet-total-m'), r'$6,100.00');
    expect(_textOf(tester, 'sheet-total-c'), r'$2,930.00');
    expect(_textOf(tester, 'sheet-total-s'), r'$1,200.00');
    expect(_textOf(tester, 'sheet-total-jobs'), '321');
    expect(_textOf(tester, 'sheet-total'), r'$10,230.00');
    expect(_textOf(tester, 'sheet-row-total-r4'), r'$0 · no invoice');

    // An invoiced row is read-only and links to its invoice.
    expect(find.byKey(const ValueKey('cell-r5-m')), findsNothing);
    expect(find.text('INV-0139 · Overdue'), findsNothing);
    expect(find.text('INV-0139 · Sent'), findsOneWidget);
  });

  testWidgets('a typed cell is saved after a pause, and the totals follow', (
    tester,
  ) async {
    final api = await _pump(tester);
    await _openInvoices(tester);

    await tester.enterText(find.byKey(const ValueKey('cell-r1-m')), r'$2,500');
    await tester.pump();
    expect(_textOf(tester, 'sheet-total-m'), r'$6,200.00');
    expect(api.sent('PATCH', '/invoices/sheets/sh1/rows'), isEmpty);

    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    final patch = api.sent('PATCH', '/invoices/sheets/sh1/rows').single;
    expect(patch['rows'], [
      {
        'id': 'r1',
        'cells': {'m': 250000},
      },
    ]);
    expect(_textOf(tester, 'sheet-saved'), startsWith('Saved '));
  });

  testWidgets('a refused edit shows the server sentence', (tester) async {
    await _pump(
      tester,
      refuseRows:
          'Springfield · Brightside is already invoiced. Void its invoice to change it.',
    );
    await _openInvoices(tester);
    await tester.enterText(find.byKey(const ValueKey('cell-r1-c')), '10');
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(
      _textOf(tester, 'sheet-note'),
      'Springfield · Brightside is already invoiced. Void its invoice to change it.',
    );
  });

  testWidgets(
    'Make invoices counts uninvoiced branches with money, then shows the invoices',
    (tester) async {
      final api = await _pump(tester);
      await _openInvoices(tester);

      // Springfield, Riverton, Oakdale: Lakeside is $0 and Hillcrest is invoiced.
      expect(find.text('Make invoices · 3 branches'), findsOneWidget);
      await tester.tap(find.text('Make invoices · 3 branches'));
      await tester.pumpAndSettle();

      expect(api.sent('POST', '/invoices/sheets/sh1/invoice'), hasLength(1));
      expect(
        find.text('Made 3 invoices. Lakeside · Brightside: nothing to bill.'),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('invoice-total-unpaid')),
        findsOneWidget,
      );
      expect(find.text('INV-0142'), findsOneWidget);
    },
  );

  testWidgets('the list shows totals and an overdue invoice as Overdue', (
    tester,
  ) async {
    await _pump(tester);
    await _openInvoices(tester, sub: 'Invoices');
    expect(_textOf(tester, 'invoice-total-sent'), r'$8,130.00');
    expect(_textOf(tester, 'invoice-total-unpaid'), r'$4,250.00');
    expect(_textOf(tester, 'invoice-total-overdue'), r'$1,610.00');
    expect(find.text('Overdue'), findsWidgets);
    expect(find.text('Brightside Oakdale'), findsOneWidget);
  });

  group('the invoice panel', () {
    testWidgets(
      'a draft: Approve for an approver, a sentence for anybody else',
      (tester) async {
        final api = await _pump(tester);
        await _openInvoice(tester, 'INV-0145');
        expect(find.byKey(const ValueKey('invoice-approve')), findsOneWidget);
        expect(find.byKey(const ValueKey('invoice-document')), findsOneWidget);
        expect(find.text('INVOICE'), findsOneWidget);
        // Lines are editable while a draft.
        expect(
          find.byKey(const ValueKey('invoice-line-0-amount')),
          findsOneWidget,
        );

        await tester.tap(find.byKey(const ValueKey('invoice-approve')));
        await tester.pumpAndSettle();
        expect(api.sent('POST', '/invoices/i5/approve'), hasLength(1));
        expect(find.byKey(const ValueKey('invoice-send')), findsOneWidget);
        expect(find.textContaining('Approved by Pat Lin'), findsOneWidget);

        await _pump(tester, canApprove: false);
        await _openInvoice(tester, 'INV-0145');
        expect(find.byKey(const ValueKey('invoice-approve')), findsNothing);
        expect(
          find.byKey(const ValueKey('invoice-who-approves')),
          findsOneWidget,
        );
        // Editing a draft is its own tick, apart from approving it.
        expect(
          find.byKey(const ValueKey('invoice-line-0-amount')),
          findsOneWidget,
        );
      },
    );

    testWidgets('a draft line edit PATCHes cents', (tester) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0145');
      await tester.enterText(
        find.byKey(const ValueKey('invoice-line-0-amount')),
        '2,450.25',
      );
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('invoice-lines-save')));
      await tester.pumpAndSettle();
      expect(api.sent('PATCH', '/invoices/i5').single, {
        'lines': [
          {'description': 'Marketing', 'cents': 245025},
        ],
      });
    });

    testWidgets('sent: Record payment is the next step, and it posts dollars', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0139');
      expect(find.byKey(const ValueKey('invoice-approve')), findsNothing);
      expect(find.byKey(const ValueKey('invoice-send')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('invoice-pay')));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('invoice-pay-amount')))
            .controller!
            .text,
        '1610.00',
      );
      await tester.enterText(
        find.byKey(const ValueKey('invoice-pay-amount')),
        '1,200.50',
      );
      await tester.tap(find.byKey(const ValueKey('invoice-pay-method-card')));
      await tester.enterText(
        find.byKey(const ValueKey('invoice-pay-reference')),
        'Auth 4471',
      );
      await tester.tap(find.byKey(const ValueKey('invoice-pay-confirm')));
      await tester.pumpAndSettle();

      final body = api.sent('POST', '/invoices/i3/payments').single;
      expect(body['amount'], 1200.5);
      expect(body['method'], 'card');
      expect(body['kind'], 'charge');
      expect(body['reference'], 'Auth 4471');
      expect(body['paid_on'], matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')));
    });

    testWidgets('without the ticks, no action buttons', (tester) async {
      await _pump(tester, viewerOnly: true);
      await _openInvoice(tester, 'INV-0142');
      expect(find.byKey(const ValueKey('invoice-send')), findsNothing);
      expect(find.byKey(const ValueKey('invoice-pay')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('invoice-more')));
      await tester.pumpAndSettle();
      expect(find.text('Download PDF'), findsOneWidget);
      expect(find.byKey(const ValueKey('invoice-more-void')), findsNothing);
    });

    testWidgets('Void asks for a reason, in the panel', (tester) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0142');
      await tester.tap(find.byKey(const ValueKey('invoice-more')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('invoice-more-void')));
      await tester.pumpAndSettle();
      expect(find.byType(Dialog), findsNothing);

      await tester.tap(find.byKey(const ValueKey('invoice-void-confirm')));
      await tester.pumpAndSettle();
      expect(_textOf(tester, 'invoice-error'), 'Say why it is void.');
      expect(api.sent('POST', '/invoices/i2/void'), isEmpty);

      await tester.enterText(
        find.byKey(const ValueKey('invoice-void-reason-field')),
        'Billed twice',
      );
      await tester.tap(find.byKey(const ValueKey('invoice-void-confirm')));
      await tester.pumpAndSettle();
      expect(api.sent('POST', '/invoices/i2/void').single, {
        'reason': 'Billed twice',
      });
      expect(_textOf(tester, 'invoice-void-reason'), 'Void: Billed twice');
    });

    testWidgets(
      'Send… shows recipients, subject and the email, and a refusal its sentence',
      (tester) async {
        final api = await _pump(tester, refuseSend: true);
        await _openInvoice(tester, 'INV-0142');
        await tester.tap(find.byKey(const ValueKey('invoice-send')));
        await tester.pumpAndSettle();

        expect(find.text('billing@springfield.example'), findsOneWidget);
        expect(
          _textOf(tester, 'invoice-send-subject'),
          'Invoice INV-0142 from Brightside Home Services',
        );
        expect(_textOf(tester, 'invoice-send-body'), contains('Marketing'));

        await tester.enterText(
          find.byKey(const ValueKey('invoice-to-add')),
          'pat@springfield.example',
        );
        await tester.testTextInput.receiveAction(TextInputAction.done);
        await tester.pump();
        await tester.tap(find.byKey(const ValueKey('invoice-send-confirm')));
        await tester.pumpAndSettle();

        expect(api.sent('POST', '/invoices/send').single, {
          'ids': ['i2'],
          'to': ['billing@springfield.example', 'pat@springfield.example'],
        });
        expect(_textOf(tester, 'invoice-error'), _noMailServer);
        expect(
          find.text('Connect it in Settings › Integrations › Sending server.'),
          findsOneWidget,
        );
      },
    );
  });

  testWidgets('Settings saves the charge lines in their new order', (
    tester,
  ) async {
    final api = await _pump(tester);
    await _openInvoices(tester, sub: 'Settings');

    expect(
      find.text('From "Marketing" in Expenses by company'),
      findsOneWidget,
    );
    expect(find.text('next: INV-0146'), findsOneWidget);
    expect(find.text("days after they're dated."), findsOneWidget);

    final list = tester.widget<ReorderableListView>(
      find.byKey(const ValueKey('tour-invoices-columns')),
    );
    list.onReorder(0, 3); // Marketing to the end.
    await tester.pump();
    await tester.tap(
      find.byKey(const ValueKey('invoice-column-1-active')),
    ); // Software off.
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('invoice-columns-save')));
    await tester.pumpAndSettle();

    final put = api.sent('PUT', '/invoices/charge-columns').single;
    expect(
      [for (final c in put['columns'] as List) c['name']],
      ['Call center', 'Software', 'Marketing'],
    );
    expect([for (final c in put['columns'] as List) c['id']], ['c', 's', 'm']);
    expect((put['columns'] as List)[1]['is_active'], false);
    expect((put['columns'] as List)[2]['source_column'], 'Marketing');
  });

  testWidgets('Settings: choosing the summary asks who it goes to', (
    tester,
  ) async {
    final api = await _pump(tester);
    await _openInvoices(tester, sub: 'Settings');
    expect(
      find.byKey(const ValueKey('invoice-setting-summary-to')),
      findsNothing,
    );
    await tester.tap(find.byKey(const ValueKey('invoice-setting-email-mode')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('One summary email to accounting').last);
    await tester.pumpAndSettle();
    expect(api.sent('PATCH', '/settings/invoicing.email_mode').single, {
      'value': 'summary',
    });
    expect(
      find.byKey(const ValueKey('invoice-setting-summary-to')),
      findsOneWidget,
    );
  });

  group('the issuer', () {
    testWidgets("is the invoice's own, its logo fetched through the API", (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0142');
      expect(
        _textOf(tester, 'invoice-issuer-name'),
        'Brightside Springfield LLC',
      );
      expect(
        _textOf(tester, 'invoice-issuer-address'),
        '12 Elm St\nSpringfield, IL 62701',
      );
      expect(
        find.byKey(const ValueKey('invoice-issuer-logo-image')),
        findsOneWidget,
      );
      expect(
        api.requests.where((r) => r.$2.endsWith('/branding/companies/co-1')),
        hasLength(1),
      );
    });

    testWidgets('a logo that fails to load draws nothing', (tester) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0139');
      expect(_textOf(tester, 'invoice-issuer-name'), 'Brightside Oakdale LLC');
      expect(
        find.byKey(const ValueKey('invoice-issuer-address')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('invoice-issuer-logo-image')),
        findsNothing,
      );
      expect(
        api.requests.where((r) => r.$2.endsWith('/branding/companies/co-9')),
        hasLength(1),
      );
      expect(tester.takeException(), isNull);
    });

    testWidgets('without one, the organization, and no logo is asked for', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0145');
      expect(
        _textOf(tester, 'invoice-issuer-name'),
        'Brightside Home Services',
      );
      expect(
        find.byKey(const ValueKey('invoice-issuer-logo-image')),
        findsNothing,
      );
      expect(api.requests.where((r) => r.$2.contains('/branding/')), isEmpty);
    });
  });

  group('the branch filter', () {
    testWidgets('sends franchise_id for the branch picked', (tester) async {
      final api = await _pump(tester);
      await _openInvoices(tester, sub: 'Invoices');
      expect(api.lastQuery.containsKey('franchise_id'), isFalse);
      await tester.tap(find.byKey(const ValueKey('invoice-filter-branch')));
      await tester.pumpAndSettle();
      expect(find.text('Springfield Gutters · Shelbyville'), findsOneWidget);
      await tester.tap(find.text('Brightside Home Services · Oakdale').last);
      await tester.pumpAndSettle();

      expect(api.lastQuery['franchise_id'], 'fr-oak');
      expect(find.text('INV-0139'), findsOneWidget);
      expect(find.text('INV-0142'), findsNothing);
    });

    testWidgets('offers only the branches in the current view', (tester) async {
      await _pump(
        tester,
        scope: const {
          'type': 'company',
          'id': 'co-1',
          'label': 'Brightside Home Services',
          'path': ['Brightside Home Services'],
        },
      );
      await _openInvoices(tester, sub: 'Invoices');
      await tester.tap(find.byKey(const ValueKey('invoice-filter-branch')));
      await tester.pumpAndSettle();
      expect(find.text('Brightside Home Services · Riverton'), findsOneWidget);
      expect(find.text('Springfield Gutters · Shelbyville'), findsNothing);
    });
  });

  group('channels', () {
    testWidgets('Settings: send by chips, each with its readiness', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoices(tester, sub: 'Settings');
      expect(
        _textOf(tester, 'invoice-channel-email-ready'),
        'from billing@brightside.example',
      );
      expect(
        find.byKey(const ValueKey('invoice-setting-sms-from')),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('invoice-channel-sms')));
      await tester.pumpAndSettle();
      expect(api.sent('PATCH', '/settings/invoicing.channels').single, {
        'value': ['email', 'sms'],
      });
      expect(
        _textOf(tester, 'invoice-channel-sms-why'),
        'Choose the number invoices are texted from.',
      );

      await tester.tap(find.byKey(const ValueKey('invoice-setting-sms-from')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('+15555550101').last);
      await tester.pumpAndSettle();
      expect(api.sent('PATCH', '/settings/invoicing.sms_from').single, {
        'value': 'pn1',
      });
      expect(_textOf(tester, 'invoice-channel-sms-ready'), 'from +15555550101');

      await tester.tap(find.byKey(const ValueKey('invoice-channel-whatsapp')));
      await tester.pumpAndSettle();
      expect(_textOf(tester, 'invoice-channel-whatsapp-why'), _noWhatsApp);
      expect(
        find.byKey(const ValueKey('invoice-setting-whatsapp_template')),
        findsOneWidget,
      );
    });

    testWidgets('Settings: the last channel cannot be switched off', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoices(tester, sub: 'Settings');
      await tester.tap(find.byKey(const ValueKey('invoice-channel-email')));
      await tester.pumpAndSettle();
      expect(api.sent('PATCH', '/settings/invoicing.channels'), isEmpty);
      expect(
        find.text('Invoices need at least one way to be sent.'),
        findsOneWidget,
      );
    });

    testWidgets('Settings: a company has its own, read and written there', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoices(tester, sub: 'Settings');
      await tester.tap(find.byKey(const ValueKey('invoice-setting-place')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Springfield Gutters').last);
      await tester.pumpAndSettle();

      expect(
        api.queries.any(
          (q) =>
              q.$2.endsWith('/invoices/settings') &&
              q.$3['company_id'] == 'co-2',
        ),
        isTrue,
      );
      await tester.tap(find.byKey(const ValueKey('invoice-channel-sms')));
      await tester.pumpAndSettle();
      final patch = api.queries.lastWhere(
        (q) => q.$1 == 'PATCH' && q.$2.endsWith('/invoicing.channels'),
      );
      expect(patch.$3, {'scope_type': 'company', 'scope_id': 'co-2'});
    });

    testWidgets('Settings: a company connects its own Stripe account', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoices(tester, sub: 'Settings');
      expect(
        find.byKey(const ValueKey('invoice-payments-connect')),
        findsNothing,
      );
      await tester.tap(find.byKey(const ValueKey('invoice-setting-place')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Springfield Gutters').last);
      await tester.pumpAndSettle();

      await tester.tap(find.text('Connect Stripe'));
      await tester.pumpAndSettle();
      expect(api.sent('POST', '/invoices/payments-account/onboarding').single, {
        'company_id': 'co-2',
      });
      expect(find.text('Stripe refused: test'), findsOneWidget);
    });

    testWidgets('the pane: phones are editable beside the emails until sent', (
      tester,
    ) async {
      final api = await _pump(tester);
      await _openInvoice(tester, 'INV-0142');
      expect(
        find.byKey(const ValueKey('invoice-email-billing@springfield.example')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('invoice-phone-+15555550123')),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const ValueKey('invoice-phone-add')),
        '+15555550188',
      );
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(api.sent('PATCH', '/invoices/i2').single, {
        'bill_to_phones': ['+15555550123', '+15555550188'],
      });
      expect(
        find.byKey(const ValueKey('invoice-phone-+15555550188')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('invoice-card-i3')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('invoice-phone-add')), findsNothing);
    });

    testWidgets('Send…: grouped by channel; a channel not ready blocks it', (
      tester,
    ) async {
      final api = await _pump(
        tester,
        channels: const ['email', 'sms', 'whatsapp'],
        smsFrom: 'pn1',
      );
      await _openInvoice(tester, 'INV-0142');
      await tester.tap(find.byKey(const ValueKey('invoice-send')));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('invoice-send-group-email')),
        findsOneWidget,
      );
      expect(
        _textOf(tester, 'invoice-send-group-phones'),
        'Text and WhatsApp to',
      );
      expect(_textOf(tester, 'invoice-send-why-whatsapp'), _noWhatsApp);
      expect(
        find.byKey(const ValueKey('invoice-phone-to-+15555550123')),
        findsOneWidget,
      );
      expect(_textOf(tester, 'invoice-send-text'), contains('View it:'));
      final send = tester.widget<FilledButton>(
        find.byKey(const ValueKey('invoice-send-confirm')),
      );
      expect(send.onPressed, isNull);
      expect(api.sent('POST', '/invoices/send'), isEmpty);
    });

    testWidgets('Send…: email and text go with to[] and phones[]', (
      tester,
    ) async {
      final api = await _pump(
        tester,
        channels: const ['email', 'sms'],
        smsFrom: 'pn1',
      );
      await _openInvoice(tester, 'INV-0142');
      await tester.tap(find.byKey(const ValueKey('invoice-send')));
      await tester.pumpAndSettle();
      expect(_textOf(tester, 'invoice-send-group-phones'), 'Text to');
      await tester.enterText(
        find.byKey(const ValueKey('invoice-phone-to-add')),
        '+15555550199',
      );
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('invoice-send-confirm')));
      await tester.pumpAndSettle();
      expect(api.sent('POST', '/invoices/send').single, {
        'ids': ['i2'],
        'to': ['billing@springfield.example'],
        'phones': ['+15555550123', '+15555550199'],
      });
    });
  });

  group('on a phone (390 × 844)', () {
    testWidgets(
      'the sheet is a list of branches; tap one to edit it in place',
      (tester) async {
        final api = await _pump(tester, size: const Size(390, 844));
        await _openInvoices(tester);
        expect(tester.takeException(), isNull);
        expect(find.byKey(const ValueKey('cell-r1-m')), findsNothing);
        expect(_textOf(tester, 'sheet-total'), r'$10,230.00');

        await tester.tap(find.text('Riverton · Brightside'));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await tester.enterText(
          find.byKey(const ValueKey('sheet-row-jobs')),
          '90',
        );
        await tester.tap(find.byKey(const ValueKey('sheet-row-save')));
        await tester.pumpAndSettle();
        expect(api.sent('PATCH', '/invoices/sheets/sh1/rows').single['rows'], [
          {
            'id': 'r2',
            'jobs': 90,
            'cells': {'m': 160000, 'c': 74000, 's': 30000},
          },
        ]);
        expect(find.text('Riverton · Brightside'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );

    testWidgets(
      'invoices are cards with totals; the panel is full screen with ←',
      (tester) async {
        await _pump(tester, size: const Size(390, 844));
        await _openInvoices(tester, sub: 'Invoices');
        expect(tester.takeException(), isNull);
        expect(
          find.byKey(const ValueKey('invoice-total-unpaid')),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('invoice-card-i3')), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('invoice-card-i3')));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        expect(find.byKey(const ValueKey('invoice-document')), findsOneWidget);
        expect(find.byKey(const ValueKey('invoice-card-i2')), findsNothing);

        await tester.tap(find.byTooltip('Back'));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('invoice-card-i2')), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  });
}

/// A 1 x 1 transparent PNG.
final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
);

const _noWhatsApp = 'Connect a WhatsApp number in Integrations.';

const _noMailServer =
    'This business has not connected its own mail server yet, so invoices cannot be sent.';

Finder _hostTab(String label) => find.descendant(
  of: find.byKey(const ValueKey('tour-financials-tabs')),
  matching: find.text(label),
);

String _textOf(WidgetTester tester, String key) =>
    tester.widget<Text>(find.byKey(ValueKey(key))).data!;

Future<void> _openInvoices(WidgetTester tester, {String? sub}) async {
  await tester.tap(_hostTab('Invoices'));
  await tester.pumpAndSettle();
  if (sub != null) {
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('tour-invoices-tabs')),
        matching: find.text(sub),
      ),
    );
    await tester.pumpAndSettle();
  }
}

Future<void> _openInvoice(WidgetTester tester, String number) async {
  await _openInvoices(tester, sub: 'Invoices');
  await tester.tap(find.text(number).first);
  await tester.pumpAndSettle();
}

class _Api {
  final requests = <(String, String, Object?)>[];

  /// Every request's query, beside its method and path.
  final queries = <(String, String, Map<String, String>)>[];

  /// The query of the last GET /invoices.
  Map<String, String> lastQuery = const {};

  /// The JSON bodies sent to [path] with [method].
  List<Map<String, dynamic>> sent(String method, String path) => [
    for (final (m, p, b) in requests)
      if (m == method && p.endsWith('/api/v1$path'))
        (b as Map).cast<String, dynamic>(),
  ];
}

Map<String, dynamic> _invoice(
  String id,
  String number,
  String status, {
  required String branch,
  required int total,
  int? due,
  bool overdue = false,
  bool canApprove = true,
  bool viewerOnly = false,
  String? voidReason,
  Map<String, dynamic>? issuer,
}) {
  final may = !viewerOnly;
  return {
    'id': id,
    'number': number,
    'status': status,
    'overdue': overdue,
    'issued_on': '2026-10-01',
    'due_on': '2026-10-31',
    'bill_to': {
      'type': 'franchise',
      'id': 'f-$id',
      'name': branch,
      'address': '12 Elm St, Springfield',
      'emails': ['billing@springfield.example'],
      'phones': ['+15555550123'],
    },
    'summary': 'Total jobs: 142',
    'notes': null,
    'lines': [
      {'id': 'l-$id', 'description': 'Marketing', 'amount_cents': total},
    ],
    'subtotal_cents': total,
    'tax_cents': 0,
    'total_cents': total,
    'paid_cents': total - (due ?? total),
    'due_cents': due ?? total,
    'amount_due_cents': due ?? total,
    'payments': const [],
    'has_pdf': true,
    'approved_at': status == 'draft' ? null : '2026-10-20T14:02:00Z',
    'approved_by_name': status == 'draft' ? null : 'Pat Lin',
    'sent_at': status == 'sent' || status == 'paid'
        ? '2026-10-21T09:00:00Z'
        : null,
    'voided_at': status == 'void' ? '2026-10-21T10:00:00Z' : null,
    'void_reason': voidReason,
    'issuer': ?issuer,
    'can_approve': may && canApprove && status == 'draft',
    'can_send': may && (status == 'approved' || status == 'sent'),
    'can_record_payment': may && ['approved', 'sent', 'paid'].contains(status),
    'can_void': may && ['draft', 'approved', 'sent'].contains(status),
    'can_edit': may && status == 'draft',
  };
}

Future<_Api> _pump(
  WidgetTester tester, {
  bool invoicesModule = true,
  List<String>? permissions,
  bool canApprove = true,
  bool viewerOnly = false,
  bool refuseSend = false,
  String? refuseRows,
  List<String> channels = const ['email'],
  String smsFrom = '',
  Size size = const Size(1400, 900),
  Map<String, dynamic> scope = const {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = _Api();

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  final invoices = {
    for (final i in [
      _invoice(
        'i5',
        'INV-0145',
        'draft',
        branch: 'Brightside Lakeview',
        total: 245000,
        canApprove: canApprove,
        viewerOnly: viewerOnly,
      ),
      _invoice(
        'i2',
        'INV-0142',
        'approved',
        branch: 'Brightside Springfield',
        total: 388000,
        viewerOnly: viewerOnly,
        issuer: {
          'name': 'Brightside Springfield LLC',
          'address': '12 Elm St\nSpringfield, IL 62701',
          'logo_url': '/branding/companies/co-1',
        },
      ),
      _invoice(
        'i3',
        'INV-0139',
        'sent',
        branch: 'Brightside Oakdale',
        total: 161000,
        issuer: {
          'name': 'Brightside Oakdale LLC',
          'address': null,
          'logo_url': '/branding/companies/co-9',
        },
        overdue: true,
        viewerOnly: viewerOnly,
      ),
      _invoice(
        'i4',
        'INV-0143',
        'paid',
        branch: 'Brightside Riverton',
        total: 264000,
        due: 0,
        viewerOnly: viewerOnly,
      ),
    ])
      '${i['id']}': i,
  };

  Map<String, dynamic> row(
    String id,
    String label,
    int jobs,
    Map<String, int> cells, {
    String? invoice,
  }) => {
    'id': id,
    'label': label,
    'franchise_id': 'f-$id',
    'jobs': jobs,
    'cells': cells,
    'total_cents': cells.values.fold<int>(0, (s, v) => s + v),
    'invoice_id': invoice,
    'invoice_number': invoice == null ? null : 'INV-0139',
    'invoice_status': invoice == null ? null : 'sent',
  };

  final sheet = {
    'id': 'sh1',
    'title': 'September',
    'status': 'open',
    'saved_at': '2026-10-21T16:00:00Z',
    'period_from': '2026-09-01',
    'period_to': '2026-09-30',
    'columns': [
      {'id': 'm', 'name': 'Marketing'},
      {'id': 'c', 'name': 'Call center'},
      {'id': 's', 'name': 'Software'},
    ],
    'rows': [
      row('r1', 'Springfield · Brightside', 142, {
        'm': 240000,
        'c': 118000,
        's': 30000,
      }),
      row('r2', 'Riverton · Brightside', 88, {
        'm': 160000,
        'c': 74000,
        's': 30000,
      }),
      row('r3', 'Oakdale · Brightside', 51, {
        'm': 90000,
        'c': 41000,
        's': 30000,
      }),
      row('r4', 'Lakeside · Brightside', 0, {'m': 0, 'c': 0, 's': 0}),
      row('r5', 'Hillcrest · Brightside', 40, {
        'm': 120000,
        'c': 60000,
        's': 30000,
      }, invoice: 'i3'),
    ],
    'total_cents': 1023000,
  };

  // The settings this business has, as PATCH /settings leaves them.
  final store = <String, Object?>{
    'invoicing.channels': channels,
    'invoicing.sms_from': smsFrom,
  };

  final client = MockClient((request) async {
    final path = request.url.path;
    final method = request.method;
    Object? body;
    if (request.body.isNotEmpty) body = jsonDecode(request.body);
    api.requests.add((method, path, body));
    api.queries.add((method, path, request.url.queryParameters));

    if (path.endsWith('/me')) {
      return json({
        'data': {
          'id': 'u-1',
          'name': 'Pat Lin',
          'email': 'pat@brightside.example',
          'role': {'name': 'Administrator'},
          'has_avatar': false,
          'preferences': <String, dynamic>{},
          'organization': {
            'id': 'org-1',
            'name': 'Brightside Home Services',
            'franchise_label': 'Branch',
            'is_read_only': false,
          },
          'scope': scope,
          'companies': [
            {
              'id': 'co-1',
              'name': 'Brightside Home Services',
              'parent_company_id': null,
            },
            {
              'id': 'co-2',
              'name': 'Springfield Gutters',
              'parent_company_id': null,
            },
          ],
          'franchises': [
            {'id': 'fr-oak', 'name': 'Oakdale', 'company_id': 'co-1'},
            {'id': 'fr-riv', 'name': 'Riverton', 'company_id': 'co-1'},
            {'id': 'fr-sg', 'name': 'Shelbyville', 'company_id': 'co-2'},
          ],
          'modules': [
            {
              'key': 'financials',
              'label': 'Financials',
              'available': true,
              'reachable': true,
            },
            if (invoicesModule)
              {
                'key': 'invoices',
                'label': 'Invoices',
                'parent': 'financials',
                'available': true,
                'reachable': true,
              },
          ],
          'permissions':
              permissions ??
              [
                'financials.view',
                'financials.bills.view',
                'invoices.view',
                'invoices.charge_sheets.view',
                'invoices.invoices.view',
                if (!viewerOnly) ...[
                  'invoices.create',
                  'invoices.edit',
                  'invoices.delete',
                  'invoices.approve',
                  'invoices.send',
                ],
              ],
        },
      });
    }
    if (path.endsWith('/financials/bills')) return json({'data': <Map>[]});
    if (path.endsWith('/branding/companies/co-1')) {
      return http.Response.bytes(
        _png,
        200,
        headers: const {'content-type': 'image/png'},
      );
    }
    if (path.contains('/branding/')) return json({'message': 'No logo.'}, 404);

    if (path.endsWith('/invoices/sheets') && method == 'GET') {
      return json({
        'data': [
          {
            'id': 'sh1',
            'title': 'September',
            'status': 'open',
            'rows_count': 5,
            'period_from': '2026-09-01',
            'period_to': '2026-09-30',
          },
        ],
      });
    }
    if (path.endsWith('/invoices/sheets/sh1') && method == 'GET') {
      return json({'data': sheet});
    }
    if (path.endsWith('/invoices/sheets/sh1/rows')) {
      if (refuseRows != null) {
        return json({
          'message': refuseRows,
          'errors': {
            'rows': [refuseRows],
          },
        }, 422);
      }
      return json({
        'data': {...sheet, 'saved_at': '2026-10-21T16:05:00Z'},
      });
    }
    if (path.endsWith('/invoices/sheets/sh1/invoice')) {
      return json({
        'data': {
          'made': [invoices['i5'], invoices['i2'], invoices['i3']],
          'skipped': [
            {
              'row_id': 'r4',
              'label': 'Lakeside · Brightside',
              'reason': 'nothing to bill',
            },
          ],
        },
      }, 201);
    }
    if (path.endsWith('/invoices/charge-columns')) {
      if (method == 'PUT') {
        return json({
          'data': [
            for (final (i, c) in ((body as Map)['columns'] as List).indexed)
              {
                ...(c as Map),
                'position': i,
                'source_label': 'Typed in on the sheet',
              },
          ],
        });
      }
      return json({
        'data': [
          {
            'id': 'm',
            'name': 'Marketing',
            'source_column': 'Marketing',
            'position': 0,
            'is_active': true,
            'source_label': 'From "Marketing" in Expenses by company',
          },
          {
            'id': 'c',
            'name': 'Call center',
            'source_column': null,
            'position': 1,
            'is_active': true,
            'source_label': 'Typed in on the sheet',
          },
          {
            'id': 's',
            'name': 'Software',
            'source_column': null,
            'position': 2,
            'is_active': true,
            'source_label': 'Typed in on the sheet',
          },
        ],
      });
    }
    if (path.endsWith('/invoices/settings')) {
      final chosen = (store['invoicing.channels'] as List).cast<String>();
      final smsReady = store['invoicing.sms_from'] != '';
      return json({
        'data': {
          'next_number': 'INV-0146',
          'channels': [
            {
              'channel': 'email',
              'ready': true,
              'why_not': null,
              'from': 'billing@brightside.example',
              'chosen': chosen.contains('email'),
            },
            {
              'channel': 'sms',
              'ready': smsReady,
              'why_not': smsReady
                  ? null
                  : 'Choose the number invoices are texted from.',
              'from': smsReady ? '+15555550101' : null,
              'chosen': chosen.contains('sms'),
            },
            {
              'channel': 'whatsapp',
              'ready': false,
              'why_not': _noWhatsApp,
              'from': null,
              'chosen': chosen.contains('whatsapp'),
            },
          ],
          'sms_numbers': [
            {'id': 'pn1', 'e164': '+15555550101', 'label': 'Main line'},
          ],
          'mailbox': {'id': 'mb1', 'address': 'billing@brightside.example'},
          'payments': request.url.queryParameters['company_id'] == null
              ? null
              : {
                  'configured': true,
                  'may_connect': true,
                  'stripe_account_id': null,
                  'charges_enabled': false,
                },
        },
      });
    }
    if (path.endsWith('/invoices/payments-account/onboarding')) {
      return json({
        'message': 'Stripe refused: test',
        'errors': {
          'company_id': ['Stripe refused: test'],
        },
      }, 422);
    }
    if (path.endsWith('/api/v1/settings')) {
      Map<String, dynamic> s(String key, Object? value, {List? options}) => {
        'key': key,
        'value': value,
        'options': options,
        'may_edit': true,
      };
      return json({
        'data': [
          s(
            'invoicing.email_mode',
            'per_branch',
            options: [
              {'value': 'per_branch', 'label': 'One email per invoice'},
              {'value': 'summary', 'label': 'One summary email'},
            ],
          ),
          s('invoicing.summary_to', ''),
          s(
            'money.payment_terms_days',
            30,
            options: [
              for (final d in [0, 7, 14, 15, 30, 45, 60, 90])
                {'value': d, 'label': 'Net $d'},
            ],
          ),
          s('numbering.invoice_prefix', 'INV-'),
          s('invoicing.channels', store['invoicing.channels']),
          s('invoicing.sms_from', store['invoicing.sms_from']),
          s('invoicing.whatsapp_from', ''),
          s('invoicing.whatsapp_template', ''),
        ],
      });
    }
    if (path.contains('/api/v1/settings/') && method == 'PATCH') {
      store[path.split('/').last] = (body as Map)['value'];
      return json({'data': body});
    }
    if (path.endsWith('/invoices/send-preview')) {
      final chosen = (store['invoicing.channels'] as List).cast<String>();
      final phones = (invoices['i2']!['bill_to'] as Map)['phones'];
      return json({
        'data': {
          'channels': [
            for (final c in chosen)
              {
                'channel': c,
                'ready':
                    c == 'email' ||
                    (c == 'sms' && store['invoicing.sms_from'] != ''),
                'why_not': c == 'whatsapp'
                    ? _noWhatsApp
                    : c == 'sms' && store['invoicing.sms_from'] == ''
                    ? 'Choose the number invoices are texted from.'
                    : null,
                'from': null,
                'to': c == 'email' ? ['billing@springfield.example'] : phones,
              },
          ],
          'text':
              'Brightside Home Services: invoice INV-0142 for \$3,880.00, '
              'due 10/31/2026. View it: https://ezbizi.example/i/abc',
          'to': ['billing@springfield.example'],
          'subject': 'Invoice INV-0142 from Brightside Home Services',
          'mode': 'per_branch',
          'html':
              '<html><style>p{}</style><body><p>Invoice INV-0142</p>'
              '<table><tr><td>Marketing</td><td>\$2,400.00</td></tr></table></body></html>',
        },
      });
    }
    if (path.endsWith('/invoices/send')) {
      if (refuseSend) {
        return json({
          'message': _noMailServer,
          'errors': {
            'send': [_noMailServer],
          },
        }, 422);
      }
      return json({
        'data': [
          {
            ...?invoices['i2'],
            'status': 'sent',
            'sent_at': '2026-10-21T17:00:00Z',
          },
        ],
      });
    }
    if (path.endsWith('/api/v1/invoices') && method == 'GET') {
      api.lastQuery = request.url.queryParameters;
      // Oakdale's branch was billed INV-0139 only.
      final branch = request.url.queryParameters['franchise_id'];
      return json({
        'data': [
          for (final i in invoices.values)
            if (branch == null || (branch == 'fr-oak' && i['id'] == 'i3')) i,
        ],
        'totals': {
          'sent_cents': 813000,
          'unpaid_cents': 425000,
          'overdue_cents': 161000,
        },
      });
    }
    final match = RegExp(r'/api/v1/invoices/(i\d)(/[a-z]+)?$').firstMatch(path);
    if (match != null) {
      final id = match.group(1)!;
      final action = match.group(2);
      final current = invoices[id]!;
      final next = switch ((method, action)) {
        ('POST', '/approve') => {
          ...current,
          'status': 'approved',
          'approved_at': '2026-10-21T16:10:00Z',
          'approved_by_name': 'Pat Lin',
          'can_approve': false,
          'can_send': true,
          'can_edit': false,
          'can_record_payment': true,
        },
        ('POST', '/void') => {
          ...current,
          'status': 'void',
          'void_reason': (body as Map)['reason'],
          'can_void': false,
          'can_send': false,
          'can_record_payment': false,
        },
        ('POST', '/payments') => {...current, 'amount_due_cents': 40950},
        ('PATCH', null) => {
          ...current,
          'bill_to': {
            ...(current['bill_to'] as Map),
            if ((body as Map).containsKey('bill_to_phones'))
              'phones': body['bill_to_phones'],
            if (body.containsKey('bill_to_emails'))
              'emails': body['bill_to_emails'],
          },
        },
        _ => current,
      };
      invoices[id] = next;
      return json({
        'data': next,
      }, method == 'POST' && action == '/payments' ? 201 : 200);
    }
    return json({'message': 'Nothing stubbed for $method $path.'}, 500);
  });

  // A second pump in one test starts from nothing, not from the last tree.
  await tester.pumpWidget(const SizedBox());
  final apiClient = ApiClient(client: client);
  final session = Session(apiClient);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FinancialsScreen(api: apiClient, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return api;
}
