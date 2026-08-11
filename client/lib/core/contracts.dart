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
    this.merges = true,
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

  /// Whether a field of this kind can be named by a merge tag.
  ///
  /// The server's `merges`, and the second server rule this end used to guess
  /// at. The body editor offered every live field with a key, so a template
  /// carrying a signature field offered its tag — and the server refuses that
  /// tag with a paragraph about a signature being an act rather than text. An
  /// offer that cannot be accepted is worse than no offer.
  ///
  /// Defaults TRUE, which is the opposite default to [takesOptions] and right
  /// for the opposite reason: that one puts a control on screen, so absent must
  /// mean no; this one takes a tag away, and a catalogue too old to carry the
  /// property must not silently withdraw tags that publish perfectly well.
  final bool merges;
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

/// Where a merge tag's value comes from, and the only part of the merge-tag
/// vocabulary this file names.
///
/// §2.1's carve-out, and the same test [filledByEmployer] applies: could an
/// organization reasonably want it to say something else? No — these three are
/// the structure of the merge itself (`MergeTags::SOURCE_*`), and a tenant does
/// not get to invent a fourth source any more than they get to invent a third
/// party to the contract. Every WORD on screen is a heading the client wrote or
/// a label the server sent; nothing readable is derived from these strings.
const String mergeSourceField = 'field';
const String mergeSourceCore = 'core';
const String mergeSourceFact = 'fact';

/// One tag the server says resolves, ready to be offered.
///
/// ── This is a REGISTRY entry, not a description of one ───────────
///
/// `MergeTags::resolvable()` is the registry, and it is published on THREE
/// responses:
///
///  1. `GET /hr/contracts/catalogue` as `merge_tags.core` and
///     `merge_tags.facts` — the half that does not depend on a template, which
///     is all that endpoint can know. It is the only half there is while a
///     contract is being CREATED and there is no id to preview against.
///  2. `GET /hr/contracts/{contract}` as `merge_tags` — the whole list for the
///     version in force, in the same payload the fields and the body arrive
///     in. This is the one that is already in hand the moment the designer
///     opens an existing contract.
///  3. `POST /hr/contracts/{contract}/preview` as `merge_tags` — the whole
///     list again, for the questions as they stand THIS second rather than as
///     they were published, because that request is the one carrying them.
///
/// Two and three overlap, and the overlap is the point: three is the live
/// answer and two is what stands in until it arrives — or permanently, when a
/// preview is refused, which is exactly the moment somebody is reaching for a
/// chip to fix the tag that was refused.
///
/// A client that assembled this itself would have to know four server rules:
/// that a field is offered only while it is live, that a signature field is not
/// merged at all, that the common core offers the six onboarding ASKS and not
/// the eight keys it RESERVES, and that a fact key is dotted. This end knew
/// none of them correctly. It offered `{{name}}` and `{{middle_name}}` — both
/// reserved, neither asked, both refused by name at publish — and it offered
/// signature fields, which are refused with a paragraph explaining that a
/// signature is an act rather than text.
///
/// So nothing here is derived. [key], [tag], [label] and [note] are the
/// server's, [tag] most of all: the exact characters to insert, assembled where
/// the delimiters are defined.
@immutable
class MergeTag {
  const MergeTag({
    required this.key,
    required this.tag,
    required this.label,
    required this.source,
    this.note,
  });

  /// What goes between the delimiters — `wage`, `first_name`,
  /// `organization.name`.
  ///
  /// A fact key is DOTTED, and the dot is the whole safety argument on the
  /// server (`ContractFacts`): a field key can never contain one, so a tenant's
  /// own question can never collide with a fact. A build that guessed the shape
  /// from what "everybody knows" would guess `organization_name` and put a tag
  /// in somebody's employment contract that renders as itself.
  final String key;

  /// The exact text to insert.
  ///
  /// Assembled by the server, and this end never wraps a key in delimiters of
  /// its own — see [MergeTagSyntax.wrap] for the one case that has to.
  final String tag;

