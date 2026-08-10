/// The vocabulary an organization builds automations out of (plan §5.5, §2.1).
///
/// It arrives from `GET /automations/catalogue` because the MODULES own it: a
/// module declares the triggers it emits and the actions it intends to expose,
/// and the catalogue is that declaration flattened for a client. Nothing in
/// this file is a list of triggers — a module added next year appears the
/// moment it declares itself, and the words describing one change without a
/// release. That is the no-hardcoding rule doing its job rather than being
/// quoted at people.
///
/// ── Why this is a file and not a pile of `as Map` casts ──────────
///
/// The builder reads the catalogue in a dozen places: to group events, to name
/// the one a rule already points at, to decide whether an action can be picked.
/// Doing that against raw JSON puts the same three casts in a dozen widgets,
/// and one of them eventually disagrees with the others about what a missing
/// `description` means. Parsing once, here, also makes the whole vocabulary
/// testable without a widget tree.
library;

/// A `{key, label}` pair — an operator, or one of the action types the engine
/// can actually run.
class CatalogueChoice {
  const CatalogueChoice({required this.key, required this.label});

  final String key;
  final String label;

  /// Null for anything that is not a usable entry, so a single malformed row
  /// costs one option rather than the whole screen.
  static CatalogueChoice? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueChoice(key: key, label: json['label'] as String? ?? key);
  }
}

/// Something that happens, which a rule can listen for.
class CatalogueTrigger {
  const CatalogueTrigger({
    required this.key,
    required this.label,
    this.description,
  });

  /// What gets stored on the automation — `customer.owner_reassigned`.
  final String key;

  /// What a person reads — "Owner reassigned".
  final String label;

  /// One sentence saying when this fires. Null when the server sent none.
  final String? description;

  /// [json] is an object per the catalogue contract.
  ///
  /// A bare string is still accepted, and means one thing only: a server older
  /// than this build, during the minutes of a deploy. Showing the raw key then
  /// is exactly what this screen used to do all the time — poor, but legible,
  /// and better than a cast that takes the page down.
  static CatalogueTrigger? tryFrom(Object? json) {
    if (json is String) {
      return json.isEmpty ? null : CatalogueTrigger(key: json, label: json);
    }

    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueTrigger(
      key: key,
      label: json['label'] as String? ?? key,
      description: json['description'] as String?,
    );
  }
}

/// One field a rule is allowed to set, and what it is called.
///
/// The key is what goes on the wire; the label is what somebody reads. Both
/// come from the server, from the same array the engine enforces — a client
/// that invented "Date and time" for `scheduled_for` would be a second
/// dictionary, and the field list is a security invariant, so the copy that
/// drifts would be the one deciding what the form offers.
class CatalogueField {
  const CatalogueField({required this.key, required this.label});

  final String key;
  final String label;

  static CatalogueField? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueField(key: key, label: json['label'] as String? ?? key);
  }
}

/// The RECORD a module's events are about.
///
/// This is what makes the field picker possible: `update_field` writes to the
/// subject of the event, so the trigger — not the action — decides which fields
/// exist. A rule on "Job › Status changed" can set a job's fields, whichever
/// module's "Update a field" was chosen from the list.
///
/// [writableFields] empty is an ANSWER, not a gap: a rule can react to a
/// company being created and cannot change anything about the company.
class CatalogueSubject {
  const CatalogueSubject({
    required this.key,
    required this.label,
    required this.writableFields,
  });

  /// `job`, `customer` — the same word the trigger keys begin with.
  final String key;

  /// Singular, and how it reads in a sentence: "on a Job".
  final String label;

  final List<CatalogueField> writableFields;

  bool allows(String field) => writableFields.any((f) => f.key == field);

  static CatalogueSubject? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueSubject(
      key: key,
      label: json['label'] as String? ?? key,
      writableFields: _list(json['writable_fields'], CatalogueField.tryFrom),
    );
  }
}

