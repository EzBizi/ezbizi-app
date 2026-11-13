import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('clicking a dashboard card opens its module', (tester) async {
    var opened = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: DashboardCard(
            title: 'Jobs',
            icon: Icons.work,
            onOpen: () => opened++,
            child: const Text('196 today'),
          ),
        ),
      ),
    );
    await tester.tap(find.text('196 today'));
    expect(opened, 1);
  });

  test('openModule hands the key to the shell', () {
    String? heard;
    void listener() => heard = DeepLinks.module.value;
    DeepLinks.module.addListener(listener);
    DeepLinks.openModule('jobs');
    expect(heard, 'jobs');
    DeepLinks.module.removeListener(listener);
    DeepLinks.module.value = null;
  });
}
