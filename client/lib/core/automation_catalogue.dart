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

  /// The same pair, from a list that calls its key `id`.
  ///
  /// Timezones come from the settings registry, where a zone has an `id`. They
  /// are mapped onto the same type as every other closed list rather than
  /// getting one of their own, so a picker does not need to know where its
  /// options were defined.
  static CatalogueChoice? tryFromIdLabel(Object? json) {
    if (json is! Map) return null;

    final id = json['id'];

    if (id is! String || id.isEmpty) return null;

    return CatalogueChoice(key: id, label: json['label'] as String? ?? id);
  }
}

class CatalogueActionParameter {
  const CatalogueActionParameter({
    required this.key,
    required this.label,
    required this.kind,
    required this.required,
    required this.nullable,
    required this.variables,
  });

  final String key;
  final String label;
  final String kind;
  final bool required;
  final bool nullable;

  /// Whether the runner substitutes `{{ field }}` in this box.
  ///
  /// Declared by the server rather than guessed from the kind: it is true of a
  /// message and false of `update_field`'s value, which is written as typed. A
  /// builder that offered variables everywhere would promise something the
  /// runner does not do in most of the places it was offered.
  final bool variables;

  static CatalogueActionParameter? tryFrom(Object? json) {
    if (json is! Map || json['key'] is! String) return null;

    return CatalogueActionParameter(
      key: json['key'] as String,
      label: json['label'] as String? ?? json['key'] as String,
      kind: json['kind'] as String? ?? 'text',
      required: json['required'] == true,
      nullable: json['nullable'] == true,
      variables: json['variables'] == true,
    );
  }
}

class CatalogueActionType extends CatalogueChoice {
  const CatalogueActionType({
    required super.key,
    required super.label,
    required this.moduleOnly,
    required this.parameters,
    this.subjects,
  });

  final bool moduleOnly;
  final List<CatalogueActionParameter> parameters;

  /// The records this step works on, as model names ("Job", "PhoneCall"), or
  /// null for any record. A server older than the list sends nothing, which
  /// reads as null: "any", the answer it gave before.
  final List<String>? subjects;

  /// Does this step work on the record a subject key names?
  ///
  /// Model names and subject keys are spelled differently ("PhoneCall" and
  /// `phone_call`), so both are compared without case or underscores.
  bool worksOn(String subjectKey) {
    final allowed = subjects;
    if (allowed == null) return true;
    return allowed.any((model) => sameRecord(model, subjectKey));
  }

  static CatalogueActionType? tryFrom(Object? json) {
    if (json is! Map || json['key'] is! String) return null;
    final key = json['key'] as String;
    if (key.isEmpty) return null;

    return CatalogueActionType(
      key: key,
      label: json['label'] as String? ?? key,
      moduleOnly: json['module_only'] == true,
      parameters: _list(json['parameters'], CatalogueActionParameter.tryFrom),
      subjects: json['subjects'] is List
          ? [for (final one in json['subjects'] as List) '$one']
          : null,
    );
  }
}

/// Something that happens, which a rule can listen for.
class CatalogueTrigger {
  const CatalogueTrigger({
    required this.key,
    required this.label,
    this.description,
    this.isRaised = true,
    this.subjects,
  });

  /// The records THIS trigger is about, as model names ("Invitation"), when
  /// they are not its module's. Empty means no record in particular; null
  /// means the module's subject, which is also what an older server meant.
  final List<String>? subjects;

  /// What gets stored on the automation — `customer.owner_reassigned`.
  final String key;

  /// What a person reads — "Owner reassigned".
  final String label;

  /// One sentence saying when this fires. Null when the server sent none.
  final String? description;

  /// Does anything actually emit this yet?
  ///
  /// False means a rule built on it saves, switches on, and never runs. The
  /// description already says so in words; this is the same fact as a flag, so
  /// the row can be marked rather than only explained.
  ///
  /// **Defaults to TRUE, and that direction is deliberate.** A server older
  /// than this build sends no `is_raised`, and the honest reading of silence is
  /// "this trigger is fine" — the same answer that server's own description
  /// gives, since it appends the warning sentence itself when it is not. The
  /// opposite default would badge every trigger in the catalogue as broken for
  /// the minutes of a deploy.
  final bool isRaised;

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
      // `!= false` rather than `== true`: absent and null both mean an older
      // server, which is not the same claim as `false`. Only an explicit false
      // marks the row.
      isRaised: json['is_raised'] != false,
      subjects: json['subjects'] is List
          ? [for (final one in json['subjects'] as List) '$one']
          : null,
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
  const CatalogueField({
    required this.key,
    required this.label,
    this.kind,
    this.options,
  });

