/// The Contracts tab's wire types (EZBIZI-HR-PLAN.md, "Contracts").
///
/// ── What is here, and what is deliberately not ───────────────────
///
/// A contract template's fields are the TENANT's — their keys, their labels,
/// their order, which of them are sensitive. None of that is modelled here
/// beyond being carried, because the app must not know what any of them mean.
///
/// What IS modelled is the vocabulary a field is written in: the kinds of
/// question that exist, who a question can be answered by, and the common core
/// that is asked whatever the contract says. Every one of those arrives from
/// `GET /hr/contracts/catalogue`. There is no matching list in this file, and
/// that is the point — the automation builder shipped a client-side copy of the
/// action vocabulary and drew a card reading "Create a task" that saved a
/// notification, and a client-side copy of a trigger's field list that offered
/// fields the engine refused. The side that ENFORCES a vocabulary is the side
/// that describes it.
library;

import 'package:flutter/foundation.dart';

/// The three values of `filled_by`, and the only part of the field vocabulary
/// this file names.
///
/// ── Why these are compiled in when the labels are not ────────────
///
/// §2.1's carve-out for API contracts, and the same argument `viewAction` makes
/// in core/permissions.dart. Every WORD on screen for these three comes from
/// the catalogue and is never guessed here. What is compiled in is the
/// structural fact behind them: a contract has two parties, and one of the three
/// values means "both may". A preview cannot draw "the form the employee sees"
/// without knowing which value is the employee, and there is no answer to that
/// question a tenant could give — an organization does not get to invent a
/// fourth party.
///
/// The test worth applying to anything added below: could an organization
/// reasonably want it to say something else? If yes, it belongs in the
/// catalogue, not here.
const String filledByEmployer = 'employer';
const String filledByEmployee = 'employee';
const String filledByEither = 'either';

/// One entry of a server-declared vocabulary — a field type, or one of the
/// three answers to "who fills this in".
@immutable
class ContractChoice {
  const ContractChoice({
    required this.key,
    required this.label,
    this.description,
    this.takesOptions = false,
  });

  /// What travels on the wire — `text`, `employer`.
  final String key;

  /// The server's word for it. Never derived from [key]: the settings screen
  /// upper-cased identifiers and produced headings like "NUMBERING" that nobody
  /// had written, and "One of a list" is not recoverable from `choice` by any
  /// amount of string manipulation.
  final String label;

  /// A sentence about what it is for, where the server offers one.
  final String? description;

  /// Whether a field of this kind carries a list of answers to pick from.
  ///
  /// A field type's property, declared by the server, and the designer draws its
  /// options editor from this. It was `type == 'choice'` in Dart — a server rule
  /// re-stated on the client, in the screen whose own docblock says it does not
  /// do that — so a seventh kind that took options would have been offered with
  /// no editor and no explanation.
  ///
  /// Defaults false, which is the safe direction for a `filled_by` entry that
  /// has no such property and for a malformed catalogue: a type nobody said
  /// takes options gets no options box, and the server refuses options on it
  /// anyway.
  final bool takesOptions;
}

/// One of the facts a template may not declare, because the person's own
/// profile already owns it (the plan's one-home rule).
@immutable
class CoreField {
  const CoreField({
    required this.key,
    required this.label,
    this.note,
    this.type,
    this.filledBy,
    this.required = true,
  });

  final String key;

  /// The server's wording where it sends one, and otherwise the key itself.
  ///
  /// The key, verbatim, rather than title-cased into words nobody wrote. A
  /// reader seeing `date_of_birth` knows they are looking at an identifier the
  /// server did not put a label on; a reader seeing "Date Of Birth" believes
  /// somebody chose that wording.
  final String label;

  /// Why it is always there, or where it lives. Both spellings the server might
  /// use land here — see [ContractVocabulary.fromJson].
  final String? note;

  final String? type;
  final String? filledBy;
  final bool required;
}