  /// The server's word for it, or the key when it sent none — the same
  /// fallback, and the same reasoning, as [CoreField.label].
  final String label;

  /// [mergeSourceField] · [mergeSourceCore] · [mergeSourceFact].
  final String source;

  /// The server's `is` — the phrase completing "it is …", shown under the tag
  /// in the tooltip and never used as a label. [CoreField.label] says what
  /// happened the last time a phrase was promoted into a caption.
  final String? note;

  /// A list of `{key, tag, label, source, is}` — the shape all three responses
  /// publish.
  ///
  /// [source] is the fallback for a payload that does not carry one, and the
  /// entry's own answer always wins. All three send it today — the catalogue's
  /// two named lists are the same `resolvable()` entries the other two send as
  /// one flat list, grouped rather than rebuilt — so the argument is tolerance
  /// for an older server and not the normal path. It is still passed rather
  /// than defaulted, because `merge_tags.core` and `merge_tags.facts` arrive
  /// under names that already say which they are.
  ///
  /// ── An entry with no `tag` is dropped, not written here ──────
  ///
  /// This used to wrap the key itself when `tag` was missing, which made it a
  /// SECOND place that assembles a tag — in the same class as the docblock on
  /// [tag] promising there is exactly one, with an inline comment beside it
  /// that admitted as much.
  /// `resolvable()` writes `tag` for every entry it publishes, so an entry
  /// arriving without one is a server that has said nothing about how this tag
  /// is written; the empty string falls out of the filter below, and an entry
  /// that cannot be inserted is not offered. Guessing would put characters in
  /// somebody's employment contract that no server chose.
  ///
  /// The note is `is`, and only `is`. It read `note` as an alternative — a key
  /// no endpoint has ever sent, which is the same "fallback to a name nothing
  /// uses" that hid the always-available facts for as long as nobody looked.
  /// See [ContractVocabulary.fromJson].
  static List<MergeTag> listFrom(Object? node, String source) {
    if (node is! List) return const [];

    return [
      for (final entry in node.whereType<Map>())
        if (entry['key'] is String)
          MergeTag(
            key: entry['key'] as String,
            tag: entry['tag'] as String? ?? '',
            label: entry['label'] as String? ?? entry['key'] as String,
            source: entry['source'] as String? ?? source,
            note: entry['is'] as String?,
          ),
    ].where((tag) => tag.tag.isNotEmpty).toList(growable: false);
  }
}

/// How a merge tag is written, and the tags that do not depend on which
/// template is open — `merge_tags` on `GET /hr/contracts/catalogue`.
///
/// The delimiters are published rather than compiled in, and they are two
/// strings rather than a format string so that building a tag from a key is
/// WRAPPING and never parsing an example.
///
/// That reason is the server's, and it is deliberately not quoted as the
/// server's words: `HrContractsController` says why it sends two strings and
/// then says "Sent, not enforced. What any client then does is not asserted
/// here." A sentence in this file claiming the server asserts something about
/// this end would be putting a promise in its mouth that it took care to
/// remove. What is written above is this end's own undertaking, which is the
/// only side that can make it: [wrap] is the one place in this app that turns
/// a key into a tag.
@immutable
class MergeTagSyntax {
  const MergeTagSyntax({
    required this.open,
    required this.close,
    required this.example,
    required this.maxBodyLength,
    required this.core,
    required this.facts,
  });

  /// Nothing declared — before the catalogue lands, and after it fails.
  static const MergeTagSyntax none = MergeTagSyntax(
    open: '',
    close: '',
    example: '',
    maxBodyLength: 0,
    core: [],
    facts: [],
  );

  final String open;
  final String close;

  /// A written-out tag, for the hint in the writing surface.
  final String example;

  /// The most a body may be, or 0 when the server declared no cap.
  ///
  /// Zero means "not declared" rather than "nothing allowed": the editor shows
  /// no counter and refuses nothing, which is what it did before this was read
  /// at all. A wrong cap invented here would refuse a contract the server would
  /// have accepted, which is worse than the server's own refusal arriving late.
  final int maxBodyLength;

