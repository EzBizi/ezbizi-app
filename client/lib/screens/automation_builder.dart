import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/automation_catalogue.dart';
import '../core/timestamps.dart';
import '../widgets/catalogue_picker.dart';
import '../widgets/error_note.dart';
import '../widgets/tag.dart';

/// Building one automation, on the page rather than in a panel (plan §5.5).
///
/// ── Why this stopped being a side panel ──────────────────────────
///
/// A side panel is the right shape for a form you fill in beside the thing it
/// is about — editing a customer next to the list of customers. Writing a rule
/// is not that. There is no list behind it worth keeping in view, and the form
/// itself is four decisions deep: what it is called, what it reacts to, when it
/// should hold back, and what it then does. Squeezed into 560px, the trigger
/// became a dropdown of twenty raw event keys with nothing to say what any of
/// them meant, which is the state the owner objected to.
///
/// So it is a tab on the main screen with the whole width to work in, and the
/// four decisions are four numbered steps in the order somebody makes them.
///
/// ── Simple mode, and what it refuses to do ───────────────────────
///
/// The engine takes a nested AND/OR tree. This edits the flat "all of these"
/// case, which is what almost every real rule is, and sends it as
/// `{"all": [...]}` so the stored shape is already the full one.
///
/// A rule whose stored tree is NESTED can still be opened here — its name and
/// its actions are editable — but its conditions are shown as they are and sent
/// back untouched. Flattening a tree this form cannot draw would quietly change
/// what the rule means, and re-saving is exactly when nobody is watching for
/// that.
class AutomationBuilder extends StatefulWidget {
  const AutomationBuilder({
    super.key,
    required this.api,
    required this.catalogue,
    required this.formats,
    required this.onSaved,
    required this.onCancel,
    this.editing,
  });

  final ApiClient api;
  final AutomationCatalogue catalogue;
  final DisplayFormats formats;

  /// The automation being edited, as `GET /automations/{id}` returned it.
  /// Null builds a new one.
  final Map<String, dynamic>? editing;

  final VoidCallback onSaved;
  final VoidCallback onCancel;

  @override
  State<AutomationBuilder> createState() => _AutomationBuilderState();
}

/// One clause of "only if".
///
/// It owns its controllers rather than being redrawn from a map of strings, and
/// that is the fix for a real bug: `TextFormField(initialValue:)` inside a list
/// keeps the FIELD's state where it sits in the list, so deleting the first row
/// left the second one wearing the first one's text while the payload carried
/// the second one's. Text that follows a controller cannot do that, and the key
/// keeps focus with the row it belongs to.
class _ConditionRow {
  _ConditionRow({String field = '', this.op = 'eq', String value = ''})
    : field = TextEditingController(text: field),
      value = TextEditingController(text: value);

  final TextEditingController field;
  final TextEditingController value;
  final Key key = UniqueKey();

  String op;

  Map<String, dynamic> toJson() {
    final clause = <String, dynamic>{'field': field.text.trim(), 'op': op};

    switch (arityOf(op)) {
      case OperatorArity.none:
        // "is empty" and "changed" take no value, and sending an empty string
        // as one would store a clause that reads as a comparison against ''.
        break;
      case OperatorArity.one:
        clause['value'] = value.text;
      case OperatorArity.many:
        clause['value'] = parsedValues;
    }

    return clause;
  }

  /// The values "is one of" will actually compare against.
  ///
  /// Read by the save blocker as well as by toJson, so the check and the payload
  /// cannot disagree — checking the raw text instead is what let " , , " through
  /// as a configured-looking rule that matched nothing.
  List<String> get parsedValues => [
    for (final part in value.text.split(','))
      if (part.trim().isNotEmpty) part.trim(),
  ];

  void dispose() {
    field.dispose();
    value.dispose();
  }
}

/// One step of "then do this".
class _ActionRow {
  _ActionRow({this.catalogueKey, this.type, this.picking = true});

