/// Onboarding's wire types (EZBIZI-HR-PLAN.md, "Onboarding").
///
/// ── What this file is allowed to know ────────────────────────────
///
/// An onboarding renders a contract version as a form. The questions are the
/// TENANT's — their keys, their wording, their kinds, which of them are
/// sensitive — and none of that is modelled here beyond being carried. That is
/// core/contracts.dart's rule, and this file inherits it: [ContractField] is
/// reused rather than re-declared, so a question means the same thing on the
/// designer and on the form somebody fills in.
///
/// What IS modelled is the part onboarding adds to a question: whether THIS
/// reader may write it, what has been answered, and who answered it.
///
/// ── Provenance is not decoration ─────────────────────────────────
///
/// `filled_by: either` means two people can write the same field, so an answer
/// carries who set it and when. The owner's case is a date of birth the
/// employer typed wrong; the case that matters more is a bank account, where
/// "the employer typed it" and "I corrected it" are different facts and a form
/// that cannot tell them apart is a payroll-fraud story waiting to happen.
///
/// ── Nothing here decides who may write what ──────────────────────
///
/// [OnboardingQuestion.editable] is the SERVER's answer, carried. It is never
/// worked out from `filled_by` and which side the reader is on, even though
/// that looks like a two-line rule: it is an authorization decision, the server
/// enforces it on PATCH, and a second implementation in Dart is a rule that is
/// right until the day the two disagree — on the screen where disagreeing means
/// a new starter being asked to set their own wage. `filled_by` is used here
/// for one thing only: saying "either of you" on a field where the other side
/// may have got there first.
library;

import 'package:flutter/foundation.dart';

import 'contracts.dart';

/// One onboarding as the employer's list shows it.
///
/// Deliberately not the same class as [Onboarding]. The list row carries counts
/// and no questions; the form carries questions and no counts. One class
/// holding both would be a class that is half-null in both places, and every
/// reader of it would have to know which half they were in.
@immutable
class OnboardingSummary {
  const OnboardingSummary({
    required this.id,
    required this.userId,
    required this.invitationId,
    required this.personName,
    required this.personEmail,
    required this.contractName,
    required this.version,
    required this.status,
    required this.progress,
    required this.startedAt,
    required this.submittedAt,
    required this.completedAt,
    this.cancelledAt,
    this.downloadable = false,
  });

  /// There is a signed PDF to download: its own, or the one an import
  /// brought (Jose, 2026-10-05).
  final bool downloadable;

  final String id;

  /// Who it is about — an account, or an invitation nobody has accepted yet.
  /// Exactly one of the two, matching the nullable pair on the row.
  ///
  /// Carried so the panel that starts a new one can mark somebody who already
  /// has one. Nothing else reads them: who a row is ABOUT is answered on screen
  /// by the name beside it, and an id is not a thing to show anybody.
  final String? userId;
  final String? invitationId;

  /// Who is joining. The name where there is one, and otherwise nothing — the
  /// address below carries the row on its own, and a placeholder like "Unnamed"
  /// would read as a person whose name is missing rather than as an invitation
  /// nobody has accepted yet.
  final String personName;
  final String personEmail;

  /// The contract, and the version PINNED when this started. Publishing a new
  /// version afterwards does not move a form somebody is filling in, so the
  /// number here can lag the template's current one and that is correct.
  final String contractName;
  final int? version;

  final String status;

  /// How far along, per side, as the server counted it. Keyed by the same three
  /// `filled_by` values the catalogue publishes.
  final Map<String, SideProgress> progress;

  /// Everything answered so far, both sides together — "how far along is this".
  ///
  /// Summed here rather than asked for as a fourth number, because it is
  /// arithmetic over figures the server already sent and a total that could
  /// disagree with its own parts is worse than no total.
  int get answered =>
      progress.values.fold(0, (running, side) => running + side.answered);

  final String? startedAt;
  final String? submittedAt;
  final String? completedAt;
  final String? cancelledAt;

