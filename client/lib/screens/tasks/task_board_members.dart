import 'package:flutter/material.dart';

import '../../widgets/async_body.dart';
import 'task_api.dart';
import 'task_models.dart';

class TaskBoardMembers extends StatefulWidget {
  const TaskBoardMembers({
    super.key,
    required this.api,
    required this.board,
    required this.onUnavailable,
  });

  final TaskApi api;
  final TaskBoard board;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskBoardMembers> createState() => _TaskBoardMembersState();
}

class _TaskBoardMembersState extends State<TaskBoardMembers> {
  bool _loading = true;
  bool _saving = false;
  String? _message;
  List<TaskBoardMember> _members = const [];
  List<TaskParticipant> _candidates = const [];
  String? _candidateId;
  TaskBoardMemberRole _candidateRole = TaskBoardMemberRole.member;

  @override
  void initState() {
    super.initState();
    if (widget.board.can('manage_members')) {
      _load();
    } else {
      _loading = false;
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _message = null;
    });
    final membersRequest = widget.api.boardMembers(widget.board.id);
    final candidatesRequest = widget.api.participants(
      widget.board.id,
      purpose: TaskParticipantPurpose.membership,
    );
    final members = await membersRequest;
    final candidates = await candidatesRequest;
    if (!mounted) return;
    if (members is TaskApiUnavailable<List<TaskBoardMember>>) {
      _clearAndEvict(members.message);
      return;
    }
    if (candidates is TaskApiUnavailable<List<TaskParticipant>>) {
      _clearAndEvict(candidates.message);
      return;
    }
    setState(() {
      _loading = false;
      if (members is TaskApiSuccess<List<TaskBoardMember>>) {
        _members = members.data.where((item) => item.active).toList();
      } else {
        _message = members.message ?? 'Board members could not be loaded.';
      }
      if (candidates is TaskApiSuccess<List<TaskParticipant>>) {
        final existing = _members.map((item) => item.userId).toSet();
        _candidates = candidates.data
            .where((item) => !existing.contains(item.id))
            .toList(growable: false);
      } else if (_message == null) {
        _message = candidates.message ?? 'Eligible people could not be loaded.';
      }
    });
  }

  void _clearAndEvict(String? message) {
    setState(() {
      _loading = false;
      _members = const [];
      _candidates = const [];
    });
    widget.onUnavailable(message);
  }

  Future<void> _add() async {
    final userId = _candidateId;
    if (_saving || userId == null) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.addBoardMember(
      widget.board.id,
      userId: userId,
      role: _candidateRole,
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskBoardMember>) {
      _clearAndEvict(result.message);
    } else if (result is TaskApiSuccess<TaskBoardMember>) {
      setState(() {
        _saving = false;
        _members = [..._members, result.data];
        _candidates = _candidates
            .where((item) => item.id != userId)
            .toList(growable: false);
        _candidateId = null;
      });
    } else {
      setState(() {
        _saving = false;
        _message = result.message ?? 'That member could not be added.';
      });
    }
  }

  Future<void> _changeRole(
    TaskBoardMember member,
    TaskBoardMemberRole role,
  ) async {
    if (_saving || !member.can('change_role')) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.changeBoardMemberRole(
      widget.board.id,
      member.id,
      role: role,
    );
    if (!mounted) return;
    _applyMemberResult(result, member.id);
  }

  Future<void> _remove(TaskBoardMember member) async {
    if (_saving || !member.can('revoke')) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.revokeBoardMember(
      widget.board.id,
      member.id,
    );
    if (!mounted) return;
    _applyMemberResult(result, member.id);
  }

  void _applyMemberResult(
    TaskApiResult<TaskBoardMember> result,
    String membershipId,
  ) {
    if (result is TaskApiUnavailable<TaskBoardMember>) {
      _clearAndEvict(result.message);
    } else if (result is TaskApiSuccess<TaskBoardMember>) {
      setState(() {
        _saving = false;
        if (!result.data.active) {
          _members = _members
              .where((item) => item.id != membershipId)
              .toList(growable: false);
        } else {
          _members = [
            for (final item in _members)
              if (item.id == membershipId) result.data else item,
          ];
        }
      });
    } else {
      setState(() {
        _saving = false;
        _message = result.message ?? 'That membership could not be changed.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.board.can('manage_members')) {
      return const SizedBox.shrink();
    }
    return AsyncBody(
      loading: _loading,
      error: null,
      onRetry: _load,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          Text('People', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            'Owners administer the board. Members can work only within their Tasks permissions.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          for (final member in _members)
            Card(
              child: ListTile(
                title: Text(member.name),
                subtitle: Text(member.email),
                trailing: Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (member.can('change_role'))
                      DropdownButton<TaskBoardMemberRole>(
                        value: member.role,
                        items: const [
                          DropdownMenuItem(
                            value: TaskBoardMemberRole.member,
                            child: Text('Member'),
                          ),
                          DropdownMenuItem(
                            value: TaskBoardMemberRole.owner,
                            child: Text('Owner'),
                          ),
                        ],
                        onChanged: _saving
                            ? null
                            : (role) => _changeRole(member, role!),
                      )
                    else
                      Text(
                        member.role == TaskBoardMemberRole.owner
                            ? 'Owner'
                            : 'Member',
                      ),
                    if (member.can('revoke'))
                      IconButton(
                        tooltip: 'Remove ${member.name}',
                        onPressed: _saving ? null : () => _remove(member),
                        icon: const Icon(Icons.person_remove_outlined),
                      ),
                  ],
                ),
              ),
            ),
          if (_candidates.isNotEmpty) ...[
            const SizedBox(height: 14),
            Text('Add member', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: _candidateId,
              decoration: const InputDecoration(
                labelText: 'Eligible person',
                border: OutlineInputBorder(),
              ),
              items: [
                for (final candidate in _candidates)
                  DropdownMenuItem(
                    value: candidate.id,
                    child: Text('${candidate.name} · ${candidate.email}'),
                  ),
              ],
              onChanged: _saving
                  ? null
                  : (value) => setState(() => _candidateId = value),
            ),
            const SizedBox(height: 8),
            SegmentedButton<TaskBoardMemberRole>(
              segments: const [
                ButtonSegment(
                  value: TaskBoardMemberRole.member,
                  label: Text('Member'),
                ),
                ButtonSegment(
                  value: TaskBoardMemberRole.owner,
                  label: Text('Owner'),
                ),
              ],
              selected: <TaskBoardMemberRole>{_candidateRole},
              onSelectionChanged: _saving
                  ? null
                  : (value) => setState(() => _candidateRole = value.single),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                onPressed: _saving || _candidateId == null ? null : _add,
                icon: const Icon(Icons.person_add_outlined),
                label: const Text('Add member'),
              ),
            ),
          ],
          if (_message case final message?) ...[
            const SizedBox(height: 8),
            Text(
              message,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// Recovery intentionally renders no board name, id, structure, or people from
/// the board. The server supplies only opaque recoverable rows and eligible
/// replacement owners for the exact active place.
class TaskBoardRecoveryList extends StatefulWidget {
  const TaskBoardRecoveryList({
    super.key,
    required this.api,
    required this.onUnavailable,
  });

  final TaskApi api;
  final ValueChanged<String?> onUnavailable;

  @override
  State<TaskBoardRecoveryList> createState() => _TaskBoardRecoveryListState();
}

class _TaskBoardRecoveryListState extends State<TaskBoardRecoveryList> {
  bool _loading = true;
  bool _saving = false;
  List<TaskRecoverableBoard> _boards = const [];
  final Map<String, List<TaskParticipant>> _candidates = {};
  final Map<String, String> _selectedOwners = {};
  String? _message;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _message = null;
    });
    final result = await widget.api.recoverableBoards();
    if (!mounted) return;
    if (result is TaskApiUnavailable<List<TaskRecoverableBoard>>) {
      _unavailable(result.message);
      return;
    }
    if (result is! TaskApiSuccess<List<TaskRecoverableBoard>>) {
      setState(() {
        _loading = false;
        _message = result.message ?? 'Recovery items could not be loaded.';
      });
      return;
    }
    final boards = result.data
        .where((board) => board.can('recover_owner'))
        .toList(growable: false);
    final candidates = <String, List<TaskParticipant>>{};
    for (final board in boards) {
      final people = await widget.api.participants(
        board.id,
        purpose: TaskParticipantPurpose.membership,
      );
      if (!mounted) return;
      if (people is TaskApiUnavailable<List<TaskParticipant>>) {
        _unavailable(people.message);
        return;
      }
      if (people is TaskApiSuccess<List<TaskParticipant>>) {
        candidates[board.id] = people.data;
      }
    }
    setState(() {
      _loading = false;
      _boards = boards;
      _candidates
        ..clear()
        ..addAll(candidates);
    });
  }

  void _unavailable(String? message) {
    setState(() {
      _loading = false;
      _boards = const [];
      _candidates.clear();
      _selectedOwners.clear();
    });
    widget.onUnavailable(message);
  }

  Future<void> _recover(TaskRecoverableBoard board) async {
    final ownerId = _selectedOwners[board.id];
    if (_saving || !board.can('recover_owner') || ownerId == null) return;
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await widget.api.recoverBoardOwner(board.id, ownerId);
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskBoardRecovery>) {
      _unavailable(result.message);
    } else if (result is TaskApiSuccess<TaskBoardRecovery>) {
      setState(() {
        _saving = false;
        _boards = _boards
            .where((candidate) => candidate.id != board.id)
            .toList(growable: false);
        _candidates.remove(board.id);
        _selectedOwners.remove(board.id);
        _message = 'A replacement owner was assigned.';
      });
    } else {
      setState(() {
        _saving = false;
        _message =
            result.message ?? 'A replacement owner could not be assigned.';
      });
    }
  }

  @override
  Widget build(BuildContext context) => AsyncBody(
    loading: _loading,
    error: null,
    onRetry: _load,
    child: ListView(
      padding: EdgeInsets.zero,
      children: [
        Text('Board recovery', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Private board details stay hidden until an eligible owner accepts responsibility.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        if (_boards.isEmpty && _message == null)
          const Text('No private boards need an owner.'),
        for (var index = 0; index < _boards.length; index++)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('Private board needing an owner'),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    key: ValueKey('task-recovery-owner-$index'),
                    initialValue: _selectedOwners[_boards[index].id],
                    decoration: const InputDecoration(
                      labelText: 'Eligible replacement owner',
                      border: OutlineInputBorder(),
                    ),
                    items: [
                      for (final person
                          in _candidates[_boards[index].id] ?? const [])
                        DropdownMenuItem(
                          value: person.id,
                          child: Text('${person.name} · ${person.email}'),
                        ),
                    ],
                    onChanged: _saving
                        ? null
                        : (value) => setState(() {
                            if (value == null) {
                              _selectedOwners.remove(_boards[index].id);
                            } else {
                              _selectedOwners[_boards[index].id] = value;
                            }
                          }),
                  ),
                  const SizedBox(height: 8),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton(
                      onPressed:
                          _saving || _selectedOwners[_boards[index].id] == null
                          ? null
                          : () => _recover(_boards[index]),
                      child: const Text('Assign owner'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        if (_message case final message?) ...[
          const SizedBox(height: 8),
          Text(message),
        ],
      ],
    ),
  );
}