  final Key key = UniqueKey();

  final field = TextEditingController();
  final value = TextEditingController();
  final url = TextEditingController();
  final message = TextEditingController();

  /// What was picked out of the catalogue — either a generic action type or a
  /// module's declared action. Kept beside [type] so the card can say
  /// "Customers › Update a field" while the payload says `update_field`.
  String? catalogueKey;

  /// The `type` the API accepts. Null until something is chosen.
  String? type;

  /// The picker is open on this card.
  bool picking;

  Map<String, dynamic> toJson() => switch (type) {
    'update_field' => {
      'type': 'update_field',
      'field': field.text.trim(),
      'value': value.text,
    },
    'webhook' => {'type': 'webhook', 'url': url.text.trim()},
    'notify' => {'type': 'notify', 'message': message.text},

    // No silent fallthrough. This used to end `_ => notify`, so a type the
    // catalogue offered but this build had no form for was drawn as a Message
    // box and SAVED AS A NOTIFICATION — the card said "Create a task", the
    // server was sent `{"type":"notify"}`, and it accepted it. A refused save
    // is recoverable; a rule that quietly does something else is not.
    //
    // The catalogue is server-driven precisely so the vocabulary can grow, and
    // this was the one place that assumed it never would. _knownTypes below is
    // what keeps the picker and this switch honest with each other.
    _ => throw StateError(
      'No form for action type "$type" — it should not have been selectable.',
    ),
  };

  /// The action types this build can actually draw a form for.
  ///
  /// The picker consults it, so an unknown type is never offered and the throw
  /// above is unreachable rather than merely unlikely.
  static const Set<String> knownTypes = {'update_field', 'webhook', 'notify'};

  void dispose() {
    field.dispose();
    value.dispose();
    url.dispose();
    message.dispose();
  }
}

class _AutomationBuilderState extends State<AutomationBuilder> {
  final _name = TextEditingController();
  final _description = TextEditingController();

  String? _trigger;
  final List<_ConditionRow> _conditions = [];
  final List<_ActionRow> _actions = [];

  /// A stored condition tree this form cannot draw. Held verbatim and sent back
  /// unchanged — see the class comment.
  /// Conditions this build cannot draw as rows, kept EXACTLY as stored.
  ///
  /// `Object?` and not `Map`, because the thing that made this dangerous was
  /// assuming a shape. The API types `conditions` as `['nullable','array']`, so
  /// a JSON list of clauses is storable — and a list arriving here used to be
  /// replaced with `{'all': []}`, which is not "handing it back" at all. Worse,
  /// the engine reads an empty `all` as matched (`! in_array(false, [])` is
  /// true), so opening such a rule and pressing Save turned a CONDITIONAL rule
  /// into one that fires on every event. Silently, and permanently.
  Object? _opaqueConditions;

  /// Whether anything is being held opaquely. A stored `null` is not.
  bool _hasOpaque = false;

  /// What was loaded, for the read-only view when [_lockedBecause] is set.
  List<Map<String, dynamic>> _loadedActions = [];

  /// Why the version's contents — conditions and actions — cannot be edited.
  ///
  /// Only one thing causes it today: an action carrying a webhook signing
  /// secret. The API masks secrets on the way out (rightly), so saving the form
  /// back would write eight bullet characters over a live secret and every
  /// signed call the receiver checks would start failing. Name and description
  /// are still editable, because changing those alone writes no new version.
  String? _lockedBecause;

  bool _busy = false;
  String? _error;

  bool get _isEdit => widget.editing != null;
  String? get _id => widget.editing?['id'] as String?;

