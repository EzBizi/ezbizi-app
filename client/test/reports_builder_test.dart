import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_builder.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('the tree lists the resources the fields endpoint offers', (
    tester,
  ) async {
    await _pumpBuilder(tester);

    expect(find.text('Jobs'), findsOneWidget);
    expect(find.text('Customers'), findsOneWidget);
    expect(find.text('People'), findsOneWidget);
  });

  testWidgets('a resource expands to its declared columns', (tester) async {
    await _pumpBuilder(tester);

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();

    expect(find.text('Title'), findsOneWidget);
    expect(find.text('Status'), findsOneWidget);
  });

  testWidgets('a hop is drawn with its relation name, not just the target', (
    tester,
  ) async {
    await _pumpBuilder(tester);

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();

    // The relation's NAME is its key in the payload. There is no human label
    // and deliberately so while one relation exists in the product — the tree
    // says which way through the graph a column came.
    expect(find.textContaining('customer'), findsWidgets);
  });

  testWidgets('a resource whose relations arrive as an empty JSON array draws', (
    tester,
  ) async {
    // The regression that shipped in 0.0.0192: three of four resources had no
    // edges, PHP encoded those as `[]`, and the cast to Map threw inside
    // build() — which a release build renders as a blank grey panel.
    await _pumpBuilder(tester);

    expect(find.text('Customers'), findsOneWidget);

    await tester.tap(find.text('Customers'));
    await tester.pumpAndSettle();

    expect(find.text('Name'), findsWidgets);
  });

  testWidgets('choosing a column and saving posts the definition', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pumpBuilder(tester, onPost: (body) => posted = body);

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Title'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Name this report'),
      'Open jobs',
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Save report'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['name'], 'Open jobs');
    expect(posted!['definition']['root'], 'jobs');
    expect((posted!['definition']['columns'] as List).first, {
      'resource': 'jobs',
      'column': 'title',
    });
  });

  testWidgets('the first column chosen decides the root', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpBuilder(tester, onPost: (body) => posted = body);

    await tester.tap(find.text('Customers'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Name'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Name this report'),
      'The book',
    );
    await tester.tap(find.text('Save report'));
    await tester.pumpAndSettle();

    expect(posted!['definition']['root'], 'customers');
  });

  testWidgets('saving with no columns is refused before a request is sent', (
    tester,
  ) async {
    var posts = 0;

    await _pumpBuilder(tester, onPost: (_) => posts++);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name this report'),
      'Empty',
    );
    await tester.tap(find.text('Save report'));
    await tester.pumpAndSettle();

    expect(posts, 0);
    expect(find.textContaining('at least one column'), findsOneWidget);
  });

  testWidgets("a refusal from the server is shown in the server's own words", (
    tester,
  ) async {
    await _pumpBuilder(
      tester,
      postStatus: 422,
      postBody: {
        'message': 'The given data was invalid.',
        'errors': {
          'definition': ["'Jobs' does not report a column called 'secret'."],
        },
      },
    );

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Title'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextField, 'Name this report'),
      'Doomed',
    );
    await tester.tap(find.text('Save report'));
    await tester.pumpAndSettle();

    // The server validates a definition by RUNNING it, so its complaint is the
    // real one. Replacing it with "invalid" would throw away the only sentence
    // that says what is actually wrong.
    expect(
      find.textContaining('does not report a column called'),
      findsOneWidget,
    );
  });

  testWidgets('a filter is sent with its resource, column, operator and value', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pumpBuilder(tester, onPost: (body) => posted = body);

    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Title'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Add filter'));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextField, 'Value'), 'open');
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Name this report'),
      'Filtered',
    );
    await tester.tap(find.text('Save report'));
    await tester.pumpAndSettle();

    final filters = posted!['definition']['filters'] as List;
    expect(filters, hasLength(1));
    expect((filters.first as Map)['resource'], 'jobs');
    expect((filters.first as Map)['op'], 'eq');
    expect((filters.first as Map)['value'], 'open');
  });
}

Future<void> _pumpBuilder(
  WidgetTester tester, {
  void Function(Map<String, dynamic> body)? onPost,
  int postStatus = 201,
  Map<String, dynamic>? postBody,
}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/reports/fields')) {
        return _json({'data': _fields});
      }

      if (request.method == 'POST' && path.endsWith('/reports')) {
        onPost?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json(
          postBody ?? {'data': {'id': 'rep-9', 'name': 'Saved'}},
          postStatus,
        );
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ReportBuilder(api: api, onSaved: (_) {})),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _fields = {
  'jobs': {
    'label': 'Jobs',
    'columns': {
      'title': {'label': 'Title', 'type': 'string'},
      'status': {'label': 'Status', 'type': 'string'},
    },
    'relations': {
      'customer': {'to': 'customers'},
    },
  },
  'customers': {
    'label': 'Customers',
    'columns': {
      'name': {'label': 'Name', 'type': 'string'},
      'city': {'label': 'City', 'type': 'string'},
    },
    // THE WIRE SHAPE, not a Dart map. PHP serializes an empty array as `[]`,
    // so a resource with no relations arrived as a JSON ARRAY and the client
    // threw casting it — a blank grey panel in release with nothing logged.
    // The fixture that hid this used `<String, dynamic>{}`, which agreed with
    // the client instead of with the server.
    'relations': <dynamic>[],
  },
  'users': {
    'label': 'People',
    'columns': {
      'name': {'label': 'Name', 'type': 'string'},
    },
    'relations': <dynamic>[],
  },
};
