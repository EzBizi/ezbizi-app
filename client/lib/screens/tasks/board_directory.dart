import 'package:flutter/material.dart';
import 'task_models.dart';

class BoardDirectory extends StatelessWidget {
  const BoardDirectory({
    super.key,
    required this.boards,
    this.folders = const <TaskFolder>[],
    required this.selectedBoardId,
    required this.onSelected,
    this.onCreate,
    this.onRecover,
    this.onManageFolders,
  });
  final List<TaskBoard> boards;
  final List<TaskFolder> folders;
  final String? selectedBoardId;
  final ValueChanged<TaskBoard> onSelected;
  final VoidCallback? onCreate;
  final VoidCallback? onRecover;
  final VoidCallback? onManageFolders;
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
              if (onManageFolders != null)
                IconButton(
                  tooltip: 'Manage board folders',
                  onPressed: onManageFolders,
                  icon: const Icon(Icons.folder_outlined),
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
            subtitle: Text(
              [
                if (board.isFavorite) 'Favorite',
                if (board.folderId != null)
                  folders
                          .where((folder) => folder.id == board.folderId)
                          .map((folder) => folder.name)
                          .firstOrNull ??
                      'Folder unavailable',
              ].join(' · '),
            ),
            onTap: () => onSelected(board),
          ),
      ],
    );
  }
}

extension _BoardDirectoryFirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