/// Everything `GET /hr/contracts/catalogue` declares.
///
/// ── Why the parsing is tolerant in exactly three places ──────────
///
/// A vocabulary is naturally expressed two ways — a list of `{key, label}`
/// objects, or a map of key to label — and both are reasonable answers to the
/// same question. `PermissionCatalogue.fromResponse` already accepts two
/// spellings for the same reason it is worth doing here: a wording difference
/// between the two halves of one contract should not turn into a screen that
/// renders nothing and says nothing about why. It is tolerant about SHAPE and
/// never about CONTENT — no key, label or core field is invented here when the
/// server sends none.
@immutable
class ContractVocabulary {
  const ContractVocabulary({
    required this.types,
    required this.filledBy,
    required this.core,
    required this.loaded,
  });

  /// The state before the catalogue lands, and after it fails. [loaded] is what
  /// tells the two apart from "the server declares nothing", which would be a
  /// real answer and is not this one.
  static const ContractVocabulary none = ContractVocabulary(
    types: [],
    filledBy: [],
    core: [],
    loaded: false,
  );

  /// text · number · date · choice · file · signature — whatever the server
  /// says, in the server's order.
  final List<ContractChoice> types;

  /// employer · employee · either.
  final List<ContractChoice> filledBy;

  /// The questions asked whatever the contract says.
  final List<CoreField> core;

  final bool loaded;

  bool get isUsable => loaded && types.isNotEmpty && filledBy.isNotEmpty;

  /// The server's word for a key, or the key itself when it has none.
  ///
  /// The raw key is the honest fallback. A template published against a newer
  /// server can carry a type this build's catalogue has never heard of, and
  /// showing `duration` is a smaller lie than showing nothing.
  String typeLabel(String key) => _labelOf(types, key);

  String filledByLabel(String key) => _labelOf(filledBy, key);

  String? filledByDescription(String key) {
    for (final choice in filledBy) {
      if (choice.key == key) return choice.description;
    }

    return null;
  }

  bool knowsType(String key) => types.any((t) => t.key == key);

  /// Whether a kind of question carries a list of answers.
  ///
  /// Asked of the server's declaration, never of the string `choice`. A type
  /// this build has not heard of answers false — the same fallback [knowsType]
  /// takes, and for the same reason: this end does not invent properties for a
  /// vocabulary it was not sent.
  bool typeTakesOptions(String? key) {
    if (key == null) return false;

    for (final choice in types) {
      if (choice.key == key) return choice.takesOptions;
    }

    return false;
  }

  /// Whether a key is one the common core already owns.
  ///
  /// Asked of the server's list, never of a list of six strings in Dart. The
  /// server refuses these keys and its refusal names where the fact lives; this
  /// only exists so the refusal arrives while somebody is typing rather than
  /// after they press Publish.
  bool isCoreKey(String key) => core.any((field) => field.key == key);

  static String _labelOf(List<ContractChoice> choices, String key) {
    for (final choice in choices) {
      if (choice.key == key) return choice.label;
    }

    return key;
  }

  factory ContractVocabulary.fromJson(Map<String, dynamic> json) {
    return ContractVocabulary(
      // `field_types` is what the endpoint sends; `types` is the shorter
      // spelling of the same list, accepted for the reason on the class.
      types: _choices(json['field_types'] ?? json['types']),
      filledBy: _choices(json['filled_by'] ?? json['filledBy']),
      // `reserved_keys` is the endpoint's word for the common core, and it is
      // the better one: what the server publishes is the set of keys a template
      // may not declare, which is the same list read from the other end.
      core: _coreFields(
        json['reserved_keys'] ??
            json['core'] ??
            json['common_core'] ??
            json['reserved'],
      ),
      loaded: true,
    );
  }

