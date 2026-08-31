import 'package:flutter/material.dart';
import 'task_models.dart';
import 'task_api.dart';
import 'task_dependency_panel.dart';
import 'task_participants.dart';
import 'task_record_links.dart';

class TaskDetailPanel extends StatelessWidget {
  const TaskDetailPanel({
    super.key,
    required this.task,
    required this.taskApi,
    required this.onTaskChanged,
    required this.onOpenRecordRoute,
    required this.onClose,
    this.fullScreen = false,
    this.onDraftChanged,
    this.privacyEpoch = 0,
    this.onUnavailable,
  });
  final TaskDetailSnapshot? task;
  final TaskApi taskApi;
  final ValueChanged<TaskDetailSnapshot> onTaskChanged;
  final ValueChanged<String> onOpenRecordRoute;
  final VoidCallback onClose;
  final bool fullScreen;

  /// Reserved for detail editors and the Task 17B update composer so they join
  /// the board's convergence draft registry by stable identity.
  final TaskDraftChanged? onDraftChanged;
  final int privacyEpoch;
  final ValueChanged<String?>? onUnavailable;
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
                TaskRecordLinks(
                  api: taskApi,
                  snapshot: task!,
                  onChanged: onTaskChanged,
                  onOpenRoute: onOpenRecordRoute,
                ),
                const SizedBox(height: 24),
                TaskDependencyPanel(
                  api: taskApi,
                  snapshot: task!,
                  onChanged: onTaskChanged,
                ),
                const SizedBox(height: 24),
                TaskCollaborationPanel(
                  snapshot: task!,
                  api: taskApi,
                  privacyEpoch: privacyEpoch,
                  onChanged: onTaskChanged,
                  onUnavailable: onUnavailable ?? (_) {},
                  onDraftChanged: onDraftChanged,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
