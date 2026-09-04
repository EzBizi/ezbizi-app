import 'package:flutter/foundation.dart';

/// The organization's own shared inboxes, as the roles editor draws them.
///
/// ── Why this is beside permissions.dart and not inside it ────────
///
/// A permission is the same everywhere. `customers.delete` means the same thing
/// for every tenant on the platform, it is declared in code, and the client can
/// hold a catalogue of them without holding anybody's data. An inbox is the
/// opposite: `info@a-1chimney.com` belongs to one organization, exists because
/// somebody connected it, and is nobody else's business.
///
/// The two are drawn as one tree — the owner asked for the addresses nested
/// under Email — and they are COMPOSED for that, not merged. The server keeps
/// them apart (a platform permission catalogue, and tenant records) and so does
/// this file, so that "what can the software do" and "what mail does this
/// customer have" never end up in the same list by accident.
///
/// Nothing here knows there is a module called Email. Which module the inboxes
/// hang under arrives with them, as the key they are attached to.
@immutable
class InboxOption {
  const InboxOption({
    required this.id,
    required this.address,
    required this.grantable,
    this.label,
  });

  /// The mailbox record's id — what a grant is stored against and what the save
  /// sends back. Never shown to anybody.
  final String id;

  /// `info@a-1chimney.com`. Always shown, whatever else is.
  final String address;

  /// What the organization named it, when it named it anything.
  final String? label;

  /// Whether the person editing can open this inbox themselves (§4: nobody
  /// grants what they do not hold). Per inbox, not per module — somebody who
  /// only answers info@ may hand out info@ and nothing else.
  ///
  /// Absent counts as grantable, matching PermissionAction: this endpoint is the
  /// GRANTABLE tree, so an entry the server did not qualify is one it is not
  /// restricting.
  final bool grantable;

  /// The line somebody reads. The address when there is no name, because an
  /// inbox with neither would be a blank row.
  String get heading =>
      (label == null || label!.trim().isEmpty) ? address : label!.trim();

  /// Whether [heading] and [address] are two different facts worth showing on
  /// two lines. "Support" is not enough on its own — it could be any of four
  /// addresses, and which mail a role can read is the question this screen
  /// exists to answer.
  bool get named => heading != address;

  factory InboxOption.fromJson(Map<String, dynamic> json) => InboxOption(
    id: json['id'].toString(),
    address:
        json['address'] as String? ??
        json['e164'] as String? ??
        json['name'] as String? ??
        '',
    label: json['label'] as String? ?? json['name'] as String?,
    grantable: json['grantable'] != false,
  );
}

/// The JSON keys a module may nest this organization's own records under.
///
/// One list, read by the catalogue and by the holdings reader below, so adding
/// a fourth kind is a line here rather than a new branch in each of them. It
/// was two `else if`s before phone lines, and three is where that shape starts
/// costing more than it explains.
///
/// This is the client end of an agreement the server composes from the other
/// side (`InboxAccess`, `InspectionAccess`, `PhoneNumberAccess` each hang their
/// key onto the module tree). Adding a kind means adding it in both places —
/// there is no discovery here, and there should not be: a client that grants
/// whatever key it is handed would grant one added by mistake.
// 'phone_numbers' was here until 3 Sep. A role with Communication now gets its
// company's lines by reach, not one tick at a time, so the server stopped
// composing the key (PhoneNumberAccess::compose) and this stopped asking for
// it. Both ends, as the note above requires — a client that kept asking would
// keep drawing an empty section.
const List<String> grantKinds = <String>[
  'inboxes',
  'inspections',
];

/// Which modules take inboxes, and which inboxes this organization has.
@immutable
class InboxCatalogue {
  const InboxCatalogue(this._byModule, [this._kind = const {}]);

  static const InboxCatalogue none = InboxCatalogue(
    <String, List<InboxOption>>{},
  );

  final Map<String, List<InboxOption>> _byModule;
  final Map<String, String> _kind;

