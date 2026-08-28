import 'package:flutter/material.dart';
import 'task_models.dart';

class BoardDirectory extends StatelessWidget {
  const BoardDirectory({
    super.key,
    required this.boards,
    required this.selectedBoardId,
    required this.onSelected,
    this.onCreate,
    this.onRecover,
  });
  final List<TaskBoard> boards;
  final String? selectedBoardId;
  final ValueChanged<TaskBoard> onSelected;
  final VoidCallback? onCreate;
  final VoidCallback? onRecover;
  @override
  Widget build(BuildContext context) {
    final ordered = [...boards]
      ..sort((a, b) {
        if (a.isFavorite != b.isFavorite) return a.isFavorite ? -1 : 1;
        return a.name.toLowerCase().compareTo(b.name.toLowerCase());
      });
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
          child: Row(
            children: [
              const Expanded(
                child: Text(
                  'Boards',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              if (onCreate != null)
                IconButton(
                  tooltip: 'New board',
                  onPressed: onCreate,
                  icon: const Icon(Icons.add),
                ),
              if (onRecover != null)
                IconButton(
                  tooltip: 'Recover private boards',
                  onPressed: onRecover,
                  icon: const Icon(Icons.admin_panel_settings_outlined),
                ),
            ],
          ),
        ),
        for (final board in ordered)
          ListTile(
            selected: board.id == selectedBoardId,
            leading: Icon(
              board.visibility == TaskBoardVisibility.private
                  ? Icons.lock_outline
                  : Icons.table_chart_outlined,
            ),
            title: Text(board.name, overflow: TextOverflow.ellipsis),
            subtitle: board.isFavorite ? const Text('Favorite') : null,
            onTap: () => onSelected(board),
          ),
      ],
    );
  }
}
