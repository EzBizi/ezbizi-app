import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/reply_templates_manager.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Jose, 2026-09-30: a template manager inside Leads, one list per company.
  testWidgets('each company keeps its own list, and Save sends that list', (
    tester,
  ) async {
    final puts = await _pump(tester);

    expect(find.text('Inspection prices'), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('templates-company-gd')));
    await tester.pumpAndSettle();
    expect(find.text('Inspection prices'), findsNothing);
    expect(find.text('Free estimate'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('templates-save')));
    await tester.pumpAndSettle();
    expect(puts.last.$1, '/api/v1/leads/reply-templates/gd');
    expect(puts.last.$2, [
      {'name': 'Free estimate', 'text': 'The visit is free.'},
    ]);
  });

  /// UI queue 38, 1: codes go in with a button rather than typed braces, and
  /// the customer's version shows as it is typed (Jose: "it looks terrible").
  testWidgets('Insert puts a code where the cursor is, the preview fills it '
      'in, and Unsaved shows until it is discarded', (tester) async {
    await _pump(tester);
    final message = find.byKey(const ValueKey('template-text'));

    await tester.enterText(message, ', which one?');
    await tester.pump();
    final field = tester.widget<TextField>(message).controller!;
    field.selection = const TextSelection.collapsed(offset: 0);
    await tester.tap(
      find.byKey(const ValueKey('template-insert-{{greeting}}')),
    );
    await tester.pump();

    expect(field.text, '{{greeting}}, which one?');
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('template-preview')),
        matching: find.text('Hi Mark, which one?'),
      ),
      findsOneWidget,
    );
    expect(find.text('Unsaved changes'), findsOneWidget);

    await tester.tap(find.text('Discard'));
    await tester.pump();
    expect(find.text('Unsaved changes'), findsNothing);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('template-text')))
          .controller!
          .text,
      'Level 1 is \$99.',
    );
  });

  testWidgets('a new template joins the end of the list and Save sends both', (
    tester,
  ) async {
    final puts = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('template-add')));
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('template-name')),
      'Sweep price',
    );
    await tester.enterText(
      find.byKey(const ValueKey('template-text')),
      'A sweep is \$249.',
    );
    await tester.tap(find.byKey(const ValueKey('templates-save')));
    await tester.pumpAndSettle();

    expect(puts.last.$1, '/api/v1/leads/reply-templates/ch');
    expect(puts.last.$2, [
      {'name': 'Inspection prices', 'text': 'Level 1 is \$99.'},
      {'name': 'Sweep price', 'text': 'A sweep is \$249.'},
    ]);
    expect(find.text('Saved.'), findsOneWidget);
  });

  testWidgets('a template with no message is not sent', (tester) async {
    final puts = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('template-add')));
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('template-name')),
      'Empty',
    );
    await tester.tap(find.byKey(const ValueKey('templates-save')));
    await tester.pumpAndSettle();

    expect(puts, isEmpty);
    expect(
      find.text('Every template needs a name and a message.'),
      findsOneWidget,
    );
  });

  // The server keeps at least one per company (min:1).
  testWidgets('the last template of a company cannot be deleted', (
    tester,
  ) async {
    await _pump(tester);

    final delete = tester.widget<TextButton>(
      find.byKey(const ValueKey('template-delete')),
    );
    expect(delete.onPressed, isNull);
    expect(find.text('The last one stays'), findsOneWidget);
  });

  testWidgets('Copy puts the open template into each company ticked', (
    tester,
  ) async {
    final puts = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('template-copy')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('template-copy-gd')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('template-copy-go')));
    await tester.pumpAndSettle();

    expect(puts.single.$1, '/api/v1/leads/reply-templates/gd');
    expect(puts.single.$2, [
      {'name': 'Free estimate', 'text': 'The visit is free.'},
      {'name': 'Inspection prices', 'text': 'Level 1 is \$99.'},
    ]);
    expect(find.text('Copied to 1 company.'), findsOneWidget);
  });

  /// UI queue 38, 4: on a phone the companies, the templates and one template
  /// are steps, each with ← back, and the steps past the first take the page.
  testWidgets('on a phone the companies, the templates and one template are '
      'steps', (tester) async {
    final deeper = <bool>[];
    await _pump(tester, size: const Size(390, 800), onDeeper: deeper.add);

    expect(find.byKey(const ValueKey('templates-company-ch')), findsOneWidget);
    expect(find.byKey(const ValueKey('template-text')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('templates-company-ch')));
    await tester.pumpAndSettle();
    expect(find.text('Inspection prices'), findsOneWidget);
    expect(find.byTooltip('Back to the companies'), findsOneWidget);

    await tester.tap(find.text('Inspection prices'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('template-text')), findsOneWidget);
    expect(find.byKey(const ValueKey('templates-save')), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.tap(find.byTooltip('Back to the templates'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Back to the companies'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('templates-company-gd')), findsOneWidget);
    expect(deeper, [true, true, true, false]);
  });

  testWidgets('somebody who can only view sees the templates and no controls', (
    tester,
  ) async {
    await _pump(tester, mayEdit: false);

    expect(
      find.text('Changing these needs permission to edit Leads.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('templates-save')), findsNothing);
    expect(find.byKey(const ValueKey('template-add')), findsNothing);
    expect(
      find.byKey(const ValueKey('template-insert-{{greeting}}')),
      findsNothing,
    );
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('template-text')))
          .enabled,
      isFalse,
    );
  });

  test('the preview fills every code the server fills', () {
    expect(
      templatePreview(
        '{{greeting}}, {{company}} needs your {{missing}}. {{availability}}',
        'Harbor',
      ),
      'Hi Mark, Harbor needs your full service address. Thursday: 8-11am or 2-5pm',
    );
    expect(
      [for (final c in templateCodes) c.code],
      ['{{greeting}}', '{{company}}', '{{missing}}', '{{availability}}'],
    );
  });
}

/// Two invented companies with one template each; every PUT is answered
/// with what it sent and recorded as (path, templates).
Future<List<(String, Object?)>> _pump(
  WidgetTester tester, {
  Size size = const Size(1400, 900),
  bool mayEdit = true,
  ValueChanged<bool>? onDeeper,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final puts = <(String, Object?)>[];
  final names = {'ch': 'A-1 Chimney', 'gd': 'A1 Garage Doors'};
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'PUT') {
        final sent =
            (jsonDecode(request.body) as Map<String, dynamic>)['templates'];
        puts.add((request.url.path, sent));
        final id = request.url.pathSegments.last;
        return http.Response(
          jsonEncode({
            'data': {
              'company': {'id': id, 'name': names[id]},
              'templates': sent,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        jsonEncode({
          'data': [
            {
              'company': {'id': 'ch', 'name': 'A-1 Chimney'},
              'templates': [
                {'name': 'Inspection prices', 'text': 'Level 1 is \$99.'},
              ],
            },
            {
              'company': {'id': 'gd', 'name': 'A1 Garage Doors'},
              'templates': [
                {'name': 'Free estimate', 'text': 'The visit is free.'},
              ],
            },
          ],
          'meta': {'may_edit': mayEdit},
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: ReplyTemplatesManager(api: api, onDeeper: onDeeper),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return puts;
}