  /// `inboxes` or `inspections` — which JSON key this module nested.
  String? kindFor(String moduleKey) => _kind[moduleKey];

  bool hasKind(String kind) => _kind.values.contains(kind);

  Iterable<String> moduleKeysOf(String kind) =>
      _kind.entries.where((e) => e.value == kind).map((e) => e.key);

  /// Does this module take inboxes at all?
  ///
  /// The distinction that matters, and the reason this is not just "is the list
  /// empty": a module that takes inboxes and has none gets a sentence saying
  /// they will appear here once mail is connected. A module that has no inbox
  /// concept gets nothing. Collapsing the two draws an empty nested list under
  /// Customers, which reads as a screen that failed to load.
  bool nests(String moduleKey) => _byModule.containsKey(moduleKey);

  List<InboxOption> under(String moduleKey) =>
      _byModule[moduleKey] ?? const <InboxOption>[];

  /// True when no module in the tree takes inboxes at all.
  ///
  /// The editor asks before putting `inboxes` in a save. An organization whose
  /// catalogue has no inbox-bearing module never drew an inbox control, so
  /// sending an empty list would be this client asserting "this role should hold
  /// no inboxes" about something it never showed anybody — which is how an
  /// editor silently revokes access it could not see.
  bool get isEmpty => _byModule.isEmpty;

  /// May this inbox go in a save? Unknown means no, for the same reason
  /// PermissionCatalogue.mayGrant says no: the server refuses the whole request
  /// rather than ignoring one entry, and it preserves what it refuses.
  bool mayGrant(String id) {
    for (final inboxes in _byModule.values) {
      for (final inbox in inboxes) {
        if (inbox.id == id) return inbox.grantable;
      }
    }

    return false;
  }

  /// Read from the same `/roles/catalogue` body PermissionCatalogue reads.
  ///
  /// The unwrap is duplicated from that class rather than shared, and the reason
  /// is ownership rather than taste: these are two independent readings of one
  /// answer, and neither should be able to change the other's shape. It is three
  /// lines, and both are pinned by tests that feed them the endpoint's real
  /// shape.
  factory InboxCatalogue.fromResponse(dynamic payload) {
    final node = payload is Map<String, dynamic> && payload['modules'] == null
        ? payload['data']
        : payload;

    final list = node is Map<String, dynamic> ? node['modules'] : node;

    if (list is! List) return none;

    final byModule = <String, List<InboxOption>>{};
    final kind = <String, String>{};

    for (final module in list.whereType<Map<String, dynamic>>()) {
      final key = module['key'].toString();

      // First match wins, as the else-if chain did: a module nests one kind.
      for (final nested in grantKinds) {
        if (module[nested] is! List) continue;

        kind[key] = nested;
        byModule[key] = (module[nested] as List)
            .whereType<Map<String, dynamic>>()
            .map(InboxOption.fromJson)
            .toList(growable: false);
        break;
      }
    }

    return InboxCatalogue(byModule, kind);
  }
}

/// Which inboxes each role holds, from the `/roles` list.
///
/// Kept out of RoleSummary deliberately. That class describes what a role IS in
/// terms every tenant shares; this is a set of ids that mean something only
/// inside one organization, and only while those inboxes are connected.
Map<String, Set<String>> inboxHoldingsFrom(dynamic data) {
  final holdings = <String, Set<String>>{};

  if (data is! List) return holdings;

  for (final role in data.whereType<Map<String, dynamic>>()) {
    final nested = <String>{};

    // Every kind, not the first: one role holds inboxes AND inspections AND
    // phone lines at once. This is the one place that differs from the
    // catalogue's first-match-wins above, and getting it wrong would silently
    // drop a role's grants for whichever kind sorted second.
    for (final kind in grantKinds) {
      if (role[kind] is List) {
        nested.addAll((role[kind] as List).map((id) => id.toString()));
      }
    }

    holdings[role['id'].toString()] = nested;
  }

  return holdings;
}
