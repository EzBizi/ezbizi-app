import '../core/phone_numbers.dart';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/automation_catalogue.dart';
import '../core/timestamps.dart';
import '../widgets/catalogue_picker.dart';
import '../widgets/error_note.dart';
import '../widgets/numbered_step.dart';
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

  /// WhatsApp: the chosen template and one controller per blank it declares.
  ///
  /// The COUNT comes from the server, never from counting {{n}} in the preview:
  /// Meta numbers positionally and "{{1}} owes {{3}}" is a three-parameter
  /// template with a gap. Deriving it would be right on every template anybody
  /// tested and wrong on the one with a hole in it, at send time.
  String whatsAppTemplate = '';
  int whatsAppParameters = 0;
  final List<TextEditingController> whatsAppValues = [];
  final Map<String, TextEditingController> parameters = {};
  final Set<String> attachedParameters = {};

  /// What was picked out of the catalogue — either a generic action type or a
  /// module's declared action. Kept beside [type] so the card can say
  /// "Customers › Update a field" while the payload says `update_field`.
  String? catalogueKey;

  /// The `type` the API accepts. Null until something is chosen.
  String? type;

  /// The picker is open on this card.
  bool picking;

  TextEditingController parameter(String key) =>
      parameters.putIfAbsent(key, TextEditingController.new);

  /// The `_` arm below throws, and it is UNREACHABLE — see [knownTypes].
  ///
  /// Read on its own this method looks like it crashes on any action type the
  /// build does not know, which would make every server-side action
  /// registration a hazard. It does not: the picker consults [knownTypes]
  /// first and offers an unknown type disabled, so nothing unknown ever
  /// reaches here to be serialised.
  ///
  /// Said here because two people checking whether a new automation action
  /// could break a released build both read the throw, concluded it could, and
  /// stopped — separately, within an hour, on 2026-08-28, while an App Store
  /// binary was frozen and the answer decided whether work shipped. The guard
  /// is forty lines down behind a docblock neither scrolled to. One of them
  /// then told the other to hold a finished, correct commit.
  Map<String, dynamic> toJson(CatalogueActionType? definition) {
    // Any action that DECLARES parameters is built from them, rather than
    // any action whose key happens to start with a particular prefix.
    //
    // The prefix was accurate while one module was the only one with
    // configurable actions, and it silently stopped being a description of
    // anything the moment Phone added one: "Send a message" was selectable,
    // drew no fields, and fell to the throw below — a StateError on a rule
    // somebody had just built.
    if (definition != null && definition.parameters.isNotEmpty) {
      final payload = <String, dynamic>{'type': type};
      for (final field in definition.parameters) {
        final value = parameter(field.key).text.trim();

        // A yes/no answer goes on the wire as a REAL boolean, not as the text
        // the switch happens to store it in.
        //
        // Every parameter shares one map of text controllers, so an off switch
        // is the empty string — and sending that leaves the server casting a
        // string to a bool. PHP casts '' to false and 'true' to true, which
        // looks fine and is one typo from disaster: the string 'false' casts
        // to TRUE. Typing it here means the server never has to know what the
        // control stored, and no future writer has to rediscover that rule.
        // A roles parameter is a LIST on the wire. It is held as a comma-joined
        // string only because every parameter shares one map of text
        // controllers; sending that string would hand the runner one role id
        // with commas in it, which matches nothing and tells nobody.
        if (field.kind == 'roles') {
          payload[field.key] = [
            for (final id in value.split(','))
              if (id.trim().isNotEmpty) id.trim(),
          ];

          continue;
        }

        if (field.kind == 'boolean') {
          payload[field.key] = value == 'true';
          continue;
        }

        if (value.isNotEmpty) {
          payload[field.key] = value;
        } else if (field.required && field.nullable) {
          payload[field.key] = null;
        } else if (field.required) {
          payload[field.key] = '';
        }
      }
      return payload;
    }

    return switch (type) {
      'update_field' => {
        'type': 'update_field',
        'field': field.text.trim(),
        'value': value.text,
      },
      'webhook' => {'type': 'webhook', 'url': url.text.trim()},
      'whatsapp.send' => {
        'type': 'whatsapp.send',
        'template': whatsAppTemplate,
        'to_field': parameter('to_field').text.trim(),
        'parameters': [
          for (final c in whatsAppValues) c.text,
        ],
      },
      // Phone actions take no form fields — the subject of the event is enough.
      'voicemail.mark_dealt' || 'missed.create_job' => {'type': type},
      _ => throw StateError(
        'No form for action type "$type" — it should not have been selectable.',
      ),
    };
  }

  /// The action types this build can actually draw a form for.
  ///
  /// The picker consults it, so an unknown type is never offered and the throw
  /// above is unreachable rather than merely unlikely.
  static const Set<String> knownTypes = {
    'update_field',
    'webhook',
    'notify',
    'voicemail.mark_dealt',
    'missed.create_job',
    // Generic rather than module_only, so it is offered through _genericItem -
    // which gates on THIS set. Declared server-side without adding it here and
    // the picker would have shown "Needs a newer version of the app" on a build
    // that has the form, with nothing to explain it.
    'email.send',
    'whatsapp.send',
  };

  void dispose() {
    field.dispose();
    value.dispose();
    url.dispose();
    message.dispose();
    for (final c in whatsAppValues) {
      c.dispose();
    }
    for (final controller in parameters.values) {
      controller.dispose();
    }
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

  /// What changing the trigger took away, in the words of what was lost.
  ///
  /// Changing what a rule reacts to changes the RECORD it is about, and with it
  /// which fields exist and which module's actions mean anything. Leaving a
  /// stale field selected would save a rule the server refuses; clearing it
  /// without a word is worse, because somebody chose it. So it is cleared and
  /// said out loud, here, until they dismiss it.
  String? _triggerNotice;

  // ── the schedule, for the one trigger that is a clock ────────────
  //
  // Held as separate fields rather than a map so the form can be half-filled
  // without meaning anything yet: somebody who picks "days of the week" and has
  // not ticked a day has an incomplete schedule, not an invalid one, and only
  // becomes a save error when they try to save.
  String _scheduleKind = 'daily';
  final TextEditingController _scheduleTime = TextEditingController(text: '09:00');
  final Set<String> _scheduleDays = <String>{};
  int _scheduleDayOfMonth = 1;
  String? _scheduleZone;

  /// Is the picked trigger the clock?
  ///
  /// Compared against the server's own key rather than a guess: the catalogue
  /// carries it, and a client that decided for itself which trigger is a clock
  /// would be a second definition of the same fact.
  bool get _isScheduled => _trigger == _clockTrigger;

  static const _clockTrigger = 'automation.schedule_reached';

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

  /// The phone lines this company holds, for the "Send from" picker.
  ///
  /// Fetched rather than declared in the catalogue because it is tenant data,
  /// not a description of what the module can do — the catalogue says a
  /// parameter is a phone line, and which lines exist is a different question
  /// with a different answer per company.
  List<(String, String)> _lines = const [];

  /// How many lines the company holds BEFORE the messaging filter.
  ///
  /// Without it an empty picker has two causes and one sentence: no phone lines
  /// at all, and lines that exist but none approved for texting. Those send
  /// somebody to two different screens - buy a number, or get an existing one
  /// approved - so the picker has to say which.
  int _linesHeld = 0;

  /// The approved WhatsApp templates, and WHY there are none when there are none.
  ///
  /// Two different empty answers — no number attached, and nothing approved yet —
  /// and the Meta session carries the reason on both. Rendered verbatim rather
  /// than replaced with a sentence of mine: they know which it is and I do not,
  /// and "nothing approved yet" is what Jose sees today because `report_summary`
  /// is still PENDING with Meta.
  List<({String name, int parameters, String preview})> _whatsAppTemplates =
      const [];
  String? _whatsAppBecause;

  Future<void> _loadWhatsAppTemplates() async {
    final response = await widget.api.get('/automations/whatsapp-templates');
    if (!mounted) return;

    setState(() {
      // `response.data` IS the `data` value, not the envelope — so the list is
      // here and `because` is a sibling of it, read off the body.
      _whatsAppTemplates = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map<String, dynamic>)
                  (
                    name: (row['name'] ?? '').toString(),
                    parameters: (row['parameters'] as num?)?.toInt() ?? 0,
                    preview: (row['preview'] ?? '').toString(),
                  ),
            ]
          : const [];
      _whatsAppBecause = response.ok
          ? (response.body['because'] as String?)
          : 'The WhatsApp templates could not be read just now.';
    });
  }

  Future<void> _loadPhoneLines() async {
    final response = await widget.api.get('/phone/numbers');
    if (!mounted || !response.ok) return;

    final rows = (response.data as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    setState(() {
      _linesHeld = rows.length;
      _lines = [
        for (final row in rows)
          // ONLY LINES THAT MAY SEND A TEXT. Twilio approves outbound SMS per
          // number, and a line without it accepts the rule, runs, and is refused
          // by the carrier at send time - a failure that arrives in the run log
          // long after the rule was written and reads as a Twilio problem rather
          // than as a choice made in this picker. `sms_outbound_allowed` is what
          // the Phone module already tracks for exactly this question.
          if (row['sms_outbound_allowed'] == true)
            (
              (row['id'] ?? '').toString(),
              [
                (row['label'] ?? '').toString(),
                formatPhoneNumber((row['e164'] ?? '').toString()),
              ].where((part) => part.isNotEmpty).join(' · '),
            ),
      ];
    });
  }

  /// The field names a condition on [_trigger] can actually read.
  ///
  /// Fetched, not declared, and for the same reason as [_lines]: this is what
  /// events of this kind have actually carried in THIS organization, which the
  /// catalogue cannot know. The server reads them the way the evaluator
  /// resolves them, so anything offered here is something a condition can read.
  ///
  /// Empty means one of two things and [_fieldsSampled] tells them apart: the
  /// trigger has never fired here, or it is still loading. Neither is "this
  /// event has no fields", and the form must not say so.
  List<String> _triggerFields = const [];
  int? _fieldsSampled;

  /// Value => label for fields whose set is fixed in code, keyed by field.
  ///
  /// A field with no entry keeps its text box: a dropdown over an open set
  /// would be a shorter list pretending to be the whole one.
  Map<String, List<({String value, String label})>> _fieldValues = const {};

  Future<void> _loadTriggerFields() async {
    final key = _trigger;

    if (key == null) {
      setState(() {
        _triggerFields = const [];
        _fieldsSampled = null;
      });

      return;
    }

    final response = await widget.api.get(
      '/automations/trigger-fields?trigger=${Uri.encodeQueryComponent(key)}',
    );

    // A trigger whose tab this person cannot open answers 403, and a rule they
    // are editing may well be on one. Leaving the last trigger's fields on
    // screen would offer fields that belong to a different event, so they go.
    if (!mounted || _trigger != key) return;

    setState(() {
      _triggerFields = response.ok
          ? [
              for (final field in (response.data?['fields'] as List? ?? const []))
                field.toString(),
            ]
          : const [];
      _fieldsSampled = response.ok
          ? (response.data?['sampled'] as num?)?.toInt() ?? 0
          : null;
      _fieldValues = response.ok
          ? {
              for (final entry
                  in (response.data?['values'] as Map? ?? const {}).entries)
                entry.key.toString(): [
                  for (final option in (entry.value as List? ?? const []))
                    (
                      value: (option['value'] ?? '').toString(),
                      label: (option['label'] ?? '').toString(),
                    ),
                ],
            }
          : const {};
    });
  }

  @override
  void initState() {
    super.initState();

    _name.addListener(_changed);
    _description.addListener(_changed);
    _loadPhoneLines();
    _loadWhatsAppTemplates();

    final editing = widget.editing;

    if (editing != null) {
      _name.text = editing['name'] as String? ?? '';
      _description.text = editing['description'] as String? ?? '';
      _trigger = editing['trigger_key'] as String?;
      _adoptActions(editing['actions']);
      _adoptSchedule(editing['schedule']);
      _adoptConditions(editing['conditions']);
      _loadTriggerFields();
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _scheduleTime.dispose();

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
      // `message` is no longer excluded: notify declares it as a parameter now,
      // so a rule SAVED BEFORE THIS CHANGE - whose action is still
      // {type: notify, message: "..."} on the wire - has to load into the
      // parameter controller or editing an existing rule silently empties the
      // box and saves it blank. The shape on the wire did not change, only
      // which controller reads it.
      for (final entry in action.entries) {
        if (entry.key != 'type' &&
            !const {'field', 'value', 'url'}.contains(entry.key)) {
          row.parameter(entry.key).text = '${entry.value ?? ''}';
        }
      }

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
      _attachTaskParameters(row);
    }
  }

  void _attachTaskParameters(_ActionRow row) {
    final definition = widget.catalogue.actionType(row.type);
    if (definition == null) return;
    for (final parameter in definition.parameters) {
      if (row.attachedParameters.add(parameter.key)) {
        row.parameter(parameter.key).addListener(_changed);
      }
    }
  }

  // ── what can be offered ──────────────────────────────────────────

  List<PickerGroup> get _triggerGroups => [
    for (final module in widget.catalogue.modules)
      if (module.triggers.isNotEmpty)
        PickerGroup(
          key: module.key,
          // "Marketing › Leads" where the app draws Leads inside Marketing, so
          // the picker is grouped the way the person writing the rule just
          // navigated. See AutomationCatalogue.pathLabelOf.
          label: widget.catalogue.pathLabelOf(module),
          items: [
            for (final trigger in module.triggers)
              PickerItem(
                key: trigger.key,
                label: trigger.label,
                description: trigger.description,
                // ENABLED stays true on purpose. A trigger declared ahead of
                // its emitter is a reasonable thing to offer — hiding it or
                // refusing the tap answers nobody's question about why an
                // obvious event is missing, and the description explains the
                // consequence. What was wrong was that the ONLY marker was the
                // tail of a paragraph, so the row looked like every other one.
                badge: trigger.isRaised ? null : 'Not raised yet',
              ),
          ],
        ),
  ];

  /// The record this rule is about, decided by the trigger and nothing else.
  CatalogueSubject? get _subject => widget.catalogue.subjectOf(_trigger);

  /// The fields a step may set — the subject's, never the picked action's.
  List<CatalogueField> get _writableFields =>
      widget.catalogue.writableFieldsFor(_trigger);

  /// The generic actions first, then what each module declares.
  ///
  /// Both, because neither list alone is the truth. The generic three are the
  /// only ones the engine runs, and two of them — a webhook and a notification
  /// — belong to no module at all, so a picker built from the modules would
  /// hide the most useful things it can do. The module lists are what this
  /// system is going to be able to do, marked so nobody plans a rule around
  /// one and finds out at save time.
  ///
  /// A third reason a row can be refused now: it does not apply to THIS rule.
  /// "Customers › Update a field" was offered under a job trigger, enabled,
  /// with a description promising to set a field on the customer — and the
  /// engine wrote to the job. Marked and disabled, in the same way and for the
  /// same reason as "Not built yet": hiding it answers nobody's question about
  /// why an obvious action is missing.
  List<PickerGroup> get _actionGroups {
    final subject = _subject;
    // Only when a trigger has been chosen. Before that, "nothing is writable"
    // and "we do not know yet" look identical, and refusing on the second would
    // grey out the picker for a form nobody has filled in yet.
    final nothingWritable = subject != null && subject.writableFields.isEmpty;

    return [
      PickerGroup(
        key: '_generic',
        label: 'Works with anything',
        items: [
          for (final type in widget.catalogue.actionTypes)
            if (!type.moduleOnly) _genericItem(type, subject, nothingWritable),
        ],
      ),
      for (final module in widget.catalogue.modules)
        if (module.actions.isNotEmpty)
          PickerGroup(
            key: module.key,
            label: widget.catalogue.pathLabelOf(module),
            items: [
              for (final action in module.actions)
                _moduleItem(module, action, subject),
            ],
          ),
    ];
  }

  PickerItem _genericItem(
    CatalogueActionType type,
    CatalogueSubject? subject,
    bool nothingWritable,
  ) {
    // The catalogue can name a type this build has no form for — that is the
    // point of it being server-driven — and offering one produced a card saying
    // "Create a task" that saved as a notification. Checked first, because
    // "this app is too old" is true regardless of the trigger.
    if (!_ActionRow.knownTypes.contains(type.key)) {
      return PickerItem(
        key: type.key,
        label: type.label,
        enabled: false,
        badge: 'Needs a newer version of the app',
      );
    }

    if (type.key == 'update_field' && nothingWritable) {
      return PickerItem(
        key: type.key,
        label: type.label,
        enabled: false,
        badge: 'Nothing to set here',
        description:
            'This rule reacts to something about ${_article(subject!.label)}, '
            'and a rule may not change ${_article(subject.label)}.',
      );
    }

    return PickerItem(key: type.key, label: type.label);
  }

  PickerItem _moduleItem(
    CatalogueModule module,
    CatalogueAction action,
    CatalogueSubject? subject,
  ) {
    // "Not built yet" wins. An action the engine cannot run is not usable for
    // any trigger, so saying it is the wrong trigger would be a smaller truth
    // in front of a larger one.
    if (!widget.catalogue.canUse(action)) {
      return PickerItem(
        key: action.key,
        label: action.label,
        description: action.description,
        enabled: false,
        badge: 'Not built yet',
      );
    }

    if (widget.catalogue.moduleActionApplies(module, action.key, _trigger)) {
      return PickerItem(
        key: action.key,
        label: action.label,
        description: action.description,
      );
    }

    return PickerItem(
      key: action.key,
      label: action.label,
      enabled: false,
      badge: 'Not for this trigger',
      // The catalogue's own sentence, then why it cannot happen here. The
      // sentence is not wrong — it is about a customer — and that is exactly
      // what makes it misleading under a job trigger.
      description:
          '${action.description ?? ''} This rule reacts to something about '
                  '${_article(subject!.label)}, so it cannot act on '
                  '${_article(module.subject!.label)}.'
              .trim(),
    );
  }

  /// "a job", "an organization" — the record named the way a sentence needs it.
  /// "a job", "an organization" — and "a user", which is why this is not a
  /// vowel test.
  ///
  /// It was `'aeiou'.contains(word[0])`, and the users module is a real subject
  /// with three real triggers, so the picker said "This rule reacts to something
  /// about an user, and a rule may not change an user" — twice in one sentence,
  /// on a screen whose entire purpose this week was to stop the product sounding
  /// like a database.
  ///
  /// English picks the article by SOUND, not spelling: a leading "u" that says
  /// "you" takes "a" (user, unit), and a silent h or a vowel-sounding initialism
  /// takes "an" (hour, SMS). Those are the cases the subjects here can actually
  /// hit, so they are the cases handled; anything else falls back to the vowel
  /// rule, which is right far more often than not.
  static String _article(String label) {
    final word = label.toLowerCase();

    if (word.isEmpty) return word;

    // "you"-sounding u: a user, a unit, a URL.
    final saysYou =
        word.startsWith('u') &&
        !RegExp(r'^u[aeiou]').hasMatch(word) &&
        !word.startsWith('un') &&
        !word.startsWith('up');

    final silentH = word.startsWith('hour') || word.startsWith('honest');

    final needsAn = silentH || (!saysYou && 'aeiou'.contains(word[0]));

    return '${needsAn ? 'an' : 'a'} $word';
  }

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
          // And a third "no": it does not apply to this rule's subject.
          //
          // Belt to the picker's braces, and unreachable today — catalogue_picker
          // sets `onTap: null` on a disabled row, so an inapplicable action
          // cannot get here. Kept because this method is what turns a KEY into a
          // stored type, and the next caller may not come from the picker; the
          // one thing it must never do is answer "yes" for something the engine
          // would refuse. An earlier version of this comment claimed the
          // trigger-change sweep read it, which was simply untrue —
          // _dropWhatNoLongerApplies does its own check and clears the row.
          return widget.catalogue.canUse(action) &&
                  widget.catalogue.moduleActionApplies(
                    module,
                    action.key,
                    _trigger,
                  )
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
        if (action.key == picked) {
          return '${widget.catalogue.pathLabelOf(module)} › ${action.label}';
        }
      }
    }

    return picked;
  }

  // ── changing what it reacts to ───────────────────────────────────

  /// Picking a trigger, and dealing honestly with what the last one allowed.
  ///
  /// Only reachable while BUILDING: the trigger is fixed once a rule exists,
  /// because every run already recorded would otherwise describe something the
  /// rule no longer does. Which is also why this only has to cope with a form
  /// somebody is still filling in.
  void _chooseTrigger(String key) {
    if (_trigger == key) return;

    final previous = _trigger;

    setState(() {
      _trigger = key;
      _triggerNotice = _dropWhatNoLongerApplies(previous);
      // Cleared before the fetch, not after: the previous trigger's fields are
      // wrong the instant the trigger changes, and offering them for the moment
      // the request is in flight is offering fields that cannot be read.
      _triggerFields = const [];
      _fieldsSampled = null;
      _fieldValues = const {};
    });

    _loadTriggerFields();
  }

  /// Clear the steps the new subject makes impossible, and say which.
  ///
  /// Two things can go stale, and they are different. A step can be pointed at
  /// ANOTHER module's action — "Customers › Update a field" under a job trigger
  /// — and that whole choice is wrong, label and all. Or the action is fine and
  /// only the FIELD belongs to the old record: `email` on a job. The first
  /// reopens the picker; the second empties one box. Clearing more than the
  /// change requires is its own kind of rude.
  ///
  /// Returns the sentence to show, or null when nothing had to go.
  ///
  /// [previous] is the trigger being left behind, and it is needed for one
  /// reason: to name what is being cleared the way the reader saw it. They
  /// chose "Email" from a list; a sentence about `email` would be the wire key
  /// leaking back out at exactly the moment somebody is being asked to trust
  /// the form.
  String? _dropWhatNoLongerApplies(String? previous) {
    final subject = _subject;
    final writable = _writableFields;
    final before = widget.catalogue.writableFieldsFor(previous);
    final lost = <String>[];

    for (var i = 0; i < _actions.length; i++) {
      final row = _actions[i];
      final picked = row.catalogueKey;
      final actionKey = picked;
      final module = picked == null
          ? null
          : widget.catalogue.moduleOwning(picked);

      if (module != null &&
          actionKey != null &&
          !widget.catalogue.moduleActionApplies(module, actionKey, _trigger)) {
        lost.add('step ${i + 1}, “${_actionLabel(actionKey)}”');
        row.catalogueKey = null;
        row.type = null;
        row.picking = true;
        row.field.text = '';
        continue;
      }

      final field = row.field.text.trim();

      // Only when the new subject is KNOWN. An unplaceable trigger has an empty
      // field list for want of information, and clearing somebody's field on
      // the strength of that would be the silent deletion this exists to avoid.
      if (row.type == 'update_field' &&
          subject != null &&
          field.isNotEmpty &&
          !writable.any((f) => f.key == field)) {
        final named = before
            .where((f) => f.key == field)
            .map((f) => f.label)
            .firstOrNull;

        lost.add('the field “${named ?? field}” on step ${i + 1}');
        row.field.text = '';
      }
    }

    if (lost.isEmpty) return null;

    final last = lost.removeLast();
    final list = lost.isEmpty ? last : '${lost.join(', ')} and $last';

    return 'This rule is now about ${_article(subject?.label ?? 'record')}, so '
        '$list did not survive the change and ${lost.isEmpty ? 'has' : 'have'} '
        'been cleared. Everything else is as you left it — choose again below.';
  }

  // ── whether it can be saved, and why not ─────────────────────────

  /// The one thing stopping a save, in the words of whoever has to fix it.
  String? get _blocker {
    if (_name.text.trim().isEmpty) return 'Give it a name first.';

    if (_trigger == null) return 'Choose what it should react to.';

    // Before the lock check below, not after: a schedule is part of WHAT THE
    // RULE IS, like its trigger, and it stays editable on a rule whose version
    // is locked. Putting this after the lock would let a locked scheduled rule
    // be saved with a half-filled schedule.
    final schedule = _scheduleProblem;
    if (schedule != null) return schedule;

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
        // A field the subject does not have. Reachable two ways: a rule saved
        // before the field list was published, and a trigger changed under a
        // step whose choice this build could not clear. The server refuses
        // both; saying so here means nobody fills the form in first.
        //
        // Only when the subject is KNOWN. A trigger this build cannot place
        // has an empty field list for want of information, not because nothing
        // is writable, and blocking on that would strand an edit over a rule
        // that is very likely fine.
        'update_field'
            when _subject != null &&
                !_writableFields.any(
                  (f) => f.key == action.field.text.trim(),
                ) =>
          '“${action.field.text.trim()}” cannot be set on '
              '${_article(_subject?.label ?? 'record')}. Choose a field from '
              'the list.',
        'webhook' when action.url.text.trim().isEmpty =>
          'The webhook step needs an address to call.',
        'notify' when action.parameter('message').text.trim().isEmpty =>
          'The notification step needs a message.',
        'sms.send' => _missingParameter(action, 'sms.send'),
        'email.send' => _missingParameter(action, 'email.send'),
        // Refused HERE rather than by Meta. Meta catches a short parameter list
        // too — at send time, in the run log, on the rule that looked finished.
        'whatsapp.send' when action.whatsAppTemplate.isEmpty =>
          'The WhatsApp step needs a template.',
        'whatsapp.send'
            when action.parameter('to_field').text.trim().isEmpty =>
          'The WhatsApp step needs a field to take the number from.',
        'whatsapp.send'
            when action.whatsAppValues.any((c) => c.text.trim().isEmpty) =>
          'Every blank in the WhatsApp template needs a value — Meta rejects a '
              'template sent with one missing.',
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
      // Sent on create AND on edit: retiming a rule is the one thing about a
      // scheduled rule that can legitimately change without it becoming a
      // different rule. The server recomputes the next run from it.
      if (_isScheduled) 'schedule': _schedulePayload(),
      if (_lockedBecause == null) ...{
        // Always sent together. A PATCH carrying actions and no conditions
        // writes a version whose conditions are NULL — the new version would
        // quietly start matching everything.
        'conditions': _conditionsPayload(),
        'actions': [
          for (final action in _actions)
            action.toJson(widget.catalogue.actionType(action.type)),
        ],
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
            onSelected: _chooseTrigger,
          ),
          if (chosen != null) ...[
            const SizedBox(height: 10),
            Text(
              'Chosen: ${widget.catalogue.describeTrigger(chosen)}',
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ],
          if (_isScheduled) _scheduleEditor(context),
        ],
      ),
    );
  }

  /// Read a stored schedule back into the form.
  ///
  /// Tolerant of every field being missing, because the alternative is an edit
  /// screen that will not open for a rule saved by a newer build. What it will
  /// not do is invent: a zone it does not recognise is kept as the stored id
  /// rather than silently replaced with a default, since replacing it would
  /// retime somebody's rule the next time they pressed Save on an unrelated
  /// change.
  void _adoptSchedule(Object? stored) {
    if (stored is! Map) return;

    final kind = stored['kind'];
    if (kind is String && kind.isNotEmpty) _scheduleKind = kind;

    final time = stored['time'];
    if (time is String && time.isNotEmpty) _scheduleTime.text = time;

    final zone = stored['timezone'];
    if (zone is String && zone.isNotEmpty) _scheduleZone = zone;

    final days = stored['days'];
    if (days is List) {
      _scheduleDays
        ..clear()
        ..addAll(days.whereType<String>());
    }

    final day = stored['day_of_month'];
    if (day is int && day >= 1 && day <= 31) _scheduleDayOfMonth = day;
  }

  /// The schedule as the API takes it — only the keys this kind uses.
  ///
  /// `days` on a monthly rule and `day_of_month` on a weekly one are not merely
  /// redundant, they are misleading: they would be stored, read back into this
  /// form, and shown to whoever edits it next as though they meant something.
  Map<String, dynamic> _schedulePayload() => {
    'kind': _scheduleKind,
    'time': _scheduleTime.text.trim(),
    'timezone': _scheduleZone,
    if (_scheduleKind == 'weekly') 'days': _scheduleDays.toList()..sort(),
    if (_scheduleKind == 'monthly') 'day_of_month': _scheduleDayOfMonth,
  };

  /// Why this schedule cannot be saved yet, or null when it can.
  ///
  /// The server refuses all of these too — this is the same question asked
  /// early, so somebody is told before the round trip rather than by a 422.
  /// The server remains the one that decides; nothing here is the only check.
  String? get _scheduleProblem {
    if (!_isScheduled) return null;

    if (widget.catalogue.timezones.isEmpty) {
      return 'This build cannot offer timezones, so a schedule cannot be set here.';
    }

    if (_scheduleZone == null) {
      return 'Choose the timezone the time is written in.';
    }

    if (!RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(_scheduleTime.text.trim())) {
      return 'Write the time as HH:MM, such as 09:00.';
    }

    if (_scheduleKind == 'weekly' && _scheduleDays.isEmpty) {
      return 'Pick at least one day, or this rule never comes round.';
    }

    return null;
  }

  /// When it runs — shown only for the clock trigger.
  ///
  /// Inside "When this happens" rather than as a step of its own, because for
  /// this trigger the schedule IS what happens. A fifth numbered step would
  /// also renumber the three below it for every other rule in the product.
  Widget _scheduleEditor(BuildContext context) {
    final zones = widget.catalogue.timezones;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 14),
        Text('How often', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          children: [
            for (final kind in widget.catalogue.scheduleKinds)
              ChoiceChip(
                label: Text(kind.label),
                selected: _scheduleKind == kind.key,
                onSelected: (_) => setState(() => _scheduleKind = kind.key),
              ),
          ],
        ),
        if (_scheduleKind == 'weekly') ...[
          const SizedBox(height: 12),
          Text('On these days', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final day in _weekdays)
                FilterChip(
                  label: Text(_dayLabel(day)),
                  selected: _scheduleDays.contains(day),
                  onSelected: (on) => setState(() {
                    on ? _scheduleDays.add(day) : _scheduleDays.remove(day);
                  }),
                ),
            ],
          ),
        ],
        if (_scheduleKind == 'monthly') ...[
          const SizedBox(height: 12),
          Text('Day of the month', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 6),
          DropdownMenu<int>(
            initialSelection: _scheduleDayOfMonth,
            dropdownMenuEntries: [
              for (var d = 1; d <= 31; d++)
                DropdownMenuEntry(value: d, label: '$d'),
            ],
            onSelected: (d) => setState(() => _scheduleDayOfMonth = d ?? 1),
          ),
          const SizedBox(height: 4),
          Text(
            'A month without that date runs on its last day, so the 31st still '
            'runs in February.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 140,
              child: TextField(
                controller: _scheduleTime,
                decoration: const InputDecoration(
                  labelText: 'At',
                  hintText: '09:00',
                ),
                onChanged: (_) => setState(() {}),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: DropdownMenu<String>(
                width: double.infinity,
                initialSelection: _scheduleZone,
                label: const Text('Timezone'),
                dropdownMenuEntries: [
                  for (final zone in zones)
                    DropdownMenuEntry(value: zone.key, label: zone.label),
                ],
                onSelected: (z) => setState(() => _scheduleZone = z),
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          // Said plainly because it is the reason the zone is asked for at all,
          // and because somebody who picks a fixed offset should know what they
          // have chosen rather than find out in March.
          'The time is read in this timezone, so it stays correct when the '
          'clocks change. A "UTC−5" style zone has no daylight saving and will '
          'not.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        // The problem is deliberately NOT drawn here. `_scheduleProblem` is
        // already what the save blocker reports, and this screen's rule is one
        // sentence per refusal — a second copy beside the control is how the
        // two come to disagree about which one is current. The blocker is the
        // single place a person is told why Save is dead.
      ],
    );
  }

  static const _weekdays = [
    'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
  ];

  String _dayLabel(String day) =>
      day.substring(0, 1).toUpperCase() + day.substring(1, 3);

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

  /// Choosing a field, rather than guessing one.
  ///
  /// ── What this replaced, and what it cost ─────────────────────
  ///
  /// A free `TextField` with the hint "status". The vocabulary is fixed and the
  /// form published none of it, so a customer building "tell me about missed
  /// calls" wrote `from` and `Status` for an email whose payload carries
  /// `from_address` and `subject`. Both clauses read null, the rule skipped
  /// every run for a fortnight, and the trace said "the value at the time was
  /// nothing" each time — which reads as missing data, not as a wrong name. No
  /// error, nothing red; the rule simply never fired.
  ///
  /// A typo cannot be typed into a list. Search comes free with DropdownMenu
  /// and matters at the wide end: a Task event carries a couple of dozen
  /// fields, and scrolling a menu to find `due_on` is the same problem in a
  /// shorter costume.
  ///
  /// ── Why it still accepts free text ───────────────────────────
  ///
  /// The list is what recent events CARRIED, so a real field that has not
  /// appeared lately is absent. Refusing anything off-list would make a
  /// correct rule unsaveable because the office was quiet, so typing still
  /// works and an off-list value is kept and marked rather than dropped —
  /// the same idiom the settings selectors use for a stored value that is no
  /// longer offered.
  Widget _fieldChooser(BuildContext context, _ConditionRow row) {
    final typed = row.field.text.trim();
    final known = _triggerFields.contains(typed);

    // Nothing to offer yet. Say WHICH kind of nothing: a trigger that has not
    // fired here cannot be answered by waiting, and telling somebody to wait
    // for a list that will never arrive is worse than the text box was.
    if (_triggerFields.isEmpty) {
      return TextField(
        controller: row.field,
        enabled: !_busy,
        decoration: InputDecoration(
          labelText: 'Field',
          isDense: true,
          hintText: 'status',
          helperMaxLines: 3,
          helperText: _trigger == null
              ? 'Choose what this reacts to first, and the fields it carries '
                    'will be offered here.'
              : _fieldsSampled == 0
              ? 'This has not happened here yet, so there is nothing to list. '
                    'Type the field name if you know it.'
              : null,
        ),
      );
    }

    return DropdownMenu<String>(
      controller: row.field,
      enabled: !_busy,
      enableFilter: true,
      requestFocusOnTap: true,
      expandedInsets: EdgeInsets.zero,
      label: const Text('Field'),
      helperText: known || typed.isEmpty ? null : 'Not seen on this event lately',
      inputDecorationTheme: const InputDecorationTheme(isDense: true),
      dropdownMenuEntries: [
        // The stored value first when it is not one of the offered ones, so
        // opening an existing rule shows what it actually says instead of an
        // empty box that looks like the rule lost its field.
        if (typed.isNotEmpty && !known)
          DropdownMenuEntry(value: typed, label: '$typed — not seen lately'),
        for (final field in _triggerFields)
          DropdownMenuEntry(value: field, label: field),
      ],
      onSelected: (picked) {
        if (picked != null) _changed();
      },
    );
  }

  Widget _conditionRow(BuildContext context, _ConditionRow row) {
    final arity = arityOf(row.op);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(flex: 3, child: _fieldChooser(context, row)),
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
              : (arity == OperatorArity.one &&
                        _fieldValues[row.field.text.trim()] != null)
              // A CLOSED SET GETS A LIST, because nothing on this screen told
              // anybody the value they had to type was `website_chat`. The
              // field picker fixed half of "people cannot guess them around"
              // and left the other half: knowing the field is `source` does not
              // tell you what goes in the box beside it.
              //
              // Only for `is`. "is one of" takes several values separated by
              // commas, and one dropdown cannot say two things - that case
              // keeps the text box and lists the options underneath instead.
              ? DropdownMenu<String>(
                  initialSelection: row.value.text.trim().isEmpty
                      ? null
                      : row.value.text.trim(),
                  enabled: !_busy,
                  label: const Text('Value'),
                  expandedInsets: EdgeInsets.zero,
                  enableFilter: true,
                  requestFocusOnTap: true,
                  onSelected: _busy
                      ? null
                      : (chosen) => setState(
                          () => row.value.text = chosen ?? '',
                        ),
                  dropdownMenuEntries: [
                    for (final option in _fieldValues[row.field.text.trim()]!)
                      DropdownMenuEntry<String>(
                        value: option.value,
                        // The wire name is shown as well as the plain one: a
                        // rule saved before this existed reads back as the raw
                        // value, and somebody comparing the two needs to see
                        // they are the same thing.
                        label: '${option.label}  (${option.value})',
                      ),
                  ],
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
                        ? (_fieldValues[row.field.text.trim()] == null
                              ? 'Separate them with commas'
                              : 'Separate them with commas — '
                                    '${_fieldValues[row.field.text.trim()]!.map((o) => o.value).join(', ')}')
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
          if (_triggerNotice != null) ...[
            _triggerNoticeBanner(context),
            const SizedBox(height: 12),
          ],
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

  /// What the last trigger change took away.
  ///
  /// It sits inside step 4 rather than over the page, because that is where the
  /// gap it describes now is. Dismissible and not automatic: it stops being
  /// news when the reader says so, not when the form guesses they have read it.
  Widget _triggerNoticeBanner(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 2),
            child: Icon(Icons.info_outline, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _triggerNotice!,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          IconButton(
            icon: const Icon(Icons.close, size: 18),
            tooltip: 'Dismiss',
            onPressed: () => setState(() => _triggerNotice = null),
          ),
        ],
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
                  _attachTaskParameters(row);
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
      'update_field' => _updateFieldForm(row),
      'webhook' => TextField(
        controller: row.url,
        enabled: !_busy,
        decoration: const InputDecoration(
          labelText: 'https:// URL',
          isDense: true,
          helperText: 'Public addresses only — internal ones are refused.',
        ),
      ),
      'voicemail.mark_dealt' || 'missed.create_job' => _note(
        'Uses the call that triggered the rule. Nothing else to fill in.',
      ),
      'sms.send' => _parameterForm(row, widget.catalogue.actionType('sms.send')),
      'email.send' => _parameterForm(row, widget.catalogue.actionType('email.send')),
      'lead.reply' => _parameterForm(row, widget.catalogue.actionType('lead.reply')),
      'whatsapp.send' => _whatsAppForm(row),
      // Was the bespoke Message box below. It had to move in the same commit as
      // the server declaring the parameter: _payload() flips to the generic
      // branch on the first declared parameter and reads the PARAMETER
      // controllers, so a hand-written box would have posted nothing.
      'notify' => _parameterForm(row, widget.catalogue.actionType('notify')),
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

  String? _missingParameter(_ActionRow row, String type) {
    final definition = widget.catalogue.actionType(type);
    if (definition == null) {
      return 'This step needs a newer version of the app.';
    }

    for (final field in definition.parameters) {
      // A BOOLEAN IS NEVER MISSING. Off is an answer, and it is stored as the
      // empty string — so an emptiness test reads a deliberate "no" as a blank
      // and refuses to save with "X is required", against a switch that is
      // plainly sitting there answered. Every other kind is missing when empty;
      // this one cannot be.
      if (field.kind == 'boolean') {
        continue;
      }

      if (field.required &&
          !field.nullable &&
          row.parameter(field.key).text.trim().isEmpty) {
        return '${field.label} is required.';
      }
    }
    return null;
  }

  Widget _parameterForm(_ActionRow row, CatalogueActionType? definition) {
    if (definition == null) {
      return _note('This step needs a newer version of the app.');
    }

    return Column(
      children: [
        for (var i = 0; i < definition.parameters.length; i++) ...[
          if (definition.parameters[i].kind == 'phone_number')
            // A line is picked from the ones this company holds. The value
            // stored is an id, and asking somebody to type one is asking them
            // to fail — which is what a plain text field here would do.
            DropdownButtonFormField<String>(
              initialValue:
                  row.parameter(definition.parameters[i].key).text.isEmpty
                  ? null
                  : row.parameter(definition.parameters[i].key).text,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: definition.parameters[i].label,
                isDense: true,
                helperText: _lines.isNotEmpty
                    ? 'Only lines approved for messaging are listed.'
                    : _linesHeld == 0
                    ? 'No phone lines on this company yet.'
                    // The sentence that used to be missing. Lines exist and none
                    // of them may text, which is a different errand entirely -
                    // and before the filter this case showed a full list whose
                    // every option failed at the carrier.
                    : 'This company has $_linesHeld phone '
                          '${_linesHeld == 1 ? 'line' : 'lines'}, and none are '
                          'approved for text messaging yet.',
              ),
              items: [
                for (final line in _lines)
                  DropdownMenuItem(
                    value: line.$1,
                    child: Text(line.$2, overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: _busy
                  ? null
                  : (id) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          id ?? '',
                    ),
            )
          // WHICH FIELD OF THE EVENT CARRIES THE ADDRESS, from the same list
          // the condition builder offers. Not a typed address: on EzBizi's own
          // sending identity a rule that can email anywhere is a spam cannon
          // with the whole platform's reputation behind it. `sms.send` takes a
          // typed destination and that is deliberate — the line there is the
          // tenant's own.
          // THREE SOURCES, ONE BOX, AND TYPING IS ALLOWED. A person here, a
          // field of the event, or an address somebody types. Jose asked for
          // the first and last on 2026-09-16; the middle one is what the step
          // shipped with.
          else if (definition.parameters[i].kind == 'recipient')
            _recipientPicker(
              row,
              definition.parameters[i].key,
              definition.parameters[i].label,
            )
          else if (definition.parameters[i].kind == 'trigger_field')
            _triggerFieldPicker(
              row,
              definition.parameters[i].key,
              definition.parameters[i].label,
            )
          // WHICH ROLES, and nothing chosen means everybody with reach — which
          // is what every rule saved before this did, so an untouched picker
          // must not change one. It narrows and can never widen: reach still
          // decides who COULD be told.
          else if (definition.parameters[i].kind == 'roles')
            _rolePicker(row, definition.parameters[i].key, definition.parameters[i].label)
          else if (definition.parameters[i].kind == 'boolean')
            // A yes/no answer, drawn as a switch.
            //
            // Without this kind a boolean parameter renders as a TEXT BOX
            // somebody types "true" into — so a module declaring one would
            // ship a control that looks broken and stores a string the server
            // has to guess at. The Settings session hit exactly that wanting a
            // per-rule "also email this", and the honest options were a bespoke
            // key outside the parameter mechanism or this. This is smaller and
            // it is the last time anyone has to choose.
            //
            // Stored as the same text the other kinds store — 'true' or '' —
            // because every parameter travels through one map of controllers
            // and a second typed store beside it would be a second thing to
            // keep in step. The server reads it as a boolean; the empty string
            // is false, which is also what an older client that never drew the
            // switch sends.
            SwitchListTile(
              value:
                  row.parameter(definition.parameters[i].key).text == 'true',
              onChanged: _busy
                  ? null
                  : (on) => setState(
                      () => row.parameter(definition.parameters[i].key).text =
                          on ? 'true' : '',
                    ),
              title: Text(definition.parameters[i].label),
              contentPadding: EdgeInsets.zero,
              dense: true,
            )
          else
            TextField(
              controller: row.parameter(definition.parameters[i].key),
              enabled: !_busy,
              keyboardType: definition.parameters[i].kind == 'date'
                  ? TextInputType.datetime
                  : TextInputType.text,
              maxLines: definition.parameters[i].kind == 'rich_text' ? 3 : 1,
              decoration: InputDecoration(
                labelText: definition.parameters[i].label,
                isDense: true,
                hintText: definition.parameters[i].kind == 'date'
                    ? 'YYYY-MM-DD'
                    : null,
                helperText: definition.parameters[i].nullable
                    ? 'Leave blank to clear it.'
                    : null,
              ),
            ),
          if (i < definition.parameters.length - 1) const SizedBox(height: 10),
        ],
      ],
    );
  }

  /// Roles to narrow a notification to, stored as IDS.
  ///
  /// Not names: a Role has no key column and its name is editable, so a rule
  /// storing names would re-point itself the day somebody tidied "Office" into
  /// "Office Staff" — silently, and in the WIDENING direction. An id survives a
  /// rename and fails loudly on a delete.
  ///
  /// Stored in the parameter controller as a comma-joined list because every
  /// parameter shares one map of text controllers; the payload splits it back
  /// into an array, the same shape the runner reads.
  Widget _rolePicker(_ActionRow row, String key, String label) {
    final chosen = row
        .parameter(key)
        .text
        .split(',')
        .where((id) => id.trim().isNotEmpty)
        .toSet();

    final roles = widget.catalogue.roles;

    if (roles.isEmpty) {
      return _note('No roles to choose from, so this will tell everybody who '
          'can see the record.');
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        Text(
          chosen.isEmpty
              ? 'Nothing chosen — everybody who can see the record is told.'
              : 'Only these roles are told, and only those of them who can '
                    'already see the record.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 4),
        Wrap(
          spacing: 8,
          children: [
            for (final role in roles)
              FilterChip(
                label: Text(role.label),
                selected: chosen.contains(role.key),
                onSelected: _busy
                    ? null
                    : (on) => setState(() {
                        final next = {...chosen};
                        on ? next.add(role.key) : next.remove(role.key);
                        row.parameter(key).text = next.join(',');
                      }),
              ),
          ],
        ),
      ],
    );
  }

  /// Who an email goes to: somebody here, a field of the event, or a typed address.
  ///
  /// The TEXT BOX is the value, and the menu beside it fills it in. That way
  /// typing an address is not a special case bolted onto a dropdown — it is the
  /// ordinary thing, and choosing is the shortcut. A DropdownMenu that also
  /// accepted free text would have to guess whether half-typed text was a choice
  /// or a value, and guesses like that are how a field silently discards what
  /// somebody typed.
  ///
  /// A person is stored as `user:<id>`, never as their address, so the rule
  /// follows them when they change it and refuses when they leave.
  Widget _recipientPicker(_ActionRow row, String key, String label) {
    final controller = row.parameter(key);

    final choices = <CatalogueChoice>[
      ...widget.catalogue.people,
      for (final field in _triggerFields)
        CatalogueChoice(key: field, label: 'From the event: $field'),
    ];

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: TextField(
            controller: controller,
            enabled: !_busy,
            decoration: InputDecoration(
              labelText: label,
              isDense: true,
              helperText: _describeRecipient(controller.text.trim()),
            ),
          ),
        ),
        const SizedBox(width: 8),
        PopupMenuButton<String>(
          enabled: !_busy && choices.isNotEmpty,
          tooltip: 'Choose a person or a field',
          icon: const Icon(Icons.arrow_drop_down_circle_outlined),
          onSelected: (chosen) => setState(() => controller.text = chosen),
          itemBuilder: (context) => [
            for (final choice in choices)
              PopupMenuItem(value: choice.key, child: Text(choice.label)),
          ],
        ),
      ],
    );
  }

  /// Says which of the three a stored value is, so nobody has to know the syntax.
  String _describeRecipient(String value) {
    if (value.isEmpty) {
      return 'Type an address, or choose a person or a field of the event.';
    }

    if (value.startsWith('user:')) {
      final person = widget.catalogue.people
          .where((p) => p.key == value)
          .firstOrNull;

      return person == null
          // The person is gone from the list — left, switched off, or lost their
          // address. Said here rather than at run time, where it would be a
          // failed step somebody reads days later.
          ? 'This person no longer works here, so nothing would be sent.'
          : 'Goes to ${person.label}. If they change their address it follows them.';
    }

    if (value.contains('@')) return 'Goes to this address every time.';

    return _triggerFields.contains(value)
        ? 'Goes to whatever this event carries in “$value”.'
        : 'Not an address, and this event does not carry a field called “$value”.';
  }

  /// WHICH FIELD OF THE EVENT carries the address or number.
  ///
  /// Shared by the email step's declared parameter and the WhatsApp step's own
  /// form, because they are asking the same question and a second copy would
  /// answer it differently the first time either changed.
  ///
  /// Not a typed destination, and that is the point on both: these send on
  /// EzBizi's own sending identity and EzBizi's own WhatsApp number, shared by
  /// every tenant, so a rule that could write to any address is a spam cannon
  /// with the platform's reputation behind it. `sms.send` takes a typed number
  /// on purpose — the line there is the tenant's own.
  Widget _triggerFieldPicker(_ActionRow row, String key, String label) {
    return DropdownMenu<String>(
      initialSelection: row.parameter(key).text.isEmpty
          ? null
          : row.parameter(key).text,
      enabled: !_busy && _triggerFields.isNotEmpty,
      label: Text(label),
      expandedInsets: EdgeInsets.zero,
      enableFilter: true,
      requestFocusOnTap: true,
      helperText: _triggerFields.isEmpty
          ? (_trigger == null
                ? 'Choose what sets the rule off first.'
                : 'Nothing of this kind has happened here yet, so there are no '
                      'fields to choose from.')
          : null,
      onSelected: _busy
          ? null
          : (chosen) => setState(() => row.parameter(key).text = chosen ?? ''),
      dropdownMenuEntries: [
        for (final field in _triggerFields)
          DropdownMenuEntry<String>(value: field, label: field),
      ],
    );
  }

  /// Pick an approved template, say who it goes to, fill its blanks.
  ///
  /// The blanks are drawn from the SERVER's count, not from the preview text:
  /// Meta numbers positionally and "{{1}} owes {{3}}" is three with a gap, so
  /// counting placeholders would be right on every template anybody tested and
  /// wrong at send time on the one with a hole. The save is refused when a blank
  /// is empty for the same reason — Meta would catch it, in the run log, later.
  Widget _whatsAppForm(_ActionRow row) {
    if (_whatsAppTemplates.isEmpty) {
      return _note(
        _whatsAppBecause ??
            'No WhatsApp templates are available for this business yet.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<String>(
          initialValue: row.whatsAppTemplate.isEmpty
              ? null
              : row.whatsAppTemplate,
          isExpanded: true,
          decoration: const InputDecoration(
            labelText: 'Template',
            isDense: true,
            helperText: 'Only templates Meta has approved are listed.',
          ),
          items: [
            for (final template in _whatsAppTemplates)
              DropdownMenuItem(
                value: template.name,
                child: Text(
                  template.preview.isEmpty
                      ? template.name
                      : '${template.name} — ${template.preview}',
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: _busy
              ? null
              : (chosen) => setState(() {
                  row.whatsAppTemplate = chosen ?? '';

                  final picked = _whatsAppTemplates
                      .where((t) => t.name == chosen)
                      .firstOrNull;

                  row.whatsAppParameters = picked?.parameters ?? 0;

                  // Rebuilt rather than resized: a leftover controller from a
                  // previous template would carry its value into a blank that
                  // means something else.
                  for (final c in row.whatsAppValues) {
                    c.dispose();
                  }
                  row.whatsAppValues
                    ..clear()
                    ..addAll([
                      for (var i = 0; i < row.whatsAppParameters; i++)
                        TextEditingController()..addListener(_changed),
                    ]);
                }),
        ),
        const SizedBox(height: 10),
        _triggerFieldPicker(row, 'to_field', 'Send to the number in'),
        for (var i = 0; i < row.whatsAppValues.length; i++) ...[
          const SizedBox(height: 10),
          TextField(
            controller: row.whatsAppValues[i],
            enabled: !_busy,
            decoration: InputDecoration(
              labelText: 'Blank ${i + 1}',
              isDense: true,
              helperText: 'Use {{ field }} to take it from the record.',
            ),
          ),
        ],
      ],
    );
  }

  /// Which field, and what to set it to.
  ///
  /// The field was a free-text box, and the server took whatever was in it:
  /// `organization_id` on a job trigger saved with a 201 and was then refused
  /// by the engine on every run, forever. It is a picker now, and the options
  /// are the SUBJECT's — the record the trigger is about — because that is what
  /// the engine writes to, whichever module's action was chosen.
  Widget _updateFieldForm(_ActionRow row) {
    final subject = _subject;
    final fields = _writableFields;

    if (_trigger == null) {
      // An empty dropdown is a dead end that explains nothing. Which fields
      // exist is not knowable until step 2 is answered, so it says so.
      return _note(
        'Choose what this reacts to first, in step 2. Which fields a step can '
        'set depends on the record the event is about.',
      );
    }

    if (subject == null) {
      // A trigger this build cannot place: a module removed, or a server older
      // than this app. "No fields" and "I cannot tell" are different answers,
      // and treating the second as the first would dead-end the form over a
      // rule that is probably fine — including, on an edit, one that has been
      // running for months. So it degrades to what this box always was, says
      // why, and lets the server be the one that refuses.
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: TextField(
              controller: row.field,
              enabled: !_busy,
              decoration: const InputDecoration(
                labelText: 'Field',
                isDense: true,
                helperText:
                    'This app cannot tell what that event is about, so it '
                    'cannot offer the list. Saving will check it.',
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(child: _valueBox(row)),
        ],
      );
    }

    if (fields.isEmpty) {
      return _note(
        'Nothing on ${_article(subject.label)} can be set by a rule, so there '
        'is no field to choose. Pick a different kind of step.',
      );
    }

    final current = row.field.text.trim();
    final known = fields.any((f) => f.key == current);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: DropdownButtonFormField<String>(
            // Null for anything the subject does not have, because the dropdown
            // asserts on a value with no matching item — and a saved rule can
            // legitimately carry one, from before any of this was checked.
            initialValue: known ? current : null,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: 'Field',
              isDense: true,
              helperText: known || current.isEmpty
                  ? 'On ${_article(subject.label)} — whichever one the event '
                        'was about.'
                  : null,
              // Named, not silently dropped. Somebody chose that value, or the
              // server stored it, and a box that just went blank is the thing
              // people file bugs about.
              errorText: known || current.isEmpty
                  ? null
                  : '“$current” is not something a rule may set on '
                        '${_article(subject.label)}.',
            ),
            items: [
              for (final field in fields)
                DropdownMenuItem(
                  value: field.key,
                  child: Text(field.label, overflow: TextOverflow.ellipsis),
                ),
            ],
            onChanged: _busy
                ? null
                : (value) => setState(() {
                    if (value != null) row.field.text = value;
                  }),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(child: _valueBox(row)),
      ],
    );
  }

  Widget _valueBox(_ActionRow row) => TextField(
    controller: row.value,
    enabled: !_busy,
    decoration: const InputDecoration(labelText: 'Set to', isDense: true),
  );

  Widget _note(String text) => Builder(
    builder: (context) =>
        Text(text, style: Theme.of(context).textTheme.bodySmall),
  );

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

  /// The numbered-step shape this screen invented, now drawn by a shared widget
  /// so the contract designer cannot slowly disagree with it about a heading
  /// size. Kept as a method because every call site here reads
  /// `_step(context, 3, …)` and rewriting six of them buys nothing.
  Widget _step(
    BuildContext context,
    int number,
    String title,
    String note,
    Widget body, {
    Widget? action,
  }) {
    return NumberedStep(
      number: number,
      title: title,
      note: note,
      action: action,
      child: body,
    );
  }
}