  factory OnboardingSummary.fromJson(Map<String, dynamic> json) {
    final person = json['person'] as Map<String, dynamic>?;
    final contract = json['contract'] as Map<String, dynamic>?;

    return OnboardingSummary(
      id: json['id'].toString(),
      userId:
          person?['user_id'] as String? ??
          json['user_id'] as String? ??
          person?['id'] as String?,
      invitationId:
          json['invitation_id'] as String? ??
          (json['invitation'] as Map<String, dynamic>?)?['id'] as String?,
      personName: person?['name'] as String? ?? '',
      // The invitation's address when there is no account yet — an onboarding
      // can be started against an invitation nobody has accepted, which is the
      // whole reason `onboardings.user_id` is nullable.
      personEmail:
          person?['email'] as String? ??
          (json['invitation'] as Map<String, dynamic>?)?['email'] as String? ??
          '',
      contractName: contract?['name'] as String? ?? '',
      version: (contract?['version'] as num?)?.toInt(),
      status: json['status'] as String? ?? '',
      progress: SideProgress.mapFrom(json['progress']),
      startedAt: json['started_at'] as String?,
      submittedAt: json['submitted_at'] as String?,
      completedAt: json['completed_at'] as String?,
      cancelledAt: json['cancelled_at'] as String?,
      downloadable: json['downloadable'] == true,
    );
  }

  static List<OnboardingSummary> listFrom(Object? data) => data is List
      ? [
          for (final row in data.whereType<Map>())
            OnboardingSummary.fromJson(Map<String, dynamic>.from(row)),
        ]
      : const [];
}

/// How much of one side's half is done.
@immutable
class SideProgress {
  const SideProgress({required this.answered, required this.outstanding});

  final int answered;

  /// Questions this side must still answer. Required ones only — the server
  /// decides which, because "required" is a property of the tenant's field
  /// definition and counting it here would mean holding the field list.
  final int outstanding;

  static Map<String, SideProgress> mapFrom(Object? node) {
    if (node is! Map) return const {};

    return {
      for (final entry in node.entries)
        if (entry.value is Map)
          '${entry.key}': SideProgress(
            answered: ((entry.value as Map)['answered'] as num?)?.toInt() ?? 0,
            outstanding:
                ((entry.value as Map)['outstanding'] as num?)?.toInt() ?? 0,
          ),
    };
  }
}

/// The form and its answers — `GET /hr/onboardings/{onboarding}`.
@immutable
class Onboarding {
  const Onboarding({
    required this.id,
    required this.status,
    required this.personName,
    required this.personEmail,
    required this.contractName,
    required this.version,
    required this.questions,
    required this.vocabulary,
    required this.missing,
    required this.signatures,
    required this.consentStatement,
    required this.intentStatement,
    required this.startedAt,
    required this.submittedAt,
    required this.completedAt,
    this.cancelledAt,
    this.employerAddress,
    this.employerAddressDefault,
    this.employerAddressEditable = false,
    this.approvalBy,
    this.approvalMine = false,
    this.downloadable = false,
    this.i9Section2Done = false,
    this.i9Section2Mine = false,
    this.parties = const [],
    this.startedBy,
  });

  /// Which sides the reader acts as on this form: `employee` when it is about
  /// them, `employer` when they may act for the employer, both, or neither
  /// (somebody reading a record). Only for choosing which tab opens and which
  /// one is the other side's; who may write a field is still
  /// [OnboardingQuestion.writable].
  final List<String> parties;

  /// Who started it, which is who the employer's half waits on.
  final String? startedBy;

  /// There is a signed PDF to download, its own or an imported one.
  final bool downloadable;

  /// The I-9's employer half (Section 2) is signed; and whether this person
  /// may sign it.
  final bool i9Section2Done;
  final bool i9Section2Mine;

  /// Signed by both sides and waiting for the sender's final approval (Jose,
  /// 2026-10-05): [approvalBy] is who sent it, [approvalMine] whether this
  /// person may give it.
  bool get awaitingApproval => status == 'awaiting_approval';
  final String? approvalBy;
  final bool approvalMine;

  final String id;
  final String status;

  /// The company address typed on this form, which the contract prints in
  /// place of [employerAddressDefault] (Jose, 2026-10-05). Only the employer
  /// may type it, and only before signing: [employerAddressEditable].
  final String? employerAddress;
  final String? employerAddressDefault;
  final bool employerAddressEditable;

  final String personName;
  final String personEmail;

  final String contractName;
  final int? version;

  /// Every question on the pinned version, in form order, whichever side owns
  /// it. Both sides receive the whole list: the employee has to be able to see
  /// what the employer still owes, and a form that showed only your own half
  /// would make a wage nobody has set look like a wage that was never asked for.
  final List<OnboardingQuestion> questions;

