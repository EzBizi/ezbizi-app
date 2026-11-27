import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/error_note.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// Task Board › ⚡ Automate (Jose, 2026-10-13: "easy automations as well on
/// task board, which should be synced with the automations tab, but easy
/// access and only task board related stuff"; UI queue 89).
///
/// Each recipe is an ordinary Automations rule whose first condition is
/// `board_id eq <this board>` (the contract Task Board's
/// TaskBoardAutomationContractTest holds), so it is also in Automations ›
/// Your automations and can be edited there in full. No second engine.

typedef _Json = Map<String, dynamic>;

const _triggers = <(String, String)>[
  ('task.created', 'a task is created'),
  ('task.status_changed', 'the status changes to'),
  ('task.overdue', 'a task is overdue'),
  ('task.column_changed', 'a column changes to'),
  ('task.date_arrived', 'a date arrives'),
];

const _assign = 'task.assign';
const _notify = 'notify';
const _alert = 'alert.send';
const _move = 'task.move_group';
const _shift = 'task.shift_date';
const _set = 'task.set_value';

/// "Add" keeps whoever is already assigned (task.assign add:true).
const _assignAdd = 'task.assign+add';

/// The built-in Due date, as task.date_arrived and the date actions name it.
const _due = 'due';

const _dateTypes = {'date', 'datetime', 'timeline'};

/// A status by NAME, resolved on the task's own board by the engine.
const _status = 'change_status';

/// The person the event is about, for notify ("the person assigned").
const _assignee = '';

