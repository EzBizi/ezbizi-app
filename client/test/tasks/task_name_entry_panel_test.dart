import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/tasks/name_entry_panel.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_folder_administration.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// `StartUp.md` is explicit: "No pop-ups. Ever. A new screen, or a right-side
/// panel", and "Add a modal" is on the never-do list. Tasks shipped Phase 1
/// with two `AlertDialog`s anyway — they went through nineteen scoped reviews,
/// a plan checklist and a green gate, because none of those look at house
/// style. The `findsNothing` assertions below are the point of this file: they
/// fail if a dialog comes back, which review demonstrably does not.
void main() {
  group('name entry request', () {
    test('submit completes with the trimmed name', () async {
      final request = _request();
      request.submit('  Field work  '.trim());
      expect(await request.future, 'Field work');
    });

    test('cancel completes with null, the contract showDialog had', () async {
      final request = _request();
      request.cancel();
      expect(await request.future, isNull);
    });

    test('a second answer cannot overwrite the first', () async {
      final request = _request();
      request.submit('First');
      request.cancel();
      request.submit('Third');
      expect(await request.future, 'First');
    });
  });

  testWidgets('naming a folder happens in a panel, not a pop-up', (
    tester,
  ) async {
    final posted = <String>[];
    final api = _folderApi(posted);

    await tester.pumpWidget(_host(api));
    await tester.pumpAndSettle();

    expect(find.byType(AlertDialog), findsNothing);

    await tester.tap(find.text('New folder'));
    await tester.pumpAndSettle();

    // The panel is present and the pop-up is not. If someone restores
    // showDialog, the second expectation fails.
    expect(find.byType(NameEntryPanel), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);

    await tester.enterText(find.byType(TextField), 'Field work');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, contains('Field work'));
    expect(find.byType(NameEntryPanel), findsNothing);
  });

  testWidgets('cancelling the panel creates nothing and restores the list', (
    tester,
  ) async {
    final posted = <String>[];
    final api = _folderApi(posted);

    await tester.pumpWidget(_host(api));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New folder'));
    await tester.pumpAndSettle();
    expect(find.byType(NameEntryPanel), findsOneWidget);

    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    expect(posted, isEmpty);
    expect(find.byType(NameEntryPanel), findsNothing);
    expect(find.text('New folder'), findsOneWidget);
  });

  testWidgets('an empty name is not submittable', (tester) async {
    final posted = <String>[];
    final api = _folderApi(posted);

    await tester.pumpWidget(_host(api));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New folder'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), '   ');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, isEmpty);
    expect(find.byType(NameEntryPanel), findsOneWidget);
  });

  testWidgets('the panel fits a phone', (tester) async {
    tester.view.physicalSize = const Size(375, 812);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = _folderApi(<String>[]);
    await tester.pumpWidget(_host(api));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New folder'));
    await tester.pumpAndSettle();

    expect(find.byType(NameEntryPanel), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

NameEntryRequest _request() => NameEntryRequest(
  title: 'New folder',
  label: 'Folder name',
  submitLabel: 'Save',
);

Widget _host(TaskApi api) => MaterialApp(
  home: Scaffold(
    body: TaskFolderAdministration(
      api: api,
      directoryActions: const <String>['create_folder', 'view_archived'],
      onChanged: (_) {},
      onUnavailable: (_) {},
    ),
  ),
);

TaskApi _folderApi(List<String> posted) => TaskApi(
  ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST') {
        final body = jsonDecode(request.body) as Map<String, Object?>;
        posted.add(body['name'] as String);
        return _json(200, <String, Object?>{
          'data': <String, Object?>{
            'id': 'folder-2',
            'name': body['name'],
            'company_id': 'company-1',
            'franchise_id': null,
            'position': 2,
            'archived': false,
            'actions': <Object?>['update', 'archive'],
          },
        });
      }
      return _json(200, <String, Object?>{'data': <Object?>[]});
    }),
  ),
);

http.Response _json(int status, Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  status,
  headers: const <String, String>{'content-type': 'application/json'},
);