/// An action a module says it wants to offer.
///
/// [implemented] is the server's answer to "can the engine run this today", and
/// it is why these are LISTED rather than hidden. A module declares intent at
/// the moment it is written (the §5.5 standing rule); the engine implements a
/// smaller generic set. Hiding the difference would advertise nothing and
/// explain nothing — showing it marked says what the system will grow into.
class CatalogueAction {
  const CatalogueAction({
    required this.key,
    required this.label,
    required this.implemented,
    this.description,
  });

  final String key;
  final String label;
  final String? description;
  final bool implemented;

  static CatalogueAction? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueAction(
      key: key,
      label: json['label'] as String? ?? key,
      description: json['description'] as String?,
      implemented: json['implemented'] == true,
    );
  }
}

/// One module's contribution to the vocabulary — its events and its actions.
class CatalogueModule {
  const CatalogueModule({
    required this.key,
    required this.label,
    required this.triggers,
    required this.actions,
    this.subject,
  });

  final String key;
  final String label;
  final List<CatalogueTrigger> triggers;
  final List<CatalogueAction> actions;

  /// The record this module's events carry, and what may be set on it.
  ///
  /// Null means a server that predates this field, or a module whose events
  /// are about no record at all. Both are read the same way and deliberately:
  /// "I cannot tell" must never become "this does not apply", because the
  /// wrong direction there is a picker that hides the only action that works.
  final CatalogueSubject? subject;

  static CatalogueModule? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    return CatalogueModule(
      key: key,
      label: json['label'] as String? ?? key,
      subject: CatalogueSubject.tryFrom(json['subject']),
      triggers: _list(json['triggers'], CatalogueTrigger.tryFrom),
      actions: _list(json['declared_actions'], CatalogueAction.tryFrom),
    );
  }
}

/// Everything the builder is allowed to offer.
class AutomationCatalogue {
  const AutomationCatalogue({
    required this.modules,
    required this.operators,
    required this.actionTypes,
    this.actionBudget,
  });

  factory AutomationCatalogue.fromJson(Map<String, dynamic> json) {
    return AutomationCatalogue(
      modules: _list(json['modules'], CatalogueModule.tryFrom),
      operators: _list(json['operators'], CatalogueChoice.tryFrom),
      actionTypes: _list(json['action_types'], CatalogueChoice.tryFrom),
      actionBudget: (json['limits'] as Map?)?['action_budget'] as int?,
    );
  }

  final List<CatalogueModule> modules;

  /// Fixed in code on the server and deliberately so — a runtime-editable
  /// operator set is arbitrary expressions over arbitrary fields. They still
  /// travel over the wire because their WORDING is not an invariant, and a
  /// client with its own copy of "is one of" is a client that will one day
  /// disagree with the evaluator about what that means.
  final List<CatalogueChoice> operators;

  /// The action vocabulary `POST /automations` accepts. Anything a module
  /// declares that does not reduce to one of these is intent, not a usable
  /// action.
  final List<CatalogueChoice> actionTypes;

  /// How many actions one originating event may fan out to across every rule
  /// it sets off, before the engine stops the cascade. Worth saying on the form
  /// — it is the number that decides whether a rule fires or is cut off.
  final int? actionBudget;

  /// Which module a trigger belongs to, and what it is called there.
  (CatalogueModule, CatalogueTrigger)? locate(String triggerKey) {
    for (final module in modules) {
      for (final trigger in module.triggers) {
        if (trigger.key == triggerKey) return (module, trigger);
      }
    }

    return null;
  }

  /// "Customers › Owner reassigned".
  ///
  /// Falls back to the raw key, which is not a formatting choice: a rule can
  /// name a trigger no module declares any more — a module removed, or one
  /// switched off for this organization — and inventing a friendly name for it
  /// would hide the one fact worth knowing, that nothing emits it.
  String describeTrigger(String triggerKey) {
    final found = locate(triggerKey);

    return found == null ? triggerKey : '${found.$1.label} › ${found.$2.label}';
  }

  /// The record a rule on [triggerKey] is about, or null when nothing here
  /// declares that trigger — a module removed, or a server older than this
  /// build.
  CatalogueSubject? subjectOf(String? triggerKey) =>
      triggerKey == null ? null : locate(triggerKey)?.$1.subject;