  @override
  void initState() {
    super.initState();

    _name.addListener(_changed);
    _description.addListener(_changed);

    final editing = widget.editing;

    if (editing != null) {
      _name.text = editing['name'] as String? ?? '';
      _description.text = editing['description'] as String? ?? '';
      _trigger = editing['trigger_key'] as String?;
      _adoptActions(editing['actions']);
      _adoptConditions(editing['conditions']);
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();

    for (final condition in _conditions) {
      condition.dispose();
    }

    for (final action in _actions) {
      action.dispose();
    }

    super.dispose();
  }

  /// Every keystroke re-asks whether the form can be saved.
  ///
  /// It has to: the Create button used to be computed once from `_name.text`
  /// with nothing listening, so typing the name LAST left the button dead until
  /// something else forced a rebuild. A blocked button that never explains
  /// itself is worse than no button.
  void _changed() {
    if (mounted) setState(() {});
  }

  // ── loading an existing rule back in ─────────────────────────────

  void _adoptActions(Object? stored) {
    final actions = stored is List
        ? stored.whereType<Map>().map(Map<String, dynamic>.from).toList()
        : <Map<String, dynamic>>[];

    _loadedActions = actions;

    for (final action in actions) {
      if (action.containsKey('secret')) {
        _lockedBecause =
            'One of these calls a webhook with a signing secret. The API masks '
            'secrets when it hands an automation back, so saving these steps '
            'again would replace the real secret with the mask and every signed '
            'call would start being rejected. Name and description can still be '
            'changed here.';
        return;
      }
    }

    for (final action in actions) {
      final type = action['type'];

      if (type is! String ||
          !widget.catalogue.actionTypes.any((t) => t.key == type)) {
        _lockedBecause =
            'One of these steps is an action this version of the app does not '
            'know how to show. Re-saving would drop what it does, so the steps '
            'are read-only until the app catches up.';
        return;
      }
    }

    for (final action in actions) {
      final row = _ActionRow(
        catalogueKey: action['type'] as String,
        type: action['type'] as String,
        picking: false,
      );

      row.field.text = action['field'] as String? ?? '';
      row.value.text = '${action['value'] ?? ''}';
      row.url.text = action['url'] as String? ?? '';
      row.message.text = action['message'] as String? ?? '';

      _attach(row);
      _actions.add(row);
    }
  }

  /// Runs AFTER [_adoptActions], which is what may have locked the version:
  /// conditions and actions are saved as one version, so if the actions cannot
  /// be rewritten the conditions cannot either, and drawing them as editable
  /// boxes would promise a save that never happens.
  void _adoptConditions(Object? stored) {
    if (stored == null) return;

    if (stored is! Map) {
      // Held verbatim, which is what "hand it straight back" has to mean. This
      // build cannot draw it, so it does not get to reinterpret it either — a
      // shape we do not understand is still the shape the engine was given.
      _opaqueConditions = stored;
      _hasOpaque = true;
      return;
    }

    final tree = Map<String, dynamic>.from(stored);
    final flat = _lockedBecause == null ? _flatten(tree) : null;

    if (flat == null) {
      _opaqueConditions = tree;
      _hasOpaque = true;
      return;
    }

    for (final (field, op, value) in flat) {
      final row = _ConditionRow(field: field, op: op, value: value);
      _attach(row);
      _conditions.add(row);
    }
  }

  /// The clauses of a tree this form can edit, or null when it cannot.
  ///
  /// Editable means one level of "all of these", every child a plain
  /// field/operator comparison. Anything with an `any`, a `not`, or a nested
  /// group is handed back untouched instead.
  List<(String, String, String)>? _flatten(Map<String, dynamic> tree) {
    final all = tree['all'];

    final leaves = all is List
        ? all
        : (tree.containsKey('field') ? [tree] : null);

    if (leaves == null) return null;

    final clauses = <(String, String, String)>[];

    for (final leaf in leaves) {
      if (leaf is! Map) return null;

      final field = leaf['field'];
      final op = leaf['op'];

      if (field is! String || op is! String) return null;

      clauses.add((field, op, _valueText(leaf['value'])));
    }

    return clauses;
  }

  static String _valueText(Object? value) => switch (value) {
    null => '',
    final List list => list.join(', '),
    _ => '$value',
  };

  void _attach(Object row) {
    if (row is _ConditionRow) {
      row.field.addListener(_changed);
      row.value.addListener(_changed);
    } else if (row is _ActionRow) {
      row.field.addListener(_changed);
      row.value.addListener(_changed);
      row.url.addListener(_changed);
      row.message.addListener(_changed);
    }
  }

  // ── what can be offered ──────────────────────────────────────────

  List<PickerGroup> get _triggerGroups => [
    for (final module in widget.catalogue.modules)
      if (module.triggers.isNotEmpty)
        PickerGroup(
          key: module.key,
          label: module.label,
          items: [
            for (final trigger in module.triggers)
              PickerItem(
                key: trigger.key,
                label: trigger.label,
                description: trigger.description,
              ),
          ],
        ),
  ];

  /// The generic actions first, then what each module declares.
  ///
  /// Both, because neither list alone is the truth. The generic three are the
  /// only ones the engine runs, and two of them — a webhook and a notification
  /// — belong to no module at all, so a picker built from the modules would
  /// hide the most useful things it can do. The module lists are what this
  /// system is going to be able to do, marked so nobody plans a rule around
  /// one and finds out at save time.
  List<PickerGroup> get _actionGroups => [
    PickerGroup(
      key: '_generic',
      label: 'Works with anything',
      items: [
        for (final type in widget.catalogue.actionTypes)
          PickerItem(
            key: type.key,
            label: type.label,
            // Same treatment the module actions already got. The catalogue can
            // name a type this build has no form for — that is the point of it
            // being server-driven — and offering one produced a card saying
            // "Create a task" that saved as a notification.
            enabled: _ActionRow.knownTypes.contains(type.key),
            badge: _ActionRow.knownTypes.contains(type.key)
                ? null
                : 'Needs a newer version of the app',
          ),
      ],
    ),
    for (final module in widget.catalogue.modules)
      if (module.actions.isNotEmpty)
        PickerGroup(
          key: module.key,
          label: module.label,
          items: [
            for (final action in module.actions)
              PickerItem(
                key: action.key,
                label: action.label,
                description: action.description,
                enabled: widget.catalogue.canUse(action),
                badge: widget.catalogue.canUse(action) ? null : 'Not built yet',
              ),
          ],
        ),
  ];

  /// The `type` a picked key stores as, or null when this build cannot run it.
  ///
  /// Null covers two different "no"s and deliberately answers both the same
  /// way: the SERVER cannot run it (implemented: false), or THIS BUILD has no
  /// form for it — a type added to the catalogue after this client shipped.
  /// Either way it must not be selectable, because the only alternatives are a
  /// save the server refuses or, as it was, a save that silently means
  /// something else.
  String? _payloadTypeFor(String picked) {
    for (final type in widget.catalogue.actionTypes) {
      if (type.key == picked) {
        return _ActionRow.knownTypes.contains(type.key) ? type.key : null;
      }
    }

    for (final module in widget.catalogue.modules) {
      for (final action in module.actions) {
        if (action.key == picked) {
          return widget.catalogue.canUse(action)
              ? widget.catalogue.payloadTypeFor(action)
              : null;
        }
      }
    }

    return null;
  }

  /// A stored action's `type`, in the catalogue's words.
  ///
  /// The read-only card for a version that cannot be rewritten used to print the
  /// raw key — `webhook` rather than "Call a webhook" — on the one path where
  /// somebody cannot edit their way out of it. From the catalogue rather than a
  /// map in Dart, so a type added on the server reads correctly here without a
  /// client release.
  String _actionTypeLabel(String type) {
    for (final known in widget.catalogue.actionTypes) {
      if (known.key == type) return known.label;
    }

    return type;
  }

  String _actionLabel(String picked) {
    for (final type in widget.catalogue.actionTypes) {
      if (type.key == picked) return type.label;
    }

    for (final module in widget.catalogue.modules) {
      for (final action in module.actions) {
        if (action.key == picked) return '${module.label} › ${action.label}';
      }
    }

    return picked;
  }

  // ── whether it can be saved, and why not ─────────────────────────

  /// The one thing stopping a save, in the words of whoever has to fix it.
  String? get _blocker {
    if (_name.text.trim().isEmpty) return 'Give it a name first.';

    if (_trigger == null) return 'Choose what it should react to.';

    // Nothing below is editable when the version is locked, so nothing below
    // can be wrong. Requiring an action there would leave the button dead
    // forever on the very rule whose name this screen still offers to change.
    if (_lockedBecause != null) return null;

    for (final condition in _conditions) {
      if (condition.field.text.trim().isEmpty) {
        // Left as it was, this saved a clause with no field, which the
        // evaluator refuses outright — the rule looked configured and every
        // single run failed.
        return 'One of the conditions has no field, so the rule could never run.';
      }

      if (arityOf(condition.op) != OperatorArity.none &&
          condition.value.text.trim().isEmpty) {
        return 'One of the conditions is missing the value to compare against.';
      }

      // The PARSED list, not the raw text. "is one of" splits on commas and
      // drops the blanks, so a box holding only " , , " passed the check above,
      // serialised to `value: []`, and produced a rule that saves, looks
      // configured, and can never match — `in_array($x, [])` is always false.
      // Same class as the empty-condition-row gap, one step further in.
      if (arityOf(condition.op) == OperatorArity.many &&
          condition.parsedValues.isEmpty) {
        return 'One of the conditions lists no values to match against.';
      }
    }

    if (_actions.isEmpty) return 'Add at least one thing for it to do.';

    for (final action in _actions) {
      if (action.type == null) return 'One of the steps has no action chosen.';

      final missing = switch (action.type) {
        'update_field' when action.field.text.trim().isEmpty =>
          'Which field should the step set?',
        'webhook' when action.url.text.trim().isEmpty =>
          'The webhook step needs an address to call.',
        'notify' when action.message.text.trim().isEmpty =>
          'The notification step needs a message.',
        _ => null,
      };

      if (missing != null) return missing;
    }

    return null;
  }

  Object? _conditionsPayload() {
    if (_hasOpaque) return _opaqueConditions;
    if (_conditions.isEmpty) return null;

    return {
      'all': [for (final row in _conditions) row.toJson()],
    };
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final body = <String, dynamic>{
      'name': _name.text.trim(),
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      // Never on an update: changing what a rule reacts to makes it a different
      // rule, and every run already recorded would describe something it no
      // longer does. The API refuses the field outright.
      if (!_isEdit) 'trigger_key': _trigger,
      if (_lockedBecause == null) ...{
        // Always sent together. A PATCH carrying actions and no conditions
        // writes a version whose conditions are NULL — the new version would
        // quietly start matching everything.
        'conditions': _conditionsPayload(),
        'actions': [for (final action in _actions) action.toJson()],
      },
    };

    final response = _isEdit
        ? await widget.api.patch('/automations/$_id', body)
        : await widget.api.post('/automations', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    widget.onSaved();
  }

  Future<void> _restore(String versionId, int number) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/automations/$_id/activate/$versionId',
      {},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text('Version $number is live again.')));