  /// A list of `{key, label, description, takes_options}`, or a map of key to
  /// label.
  static List<ContractChoice> _choices(Object? node) {
    if (node is List) {
      return [
        for (final entry in node.whereType<Map>())
          if (entry['key'] is String)
            ContractChoice(
              key: entry['key'] as String,
              label: entry['label'] as String? ?? entry['key'] as String,
              description: entry['description'] as String?,
              // `== true` rather than `!= false`: absent must mean no. This is
              // the one property that puts an extra control on screen, and the
              // wrong default would draw an options box for every type sent by a
              // server too old to have the field.
              takesOptions: entry['takes_options'] == true,
            ),
      ];
    }

    if (node is Map) {
      return [
        for (final entry in node.entries)
          ContractChoice(
            key: '${entry.key}',
            // A map's value is the label when it is a string, and a nested
            // object when the server had more to say than one word.
            label: entry.value is Map
                ? (entry.value as Map)['label'] as String? ?? '${entry.key}'
                : '${entry.value}',
            description: entry.value is Map
                ? (entry.value as Map)['description'] as String?
                : null,
            takesOptions: entry.value is Map
                ? (entry.value as Map)['takes_options'] == true
                : false,
          ),
      ];
    }

    return const [];
  }

  static List<CoreField> _coreFields(Object? node) {
    if (node is List) {
      return [
        for (final entry in node.whereType<Map>())
          if (entry['key'] is String)
            CoreField(
              key: entry['key'] as String,
              // `label` or the key, and deliberately nothing in between.
              //
              // `is` used to sit in this chain, on the reasoning that the
              // server's own words beat a bare identifier. They are the server's
              // words and they are not a label: `is` holds a phrase written to
              // complete "it is …", so the designer drew a locked chip reading
              // "their middle name" and the form preview captioned a box "the
              // email address on their account". The endpoint now sends a proper
              // `label` beside it (CommonCore::RESERVED), and a fragment is never
              // promoted into a caption again.
              //
              // The key remains the last resort for the reason on [CoreField]:
              // `date_of_birth` reads as an identifier the server put no label
              // on, which is true, and "Date Of Birth" reads as wording somebody
              // chose, which is not.
              label: entry['label'] as String? ?? entry['key'] as String,
              note:
                  entry['note'] as String? ??
                  entry['why'] as String? ??
                  entry['reason'] as String? ??
                  entry['description'] as String? ??
                  // Last, and only as a note — which is the one job the phrase
                  // is written for.
                  entry['is'] as String?,
              type: entry['type'] as String?,
              filledBy: entry['filled_by'] as String?,
              required: entry['required'] != false,
            ),
      ];
    }

    // `CommonCore::RESERVED` is a map of key to a sentence about where the fact
    // lives, so a bare string is a note rather than a label.
    if (node is Map) {
      return [
        for (final entry in node.entries)
          if (entry.value is Map)
            CoreField(
              key: '${entry.key}',
              label: (entry.value as Map)['label'] as String? ?? '${entry.key}',
              note:
                  (entry.value as Map)['note'] as String? ??
                  (entry.value as Map)['description'] as String?,
              type: (entry.value as Map)['type'] as String?,
              filledBy: (entry.value as Map)['filled_by'] as String?,
              required: (entry.value as Map)['required'] != false,
            )
          else
            CoreField(
              key: '${entry.key}',
              label: '${entry.key}',
              note: '${entry.value}',
            ),
      ];
    }

    return const [];
  }
}

/// One question on a template, as the server stored it.
///
/// Immutable, and used for what was LOADED. The designer edits its own mutable
/// rows — a field being typed into is not the same object as a field that has
/// been published, and the two were worth keeping apart.
@immutable
class ContractField {
  const ContractField({
    required this.key,
    required this.label,
    required this.type,
    required this.options,
    required this.filledBy,
    required this.required,
    required this.sensitive,
    required this.retired,
  });

  final String key;
  final String label;
  final String type;
  final List<String> options;
  final String filledBy;
  final bool required;
  final bool sensitive;

  /// Out of new onboardings, still readable on records that already carry it.
  /// Never deleted — see the plan, and the server's refusal to accept a version
  /// that drops a key.
  final bool retired;

  factory ContractField.fromJson(Map<String, dynamic> json) => ContractField(
    key: json['key'] as String? ?? '',
    label: json['label'] as String? ?? '',
    type: json['type'] as String? ?? '',
    options: (json['options'] as List<dynamic>? ?? const [])
        .whereType<String>()
        .toList(growable: false),
    filledBy: json['filled_by'] as String? ?? '',
    required: json['required'] == true,
    sensitive: json['sensitive'] == true,
    retired: json['retired'] == true,
  );

