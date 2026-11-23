import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_files.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:ezbizi_client/widgets/pdf_view_io.dart' as pdf_io;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board items 1 and 2 (backend 8463815ab): the `creator` column beside
/// `owner` relabelled Assigned, and files on a task update.
void main() {
  final png = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  );

  late List<http.Request> sent;
  late List<String> fetched;
  var canEdit = true;
  var refuse = false;

  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tba');
    canEdit = true;
    refuse = false;
    pdf_io.pdfPages = (_) => Stream.value(Uint8List.fromList(png));
    pickTaskUpdateFile = () async => null;
  });
  tearDown(() {
    store.debugStoreDirOverride = null;
    Files.debugOnDownload = null;
  });

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  const root = 'http://localhost/api/v1/attachments';
  final updates = [
    {
      'id': 'u1',
      'body': 'Photos from the roof',
      'author': {'id': 'me', 'name': 'Maya Lopez'},
      'created_at': '2026-10-27T14:10:00Z',
      'attachments': [
        {
          'id': 'a-img',
          'name': 'roof.jpg',
          'mime': 'image/jpeg',
          'size': 204800,
          'url': '$root/a-img',
          'inline_url': '$root/a-img?inline=1',
          'thumbnail_url': '$root/a-img?inline=1',
        },
        {
          'id': 'a-pdf',
          'name': 'quote.pdf',
          'mime': 'application/pdf',
          'size': 52000,
          'url': '$root/a-pdf',
          'inline_url': '$root/a-pdf?inline=1',
          'thumbnail_url': null,
        },
        {
          'id': 'a-doc',
          'name': 'notes-for-the-crew.docx',
          'mime':
              'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'size': 3 * 1024 * 1024,
          'url': '$root/a-doc',
          'inline_url': null,
          'thumbnail_url': null,
        },
      ],
    },
  ];

  ApiClient fakeServer() {
    sent = [];
    fetched = [];
    final task = {
      'id': 't1',
      'board_id': 'b-me',
      'group_id': 'g1',
      'title': 'Order liner parts',
      'status': 'working_on_it',
      'owner': {'id': 'u2', 'name': 'Omar Feld'},
      'due_on': null,
      'about': null,
      'updates_count': 1,
      'created_by': {'type': 'user', 'id': 'me', 'label': 'Maya Lopez'},
    };
    final t2 = {
      ...task,
      'id': 't2',
      'title': 'Call back about the cap',
      'owner': null,
      'created_by': {'type': 'automation', 'label': 'New lead follow-up'},
    };
    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        if (request.method != 'GET') sent.add(request);
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northside Chimney',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path == '/task-boards') {
          return json({
            'data': [
              {
                'id': 'b-me',
                'kind': 'user',
                'name': 'Maya Lopez',
                'open_for_me': 2,
                'can_edit': canEdit,
              },
            ],
          });
        }
        if (path == '/task-boards/b-me') {
          return json({
            'data': {
              'id': 'b-me',
              'kind': 'user',
              'name': 'Maya Lopez',
              'can_edit': canEdit,
              'statuses': const [
                {'key': 'working_on_it', 'label': 'Working on it'},
                {'key': 'done', 'label': 'Done'},
              ],
              'builtin_columns': {
                'creator': {'label': 'Owner', 'visible': true},
                'owner': {'label': 'Assigned', 'visible': true},
                'status': {'label': 'Status', 'visible': true},
                'created': {'label': 'Created', 'visible': false},
                'due': {'label': 'Due', 'visible': true},
                'about': {'label': 'About', 'visible': true},
                'updates': {'label': 'Updates', 'visible': true},
              },
              'assignable': [
                {'id': 'me', 'name': 'Maya Lopez'},
              ],
              'groups': [
                {
                  'id': 'g1',
                  'name': 'This week',
                  'colour': '#579BFC',
                  'position': 0,
                },
              ],
              'tasks': [task, t2],
            },
          });
        }
        if (path.startsWith('/attachments/')) {
          fetched.add('${request.url.path}?${request.url.query}');
          return http.Response.bytes(png, 200);
        }
        if (path == '/tasks/t1/updates') {
          if (refuse) {
            return json({
              'message': 'That file is too large.',
              'errors': {
                'files.0': [
                  'That file is too large. The most an update takes is 25 MB '
                      'per file.',
                ],
              },
            }, 422);
          }
          return json({
            'data': {
              'id': 'u2',
              'body': null,
              'author': {'id': 'me', 'name': 'Maya Lopez'},
              'created_at': '2026-10-27T15:00:00Z',
              'attachments': <dynamic>[],
            },
          }, 201);
        }
        if (path == '/tasks/t1') {
          return json({
            'data': {...task, 'updates': updates},
          });
        }
        return json({'message': 'Not found.'}, 404);
      }),
    );
  }

  Future<void> pumpBoard(
    WidgetTester tester, {
    Size size = const Size(1400, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = fakeServer();
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tapKey(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await tester.pumpAndSettle();
    await tester.tap(f);
  }

  Future<void> openTask(WidgetTester tester) async {
    // The name opens the task; Owner and About edit in place (Jose, 2026-11-13).
    await tester.ensureVisible(find.byKey(const ValueKey('task-t1')));
    await tester.pumpAndSettle();
    await tester.tapAt(
      tester.getTopLeft(find.byKey(const ValueKey('task-t1'))) +
          const Offset(160, 19),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('Owner is who made the task, and the assignees are Assigned', (
    tester,
  ) async {
    await pumpBoard(tester);

    expect(find.text('OWNER'), findsOneWidget);
    expect(find.text('ASSIGNED'), findsOneWidget);
    final person = find.byKey(const ValueKey('task-creator-t1'));
    // A person who made it is a face; the name is its label and hover card.
    expect(
      find.descendant(of: person, matching: find.text('Maya Lopez')),
      findsNothing,
    );
    expect(
      find.descendant(
        of: person,
        matching: find.byWidgetPredicate(
          (w) => w is Semantics && w.properties.label == 'Maya Lopez',
        ),
      ),
      findsOneWidget,
    );
    final rule = find.byKey(const ValueKey('task-creator-t2'));
    // A rule's task was made by EzBizi (Jose 11-17): the mark, the rule on hover.
    expect(
      find.descendant(of: rule, matching: find.text('New lead follow-up')),
      findsNothing,
    );
    expect(
      find.descendant(
        of: rule,
        matching: find.byWidgetPredicate(
          (w) => w is Tooltip && w.message == 'EzBizi · New lead follow-up',
        ),
      ),
      findsOneWidget,
    );
    // The assigned cell keeps the assignee, as a face.
    expect(find.text('Omar'), findsNothing);
    expect(
      find.byWidgetPredicate(
        (w) => w is Semantics && (w.properties.label ?? '').contains('Omar'),
      ),
      findsWidgets,
    );
  });

  testWidgets('files go as files[] beside an optional body, chosen ones '
      'removable before sending', (tester) async {
    await pumpBoard(tester);
    await openTask(tester);

    var n = 0;
    pickTaskUpdateFile = () async =>
        PickedBytes(n++ == 0 ? 'gauge.txt' : 'second.csv', utf8.encode('psi'));
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-update-file-1')), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('task-update-file-1')),
        matching: find.byIcon(Icons.clear),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-update-file-1')), findsNothing);

    // No body: a file alone is an update.
    await tapKey(tester, find.byKey(const Key('task-update-post')));
    await tester.pumpAndSettle();

    final req = sent.single;
    expect(req.url.path, '/api/v1/tasks/t1/updates');
    expect(req.headers['content-type'], startsWith('multipart/form-data'));
    expect(req.body, contains('name="files[]"; filename="gauge.txt"'));
    expect(req.body, isNot(contains('second.csv')));
    expect(req.body, isNot(contains('name="body"')));
    expect(find.byKey(const ValueKey('task-update-file-0')), findsNothing);

    // With words, the body travels in the same form.
    pickTaskUpdateFile = () async => PickedBytes('b.txt', utf8.encode('x'));
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('task-update-body')),
      'Gauge reading',
    );
    await tapKey(tester, find.byKey(const Key('task-update-post')));
    await tester.pumpAndSettle();
    expect(sent.last.body, contains('name="body"'));
    expect(sent.last.body, contains('Gauge reading'));
  });

  testWidgets("a refused upload shows the server's 422 sentence inline", (
    tester,
  ) async {
    refuse = true;
    await pumpBoard(tester);
    await openTask(tester);
    pickTaskUpdateFile = () async => PickedBytes('a.pdf', utf8.encode('%PDF'));
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();
    await tapKey(tester, find.byKey(const Key('task-update-post')));
    await tester.pumpAndSettle();

    expect(
      tester.widget<Text>(find.byKey(const Key('task-update-error'))).data,
      'That file is too large. The most an update takes is 25 MB per file.',
    );
    // The file stays to try again.
    expect(find.byKey(const ValueKey('task-update-file-0')), findsOneWidget);
  });

  testWidgets('the client refuses what the server would, before uploading', (
    tester,
  ) async {
    await pumpBoard(tester);
    await openTask(tester);
    pickTaskUpdateFile = () async => PickedBytes('run.exe', utf8.encode('x'));
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();
    expect(
      tester.widget<Text>(find.byKey(const Key('task-update-error'))).data,
      startsWith('That kind of file is not accepted here.'),
    );
    expect(find.byKey(const ValueKey('task-update-file-0')), findsNothing);

    expect(
      taskUpdateFileRefusal('big.mp4', taskUpdateMaxBytes + 1, 0),
      startsWith('That file is too large.'),
    );
    expect(
      taskUpdateFileRefusal('ok.png', 10, taskUpdateMaxFiles),
      'An update takes at most 10 files.',
    );
    expect(taskUpdateFileRefusal('ok.PNG', taskUpdateMaxBytes, 9), isNull);
    expect(sent, isEmpty);
  });

  testWidgets('an image is a thumbnail from thumbnail_url, anything else a '
      'chip with its name and size', (tester) async {
    await pumpBoard(tester);
    await openTask(tester);

    expect(fetched, contains('/api/v1/attachments/a-img?inline=1'));
    expect(find.byKey(const ValueKey('task-thumb-a-img')), findsOneWidget);
    final doc = find.byKey(const ValueKey('task-attachment-a-doc'));
    expect(
      find.descendant(
        of: doc,
        matching: find.text('notes-for-the-crew.docx · 3.0 MB'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('task-attachment-a-pdf')),
        matching: find.byIcon(Icons.picture_as_pdf_outlined),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an image and a PDF open in the viewer, with Download', (
    tester,
  ) async {
    final saved = <String>[];
    Files.debugOnDownload = (name, _) => saved.add(name);
    await pumpBoard(tester);
    await openTask(tester);

    await tapKey(tester, find.byKey(const ValueKey('task-attachment-a-img')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-attachment-viewer')), findsOneWidget);
    expect(find.byKey(const Key('task-attachment-image')), findsOneWidget);
    await tapKey(tester, find.byKey(const Key('task-attachment-download')));
    expect(saved, ['roof.jpg']);
    Navigator.of(
      tester.element(find.byKey(const Key('task-attachment-viewer'))),
    ).pop();
    await tester.pumpAndSettle();

    await tapKey(tester, find.byKey(const ValueKey('task-attachment-a-pdf')));
    await tester.pumpAndSettle();
    expect(fetched, contains('/api/v1/attachments/a-pdf?inline=1'));
    expect(find.byKey(const Key('task-attachment-pdf')), findsOneWidget);
    expect(find.byKey(const Key('task-attachment-download')), findsOneWidget);
    Navigator.of(
      tester.element(find.byKey(const Key('task-attachment-viewer'))),
    ).pop();
    await tester.pumpAndSettle();

    // A Word file is not viewed; it is downloaded.
    await tapKey(tester, find.byKey(const ValueKey('task-attachment-a-doc')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-attachment-viewer')), findsNothing);
    expect(saved.last, 'notes-for-the-crew.docx');
    expect(fetched, contains('/api/v1/attachments/a-doc?'));
  });

  testWidgets('a board I may only read has no Attach, but shows the files', (
    tester,
  ) async {
    canEdit = false;
    await pumpBoard(tester);
    await openTask(tester);

    expect(find.byKey(const Key('task-update-attach')), findsNothing);
    expect(find.byKey(const ValueKey('task-attachment-a-doc')), findsOneWidget);
  });

  testWidgets('a phone at 390x844 fits the files and the composer', (
    tester,
  ) async {
    await pumpBoard(tester, size: const Size(390, 844));
    await openTask(tester);
    pickTaskUpdateFile = () async => PickedBytes(
      'a-very-long-file-name-for-the-chimney-inspection-report.pdf',
      utf8.encode('%PDF'),
    );
    await tapKey(tester, find.byKey(const Key('task-update-attach')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('task-update-file-0')), findsOneWidget);
    expect(tester.takeException(), isNull);
    for (final key in [
      const Key('task-update-attach'),
      const Key('task-update-post'),
    ]) {
      expect(tester.getRect(find.byKey(key)).right, lessThanOrEqualTo(390));
    }

    await tapKey(tester, find.byKey(const ValueKey('task-attachment-a-pdf')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-attachment-download')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('Select files picks several attachments and downloads each', (
    tester,
  ) async {
    final saved = <String>[];
    Files.debugOnDownload = (name, _) => saved.add(name);
    await pumpBoard(tester);
    await openTask(tester);
    await tester.tap(find.byKey(const Key('task-attachments-select')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('task-attachment-pick-a-pdf')));
    await tester.tap(find.byKey(const ValueKey('task-attachment-pick-a-doc')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-attachments-download')));
    await tester.pumpAndSettle();
    expect(saved, containsAll(['quote.pdf', 'notes-for-the-crew.docx']));
    expect(saved, isNot(contains('roof.jpg')));
  });

  testWidgets('an update body is selectable text', (tester) async {
    await pumpBoard(tester);
    await openTask(tester);
    final body = find.byKey(const ValueKey('task-update-body-u1'));
    expect(tester.widget<SelectableText>(body).textSpan!.toPlainText(), 'Photos from the roof');
  });
}