  /// The details every onboarding collects — `CommonCore::asked()`, the SIX,
  /// and never the eight keys `reserved_keys` publishes. Two of those eight
  /// (`name`, `middle_name`) are reserved so a template cannot declare them and
  /// are not asked by anybody, so a tag naming one is refused at publish with a
  /// paragraph saying there is nothing to merge.
  final List<MergeTag> core;

  /// The facts about the business and the document.
  final List<MergeTag> facts;

  /// Whether a tag can be written at all. False means the catalogue said
  /// nothing about the syntax, and nothing may be inserted.
  bool get isUsable => open.isNotEmpty && close.isNotEmpty;

  /// A key, wrapped in the published delimiters.
  ///
  /// For the one list the server cannot pre-assemble: a template's own
  /// questions while the template does not exist yet, which is the create path
  /// where there is no `{contract}` to preview against. Everywhere else the
  /// server's own [MergeTag.tag] is used verbatim.
  String wrap(String key) => '$open$key$close';

  factory MergeTagSyntax.fromJson(Map<String, dynamic> json, Object? limits) {
    final open = json['open'] as String? ?? '';
    final close = json['close'] as String? ?? '';

    return MergeTagSyntax(
      open: open,
      close: close,
      example: json['example'] as String? ?? '',
      // Published in both places by the same endpoint, and they are the same
      // number. Neither spelling is a guess — see HrContractsController.
      maxBodyLength:
          (json['max_body_length'] as num?)?.toInt() ??
          ((limits is Map ? limits['max_body_length'] : null) as num?)
              ?.toInt() ??
          0,
      core: MergeTag.listFrom(json['core'], mergeSourceCore),
      facts: MergeTag.listFrom(json['facts'], mergeSourceFact),
    );
  }
}

/// Everything `GET /hr/contracts/catalogue` declares.
///
/// ── Where the parsing is tolerant, and where it stopped being ────
///
/// A vocabulary is naturally expressed two ways — a list of `{key, label}`
/// objects, or a map of key to label — and both are reasonable answers to the
/// same question. `PermissionCatalogue.fromResponse` already accepts two
/// spellings for the same reason it was worth doing here: a wording difference
/// between the two halves of one contract should not turn into a screen that
/// renders nothing and says nothing about why. Where it is done, it is tolerant
/// about SHAPE and never about CONTENT — no key, label or core field is
/// invented here when the server sends none.
///
/// The COMMON CORE is still read both ways, and that one is not hypothetical:
/// `CommonCore::RESERVED` is genuinely a map of key to `{label, is}` on the
/// server, and the endpoint flattening it into a list is a presentation choice
/// one refactor away from changing.
///
/// The FIELD VOCABULARIES are read one way only, as of this round. [_choices]
/// had a map branch that parsed `takes_options` out of a nested object and
/// never parsed `merges` — so a map-shaped catalogue would have been ACCEPTED
/// with the one property that takes a tag AWAY silently missing, and the
/// signature chips would have come back in the body editor to be refused by
/// name at publish. No endpoint has ever sent that shape, so nothing was ever
/// half-read in production; what was there was an untested branch that was
/// wrong about the safety property, which is worse than no branch at all. A
/// shape this cannot read is now refused visibly — `isUsable` goes false and
/// the tab says the server did not declare the vocabulary — rather than
/// accepted with a hole in it.
@immutable
class ContractVocabulary {
  const ContractVocabulary({
    required this.types,
    required this.filledBy,
    required this.core,
    required this.mergeTags,
    required this.loaded,
  });

  /// The state before the catalogue lands, and after it fails. [loaded] is what
  /// tells the two apart from "the server declares nothing", which would be a
  /// real answer and is not this one.
  static const ContractVocabulary none = ContractVocabulary(
    types: [],
    filledBy: [],
    core: [],
    mergeTags: MergeTagSyntax.none,
    loaded: false,
  );

  /// text · number · date · choice · file · signature — whatever the server
  /// says, in the server's order.
  final List<ContractChoice> types;

  /// employer · employee · either.
  final List<ContractChoice> filledBy;

