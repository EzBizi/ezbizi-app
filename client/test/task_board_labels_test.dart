import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Monday parity, 2026-11-13: label blocks, the timeline pill and footers.
void main() {
  Widget host(Widget child) => MaterialApp(
    home: Scaffold(body: Center(child: child)),
  );

  testWidgets('label blocks: one column for few labels, three for many, '
      'the current outlined, a pick reported', (tester) async {
    String? picked = 'x';
    final few = [
      for (var i = 0; i < 4; i++)
        (id: '$i', label: 'L$i', colour: const Color(0xFF225588)),
    ];
    await tester.pumpWidget(
      host(
        LabelBlocks(
          keyPrefix: 'b',
          labels: few,
          current: '1',
          onPick: (id) => picked = id,
        ),
      ),
    );
    final one = tester.getSize(find.byType(Wrap)).width;
    await tester.tap(find.byKey(const ValueKey('b-2')));
    expect(picked, '2');
    final current = tester.widget<Material>(
      find
          .ancestor(
            of: find.byKey(const ValueKey('b-1')),
            matching: find.byType(Material),
          )
          .first,
    );
    expect((current.shape as RoundedRectangleBorder).side.width, 2);

    await tester.pumpWidget(
      host(
        LabelBlocks(
          keyPrefix: 'b',
          labels: [
            for (var i = 0; i < 13; i++)
              (id: '$i', label: 'L$i', colour: const Color(0xFF225588)),
          ],
          current: null,
          onPick: (_) {},
        ),
      ),
    );
    expect(tester.getSize(find.byType(Wrap)).width, greaterThan(one * 2.5));
  });

  testWidgets('a timeline pill fills the elapsed part; an empty one is "-"', (
    tester,
  ) async {
    const formats = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
    await tester.pumpWidget(
      host(
        SizedBox(
          width: 200,
          child: TimelinePill(
            value: const {'from': '2026-11-01', 'to': '2026-11-10'},
            formats: formats,
            now: DateTime(2026, 11, 6),
          ),
        ),
      ),
    );
    final f = tester.widget<FractionallySizedBox>(
      find.byKey(const Key('timeline-elapsed')),
    );
    expect(f.widthFactor, closeTo(0.5, 0.01));

    await tester.pumpWidget(
      host(const TimelinePill(value: null, formats: formats)),
    );
    expect(find.text('-'), findsOneWidget);
  });

  test('a footer date range runs from the earliest to the latest day', () {
    const formats = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
    final days = [
      ...daysOf('2026-11-09'),
      ...daysOf(const {'from': '2026-11-02', 'to': '2026-11-20'}),
      ...daysOf(null),
    ];
    expect(
      dateRangeLabel(days, formats),
      '${formats.formatDate(DateTime(2026, 11, 2))} – '
      '${formats.formatDate(DateTime(2026, 11, 20))}',
    );
    expect(dateRangeLabel(const [], formats), isNull);
  });

  testWidgets('a people cell shows three faces and +N', (tester) async {
    await tester.pumpWidget(
      host(
        const PeopleAvatars(names: ['Ann Lee', 'Bo Ray', 'Cy Dee', 'Di Fo']),
      ),
    );
    expect(find.text('AL'), findsOneWidget);
    expect(find.text('DF'), findsNothing);
    expect(find.text('+1'), findsOneWidget);
  });
}