  /// The vocabulary these questions are written in, travelling with them.
  ///
  /// ── Why it is not simply fetched ─────────────────────────────────
  ///
  /// `GET /hr/contracts/catalogue` is the Contracts tab's endpoint and it is
  /// gated on being allowed to read contracts. The person filling in the
  /// employee's half of this form has no such permission and never will — they
  /// are a new technician, not somebody who designs templates — so a form that
  /// fetched the catalogue would render for the employer and 403 for the one
  /// person it exists for.
  ///
  /// The same shape as the catalogue's own payload, so [ContractVocabulary]
  /// parses it unchanged and there is one parser rather than two. Empty when
  /// the server sends none, which the form treats as "cannot tell a known kind
  /// of question from an unknown one" rather than as "this kind is unknown" —
  /// a distinction that decides whether a perfectly ordinary text box is
  /// captioned with a warning.
  final ContractVocabulary vocabulary;

  /// What is still unanswered, key to the label somebody reads — the server's
  /// own list, from its own check.
  ///
  /// Shown rather than acted on. This end does not decide that an unanswered
  /// question blocks signing: that is the server's rule and its refusal says so
  /// in its own words. What the screen owes somebody looking at a Sign button is
  /// the list of things they have not done, which is exactly this.
  final Map<String, String> missing;

  /// Every signature the document is waiting for, or has, merged from the two
  /// lists the server keeps apart: `signature_fields` (what the pinned version
  /// asks for) and `signatures` (what has actually been taken).
  ///
  /// Merged here, once, rather than at each place that draws one — the two are
  /// keyed by the same field key and joining them in the widget would be the
  /// join written three times.
  final List<OnboardingSignature> signatures;

  /// ESIGN: consent to transact electronically has to be GIVEN. It is a
  /// separate agreement from the contract, which is why the signature record
  /// timestamps it separately — and why this is a thing somebody does rather
  /// than a line of small print under a button.
  ///
  /// The server's words, verbatim, both of them. `ContractSignature` stores
  /// them as the evidence of what somebody was shown, so a sentence composed in
  /// Dart would be a sentence the record does not contain — which is worse than
  /// no sentence: it is evidence of something that did not happen. When they do
  /// not arrive, the screen says the step cannot be shown rather than supplying
  /// its own.
  final String consentStatement;
  final String intentStatement;

  final String? startedAt;
  final String? submittedAt;
  final String? completedAt;
  final String? cancelledAt;

  /// Whether anybody may still type into this. Read off the questions rather
  /// than off [status], so a server that adds a status this build has never
  /// heard of still draws a correct form: what decides whether a box is enabled
  /// is whether the server said that box is writable.
  bool get anyWritable => questions.any((q) => q.writable);

  /// When the form was last written to, by either side.
  ///
  /// The latest `set_at` on the page, not a column of its own. It is the answer
  /// to "where did I get to" — which is a question about the answers, so it is
  /// read off them rather than added to the onboarding row and kept in step by
  /// hand. Null when nothing has been answered yet, which is a different
  /// sentence on screen and not a missing one.
  ///
  /// The common core has no `set_at`: it lives on the person's profile and the
  /// profile's own history is where "who changed my name" is answered. So this
  /// is the last time a CONTRACT question was answered, which is what somebody
  /// resuming a form means by "where did I get to".
  String? get lastAnsweredAt {
    DateTime? latest;
    String? iso;

    for (final question in questions) {
      final at = DateTime.tryParse(question.setAt ?? '');

      if (at != null && (latest == null || at.isAfter(latest))) {
        latest = at;
        iso = question.setAt;
      }
    }

    return iso;
  }

