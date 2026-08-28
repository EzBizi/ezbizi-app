import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

final class TaskDuplicateDraft {
  const TaskDuplicateDraft({
    required this.board,
    required this.name,
    required this.originScopeKey,
    required this.includeTasks,
    required this.actorUserId,
  });

  final TaskBoard board;
  final String name;
  final String originScopeKey;
  final bool includeTasks;
  final String actorUserId;

  TaskDuplicateDraft copyWith({String? name, bool? includeTasks}) =>
      TaskDuplicateDraft(
        board: board,
        name: name ?? this.name,
        originScopeKey: originScopeKey,
        includeTasks: includeTasks ?? this.includeTasks,
        actorUserId: actorUserId,
      );
}

/// Lives above scope-keyed TasksScreen state so a normal top-bar place change
/// can remount the module without discarding an explicitly pending duplicate.
class TaskDuplicateDraftStore {
  static TaskDuplicateDraft? pending;
}

class TaskDuplicateFlow extends StatefulWidget {
  const TaskDuplicateFlow({
    super.key,
    required this.api,
    required this.board,
    required this.destinationLabel,
    required this.onDuplicated,
    required this.onUnavailable,
    this.samePlace = true,
    this.initialDraft,
    this.onDraftChanged,
    this.onChooseAnotherDestination,
  });

  final TaskApi api;
  final TaskBoard board;
  final String destinationLabel;
  final ValueChanged<TaskBoard> onDuplicated;
  final ValueChanged<String?> onUnavailable;
  final bool samePlace;
  final TaskDuplicateDraft? initialDraft;
  final ValueChanged<TaskDuplicateDraft>? onDraftChanged;
  final VoidCallback? onChooseAnotherDestination;

  @override
  State<TaskDuplicateFlow> createState() => _TaskDuplicateFlowState();
}

class _TaskDuplicateFlowState extends State<TaskDuplicateFlow> {
  late final TextEditingController _name = TextEditingController(
    text: widget.initialDraft?.name ?? '${widget.board.name} copy',
  );
  late bool _includeTasks =
      widget.samePlace && (widget.initialDraft?.includeTasks ?? false);
  bool _saving = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    _name.addListener(_draftChanged);
  }

  void _draftChanged() => widget.onDraftChanged?.call(
    (widget.initialDraft ??
            TaskDuplicateDraft(
              board: widget.board,
              name: _name.text,
              originScopeKey: '',
              includeTasks: _includeTasks,
              actorUserId: '',
            ))
        .copyWith(name: _name.text, includeTasks: _includeTasks),
  );

  @override
  void dispose() {
    _name.removeListener(_draftChanged);
    _name.dispose();
    super.dispose();
  }

  Future<void> _duplicate() async {
    if (_saving || !widget.board.can('duplicate')) return;
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
      widget.board.id,
      expectedStructureVersion: widget.board.structureVersion,
      name: name,
      // The server enforces this too; keeping the client explicit prevents a
      // stale same-place choice from crossing a top-bar scope change.
      includeTasks:
          widget.samePlace &&
          widget.board.can('duplicate_with_tasks') &&
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
    if (!widget.board.can('duplicate')) return const SizedBox.shrink();
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
                        _draftChanged();
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
          if (widget.samePlace && widget.board.can('duplicate_with_tasks'))
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              title: const Text('Copy tasks too'),
              subtitle: const Text(
                'Task-inclusive copies are available only while the source and destination are this same place.',
              ),
              value: _includeTasks,
              onChanged: _saving
                  ? null
                  : (value) => setState(() {
                      _includeTasks = value;
                      _draftChanged();
                    }),
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