  static List<ContractField> listFrom(Object? data) => data is List
      ? data
            .whereType<Map>()
            .map(
              (row) => ContractField.fromJson(Map<String, dynamic>.from(row)),
            )
            .toList(growable: false)
      : const [];
}

/// What publishing would change, as the server worked it out.
///
/// Parsed rather than computed. The client holds both lists and could diff them
/// in Dart, and then the warning somebody reads and the version that actually
/// gets written would come from two implementations that agree until one of
/// them gains a property. The server previews its own publish; this reads the
/// answer.
@immutable
class ContractDiff {
  const ContractDiff({
    required this.added,
    required this.retired,
    required this.unretired,
    required this.changed,
    required this.reordered,
    required this.isEmpty,
  });

  final List<ContractField> added;
  final List<DiffEntry> retired;
  final List<DiffEntry> unretired;
  final List<ChangedField> changed;

  /// Order is the order of the form, so a reshuffle is a real change.
  final bool reordered;

  final bool isEmpty;

  bool get isNotEmpty => !isEmpty;

  factory ContractDiff.fromJson(Map<String, dynamic> json) => ContractDiff(
    // An added entry carries the field's own properties, so it parses as one —
    // `options` is absent from that payload and comes back empty, which is
    // exactly what an added line needs to say about a non-choice field.
    added: ContractField.listFrom(json['added']),
    retired: DiffEntry.listFrom(json['retired']),
    unretired: DiffEntry.listFrom(json['unretired']),
    changed: (json['changed'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .map((row) => ChangedField.fromJson(Map<String, dynamic>.from(row)))
        .toList(growable: false),
    reordered: json['reordered'] == true,
    // Computed by the server, never re-derived from the four lists above: a
    // server that grows a fifth kind of change would otherwise be told by this
    // client that nothing happened.
    isEmpty: json['is_empty'] == true,
  );
}

/// A field named on one line of the diff.
@immutable
class DiffEntry {
  const DiffEntry({required this.key, required this.label});

  final String key;
  final String label;

  static List<DiffEntry> listFrom(Object? data) => data is List
      ? [
          for (final row in data.whereType<Map>())
            DiffEntry(
              key: row['key'] as String? ?? '',
              label: row['label'] as String? ?? row['key'] as String? ?? '',
            ),
        ]
      : const [];
}

/// A field that survived, with the properties that moved.
@immutable
class ChangedField {
  const ChangedField({
    required this.key,
    required this.label,
    required this.properties,
  });

  final String key;
  final String label;

  /// property name → (before, after), straight from the server.
  final Map<String, (Object?, Object?)> properties;

  factory ChangedField.fromJson(Map<String, dynamic> json) {
    final raw = json['properties'];

    return ChangedField(
      key: json['key'] as String? ?? '',
      label: json['label'] as String? ?? '',
      properties: {
        if (raw is Map)
          for (final entry in raw.entries)
            '${entry.key}': (
              entry.value is Map ? (entry.value as Map)['from'] : null,
              entry.value is Map ? (entry.value as Map)['to'] : null,
            ),
      },
    );
  }
}

/// The eight properties of a field definition, in words.
///
/// Compiled in under §2.1's carve-out for API contracts, and for the same
/// reason `invitationStatusLabel` is: the field model is the plan's, an
/// organization does not get to invent a ninth property, and the diff names
/// them by their wire spelling. Anything this build has not heard of is shown
/// exactly as the server sent it rather than as "unknown", which would hide the
/// one fact worth knowing.
String fieldPropertyLabel(String property) => switch (property) {
  'label' => 'Wording',
  'type' => 'Kind of answer',
  'options' => 'Answers to choose from',
  'filled_by' => 'Who fills it in',
  'required' => 'Has to be answered',
  'sensitive' => 'Sensitive',
  'retired' => 'Retired',
  'key' => 'Key',
  _ => property,
};
