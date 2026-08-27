import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_filters_panel.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('task query controls remain labelled and usable at 375px', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 812);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskFiltersPanel(
            board: TaskBoard.fromJson(_board()),
            configuration: TaskViewConfiguration.empty,
            people: const <TaskParticipant>[],
            onChanged: (_) {},
            onClose: () {},
          ),
        ),
      ),
    );

    expect(find.bySemanticsLabel(RegExp('Search this board')), findsWidgets);
    expect(find.bySemanticsLabel(RegExp('Filter by person')), findsWidgets);
    expect(find.bySemanticsLabel(RegExp('Sort tasks')), findsWidgets);
    expect(find.bySemanticsLabel(RegExp('Group tasks')), findsWidgets);
    expect(find.byTooltip('Close filters'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

Map<String, Object?> _board() => <String, Object?>{
  'id': 'board-1',
  'name': 'Operations',
  'description': null,
  'visibility': 'scope',
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': null,
  'structure_version': 1,
  'archived': false,
  'is_favorite': false,
  'last_opened_at': null,
  'groups': <Object?>[],
  'statuses': <Object?>[],
  'priorities': <Object?>[],
  'core_columns': <Object?>[
    <String, Object?>{'core_key': 'task', 'display_label': 'Task'},
    <String, Object?>{'core_key': 'owner', 'display_label': 'Person'},
    <String, Object?>{'core_key': 'status', 'display_label': 'Status'},
    <String, Object?>{'core_key': 'due_on', 'display_label': 'Due date'},
  ],
  'custom_columns': <Object?>[],
  'views': <Object?>[],
};
