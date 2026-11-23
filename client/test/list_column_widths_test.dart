import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';

/// An empty PHP array is `[]` on the wire, not `{}`: the saved column widths
/// came back as a List and Project Management drew Flutter's grey error box
/// on Windows (0.0.0559, 11-11).
void main() {
  Me me(Object? widths) => Me.fromJson({
    'id': 'u1',
    'name': 'Jose',
    'email': 'jose@example.test',
    'organization': {'id': 'o1', 'name': 'A-1'},
    'preferences': {'lists.column_widths': widths},
  });

  test('an empty list of widths reads as none saved', () {
    expect(me(<dynamic>[]).listColumnWidths('project-board'), isEmpty);
  });

  test('saved widths are read for their own list only', () {
    expect(
      me({'project-board:2': 180, 'leads:0': 90}).listColumnWidths(
        'project-board',
      ),
      {2: 180.0},
    );
  });
}
