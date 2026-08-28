import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_board_members.dart';
import 'task_board_settings.dart';
import 'task_models.dart';
import 'task_structure_editor.dart';

class TaskBoardAdministration extends StatefulWidget {
  const TaskBoardAdministration({
    super.key,
    required this.api,
    required this.board,
    required this.folders,
    required this.onBoardChanged,
    required this.onUnavailable,
  });

  final TaskApi api;
  final TaskBoard board;
  final List<TaskFolder> folders;
  final ValueChanged<TaskBoard> onBoardChanged;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskBoardAdministration> createState() =>
      _TaskBoardAdministrationState();
}

class _TaskBoardAdministrationState extends State<TaskBoardAdministration> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final tabs = <(String, Widget)>[
      if (widget.board.can('update') ||
          widget.board.can('archive') ||
          widget.board.can('restore'))
        (
          'General',
          TaskBoardSettings(
            api: widget.api,
            board: widget.board,
            folders: widget.folders,
            onChanged: widget.onBoardChanged,
            onUnavailable: widget.onUnavailable,
          ),
        ),
      if (widget.board.can('manage_structure'))
        (
          'Structure',
          TaskStructureEditor(
            api: widget.api,
            board: widget.board,
            onStructureChanged: (_) {},
            onUnavailable: widget.onUnavailable,
          ),
        ),
      if (widget.board.can('manage_members'))
        (
          'People',
          TaskBoardMembers(
            api: widget.api,
            board: widget.board,
            onUnavailable: widget.onUnavailable,
          ),
        ),
    ];
    if (tabs.isEmpty) return const SizedBox.shrink();
    final selected = _tab.clamp(0, tabs.length - 1);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (tabs.length > 1)
          SegmentedButton<int>(
            segments: [
              for (var index = 0; index < tabs.length; index++)
                ButtonSegment(value: index, label: Text(tabs[index].$1)),
            ],
            selected: <int>{selected},
            onSelectionChanged: (value) => setState(() => _tab = value.single),
          ),
        if (tabs.length > 1) const SizedBox(height: 16),
        Expanded(child: tabs[selected].$2),
      ],
    );
  }
}