  final String key;
  final String label;

  /// text, enum, money, datetime or integer: which "Set to" control fits.
  /// Null on a server older than kinds, which keeps the text box.
  final String? kind;

  /// The closed list the field takes, or null where any value goes.
  final List<({String value, String label})>? options;

  static CatalogueField? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    final options = json['options'];

    return CatalogueField(
      key: key,
      label: json['label'] as String? ?? key,
      kind: json['kind'] as String?,
      options: options is List
          ? [
              for (final one in options)
                if (one is Map)
                  (
                    value: '${one['value'] ?? ''}',
                    label: '${one['label'] ?? one['value'] ?? ''}',
                  ),
            ]
          : null,
    );
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
    required this.type,
    required this.subjectBound,
    this.description,
  });

  final String key;
  final String label;
  final String? description;
  final bool implemented;
  final String type;
  final bool subjectBound;

  static CatalogueAction? tryFrom(Object? json) {
    if (json is! Map) return null;

    final key = json['key'];

    if (key is! String || key.isEmpty) return null;

    final declaredType = json['type'];
    final dot = key.indexOf('.');

    return CatalogueAction(
      key: key,
      label: json['label'] as String? ?? key,
      description: json['description'] as String?,
      implemented: json['implemented'] == true,
      type: declaredType is String && declaredType.isNotEmpty
          ? declaredType
          : (dot < 0 ? key : key.substring(dot + 1)),
      subjectBound: json['subject_bound'] != false,
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
    this.parent,
  });

  final String key;
  final String label;
  final List<CatalogueTrigger> triggers;
  final List<CatalogueAction> actions;

  /// The module this one is drawn inside, in the app somebody just came from.
  /// Null for a module that stands on its own, and on a server that predates
  /// the field — which reads as "top level", the shape everything had before.
  final String? parent;

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
      parent: json['parent'] as String?,
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
    required this.timezones,
    required this.scheduleKinds,
    this.roles = const [],
    this.people = const [],
    this.reports = const [],
    this.workHoursReports = const [],
    this.whatsappGroups = const [],
    this.places = const [],
    this.templates = const [],
    this.trainings = const [],
    this.taskBoards = const [],
    this.contracts = const [],
    this.audiences = const [],
    this.reportTextKinds = const [],
    this.actionBudget,
  });

  factory AutomationCatalogue.fromJson(Map<String, dynamic> json) {
    return AutomationCatalogue(
      modules: _list(json['modules'], CatalogueModule.tryFrom),
      operators: _list(json['operators'], CatalogueChoice.tryFrom),
      actionTypes: _list(json['action_types'], CatalogueActionType.tryFrom),
      // `id`, not `key`, because these come from the settings registry where a
      // timezone is an id. Mapped here so the rest of the client sees the same
      // CatalogueChoice every other closed list uses.
      timezones: _list(json['timezones'], CatalogueChoice.tryFromIdLabel),
      scheduleKinds: _list(json['schedule_kinds'], CatalogueChoice.tryFrom),
      // Served by the automations catalogue rather than fetched from /roles,
      // which is gated on `roles.view` — a rule author holds automations.edit
      // and need not hold that. Id and name only.
      roles: _list(json['roles'], CatalogueChoice.tryFromIdLabel),
      // Active people with an address, for an email step's "Send to". The key
      // already carries the `user:` prefix the runner reads, so the client never
      // constructs it and cannot construct it wrongly.
      reports: _list(json['reports'], CatalogueChoice.tryFromIdLabel),
      workHoursReports: _list(json['work_hours_reports'], CatalogueChoice.tryFromIdLabel),
      whatsappGroups: _list(json['whatsapp_groups'], CatalogueChoice.tryFromIdLabel),
      places: _list(json['places'], CatalogueChoice.tryFromIdLabel),
      trainings: _list(json['trainings'], CatalogueChoice.tryFromIdLabel),
      taskBoards: _list(json['task_boards'], CatalogueChoice.tryFromIdLabel),
      contracts: _list(json['contracts'], CatalogueChoice.tryFromIdLabel),
      audiences: _list(json['audiences'], CatalogueChoice.tryFromIdLabel),
      reportTextKinds: _list(
        json['report_text_kinds'],
        CatalogueChoice.tryFromIdLabel,
      ),
      templates: [
        for (final one in (json['templates'] as List? ?? const []))
          if (one is Map) Map<String, dynamic>.from(one),
      ],
      people: [
        for (final row in (json['people'] as List? ?? const []))
          if (row is Map<String, dynamic> && row['id'] is String)
            (
              key: row['id'] as String,
              label: (row['label'] ?? row['id']).toString(),
              hasEmail: row['has_email'] == true,
              hasPhone: row['has_phone'] == true,
            ),
      ],
      actionBudget: (json['limits'] as Map?)?['action_budget'] as int?,
    );
  }

  final List<CatalogueModule> modules;

  /// The roles a notification step may narrow to. Empty narrows nothing.
  final List<CatalogueChoice> roles;

  /// People a step can be addressed to, and which contact details they have.
  ///
  /// One list rather than two, filtered by what the step needs: a texting step
  /// must not offer somebody with only an email, and an emailing step must not
  /// offer somebody with only a phone. Two lists would be two things to keep in
  /// step the day somebody's details change.
  final List<({String key, String label, bool hasEmail, bool hasPhone})> people;

  /// Saved reports a rule can send. Name only — a report's definition says which
  /// columns and filters a business uses, which is more than a permission about
  /// writing rules should carry.
  final List<CatalogueChoice> reports;

  /// Work Hours report definitions a rule can email. Name only.
  final List<CatalogueChoice> workHoursReports;

  /// Staff WhatsApp groups a rule can post to: id is the group's purpose.
  final List<CatalogueChoice> whatsappGroups;

  /// Where a rule can be pointed, in this person's own reach.
  ///
  /// The id is the STORED shape — `organization`, `company:<id>`,
  /// `franchise:<id>` — so the builder hands back what it was given instead of
  /// assembling a scope from two fields and mispairing them.
  final List<CatalogueChoice> places;

  /// Ready-made rules, the same in every business.
  ///
  /// Held as raw maps rather than a typed class on purpose: a template IS a
  /// rule's payload — trigger, conditions, actions — and the builder already
  /// knows how to read that shape from a saved rule. Typing it here would mean
  /// a second reader of the same JSON, which is the thing that drifts.
  final List<Map<String, dynamic>> templates;

  /// Published guides and tests a rule can assign, as `guide:<id>` or
  /// `test:<id>` — the stored shape, so the builder hands back what it got.
  final List<CatalogueChoice> trainings;

  /// Task boards the author may write, for "Add a task" on a named board.
  final List<CatalogueChoice> taskBoards;

  /// Contract templates an onboarding can be started against — from Human
  /// Resources, so it offers exactly what the act accepts. Name only.
  final List<CatalogueChoice> contracts;

  /// Mailchimp lists a rule can add somebody who agreed to. Never the
  /// customer book: that audience has no members to add.
  final List<CatalogueChoice> audiences;

  /// Reports' daily texts a "WhatsApp a report text" step can send.
  final List<CatalogueChoice> reportTextKinds;

  /// Whether this server says which record any module is about.
  ///
  /// False only on a server older than subjects, where every module's is null.
  /// Then "no subject" means "cannot tell" everywhere, and nothing may be
  /// hidden for want of one.
  bool get publishesSubjects => modules.any((m) => m.subject != null);

  /// Fixed in code on the server and deliberately so — a runtime-editable
  /// operator set is arbitrary expressions over arbitrary fields. They still
  /// travel over the wire because their WORDING is not an invariant, and a
  /// client with its own copy of "is one of" is a client that will one day
  /// disagree with the evaluator about what that means.
  final List<CatalogueChoice> operators;

  /// The action vocabulary `POST /automations` accepts. Anything a module
  /// declares that does not reduce to one of these is intent, not a usable
  /// action.
  final List<CatalogueActionType> actionTypes;

  /// The zones a schedule may be written in.
  ///
  /// From the server, and from the SETTINGS registry at that, so this picker
  /// and the organization's own timezone selector cannot disagree about what a
  /// zone is called. Empty on a server older than schedules, which is what the
  /// builder checks before offering to schedule anything.
  final List<CatalogueChoice> timezones;

  /// Every day / days of the week / once a month.
  final List<CatalogueChoice> scheduleKinds;

  CatalogueActionType? actionType(String? key) {
    if (key == null) return null;
    for (final type in actionTypes) {
      if (type.key == key) return type;
    }
    return null;
  }

  /// How many actions one originating event may fan out to across every rule
  /// it sets off, before the engine stops the cascade. Worth saying on the form
  /// — it is the number that decides whether a rule fires or is cut off.
  final int? actionBudget;

  /// What to call a module in a picker, given where the app draws it.
  ///
  /// "Marketing › Leads" for a module drawn inside another, its own label
  /// otherwise. Composed here rather than sent composed, because the parent's
  /// wording is already on this list and a second copy built on the server is a
  /// second chance for the two to disagree about one module's name.
  ///
  /// Falls back to the bare label when the parent is not in the catalogue at
  /// all — which is not hypothetical: the catalogue only carries modules this
  /// person can reach, so somebody holding `leads.view` without
  /// `marketing.view` gets Leads with no Marketing to hang it under. "Leads" is
  /// the right answer for them; "Marketing › Leads" would name a tab they
  /// cannot open.
  String pathLabelOf(CatalogueModule module) {
    if (module.parent case final key?) {
      for (final candidate in modules) {
        if (candidate.key == key) return '${candidate.label} › ${module.label}';
      }
    }

    return module.label;
  }

  /// The module a picker should FILE this one under: its parent, when the
  /// parent is on this list, and itself otherwise.
  ///
  /// Jose, 2026-09-24: *"The Marketing Tab is divided into tabs, when all of it
  /// should be together."* The trigger and action pickers had one category per
  /// module, so Marketing arrived as five — Marketing, Marketing › Newsletter,
  /// › Web Management, › Reviews, › Business Profile — and somebody looking
  /// for a marketing event had to open each in turn.
  ///
  /// Same fallback as [pathLabelOf], and for the same reason: the catalogue
  /// carries only modules this person can reach, so a child whose parent is
  /// absent stands on its own rather than being filed under a heading for a
  /// tab they cannot open.
  CatalogueModule topLevelOf(CatalogueModule module) {
    if (module.parent case final key?) {
      for (final candidate in modules) {
        if (candidate.key == key) return candidate;
      }
    }

    return module;
  }

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
  ///
  /// The module's subject, unless the trigger names its own records and the
  /// module's is not among them: then the event is about something the
  /// module's fields do not describe, and null says so.
  CatalogueSubject? subjectOf(String? triggerKey) {
    if (triggerKey == null) return null;
    final found = locate(triggerKey);
    if (found == null) return null;
    final (module, trigger) = found;
    final own = module.subject;
    final named = trigger.subjects;
    if (named == null || own == null) return named == null ? own : null;
    return named.any((model) => sameRecord(model, own.key)) ? own : null;
  }

  /// The records a rule on [triggerKey] acts on, as plain names to compare
  /// ("phonecall", "job"): the trigger's own list, else its module's subject.
  List<String> recordsOf(String? triggerKey) {
    if (triggerKey == null) return const [];
    final found = locate(triggerKey);
    if (found == null) return const [];
    final named = found.$2.subjects;
    if (named != null) return [for (final model in named) plainRecord(model)];
    final own = found.$1.subject?.key;
    return own == null ? const [] : [plainRecord(own)];
  }

  /// Does anything emit [triggerKey] yet?
  ///
  /// TRUE when nothing here declares it, for the same reason
  /// [CatalogueTrigger.isRaised] defaults true: a trigger this build cannot
  /// find is a question this build cannot answer, and answering "broken" would
  /// mark every saved rule during a deploy, or any rule built on a module the
  /// reader cannot reach. Only a trigger the catalogue HAS, and says is
  /// unraised, is unraised.
  bool triggerIsRaised(String? triggerKey) =>
      triggerKey == null || (locate(triggerKey)?.$2.isRaised ?? true);

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
  bool moduleActionApplies(
    CatalogueModule module,
    String actionKey,
    String? triggerKey,
  ) {
    for (final action in module.actions) {
      if (action.key == actionKey && !action.subjectBound) return true;
    }
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
    for (final type in actionTypes) {
      if (type.key == action.type) return action.type;
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

/// A model name ("PhoneCall") or a subject key (`phone_call`), spelled the
/// same way so the two can be compared.
String plainRecord(String name) => name.replaceAll('_', '').toLowerCase();

bool sameRecord(String a, String b) => plainRecord(a) == plainRecord(b);

List<T> _list<T>(Object? json, T? Function(Object?) parse) {
  if (json is! List) return const [];

  return [
    for (final entry in json)
      if (parse(entry) case final T parsed) parsed,
  ];
}
