import 'package:flutter/material.dart';
import 'task_models.dart';

class TaskMobileList extends StatelessWidget {
  const TaskMobileList({
    super.key,
    required this.board,
    required this.rows,
    required this.onSelected,
  });
  final TaskBoard board;
  final List<TaskRow> rows;
  final ValueChanged<TaskRow> onSelected;
  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(12),
    children: [
      for (final group in board.groups) ...[
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 12, 4, 6),
          child: Text(
            group.name,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
        for (final row in rows.where((row) => row.groupId == group.id))
          Card(
            child: ListTile(
              minVerticalPadding: 12,
              title: Text(
                row.title,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              subtitle: Text(
                row.dueOn == null
                    ? 'No due date'
                    : 'Due ${row.dueOn!.month}/${row.dueOn!.day}',
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => onSelected(row),
            ),
          ),
      ],
    ],
  );
}