  factory Onboarding.fromJson(Map<String, dynamic> json) {
    final person = json['person'] as Map<String, dynamic>?;
    final contract = json['contract'] as Map<String, dynamic>?;
    final ceremony = json['ceremony'] as Map<String, dynamic>?;
    final employer = json['employer_address'] as Map<String, dynamic>?;
    final approval = json['approval'] as Map<String, dynamic>?;

    // What has actually been signed, keyed so the fields below can find their
    // own. `signature_fields` says what the version asks for; `signatures` says
    // what was taken, and only the second carries the evidence.
    final taken = {
      for (final row in (json['signatures'] as List<dynamic>? ?? const [])
          .whereType<Map>())
        '${row['field_key']}': Map<String, dynamic>.from(row),
    };

    return Onboarding(
      id: json['id'].toString(),
      status: json['status'] as String? ?? '',
      personName: person?['name'] as String? ?? '',
      personEmail: person?['email'] as String? ?? '',
      contractName: contract?['name'] as String? ?? '',
      version: (contract?['version'] as num?)?.toInt(),
      questions: [
        for (final row in (json['fields'] as List<dynamic>? ?? const [])
            .whereType<Map>())
          OnboardingQuestion.fromJson(Map<String, dynamic>.from(row)),
      ],
      vocabulary: json['vocabulary'] is Map
          ? ContractVocabulary.fromJson(
              Map<String, dynamic>.from(json['vocabulary'] as Map),
            )
          : ContractVocabulary.none,
      missing: {
        if (json['missing'] is Map)
          for (final entry in (json['missing'] as Map).entries)
            '${entry.key}': '${entry.value}',
      },
      signatures: [
        for (final row in (json['signature_fields'] as List<dynamic>? ?? const [])
            .whereType<Map>())
          OnboardingSignature.fromJson(
            Map<String, dynamic>.from(row),
            taken['${row['key']}'],
          ),
      ],
      consentStatement: ceremony?['consent_statement'] as String? ?? '',
      intentStatement: ceremony?['intent_statement'] as String? ?? '',
      startedAt: json['started_at'] as String?,
      submittedAt: json['submitted_at'] as String?,
      completedAt: json['completed_at'] as String?,
      cancelledAt: json['cancelled_at'] as String?,
      employerAddress: employer?['typed'] as String?,
      employerAddressDefault: employer?['default'] as String?,
      employerAddressEditable: employer?['editable'] == true,
      approvalBy: approval?['by'] as String?,
      approvalMine: approval?['mine'] == true,
      downloadable: json['downloadable'] == true,
      i9Section2Done: (json['i9'] as Map?)?['section2'] == true,
      i9Section2Mine: (json['i9'] as Map?)?['mine'] == true,
      parties: [
        for (final party in json['parties'] as List? ?? const []) '$party',
      ],
      startedBy: json['started_by'] as String?,
    );
  }
}

/// One question, its answer, its provenance, and whether this reader may
/// change it.
///
/// Flat, because that is how the server sends it: the answer's value, who set
/// it and when sit beside the field's own properties on one row rather than
/// nested under an `answer` object. There is no separate "unanswered" object to
/// be null — [answered] is whether there is anything in [value] — and the
/// common core arrives in the same list, so a form is one loop.
@immutable
class OnboardingQuestion {
  const OnboardingQuestion({
    required this.field,
    required this.source,
    required this.writable,
    required this.lockedBecause,
    required this.value,
    required this.masked,
    required this.setByName,
    required this.setByParty,
    required this.setAt,
    this.voidable = false,
    this.voidGroup,
    this.parts,
    this.taxFormKind,
    this.signsTaxFormHere = false,
  });

  /// The tenant's field definition, in exactly the shape the Contracts tab
  /// published it. Reused rather than re-declared so a question means the same
  /// thing on the designer and on the form.
  final ContractField field;

  /// `core` or `contract`.
  ///
  /// A core question is the person's own identity — their name, their phone —
  /// which the plan's one-home rule keeps on the profile rather than copying
  /// into every record. Writing it here writes it there. The distinction is
  /// worth carrying because the sentence that belongs under one is not the
  /// sentence that belongs under the other.
  final String source;

  /// The server's answer to "may this person write this field, now". Carried,
  /// never computed — see the file docblock.
  ///
  /// It is not the same question as `filled_by`, and it is not `filled_by` plus
  /// a guess at which side is reading: a field the employee owns stops being
  /// writable by them the moment the form is submitted, a retired one is never
  /// writable by anybody, and a signature is signed rather than typed. One flag
  /// answers all of it, because the only thing a form needs to know is whether
  /// to enable the box.
  final bool writable;

  /// Why not, where the server said. Used for the core questions an employer
  /// may not touch — the plan's rule that core identity is the person's own —
  /// because a greyed box with no explanation reads as a fault.
  final String? lockedBecause;

  /// As the server sent it, which for a sensitive answer is the MASK.
  ///
  /// The mask string is never spelled in Dart. `SensitiveValues::MASK` is the
  /// server's constant, it is used for webhook secrets too, and a second copy
  /// here would be a second thing to change on the day it does. What this end
  /// knows is [masked]; what it prints is whatever arrived.
  final String value;

