import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/connected_account.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Naming the connected account, and being able to remove it.
///
/// Jose connected EzBizi's own manager account to A-1 Chimney by mistake. The
/// tab's entire response was "No enquiries yet" — true, and no use: a wrong
/// connection and a quiet week render identically. Nothing on the screen said
/// what it was reading, and the only disconnect lived in Marketing, a module
/// these providers are deliberately kept out of.
///
/// So the account name is the fix and the button is the remedy, and the name
/// is the more important of the two.
void main() {
  testWidgets('the account is named, so a wrong one is visible before it is a mystery',
      (tester) async {
    await _pump(tester, const {
      'id': 'conn-1',
      'provider': 'google_ads',
      'display_name': '706-109-4046',
    });

    expect(find.textContaining('706-109-4046'), findsOneWidget);
    expect(find.textContaining('Google Ads'), findsOneWidget);
  });

  testWidgets('disconnecting takes two taps and says what it does', (tester) async {
    final deleted = <String>[];

    await _pump(
      tester,
      const {
        'id': 'conn-1',
        'provider': 'google_ads',
        'display_name': '706-109-4046',
      },
      onDelete: deleted.add,
    );

    await tester.tap(find.text('Disconnect'));
    await tester.pumpAndSettle();

    // Armed, not done. The house rule forbids a dialog, so the confirm is the
    // button changing its mind in place — and the sentence appears with it.
    expect(deleted, isEmpty, reason: 'One tap must not delete the grant.');
    expect(find.textContaining('removes the stored permission'), findsOneWidget);
    expect(find.text('Keep'), findsOneWidget);

    await tester.tap(find.text('Disconnect'));
    await tester.pumpAndSettle();

    expect(deleted, ['/api/v1/social/connections/conn-1']);
  });

  testWidgets('Keep backs out and nothing is deleted', (tester) async {
    final deleted = <String>[];

    await _pump(
      tester,
      const {'id': 'conn-1', 'provider': 'google_ads', 'display_name': '706-109-4046'},
      onDelete: deleted.add,
    );

    await tester.tap(find.text('Disconnect'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Keep'));
    await tester.pumpAndSettle();

    expect(deleted, isEmpty);
    expect(find.text('Keep'), findsNothing);
  });

  testWidgets('a refusal shows the server sentence rather than swallowing it',
      (tester) async {
    await _pump(
      tester,
      const {'id': 'conn-1', 'provider': 'google_ads', 'display_name': '706-109-4046'},
      refuseWith: 'You do not have permission to disconnect a social account.',
    );

    await tester.tap(find.text('Disconnect'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Disconnect'));
    await tester.pumpAndSettle();

    expect(find.textContaining('do not have permission'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  Map<String, dynamic> connection, {
  void Function(String path)? onDelete,
  String? refuseWith,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'DELETE') {
        onDelete?.call(request.url.path);

        if (refuseWith != null) {
          return http.Response(
            jsonEncode({'message': refuseWith}),
            403,
            headers: {'content-type': 'application/json'},
          );
        }

        return http.Response(
          jsonEncode({
            'data': {'id': connection['id']},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{}', 200, headers: {'content-type': 'application/json'});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ConnectedAccount(
          api: api,
          connection: connection,
          onDisconnected: () async {},
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
