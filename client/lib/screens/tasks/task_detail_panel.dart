import 'package:flutter/material.dart';
import 'task_models.dart';

class TaskDetailPanel extends StatelessWidget {
  const TaskDetailPanel({
    super.key,
    required this.task,
    required this.onClose,
    this.fullScreen = false,
  });
  final TaskDetailSnapshot? task;
  final VoidCallback onClose;
  final bool fullScreen;
  @override
  Widget build(BuildContext context) {
    if (task == null) {
      return const Center(child: Text('Select a task to see its details.'));
    }
    final row = task!.task;
    return SafeArea(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    row.number,
                    style: Theme.of(context).textTheme.labelLarge,
                  ),
                ),
                IconButton(
                  tooltip: fullScreen ? 'Back to board' : 'Close details',
                  icon: const Icon(Icons.close),
                  onPressed: onClose,
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Text(
              row.title,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
          ),
          const Divider(),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                const Text(
                  'Description',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                Text(
                  row.values.values
                          .where((value) => value.kind == TaskColumnKind.text)
                          .map((value) => value.value)
                          .whereType<String>()
                          .join('\n')
                          .isEmpty
                      ? 'No description yet.'
                      : row.values.values
                            .where((value) => value.kind == TaskColumnKind.text)
                            .map((value) => value.value)
                            .whereType<String>()
                            .join('\n'),
                ),
                const SizedBox(height: 24),
                const Text(
                  'Conversation',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                const Text('Updates will appear here.'),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