  /// The value on screen is a stand-in, because the field is sensitive.
  ///
  /// Taken from an explicit `masked` where the server sends one, and otherwise
  /// from the two facts that imply it: the field is sensitive AND there is
  /// something stored. That is not a guess — the server reads these values
  /// through the masking model on every ordinary load, deliberately, so that an
  /// unrevealed value is never in the payload at all. Revealing is a separate
  /// call that writes down who asked.
  ///
  /// The consequence for a control is not cosmetic: a masked value must NEVER
  /// be echoed back on save. The automations screen saved `••••••••` over a
  /// working webhook secret exactly that way, and this is a bank account. So a
  /// masked answer starts its box EMPTY and the row says the stored answer is
  /// kept unless something new is typed — which leaves no state in which the
  /// mask could be mistaken for what somebody meant to send.
  final bool masked;

  /// Who last wrote it, and which side they were acting as. The whole reason
  /// `either` is usable at all: "the employer typed it" and "I corrected it"
  /// have to be different facts, and for a bank account that difference is the
  /// point.
  ///
  /// Both null on a core question — its home is the profile, and the profile's
  /// own history is where "who changed my name" is answered.
  final String? setByName;
  final String? setByParty;

  final String? setAt;

  /// May be answered "N/A", which leaves its clause out of the contract (A1
  /// Tools' "doesn't apply"; HR 0c0884af). The server's call, carried.
  final bool voidable;

  /// Answered "doesn't apply".
  bool get voided => voidable && value.trim() == notApplicable;

  /// Questions sharing a group void and come back together (HR: the raise's
  /// amount and interval; the commission's three; the salary's three). Null
  /// is a group of one.
  final String? voidGroup;

  String get group => voidGroup ?? key;

  /// The home address in its parts (`address_line1`, `address_line2`,
  /// `city`, `state`, `postal_code`, `country`), when the server sends them.
  /// Null for every other question.
  final Map<String, String>? parts;

  /// On the `tax_form` question: `w4` or `w9`, by the server's rule (SSN, or
  /// EIN and the 1099 contracts). Null until it can tell.
  final String? taxFormKind;

  /// The server signs the tax form in the app (it sends `tax_form_kind`,
  /// null included). A server from before still takes an upload.
  final bool signsTaxFormHere;

  String get key => field.key;

  bool get answered => value.trim().isNotEmpty;

  factory OnboardingQuestion.fromJson(Map<String, dynamic> json) {
    final setBy = json['set_by'] as Map<String, dynamic>?;
    final value = json['value']?.toString() ?? '';

    return OnboardingQuestion(
      field: ContractField.fromJson(json),
      source: json['source'] as String? ?? '',
      // Absent counts as NOT writable. The opposite default would draw a live
      // control against a server that never said so, and the refusal would
      // arrive after somebody had typed their bank details into it.
      writable: json['writable'] == true,
      lockedBecause: json['locked_because'] as String?,
      value: value,
      masked: json['masked'] as bool? ??
          (json['sensitive'] == true && value.trim().isNotEmpty),
      setByName: setBy?['name'] as String?,
      setByParty: setBy?['party'] as String?,
      setAt: json['set_at'] as String?,
      voidable: json['voidable'] == true,
      voidGroup: json['void_group'] as String?,
      parts: switch (json['parts']) {
        final Map<dynamic, dynamic> parts => {
          for (final part in parts.entries) '${part.key}': '${part.value ?? ''}',
        },
        _ => null,
      },
      taxFormKind: json['tax_form_kind'] as String?,
      signsTaxFormHere: json.containsKey('tax_form_kind'),
    );
  }
}

/// The answer that voids a term: the server drops every paragraph naming it.
const notApplicable = 'N/A';

/// An employer's term not settled yet (`MergeValue::TO_BE_DETERMINED`).
const toBeDetermined = 'To be determined';

/// Compensation methods offered as quick picks; the box still takes any words.
const compensationMethods = [
  'Hourly',
  'Hourly plus commission',
  'Commission only',
  'Salary',
  'Salary plus commission',
];

/// Sent among the answers, but not a question: the company address typed on
/// the form (`Onboarding::EMPLOYER_ADDRESS`).
const employerAddressKey = 'employer_address';