class BoardAutomatePanel extends StatefulWidget {
  const BoardAutomatePanel({
    super.key,
    required this.api,
    required this.session,
    required this.board,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> board;

  @override
  State<BoardAutomatePanel> createState() => _BoardAutomatePanelState();
}

class _BoardAutomatePanelState extends State<BoardAutomatePanel> {
  bool _loading = true;
  String? _error;
  List<_Json> _rules = const [];

  bool _adding = false;
  String _trigger = 'task.created';
  String? _statusId;
  String _action = _notify;
  String? _person = _assignee;
  String? _groupId;
  String? _toStatus;
  String? _columnId;
  String _value = '';
  int _daysUntil = 0;
  String? _actionColumn;
  String _amount = '1';
  String _unit = 'days';

  /// set_value's own value ('today' for a date).
  String _value2 = '';
  bool _saving = false;

  String get _boardId => '${widget.board['id']}';

  /// Its owners and Administrators automate their own board without the
  /// Automations permissions (Jose, 2026-10-13: "yes they should";
  /// Automations 2798c466 holds the server to the same rule). Not
  /// can_manage: since Task Board aec4364b that is "can add people", which
  /// tasks.manage_members gives without owning the board. Administrator
  /// holds every permission, so _may lets them through.
  bool get _manages => widget.board['my_role'] == 'owner';

  bool _may(String permission) =>
      _manages || (widget.session.me?.can(permission) ?? false);

  bool get _canCreate => _may('automations.create');

  bool get _canAlert => widget.session.me?.can('phone.alerts.view') ?? false;

  List<_Json> _list(String key) => [
    for (final m in (widget.board[key] as List? ?? const []))
      if (m is Map && m['id'] != null) Map<String, dynamic>.from(m),
  ];

  String? _nameIn(String key, Object? id) =>
      _list(key).where((m) => m['id'] == id).firstOrNull?['name'] as String?;

  String _columnName(Object? id) =>
      id == _due ? 'Due' : (_nameIn('columns', id) ?? 'a column');

  List<SearchOption<String>> _columnOptions({bool dates = false}) => [
    const SearchOption(value: _due, label: 'Due'),
    for (final c in _list('columns'))
      if (!dates || _dateTypes.contains(c['type']))
        SearchOption(value: '${c['id']}', label: '${c['name']}'),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  static bool _onBoard(_Json rule, String boardId) =>
      ((rule['conditions'] as Map?)?['all'] as List? ?? const []).any(
        (c) =>
            c is Map &&
            c['field'] == 'board_id' &&
            c['op'] == 'eq' &&
            c['value'] == boardId,
      );

  /// The list does not carry conditions, so each task rule is read whole.
  // ponytail: one GET per task.* rule; ask Automations for ?board_id= on the
  // list if a business ever keeps dozens of task rules.
  Future<void> _load() async {
    final res = await widget.api.get('/automations');
    if (!mounted) return;
    // An owner with no rules of their own yet is refused the list.
    final none = res.statusCode == 403 && _manages;
    if (!res.ok && !none) {
      setState(() {
        _loading = false;
        _error = res.message;
      });
      return;
    }
    final ids = [
      if (!none)
        for (final r in (res.body['data'] as List? ?? const []))
          if (r is Map && '${r['trigger_key']}'.startsWith('task.')) r['id'],
    ];
    final rules = <_Json>[];
    for (final id in ids) {
      final one = await widget.api.get('/automations/$id');
      final rule = one.ok ? one.body['data'] : null;
      if (rule is Map && _onBoard(Map<String, dynamic>.from(rule), _boardId)) {
        rules.add(Map<String, dynamic>.from(rule));
      }
    }
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = null;
      _rules = rules;
    });
  }

  /// "When the status changes to Stuck, notify the person assigned", from a
  /// rule as stored. A rule changed in Automations into something no recipe
  /// says reads as its name.
  String _sentence(_Json rule) {
    final conditions =
        (rule['conditions'] as Map?)?['all'] as List? ?? const [];
    final status = conditions
        .whereType<Map>()
        .where((c) => c['field'] == 'status_id')
        .firstOrNull?['value'];
    Object? field(String f) => conditions
        .whereType<Map>()
        .where((c) => c['field'] == f)
        .firstOrNull?['value'];
    final column = field('column_id');
    final extra = [
      status,
      column,
      field('value'),
      field('days_until'),
    ].where((v) => v != null).length;
    final actions = (rule['actions'] as List? ?? const []).whereType<Map>();
    if (actions.length != 1 || conditions.length > 1 + extra) {
      return '${rule['name']}';
    }
    return _say(
      trigger: '${rule['trigger_key']}',
      statusId: status as String?,
      columnId: column,
      value: field('value'),
      daysUntil: field('days_until'),
      action: actions.first,
    );
  }

  String _say({
    required String trigger,
    String? statusId,
    Object? columnId,
    Object? value,
    Object? daysUntil,
    required Map action,
  }) {
    final when = switch (trigger) {
      'task.status_changed' =>
        'When the status changes to ${_nameIn('statuses', statusId) ?? 'any status'}',
      'task.overdue' => 'When a task is overdue',
      'task.column_changed' =>
        'When ${_columnName(columnId)} changes to ${value ?? 'anything'}',
      'task.date_arrived' =>
        '${daysUntil == 1 ? 'The day before' : 'When'} ${_columnName(columnId)} arrives',
      _ => 'When a task is created',
    };
    String person(Object? id) => _nameIn('assignable', id) ?? 'somebody';
    final ids = action['user_ids'] as List? ?? const [];
    final then = switch (action['type']) {
      _assign when action['add'] == true =>
        'add ${person('${action['user_field']}'.replaceFirst('user:', ''))} to it',
      _assign =>
        'assign it to ${person('${action['user_field']}'.replaceFirst('user:', ''))}',
      _notify when action['to_assignee'] == true =>
        'notify the person assigned',
      _notify => 'notify ${ids.isEmpty ? 'somebody' : person(ids.first)}',
      _alert =>
        'send a Communication alert to ${ids.isEmpty ? 'somebody' : person(ids.first)}',
      _status => 'set the status to ${action['value']}',
      _move =>
        'move it to ${_nameIn('groups', action['group_id']) ?? 'another group'}',
      _shift =>
        'push ${_columnName(action['column_id'])} by ${action['amount']} ${action['unit']}',
      _set => 'set ${_columnName(action['column_id'])} to ${action['value']}',
      _ => 'do ${action['type']}',
    };
    return '$when, $then';
  }

  Map<String, dynamic> get _draftAction {
    final board = '${widget.board['name']}';
    final what = switch (_trigger) {
      'task.status_changed' => 'is now ${_nameIn('statuses', _statusId)}',
      'task.overdue' => 'is overdue',
      'task.column_changed' => 'changed ${_columnName(_columnId)}',
      'task.date_arrived' => 'reached ${_columnName(_columnId)}',
      _ => 'was added',
    };
    final message = '{{title}} $what ($board)';
    return switch (_action) {
      _assign => {'type': _assign, 'user_field': 'user:$_person'},
      _assignAdd => {
        'type': _assign,
        'user_field': 'user:$_person',
        'add': true,
      },
      _shift => {
        'type': _shift,
        'column_id': _actionColumn,
        'amount': int.tryParse(_amount) ?? 1,
        'unit': _unit,
      },
      _set => {
        'type': _set,
        'column_id': _actionColumn,
        'value': _value2.trim(),
      },
      _alert => {
        'type': _alert,
        'message': message,
        'user_ids': [_person],
      },
      _move => {'type': _move, 'group_id': _groupId},
      _status => {'type': _status, 'value': _toStatus},
      _ =>
        _person == _assignee
            ? {'type': _notify, 'message': message, 'to_assignee': true}
            : {
                'type': _notify,
                'message': message,
                'user_ids': [_person],
              },
    };
  }

  bool get _onColumn =>
      _trigger == 'task.column_changed' || _trigger == 'task.date_arrived';

  bool get _draftComplete =>
      (_trigger != 'task.status_changed' || _statusId != null) &&
      (!_onColumn || _columnId != null) &&
      (_trigger != 'task.column_changed' || _value.trim().isNotEmpty) &&
      switch (_action) {
        _move => _groupId != null,
        _status => _toStatus != null,
        _shift => _actionColumn != null && int.tryParse(_amount) != null,
        _set => _actionColumn != null && _value2.trim().isNotEmpty,
        _notify => _person != null,
        _ => _person != null && _person != _assignee,
      };

  Map<String, dynamic> get _place {
    final franchise = widget.board['franchise_id'];
    final company = widget.board['company_id'];
    return franchise != null
        ? {'scope_type': 'franchise', 'scope_id': franchise}
        : company != null
        ? {'scope_type': 'company', 'scope_id': company}
        : {'scope_type': null, 'scope_id': null};
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final action = _draftAction;
    final said = _say(
      trigger: _trigger,
      statusId: _statusId,
      columnId: _columnId,
      value: _value.trim(),
      daysUntil: _daysUntil,
      action: action,
    );
    final created = await widget.api.post('/automations', {
      'name': '${widget.board['name']}: $said',
      'trigger_key': _trigger,
      'conditions': {
        'all': [
          {'field': 'board_id', 'op': 'eq', 'value': _boardId},
          if (_trigger == 'task.status_changed')
            {'field': 'status_id', 'op': 'eq', 'value': _statusId},
          if (_onColumn) {'field': 'column_id', 'op': 'eq', 'value': _columnId},
          if (_trigger == 'task.column_changed')
            {'field': 'value', 'op': 'changed_to', 'value': _value.trim()},
          if (_trigger == 'task.date_arrived')
            {'field': 'days_until', 'op': 'eq', 'value': _daysUntil},
        ],
      },
      'actions': [action],
      // The board's own place, which the server checks an owner's rule
      // against: franchise, else company, else the whole organization.
      ..._place,
    });
    final id = (created.body['data'] as Map?)?['id'];
    // Saved off, as every rule is; a recipe here is meant on.
    final on = created.ok
        ? await widget.api.patch('/automations/$id', {'is_active': true})
        : created;
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (!on.ok) {
        _error = on.message;
      } else {
        _adding = false;
      }
    });
    if (on.ok) await _load();
  }

  Future<void> _write(Future<ApiResponse> call) async {
    final res = await call;
    if (!mounted) return;
    if (!res.ok) {
      setState(() => _error = res.message);
      return;
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return SidePanel(
      title: 'Automate this board',
      subtitle:
          'Rules in Automations › Your automations, limited to '
          '${widget.board['name']}. Edit them there for everything else a '
          'rule can do.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          if (_loading)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            )
          else ...[
            if (_rules.isEmpty && !_adding)
              Text('No automations on this board yet.', style: muted),
            for (final r in _rules)
              Card(
                key: ValueKey('board-rule-${r['id']}'),
                margin: const EdgeInsets.only(bottom: 8),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(4, 4, 4, 4),
                  child: Row(
                    children: [
                      Switch(
                        key: ValueKey('board-rule-on-${r['id']}'),
                        value: r['is_active'] == true,
                        onChanged: _may('automations.edit')
                            ? (v) => _write(
                                widget.api.patch('/automations/${r['id']}', {
                                  'is_active': v,
                                }),
                              )
                            : null,
                      ),
                      const SizedBox(width: 6),
                      Expanded(child: Text(_sentence(r))),
                      if (_may('automations.delete'))
                        IconButton(
                          key: ValueKey('board-rule-delete-${r['id']}'),
                          tooltip: 'Delete this automation',
                          icon: const Icon(Icons.delete_outline, size: 18),
                          onPressed: () => _write(
                            widget.api.delete('/automations/${r['id']}'),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            if (_canCreate && !_adding)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  key: const Key('board-rule-add'),
                  onPressed: () => setState(() => _adding = true),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add an automation'),
                ),
              ),
            if (_adding) _composer(context),
          ],
        ],
      ),
    );
  }

  /// The sentence, one blank at a time: When … , then … .
  Widget _composer(BuildContext context) {
    final people = [
      for (final p in _list('assignable'))
        SearchOption<String>(value: p['id'] as String, label: '${p['name']}'),
    ];
    Widget pick<T>(
      String key,
      String label,
      T? value,
      List<SearchOption<T>> options,
      ValueChanged<T?> onChanged,
    ) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: SearchPickerField<T>(
        key: Key(key),
        label: label,
        isDense: true,
        value: value,
        options: options,
        onChanged: (v) => setState(() => onChanged(v)),
      ),
    );

    Widget text(
      String key,
      String label,
      ValueChanged<String> onChanged, [
      String initial = '',
    ]) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        key: Key(key),
        initialValue: initial,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: (v) => setState(() => onChanged(v)),
      ),
    );

    return Card(
      key: const Key('board-rule-composer'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            pick<String>('board-rule-when', 'When', _trigger, [
              for (final (k, l) in _triggers) SearchOption(value: k, label: l),
            ], (v) => _trigger = v ?? _trigger),
            if (_trigger == 'task.status_changed')
              pick<String>('board-rule-status', 'Status', _statusId, [
                for (final s in _list('statuses'))
                  SearchOption(value: s['id'] as String, label: '${s['name']}'),
              ], (v) => _statusId = v),
            if (_onColumn)
              pick<String>(
                'board-rule-column',
                'Column',
                _columnId,
                _columnOptions(dates: _trigger == 'task.date_arrived'),
                (v) => _columnId = v,
              ),
            if (_trigger == 'task.column_changed')
              text('board-rule-value', 'Changes to', (v) => _value = v),
            if (_trigger == 'task.date_arrived')
              pick<int>('board-rule-days', 'When', _daysUntil, const [
                SearchOption(value: 0, label: 'on the day'),
                SearchOption(value: 1, label: 'the day before'),
              ], (v) => _daysUntil = v ?? 0),
            pick<String>(
              'board-rule-then',
              'Then',
              _action,
              [
                const SearchOption(value: _notify, label: 'notify'),
                const SearchOption(value: _assign, label: 'assign it to'),
                const SearchOption(value: _assignAdd, label: 'add a person'),
                if (_canAlert)
                  const SearchOption(
                    value: _alert,
                    label: 'send a Communication alert to',
                  ),
                const SearchOption(value: _status, label: 'set the status to'),
                const SearchOption(value: _move, label: 'move it to a group'),
                const SearchOption(value: _shift, label: 'push a date by'),
                const SearchOption(value: _set, label: 'set a column to'),
              ],
              (v) {
                _action = v ?? _action;
                // Only notify can mean "the person assigned".
                if (_action != _notify && _person == _assignee) _person = null;
              },
            ),
            if (_action == _move)
              pick<String>('board-rule-group', 'Group', _groupId, [
                for (final g in _list('groups'))
                  SearchOption(value: g['id'] as String, label: '${g['name']}'),
              ], (v) => _groupId = v)
            else if (_action == _status)
              pick<String>('board-rule-to-status', 'Status', _toStatus, [
                for (final s in _list('statuses'))
                  SearchOption(value: '${s['name']}', label: '${s['name']}'),
              ], (v) => _toStatus = v)
            else if (_action == _shift) ...[
              pick<String>(
                'board-rule-shift-column',
                'Date',
                _actionColumn,
                _columnOptions(dates: true),
                (v) => _actionColumn = v,
              ),
              text('board-rule-amount', 'By', (v) => _amount = v, _amount),
              pick<String>('board-rule-unit', 'Unit', _unit, const [
                SearchOption(value: 'days', label: 'days'),
                SearchOption(value: 'weeks', label: 'weeks'),
                SearchOption(value: 'months', label: 'months'),
              ], (v) => _unit = v ?? _unit),
            ] else if (_action == _set) ...[
              pick<String>(
                'board-rule-set-column',
                'Column',
                _actionColumn,
                _columnOptions(),
                (v) => _actionColumn = v,
              ),
              text(
                'board-rule-set-value',
                'To (a label, or today)',
                (v) => _value2 = v,
              ),
            ] else
              pick<String>('board-rule-person', 'Who', _person, [
                if (_action == _notify)
                  const SearchOption(
                    value: _assignee,
                    label: 'the person assigned',
                  ),
                ...people,
              ], (v) => _person = v),
            OverflowBar(
              alignment: MainAxisAlignment.end,
              spacing: 8,
              children: [
                TextButton(
                  onPressed: () => setState(() => _adding = false),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  key: const Key('board-rule-save'),
                  onPressed: _draftComplete && !_saving ? _save : null,
                  child: Text(_saving ? 'Saving…' : 'Save and switch on'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
