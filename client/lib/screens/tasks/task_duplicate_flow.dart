import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

final class TaskDuplicateDraft {
  const TaskDuplicateDraft({
    required this.sourceBoardId,
    required this.actorUserId,
  });

  final String sourceBoardId;
  final String actorUserId;
}

/// Lives above scope-keyed TasksScreen state so a normal top-bar place change
/// can remount the module without discarding an explicitly pending duplicate.
class TaskDuplicateDraftStore {
  static TaskDuplicateDraft? pending;
}

class TaskDuplicateContinuation extends StatefulWidget {
  const TaskDuplicateContinuation({
    super.key,
    required this.api,
    required this.draft,
    required this.destinationLabel,
    required this.onDuplicated,
    required this.onInvalid,
    this.onChooseAnotherDestination,
  });

  final TaskApi api;
  final TaskDuplicateDraft draft;
  final String destinationLabel;
  final ValueChanged<TaskBoard> onDuplicated;
  final ValueChanged<String?> onInvalid;
  final VoidCallback? onChooseAnotherDestination;

  @override
  State<TaskDuplicateContinuation> createState() =>
      _TaskDuplicateContinuationState();
}

class _TaskDuplicateContinuationState extends State<TaskDuplicateContinuation> {
  TaskDuplicateSource? _source;
  String? _error;
  bool _invalid = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    final result = await widget.api.duplicateSource(widget.draft.sourceBoardId);
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskDuplicateSource>) {
      setState(() => _invalid = true);
      widget.onInvalid(result.message);
    } else if (result is TaskApiSuccess<TaskDuplicateSource>) {
      setState(() => _source = result.data);
    } else {
      setState(() {
        _error = result.message ?? 'The source board could not be verified.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_invalid) return const SizedBox.shrink();
    final source = _source;
    if (source != null) {
      return TaskDuplicateFlow(
        api: widget.api,
        source: source,
        destinationLabel: widget.destinationLabel,
        onDuplicated: widget.onDuplicated,
        onUnavailable: widget.onInvalid,
        onChooseAnotherDestination: widget.onChooseAnotherDestination,
      );
    }
    if (_error case final error?) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(error),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }
    return const Center(child: CircularProgressIndicator());
  }
}

class TaskDuplicateFlow extends StatefulWidget {
  const TaskDuplicateFlow({
    super.key,
    required this.api,
    required this.source,
    required this.destinationLabel,
    required this.onDuplicated,
    required this.onUnavailable,
    this.onChooseAnotherDestination,
  });

  final TaskApi api;
  final TaskDuplicateSource source;
  final String destinationLabel;
  final ValueChanged<TaskBoard> onDuplicated;
  final ValueChanged<String?> onUnavailable;
  final VoidCallback? onChooseAnotherDestination;

  @override
  State<TaskDuplicateFlow> createState() => _TaskDuplicateFlowState();
}

class _TaskDuplicateFlowState extends State<TaskDuplicateFlow> {
  late final TextEditingController _name = TextEditingController(
    text: '${widget.source.name} copy',
  );
  bool _includeTasks = false;
  bool _saving = false;
  String? _message;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _duplicate() async {
    if (_saving || !widget.source.can('duplicate')) return;
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _message = 'Enter a name for the duplicate board.');
      return;
    }
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.duplicateBoard(
      widget.source.id,
      expectedStructureVersion: widget.source.structureVersion,
      name: name,
      // The server enforces this too; keeping the client explicit prevents a
      // stale same-place choice from crossing a top-bar scope change.
      includeTasks:
          widget.source.samePlace &&
          widget.source.can('duplicate_with_tasks') &&
          _includeTasks,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskStructureMutation<TaskBoard>>) {
      setState(() => _saving = false);
      widget.onUnavailable(result.message);
    } else if (result is TaskApiSuccess<TaskStructureMutation<TaskBoard>>) {
      setState(() {
        _saving = false;
        _message = 'Board duplicated.';
      });
      widget.onDuplicated(result.data.data);
    } else {
      setState(() {
        _saving = false;
        _message = result.message ?? 'The board could not be duplicated.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.source.can('duplicate')) return const SizedBox.shrink();
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Duplicate board',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 4),
          Text(
            'The copy is filed in the exact place currently shown below. Your source and draft stay pending if you change place with the top-bar switcher.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          InputDecorator(
            decoration: const InputDecoration(
              labelText: 'Destination in view',
              border: OutlineInputBorder(),
            ),
            child: Text(widget.destinationLabel),
          ),
          if (widget.onChooseAnotherDestination != null) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: _saving
                    ? null
                    : () {
                        widget.onChooseAnotherDestination!.call();
                      },
                icon: const Icon(Icons.swap_horiz),
                label: const Text('Change destination in top bar'),
              ),
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('task-duplicate-name'),
            controller: _name,
            enabled: !_saving,
            decoration: const InputDecoration(
              labelText: 'New board name',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          if (widget.source.samePlace &&
              widget.source.can('duplicate_with_tasks'))
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              title: const Text('Copy tasks too'),
              subtitle: const Text(
                'Task-inclusive copies are available only while the source and destination are this same place.',
              ),
              value: _includeTasks,
              onChanged: _saving
                  ? null
                  : (value) => setState(() => _includeTasks = value),
            )
          else
            const ListTile(
              contentPadding: EdgeInsets.zero,
              leading: Icon(Icons.account_tree_outlined),
              title: Text('Structure only'),
              subtitle: Text(
                'Groups, columns, labels, and saved structure are copied. Tasks stay on the source board.',
              ),
            ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              onPressed: _saving ? null : _duplicate,
              icon: const Icon(Icons.copy_all_outlined),
              label: Text(_saving ? 'Duplicating…' : 'Duplicate board'),
            ),
          ),
          if (_message case final message?) ...[
            const SizedBox(height: 10),
            Text(
              message,
              style: TextStyle(
                color: message == 'Board duplicated.'
                    ? Theme.of(context).colorScheme.primary
                    : Theme.of(context).colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
