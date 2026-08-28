import 'package:flutter/material.dart';

import 'task_models.dart';

class MyTasksView extends StatelessWidget {
  const MyTasksView({
    super.key,
    required this.page,
    required this.onOpen,
    this.loading = false,
    this.error,
    this.onRetry,
    this.onLoadMore,
    this.loadingMore = false,
  });

  final TaskMyTasksPage? page;
  final ValueChanged<TaskMyTask> onOpen;
  final bool loading;
  final String? error;
  final VoidCallback? onRetry;
  final VoidCallback? onLoadMore;
  final bool loadingMore;

  @override
  Widget build(BuildContext context) {
    if (loading && page == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (error != null && page == null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(error!),
            if (onRetry != null)
              TextButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ),
      );
    }
    final current = page;
    if (current == null || current.items.isEmpty) {
      return const Center(
        child: Text('No tasks are assigned to you in this view.'),
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(12),
      itemCount:
          current.items.length +
          (current.page < current.lastPage && onLoadMore != null ? 1 : 0),
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == current.items.length) {
          return Center(
            child: TextButton(
              onPressed: loadingMore ? null : onLoadMore,
              child: Text(loadingMore ? 'Loading more…' : 'Load more tasks'),
            ),
          );
        }
        final item = current.items[index];
        final due = item.task.dueOn;
        return ListTile(
          title: Text(item.task.title),
          subtitle: Text(
            '${item.task.number} · ${item.boardName}'
            '${due == null ? '' : ' · Due ${due.month}/${due.day}'}',
          ),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => onOpen(item),
        );
      },
    );
  }
}
