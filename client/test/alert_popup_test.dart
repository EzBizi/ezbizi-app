import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/alert_popup.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Alerts from Communication › Alerts (UI queue 65, Jose: "2").
///
/// What these hold to: an alert sits in the middle of the screen and only
/// Dismiss closes it; several come one after another; dismissed means the
/// server was told, and one the server never heard about comes back.
void main() {
  testWidgets('one at a time, and only Dismiss closes it', (tester) async {
    final server = _Server();
    final popups = await _pump(tester, server);

    popups.show(_context(tester), [
      _alert('a-1', 'Storm coming in.'),
      _alert('a-2', 'Take van 7 today.'),
    ], formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();

    expect(find.text('Storm coming in.'), findsOneWidget);
    expect(find.text('Take van 7 today.'), findsNothing);
    expect(find.textContaining('1 of 2'), findsOneWidget);
    expect(find.text('Alert from Dana Reyes'), findsOneWidget);

    // Not the barrier, and not Back.
    await tester.tapAt(const Offset(5, 5));
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('alert-popup')), findsOneWidget);
    expect(find.text('Underneath'), findsOneWidget);

    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();
    expect(server.read, ['a-1']);
    expect(find.text('Take van 7 today.'), findsOneWidget);
    expect(find.textContaining('of 2'), findsNothing);

    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();
    expect(server.read, ['a-1', 'a-2']);
    expect(find.byKey(const Key('alert-popup')), findsNothing);
  });

  // Jose, 2026-11-17: "Your Suggestion has been approved and Implemented,
  // Please Update". The suggestion's pop-up opens it and offers the update;
  // a colleague's alert offers neither.
  testWidgets('a suggestion pop-up opens the suggestion and offers Update', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);
    final opened = <String>[];
    final popups = AlertPopups(
      ApiClient(client: MockClient(server.handle)),
      onOpenSuggestion: opened.add,
    );

    popups.show(_context(tester), [
      _alert('a-1', 'Take van 7 today.'),
      {
        ..._alert('a-2', 'Your suggestion is live in v0.0.0584: Filters. Please update to see it.'),
        'sender': 'EzBizi',
        'suggestion_id': 's-1',
        'update': true,
      },
    ], formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('alert-open-suggestion')), findsNothing);
    expect(find.byKey(const Key('alert-update')), findsNothing);
    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();

    expect(find.text('Alert from EzBizi'), findsOneWidget);
    expect(find.byKey(const Key('alert-update')), findsOneWidget);
    await tester.tap(find.byKey(const Key('alert-open-suggestion')));
    await tester.pumpAndSettle();

    expect(opened, ['s-1']);
    expect(server.read, ['a-1', 'a-2']);
    expect(find.byKey(const Key('alert-popup')), findsNothing);
  });

  testWidgets('a poll in between neither repeats one nor opens a second', (
    tester,
  ) async {
    final server = _Server();
    final popups = await _pump(tester, server);

    final first = [_alert('a-1', 'Storm coming in.')];
    popups.show(_context(tester), first, formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();
    // The next poll still lists it, and brings a new one.
    popups.show(_context(tester), [
      ...first,
      _alert('a-2', 'Take van 7 today.'),
    ], formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('alert-popup')), findsOneWidget);
    expect(find.textContaining('1 of 2'), findsOneWidget);

    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();
    // A poll from before the server heard still lists the first.
    popups.show(_context(tester), [
      ...first,
      _alert('a-2', 'Take van 7 today.'),
    ], formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();
    expect(find.text('Storm coming in.'), findsNothing);
    expect(find.text('Take van 7 today.'), findsOneWidget);
  });

  testWidgets('one the server never heard was dismissed comes back', (
    tester,
  ) async {
    final server = _Server(refuse: true);
    final popups = await _pump(tester, server);

    final open = [_alert('a-1', 'Storm coming in.')];
    popups.show(_context(tester), open, formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('alert-dismiss')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('alert-popup')), findsNothing);

    popups.show(_context(tester), open, formats: DisplayFormats.fallback);
    await tester.pumpAndSettle();
    expect(find.text('Storm coming in.'), findsOneWidget);
  });
}

Map<String, dynamic> _alert(String id, String message) => {
  'id': id,
  'title': message,
  'body': 'From Dana Reyes',
  'sender': 'Dana Reyes',
  'source': 'alert',
  'created_at': '2026-10-07T17:12:00Z',
};

class _Server {
  _Server({this.refuse = false});

  final bool refuse;
  final List<String> read = [];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'POST' && path.endsWith('/read')) {
      if (refuse) return _json({'message': 'Server error'}, 500);
      read.add(path.split('/')[path.split('/').length - 2]);
      return _json({'data': {}});
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

BuildContext _context(WidgetTester tester) =>
    tester.element(find.text('Underneath'));

Future<AlertPopups> _pump(WidgetTester tester, _Server server) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: const Scaffold(body: Center(child: Text('Underneath'))),
    ),
  );
  return AlertPopups(ApiClient(client: MockClient(server.handle)));
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