  /// The questions asked whatever the contract says.
  ///
  /// `reserved_keys` — the eight keys a template may NOT declare, which is what
  /// the designer needs to grey a key out while somebody is typing it. It is
  /// deliberately NOT the list the body editor offers: two of the eight are
  /// reserved without being asked, so offering them would insert a tag that is
  /// refused at publish. The insertable core is [MergeTagSyntax.core].
  final List<CoreField> core;

  /// How a tag is written, and everything insertable that does not depend on
  /// which template is open — see [MergeTag].
  final MergeTagSyntax mergeTags;

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

  /// Whether a kind of question can be named by a merge tag.
  ///
  /// A type this build has not heard of answers TRUE — see
  /// [ContractChoice.merges] for why this default is the other way round from
  /// [typeTakesOptions]'s.
  bool typeMerges(String? key) {
    if (key == null) return true;

    for (final choice in types) {
      if (choice.key == key) return choice.merges;
    }

    return true;
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
      // ONE NAME, AND NO ALTERNATIVES. `merge_tags` is what the endpoint sends.
      //
      // This used to read `document_tags`, then `always_available`, then
      // `always` — three names, none of which any server has ever sent — so the
      // always-available tags were simply never offered in the editor. The
      // tolerant reader is what hid it: three fallbacks that all miss produce
      // an empty list, not an error, and an empty list looks exactly like "this
      // contract declares no facts".
      //
      // The tolerance on the class is about SHAPE — a list of objects or a map
      // of key to label, two reasonable ways to write one thing. It was never a
      // licence to guess at names, and the guesses are gone rather than kept as
      // a courtesy: a fallback to a key nothing sends is not a fallback, it is
      // the same silence with more code in front of it.
      mergeTags: MergeTagSyntax.fromJson(
        json['merge_tags'] is Map
            ? Map<String, dynamic>.from(json['merge_tags'] as Map)
            : const {},
        json['limits'],
      ),
      loaded: true,
    );
  }

  /// A list of `{key, label, description, takes_options, merges}`, and nothing
  /// else — see the class for why a map is refused here and accepted for the
  /// core.
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
              // `!= false` rather than `== true`, for the reason on the field:
              // absent means yes here.
              merges: entry['merges'] != false,
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
    this.bodyChange,
    required this.isEmpty,
  });

  final List<ContractField> added;
  final List<DiffEntry> retired;
  final List<DiffEntry> unretired;
  final List<ChangedField> changed;

  /// Order is the order of the form, so a reshuffle is a real change.
  final bool reordered;

  /// The wording of the document itself moved.
  ///
  /// Its own line rather than one of [changed], because it is not a property of
  /// a field: the body is the contract, and "the text of the agreement is
  /// different" is the single most consequential thing a publish can say. The
  /// server decides it — this end holds the old body and the new one and could
  /// compare them, which is exactly the second implementation the rest of this
  /// file refuses to be.
  ///
  /// Derived from [bodyChange] rather than parsed beside it. It WAS parsed
  /// beside it, out of `body_changed` — a key the server has never sent, so the
  /// answer was permanently false and the publish panel never once mentioned
  /// the wording. Two fields read from one wire value is two chances to read it
  /// wrong; there is now one, and this cannot disagree with it.
  bool get bodyChanged => bodyChange != null;

  /// Which kind of change: 'added', 'edited' or 'removed'.
  ///
  /// `removed` matters and is not a smaller `edited` — it does not blank the
  /// contract, it drops the document back to the generated form dump, which is
  /// a different document entirely. Worth naming before somebody publishes.
  final String? bodyChange;

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
    // The server sends `body` as 'added' | 'edited' | 'removed' | null — not a
    // boolean called `body_changed`, which exists nowhere. So the publish panel
    // never said the wording had changed, on the one screen whose whole job is
    // telling somebody what they are about to change.
    bodyChange: json['body'] as String?,
    // Computed by the server, never re-derived from the lists above: a server
    // that grows another kind of change would otherwise be told by this client
    // that nothing happened.
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
