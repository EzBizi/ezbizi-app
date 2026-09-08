import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/widgets/scope_picker_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, dynamic> account(String id, String view) => {
  'id': id,
  'name': id,
  'email': '$id@example.test',
  'organization': {'id': 'org', 'name': 'Business'},
  'scope': {
    'type': 'company',
    'id': view,
    'label': view,
    'path': [view],
  },
  'companies': [
    {
      'id': 'child',
      'name': 'Child company',
      'parent_company_id': 'hidden-parent',
    },
  ],
  'franchises': [
    {'id': 'branch', 'name': 'Child branch', 'company_id': 'child'},
  ],
};
http.Response me(String id, String view) =>
    http.Response(jsonEncode({'data': account(id, view)}), 200);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'requests retain displayed view across another device preference change, including uploads and bytes',
    () async {
      final requests = <http.Request>[];
      final api = ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          return request.url.path.endsWith('/me')
              ? me('u', 'a')
              : http.Response('{}', 200);
        }),
      );
      final session = Session(api);
      await session.adopt('token');
      await api.get('/jobs');
      await api.postFile('/avatar', 'avatar', 'a.png', [1]);
      await api.postFiles('/email', 'attachments', [
        (name: 'a.txt', bytes: [1]),
      ]);
      await api.getBytes('/exports/jobs');
      for (final request in requests.skip(1)) {
        expect(request.headers['X-EzBizi-View'], 'company:a');
      }
    },
  );

  test(
    'switch refresh explicitly requests target and failed switch retains displayed view',
    () async {
      final requests = <http.Request>[];
      var refuse = false;
      final api = ApiClient(
        client: MockClient((request) async {
          requests.add(request);
          if (request.method == 'PATCH') {
            return http.Response('{}', refuse ? 403 : 200);
          }
          return me(
            'u',
            request.headers['X-EzBizi-View'] == 'company:b' ? 'b' : 'a',
          );
        }),
      );
      final session = Session(api);
      await session.adopt('token');
      await session.setScope('company:b');
      expect(session.me!.scope.id, 'b');
      expect(requests.last.headers['X-EzBizi-View'], 'company:b');
      refuse = true;
      await session.setScope('company:c');
      await api.get('/jobs');
      expect(session.me!.scope.id, 'b');
      expect(requests.last.headers['X-EzBizi-View'], 'company:b');
    },
  );

  test(
    'late successful refresh cannot restore logged-out or replace new identity',
    () async {
      final pending = Completer<http.Response>();
      var count = 0;
      final api = ApiClient(
        client: MockClient((request) async {
          count++;
          return count == 2
              ? pending.future
              : me(count == 1 ? 'old' : 'new', 'a');
        }),
      );
      final session = Session(api);
      await session.adopt('old-token');
      final refresh = session.refresh();
      session.signOut();
      await session.adopt('new-token');
      pending.complete(me('old', 'b'));
      await refresh;
      expect(session.me!.id, 'new');
      expect(session.me!.scope.id, 'a');
    },
  );

  test('late 401 from previous token cannot expire the new session', () async {
    final pending = Completer<http.Response>();
    final api = ApiClient(
      client: MockClient(
        (request) async =>
            request.url.path.endsWith('/jobs') ? pending.future : me('u', 'a'),
      ),
    );
    final session = Session(api);
    await session.adopt('old-token');
    final oldRequest = api.get('/jobs');
    session.signOut();
    await session.adopt('new-token');
    pending.complete(http.Response('{}', 401));
    await oldRequest;
    expect(session.signedIn, isTrue);
    expect(api.hasToken, isTrue);
  });

  test(
    'logout during a refresh stays signed out after the response arrives',
    () async {
      final pending = Completer<http.Response>();
      var first = true;
      final api = ApiClient(
        client: MockClient((request) async {
          if (first) {
            first = false;
            return me('u', 'a');
          }
          return pending.future;
        }),
      );
      final session = Session(api);
      await session.adopt('token');
      final refresh = session.refresh();
      session.signOut();
      pending.complete(me('u', 'a'));
      await refresh;
      expect(session.signedIn, isFalse);
      expect(api.hasToken, isFalse);
    },
  );

  test(
    'a newer switch wins over a late target response and ordinary refresh',
    () async {
      final pending = Completer<http.Response>();
      final requestedB = Completer<void>();
      String? lastView;
      final api = ApiClient(
        client: MockClient((request) async {
          lastView = request.headers['X-EzBizi-View'];
          if (request.method == 'PATCH') return http.Response('{}', 200);
          if (lastView == 'company:b') {
            requestedB.complete();
            return pending.future;
          }
          return me('u', lastView == 'company:c' ? 'c' : 'a');
        }),
      );
      final session = Session(api);
      await session.adopt('token');
      final switchB = session.setScope('company:b');
      await requestedB.future;
      await session.refresh();
      await session.setScope('company:c');
      pending.complete(me('u', 'b'));
      await switchB;
      await api.get('/jobs');
      expect(session.me!.scope.id, 'c');
      expect(lastView, 'company:c');
    },
  );

  test(
    'a failed target refresh preserves the displayed request context',
    () async {
      String? lastView;
      final api = ApiClient(
        client: MockClient((request) async {
          lastView = request.headers['X-EzBizi-View'];
          if (request.method == 'PATCH') return http.Response('{}', 200);
          if (lastView == 'company:b') return http.Response('{}', 500);
          return me('u', 'a');
        }),
      );
      final session = Session(api);
      await session.adopt('token');
      await session.setScope('company:b');
      expect(session.me!.scope.id, 'a');
      expect(session.unreachable, isTrue);
      await api.get('/jobs');
      expect(lastView, 'company:a');
    },
  );

  test('late ordinary refresh cannot overwrite a newer refresh', () async {
    final pending = Completer<http.Response>();
    var calls = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        calls++;
        if (calls == 2) return pending.future;
        return me(calls == 1 ? 'initial' : 'latest', 'a');
      }),
    );
    final session = Session(api);
    await session.adopt('token');
    final oldRefresh = session.refresh();
    await session.refresh();
    pending.complete(me('stale', 'a'));
    await oldRefresh;
    expect(session.me!.id, 'latest');
  });

  for (final multiple in [false, true]) {
    test(
      'multipart ($multiple) expires a refused authenticated session',
      () async {
        final api = ApiClient(
          client: MockClient(
            (request) async => request.url.path.endsWith('/me')
                ? me('u', 'a')
                : http.Response('not json', 401),
          ),
        );
        final session = Session(api);
        await session.adopt('token');
        if (multiple) {
          await api.postFiles('/upload', 'files', [
            (name: 'a.txt', bytes: [1]),
          ]);
        } else {
          await api.postFile('/upload', 'file', 'a.txt', [1]);
        }
        expect(session.signedIn, isFalse);
        expect(session.expired, isTrue);
      },
    );
  }

  test('staff requests expire staff session on 401', () async {
    final api = ApiClient(
      client: MockClient(
        (request) async => request.url.path.endsWith('/me')
            ? http.Response(
                jsonEncode({
                  'data': {
                    'id': 's',
                    'name': 'Staff',
                    'email': 's@example.test',
                    'tier': 'developer',
                  },
                }),
                200,
              )
            : http.Response('{}', 401),
      ),
    );
    final session = StaffSession(api);
    await session.adopt('staff-token');
    await api.get('/staff/organizations');
    expect(session.signedIn, isFalse);
    expect(api.hasToken, isFalse);
  });

  testWidgets(
    'reachable child company is a picker root when parent is absent',
    (tester) async {
      final api = ApiClient(
        client: MockClient(
          (request) async => request.url.path.endsWith('/me')
              ? me('u', 'child')
              : http.Response('{}', 404),
        ),
      );
      final session = Session(api);
      await session.adopt('token');
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ScopePickerPanel(api: api, session: session),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Child company'), findsOneWidget);
      expect(find.text('Child branch'), findsOneWidget);
    },
  );
}