  /// The fields a rule on [triggerKey] may set. Empty for "nothing may be set"
  /// AND for "we cannot tell" — the caller distinguishes them by asking for the
  /// subject, because the two need different words on screen.
  List<CatalogueField> writableFieldsFor(String? triggerKey) =>
      subjectOf(triggerKey)?.writableFields ?? const [];

  /// Which module declares an action, so a picked key can be traced back to the
  /// record it is about.
  CatalogueModule? moduleOwning(String actionKey) {
    for (final module in modules) {
      for (final action in module.actions) {
        if (action.key == actionKey) return module;
      }
    }

    return null;
  }

  /// Can a module's declared action be used by a rule on [triggerKey]?
  ///
  /// Every module action is a thing done to that module's records, and the
  /// generic behaviour behind it acts on the subject of the EVENT. So
  /// "Customers › Update a field" under a job trigger is not a near miss, it is
  /// a contradiction: the card said Customers, the catalogue said "sets one
  /// field on the customer", and the engine wrote to the job.
  ///
  /// Answers TRUE whenever it cannot tell — no trigger chosen yet, a module
  /// with no subject, a server too old to send one. Guessing "no" would hide
  /// working actions, and the server refuses the real mismatch anyway.
  bool moduleActionApplies(CatalogueModule module, String? triggerKey) {
    final mine = module.subject?.key;
    final theirs = subjectOf(triggerKey)?.key;

    if (mine == null || theirs == null) return true;

    return mine == theirs;
  }

  String operatorLabel(String key) {
    for (final operator in operators) {
      if (operator.key == key) return operator.label;
    }

    return key;
  }

  /// Which payload `type` a module's declared action reduces to, or null when
  /// it reduces to nothing this engine runs.
  ///
  /// `customer.update_field` is stored and executed as the generic
  /// `update_field`; `job.change_status` has no generic behind it yet. The
  /// suffix is taken after the FIRST dot, matching how the server decides the
  /// same question — the two answers have to agree, because one of them marks
  /// the row and the other refuses the save.
  String? payloadTypeFor(CatalogueAction action) {
    final dot = action.key.indexOf('.');
    final suffix = dot < 0 ? action.key : action.key.substring(dot + 1);

    for (final type in actionTypes) {
      if (type.key == suffix) return suffix;
    }

    return null;
  }

  /// Runnable means: the server says it is implemented AND it reduces to a
  /// type the server accepts. Both, because a save the form allowed and the
  /// server refused is the failure this whole distinction exists to prevent —
  /// and if the two ever disagree, refusing to offer it is the safe direction.
  ///
  /// Runnable is not the same as SELECTABLE. An action can be perfectly
  /// runnable and still be wrong for the rule in front of you — see
  /// [moduleActionApplies], which is the other half and depends on the trigger
  /// this catalogue knows nothing about.
  bool canUse(CatalogueAction action) =>
      action.implemented && payloadTypeFor(action) != null;
}

/// How many values an operator takes.
///
/// The client works this out rather than being told, because the catalogue
/// contract carries a key and a label and nothing else. That is a real seam:
/// an operator this build has never heard of gets a single value box, which is
/// the shape most of them have and the one least likely to produce a rule that
/// silently never matches. Adding an `arity` to the contract would close it
/// properly, and is worth doing when the operator set next changes.
enum OperatorArity {
  /// "is empty", "changed" — the field is the whole clause.
  none,

  /// The ordinary case.
  one,

  /// "is one of" — a list, which a single text box cannot express and which
  /// the evaluator compares element by element.
  many,
}

OperatorArity arityOf(String operatorKey) => switch (operatorKey) {
  'is_empty' || 'is_not_empty' || 'changed' => OperatorArity.none,
  'in' || 'not_in' => OperatorArity.many,
  _ => OperatorArity.one,
};

List<T> _list<T>(Object? json, T? Function(Object?) parse) {
  if (json is! List) return const [];

  return [
    for (final entry in json)
      if (parse(entry) case final T parsed) parsed,
  ];
}
