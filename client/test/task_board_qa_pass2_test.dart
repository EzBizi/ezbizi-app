import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/widgets/priority_row.dart';
import 'package:ezbizi_client/widgets/user_avatar.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board hands-on QA, 2026-11-16 (docs/reviews/task-board-handson-
/// 2026-11-16.md): the panel's fields read as the table's, footers fit,
/// a face without a photo asks for none, and the toolbar is one line.
void main() {
  const formats = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  BoardColumn column(String type) => (
    id: 'c-$type',
    name: type,
    type: type,
    unit: null,
    max: null,
    options: const [],
    format: 'plain',
    footer: 'none',
    formula: null,
  );
  Widget host(Widget child) => MaterialApp(
    home: Scaffold(body: Center(child: child)),
  );

  testWidgets('a phone in the task panel reads as the table reads it, and '
      'left as shown it is no change', (tester) async {
    final changes = <Object?>[];
    var done = 0;
    await tester.pumpWidget(
      host(
        SizedBox(
          width: 300,
          child: ColumnEditor(
            column: column('phone'),
            value: '+14405550100',
            people: const {},
            formats: formats,
            onChanged: changes.add,
            onDone: () => done++,
          ),
        ),
      ),
    );
    expect(find.text('(440) 555-0100'), findsOneWidget);
    await tester.tap(find.byType(TextField));
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();
    expect(done, 1);
    expect(changes, isEmpty);

    await tester.enterText(find.byType(TextField), '(440) 555-0199');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();
    expect(changes, ['(440) 555-0199']);
  });

  testWidgets('a checkbox column is a Checkbox in the panel, as in the table', (
    tester,
  ) async {
    Object? got;
    await tester.pumpWidget(
      host(
        ColumnEditor(
          column: column('checkbox'),
          value: false,
          people: const {},
          formats: formats,
          onChanged: (v) => got = v,
        ),
      ),
    );
    expect(find.byType(Switch), findsNothing);
    await tester.tap(find.byType(Checkbox));
    expect(got, isTrue);
  });

  test(
    'a footer range drops the year, and one month runs its days together',
    () {
      final nov = [DateTime(2026, 11, 16), DateTime(2026, 11, 21)];
      expect(dateRangeLabel(nov, formats, short: true), '11/16–21');
      expect(
        dateRangeLabel(
          [DateTime(2026, 11, 30), DateTime(2026, 12, 2)],
          formats,
          short: true,
        ),
        '11/30–12/2',
      );
      expect(
        dateRangeLabel(
          nov,
          const DisplayFormats(dateFormat: 'D/M/YYYY', timeFormat: '24h'),
          short: true,
        ),
        '16–21/11',
      );
      // Across a year the year stays; without short nothing changes.
      expect(
        dateRangeLabel(
          [DateTime(2026, 12, 30), DateTime(2027, 1, 2)],
          formats,
          short: true,
        ),
        '12/30/2026 – 1/2/2027',
      );
      expect(dateRangeLabel(nov, formats), '11/16/2026 – 11/21/2026');
    },
  );

  testWidgets('a face the board says has no photo asks for none', (
    tester,
  ) async {
    final asked = <String>[];
    final api = ApiClient(
      client: MockClient((r) async {
        asked.add(r.url.path);
        return http.Response('', 404);
      }),
    );
    UserAvatar.knownPhotos({'u-none': false, 'u-photo': true});
    await tester.pumpWidget(
      host(
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            UserAvatar(api: api, userId: 'u-none', name: 'No Photo'),
            UserAvatar(api: api, userId: 'u-photo', name: 'Has Photo'),
          ],
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(asked, ['/api/v1/users/u-photo/avatar']);
    expect(find.text('NP'), findsOneWidget);
  });

  testWidgets('PriorityRow keeps one line: what does not fit is left to the '
      'overflow, in order', (tester) async {
    var showing = -1;
    Widget box(int i) => SizedBox(
      key: ValueKey('b$i'),
      width: 100,
      height: 30,
      child: const ColoredBox(color: Colors.teal),
    );
    await tester.pumpWidget(
      host(
        SizedBox(
          width: 400,
          child: PriorityRow(
            children: [for (var i = 0; i < 6; i++) box(i)],
            overflow: (context, n) {
              showing = n;
              return const SizedBox(key: Key('more'), width: 40, height: 30);
            },
          ),
        ),
      ),
    );
    await tester.pump();
    // 400 less the overflow (40) and a gap (8) leaves 352: three of 100.
    expect(showing, 3);
    expect(find.byKey(const ValueKey('b2')).hitTestable(), findsOneWidget);
    expect(find.byKey(const ValueKey('b3')).hitTestable(), findsNothing);
    final y = tester.getCenter(find.byKey(const ValueKey('b0'))).dy;
    expect(tester.getCenter(find.byKey(const Key('more'))).dy, y);
    expect(
      tester.getTopRight(find.byKey(const Key('more'))).dx,
      tester.getTopRight(find.byType(PriorityRow)).dx,
    );
  });
}
