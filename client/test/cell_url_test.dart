import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Jose 11-24: an EzBizi address in a cell opens in the app, any other '
      'in the browser', () {
    const inApp =
        'https://app.ezbizi.com/app/#/customer_experience/complaints/c-1';
    expect(cellUrl('see $inApp please'), inApp);
    expect(
      cellUrl({'url': 'https://x.test/a', 'label': 'A'}),
      'https://x.test/a',
    );
    expect(cellUrl('no address here'), isNull);

    final launched = <Uri>[];
    Future<bool> launch(Uri u) async {
      launched.add(u);
      return true;
    }

    openCellUrl(inApp, launch: launch);
    expect(launched, isEmpty);
    final link = DeepLinks.pending.value!;
    expect(
      [link.module, link.type, link.id],
      ['customer_experience', 'complaint', 'c-1'],
    );
    expect(DeepLinks.module.value, 'customer_experience');
    DeepLinks.pending.value = null;
    DeepLinks.module.value = null;

    openCellUrl('https://example.com/review', launch: launch);
    expect(launched, [Uri.parse('https://example.com/review')]);
    expect(DeepLinks.pending.value, isNull);
  });
}