    widget.onSaved();
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  // ── the form ─────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final blocker = _blocker;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
            child: Center(
              child: ConstrainedBox(
                // Wide enough for two panes beside each other, narrow enough
                // that a line of help text is still one line of reading rather
                // than a sentence stretched across a 27-inch monitor.
                constraints: const BoxConstraints(maxWidth: 1080),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_isEdit) _editingBanner(context),
                    _nameStep(context),
                    const SizedBox(height: 24),
                    _triggerStep(context),
                    const SizedBox(height: 24),
                    _conditionStep(context),
                    const SizedBox(height: 24),
                    _actionStep(context),
                    if (_isEdit) ...[
                      const SizedBox(height: 24),
                      _historyStep(context),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 20),
                      ErrorNote(_error!),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
        const Divider(height: 1),
        _footer(context, blocker),
      ],
    );
  }

  Widget _editingBanner(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Row(
        children: [
          const Icon(Icons.edit_outlined, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Editing “${widget.editing?['name']}”. Saving writes a new version '
              'and makes it the live one — the runs already recorded keep '
              'pointing at the version that produced them.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }

  Widget _nameStep(BuildContext context) {
    return _step(
      context,
      1,
      'Name it',
      'What you will see in the list, and in every run it records.',
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 2,
            child: TextField(
              controller: _name,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Name',
                hintText: 'Tell the office when a job is completed',
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            flex: 3,
            child: TextField(
              controller: _description,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'What it is for (optional)',
                hintText: 'Why this rule exists, for whoever reads it next',
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _triggerStep(BuildContext context) {
    final chosen = _trigger;

    if (_isEdit) {
      return _step(
        context,
        2,
        'When this happens',
        'Fixed for the life of the rule — a rule that changed what it reacts to '
            'would make every run already recorded describe something it no '
            'longer does. Build a new one instead.',
        Row(
          children: [
            const Icon(Icons.bolt_outlined, size: 18),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                chosen == null
                    ? 'Unknown'
                    : widget.catalogue.describeTrigger(chosen),
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
          ],
        ),
      );
    }

    return _step(
      context,
      2,
      'When this happens',
      'Only things this system actually emits are listed. Pick a category, then '
          'the event — or search across all of them.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          CataloguePicker(
            groups: _triggerGroups,
            selected: chosen,
            searchHint: 'Search events',
            height: 300,
            onSelected: (key) => setState(() => _trigger = key),
          ),
          if (chosen != null) ...[
            const SizedBox(height: 10),
            Text(
              'Chosen: ${widget.catalogue.describeTrigger(chosen)}',
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ],
        ],
      ),
    );
  }

  Widget _conditionStep(BuildContext context) {
    if (_lockedBecause != null || _hasOpaque) {
      return _step(
        context,
        3,
        'Only if',
        _lockedBecause != null
            ? 'Read-only, for the same reason the steps below are — conditions '
                  'and steps are saved together as one version.'
            : 'These conditions nest groups inside each other, which this form '
                  'cannot draw. They are shown as stored and saved back exactly '
                  'as they are, rather than being flattened into something that '
                  'would mean something else.',
        !_hasOpaque
            ? Text(
                'There are none, so it runs every time.',
                style: Theme.of(context).textTheme.bodyMedium,
              )
            : Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  '$_opaqueConditions',
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                ),
              ),
      );
    }

    return _step(
      context,
      3,
      'Only if',
      _conditions.isEmpty
          ? 'Optional. With nothing here it runs every time the event happens.'
          : 'Every one of these has to be true.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final row in _conditions)
            Padding(
              key: row.key,
              padding: const EdgeInsets.only(bottom: 10),
              child: _conditionRow(context, row),
            ),
        ],
      ),
      action: TextButton.icon(
        onPressed: _busy
            ? null
            : () => setState(() {
                final row = _ConditionRow();
                _attach(row);
                _conditions.add(row);
              }),
        icon: const Icon(Icons.add, size: 16),
        label: const Text('Add a condition'),
      ),
    );
  }

  Widget _conditionRow(BuildContext context, _ConditionRow row) {
    final arity = arityOf(row.op);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 3,
          child: TextField(
            controller: row.field,
            enabled: !_busy,
            decoration: const InputDecoration(
              labelText: 'Field',
              isDense: true,
              hintText: 'status',
            ),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          flex: 3,
          child: DropdownButtonFormField<String>(
            initialValue: row.op,
            // Without this the button sizes itself to its widest option and
            // overflows the column it was given — "is not one of" does not fit
            // a third of a narrow window, and the ellipsis on the item below
            // only takes effect once the button fills its box.
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Is', isDense: true),
            items: [
              for (final operator in widget.catalogue.operators)
                DropdownMenuItem(
                  value: operator.key,
                  child: Text(operator.label, overflow: TextOverflow.ellipsis),
                ),
            ],
            onChanged: _busy
                ? null
                : (value) => setState(() => row.op = value ?? row.op),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          flex: 4,
          child: arity == OperatorArity.none
              ? Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(
                    'Nothing to compare against — the field itself is the whole '
                    'test.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                )
              : TextField(
                  controller: row.value,
                  enabled: !_busy,
                  decoration: InputDecoration(
                    isDense: true,
                    labelText: arity == OperatorArity.many ? 'Values' : 'Value',
                    // "is one of" could not be expressed at all before: the form
                    // sent one string, the evaluator compares against a list, so
                    // the clause never matched anything.
                    helperText: arity == OperatorArity.many
                        ? 'Separate them with commas'
                        : null,
                  ),
                ),
        ),
        IconButton(
          icon: const Icon(Icons.close, size: 18),
          tooltip: 'Remove this condition',
          onPressed: _busy
              ? null
              : () => setState(() {
                  _conditions.remove(row);
                  row.dispose();
                }),
        ),
      ],
    );
  }

  Widget _actionStep(BuildContext context) {
    final budget = widget.catalogue.actionBudget;

    if (_lockedBecause != null) {
      return _step(
        context,
        4,
        'Then do this',
        'Read-only.',
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ErrorNote(_lockedBecause!),
            const SizedBox(height: 12),
            for (final action in _loadedActions)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.play_arrow_outlined, size: 18),
                title: Text(_actionTypeLabel(action['type'] as String? ?? '')),
                subtitle: Text(
                  [
                    for (final entry in action.entries)
                      if (entry.key != 'type') '${entry.key}: ${entry.value}',
                  ].join(' · '),
                ),
              ),
          ],
        ),
      );
    }

    return _step(
      context,
      4,
      'Then do this',
      'One step at a time, in order. Actions a module has declared but the '
          'engine cannot run yet are listed and marked, so you can see what is '
          'coming without building a rule around it.'
          '${budget == null ? '' : ' One event may set off at most $budget '
                    'actions in total before the engine stops the cascade.'}',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < _actions.length; i++)
            Padding(
              key: _actions[i].key,
              padding: const EdgeInsets.only(bottom: 12),
              child: _actionCard(context, i, _actions[i]),
            ),
          if (_actions.isEmpty)
            Text(
              'Nothing yet. A rule with no actions would watch for something and '
              'then do nothing about it.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
        ],
      ),
      action: TextButton.icon(
        onPressed: _busy
            ? null
            : () => setState(() {
                final row = _ActionRow();
                _attach(row);
                _actions.add(row);
              }),
        icon: const Icon(Icons.add, size: 16),
        label: const Text('Add a step'),
      ),
    );
  }

  Widget _actionCard(BuildContext context, int index, _ActionRow row) {
    final chosen = row.catalogueKey;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 12,
                  backgroundColor: Theme.of(
                    context,
                  ).colorScheme.surfaceContainerHighest,
                  child: Text(
                    '${index + 1}',
                    style: const TextStyle(fontSize: 11),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    chosen == null
                        ? 'Choose what this step does'
                        : _actionLabel(chosen),
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
                if (chosen != null && !row.picking)
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => setState(() => row.picking = true),
                    child: const Text('Change'),
                  ),
                IconButton(
                  icon: const Icon(Icons.close, size: 18),
                  tooltip: 'Remove this step',
                  onPressed: _busy
                      ? null
                      : () => setState(() {
                          _actions.remove(row);
                          row.dispose();
                        }),
                ),
              ],
            ),
            if (row.picking) ...[
              const SizedBox(height: 12),
              CataloguePicker(
                groups: _actionGroups,
                selected: chosen,
                searchHint: 'Search actions',
                height: 260,
                onSelected: (key) => setState(() {
                  row.catalogueKey = key;
                  row.type = _payloadTypeFor(key);
                  row.picking = false;
                }),
              ),
            ] else ...[
              const SizedBox(height: 12),
              Padding(
                padding: const EdgeInsets.only(left: 36, right: 8),
                child: _actionFields(row),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _actionFields(_ActionRow row) {
    return switch (row.type) {
      'update_field' => Row(
        children: [
          Expanded(
            child: TextField(
              controller: row.field,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Field',
                isDense: true,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: TextField(
              controller: row.value,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Set to',
                isDense: true,
              ),
            ),
          ),
        ],
      ),
      'webhook' => TextField(
        controller: row.url,
        enabled: !_busy,
        decoration: const InputDecoration(
          labelText: 'https:// URL',
          isDense: true,
          helperText: 'Public addresses only — internal ones are refused.',
        ),
      ),
      _ => TextField(
        controller: row.message,
        enabled: !_busy,
        decoration: const InputDecoration(
          labelText: 'Message',
          isDense: true,
          helperText: 'Use {{ title }} to include a field from the record.',
        ),
      ),
    };
  }

  Widget _historyStep(BuildContext context) {
    final versions = (widget.editing?['versions'] as List? ?? [])
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList();

    return _step(
      context,
      5,
      'History',
      'Every save wrote one of these and none of them can be edited, because '
          'each run points at the version that produced it. Putting an older one '
          'back is how a rule that turned out wrong is undone.',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final version in versions)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Row(
                children: [
                  Text('Version ${version['version']}'),
                  if (version['is_active'] == true) const Tag('In use'),
                ],
              ),
              subtitle: Text(
                formatStamp(
                  version['created_at'],
                  'date unknown',
                  formats: widget.formats,
                ),
              ),
              trailing: version['is_active'] == true
                  ? null
                  : TextButton(
                      onPressed: _busy
                          ? null
                          : () => _restore(
                              version['id'] as String,
                              version['version'] as int,
                            ),
                      child: const Text('Put this one back'),
                    ),
            ),
        ],
      ),
    );
  }

  Widget _footer(BuildContext context, String? blocker) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 16),
      child: Row(
        children: [
          Expanded(
            child: Text(
              // The button says no; this says why. A disabled control with no
              // explanation is the thing people file bugs about.
              blocker ??
                  (_isEdit
                      ? 'Saving makes this the live version straight away.'
                      : 'It is saved switched off. Turn it on once you have read '
                            'it back.'),
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          const SizedBox(width: 16),
          TextButton(
            onPressed: _busy ? null : widget.onCancel,
            child: const Text('Cancel'),
          ),
          const SizedBox(width: 10),
          FilledButton(
            onPressed: _busy || blocker != null ? null : _submit,
            child: Text(_isEdit ? 'Save changes' : 'Create automation'),
          ),
        ],
      ),
    );
  }

  Widget _step(
    BuildContext context,
    int number,
    String title,
    String note,
    Widget body, {
    Widget? action,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CircleAvatar(
              radius: 13,
              backgroundColor: Theme.of(context).colorScheme.primaryContainer,
              child: Text(
                '$number',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Theme.of(context).colorScheme.onPrimaryContainer,
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 2),
                  Text(note, style: Theme.of(context).textTheme.bodyMedium),
                ],
              ),
            ),
            if (action != null) ...[const SizedBox(width: 12), action],
          ],
        ),
        const SizedBox(height: 12),
        Padding(padding: const EdgeInsets.only(left: 38), child: body),
      ],
    );
  }
}