/// The rendered document, and the fingerprint of exactly these bytes.
///
/// `GET /hr/onboardings/{onboarding}/document`, which is its own request rather
/// than part of the form: before anything is signed it is a PREVIEW rendered on
/// demand, and afterwards it is the stored artefact. Both come back the same
/// shape, and [isPreview] is what tells them apart.
///
/// [contentHash] is not decoration. Signing sends it back, and the server
/// refuses without it — "send the fingerprint of the document you were shown.
/// It is what makes the signature evidence of what you actually read rather
/// than of what the server assumed you read." So this end never computes a hash
/// and never signs against one it did not receive with the content.
@immutable
class OnboardingDocument {
  const OnboardingDocument({
    required this.content,
    required this.contentHash,
    required this.mime,
    required this.isPreview,
  });

  final String content;
  final String contentHash;

  /// What the bytes ARE. Carried because the renderer's output is not
  /// necessarily something this build can lay out, and a screen that showed
  /// markup as though it were prose would be lying about what somebody read.
  final String mime;

  /// Rendered on demand because nothing has been signed yet. It can still
  /// change — answering another question changes the document — which is worth
  /// saying to somebody reading it.
  final bool isPreview;

  bool get isUsable => content.isNotEmpty && contentHash.isNotEmpty;

  factory OnboardingDocument.fromJson(Map<String, dynamic> json) =>
      OnboardingDocument(
        content: json['content'] as String? ?? '',
        contentHash: json['content_hash'] as String? ?? '',
        mime: json['mime'] as String? ?? '',
        isPreview: json['is_preview'] == true,
      );
}

/// One signature the document is waiting for, or has.
///
/// Built from `signature_fields` — what the pinned version asks for — with the
/// matching row of `signatures` where one has been taken. The two are separate
/// on the wire for a good reason: a field that asks for a signature and a
/// signature that was given are different facts, and only the second is
/// evidence.
@immutable
class OnboardingSignature {
  const OnboardingSignature({
    required this.fieldKey,
    required this.label,
    required this.party,
    required this.mine,
    required this.signed,
    required this.signedAt,
    required this.signerLabel,
    required this.affirmation,
  });

  /// Which signature field on the contract this act fills.
  /// `contract_signatures.field_key` records it.
  final String fieldKey;

  /// The tenant's wording for it — "Signature of the employee".
  final String label;

  /// Which side signs it, from the field's own `filled_by`. Never from who
  /// happens to be looking: an owner onboarding themselves is both sides at
  /// once, and the party a signature is attributed to still has to be the one
  /// the contract says. The server refuses a signature by the other side for
  /// exactly that reason.
  final String party;

  /// Whether this is one THIS person may perform. The server's answer; the
  /// others are shown so the page says what the document is still waiting for,
  /// and offer no button.
  final bool mine;

  final bool signed;
  final String? signedAt;

  /// Who signed, captured as text at the moment of signing so it still answers
  /// the question after the account is gone.
  final String? signerLabel;

  /// What they typed. Their own name, in their own keystrokes — the affirmative
  /// act ESIGN requires, and the thing a dispute turns on.
  final String? affirmation;

  factory OnboardingSignature.fromJson(
    Map<String, dynamic> field,
    Map<String, dynamic>? taken,
  ) => OnboardingSignature(
    fieldKey: field['key'] as String? ?? '',
    label: field['label'] as String? ?? '',
    party: field['filled_by'] as String? ?? '',
    mine: field['mine'] == true,
    // From either side of the join. `signed` is the field's own summary and the
    // presence of a taken row is the evidence; they cannot disagree, and
    // accepting both means neither list alone has to be complete.
    signed: field['signed'] == true || taken != null,
    signedAt: taken?['signed_at'] as String?,
    signerLabel: taken?['signer'] as String?,
    affirmation: taken?['affirmation'] as String?,
  );
}


/// A status as a person reads it.
///
/// Compiled in under §2.1's carve-out for API contracts, the same as
/// [invitationStatusLabel] in core/hr.dart: the states of an onboarding are the
/// plan's — started, submitted, finished — and an organization does not get to
/// invent a fourth. Anything this build has not heard of is shown exactly as
/// the server sent it rather than as "unknown", which would hide the one fact
/// worth knowing.
String onboardingStatusLabel(String status) => switch (status) {
  'in_progress' => 'In progress',
  'submitted' => 'Submitted',
  'awaiting_approval' => 'To approve',
  'completed' => 'Completed',
  'cancelled' => 'Cancelled',
  _ => status,
};
