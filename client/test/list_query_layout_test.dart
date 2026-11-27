import 'package:ezbizi_client/widgets/list_filters.dart';
import 'package:flutter_test/flutter_test.dart';

// A saved view carries its layout (Monday's Kanban / Calendar views map to
// config.layout); a view without one leaves the screen's own.
void main() {
  test('layout round-trips and is optional', () {
    final q = ListQuery.fromJson({'layout': 'kanban'});
    expect(q.layout, 'kanban');
    expect(q.toJson()['layout'], 'kanban');
    expect(q.isEmpty, isTrue);

    final none = ListQuery.fromJson({'sort': []});
    expect(none.layout, isNull);
    expect(none.toJson().containsKey('layout'), isFalse);
    expect(none.copyWith(layout: 'calendar').layout, 'calendar');
  });
}
