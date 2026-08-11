/// The Human Resources module's wire types (HR plan; plan §4).
///
/// Deliberately small. An invitation row and a person stay plain maps, the same
/// as customers and automations, because nothing in either needs to be reasoned
/// about beyond being shown. What is here is the part that does: the answer to
/// "what may this person hand out", which arrives as three separate lists and
/// has to become one list of choices, and a status that has to be said in words.
library;

import 'package:flutter/foundation.dart';

/// Everything `GET /hr/people/scope-options` offers — both halves of it.
///
/// ── Why one endpoint answers two questions ───────────────────────
///
/// An invitation carries a role AND a place, and both are bounded by the person
/// sending it: §4 says nobody grants a permission they do not hold, and the
/// owner's rule for places is *"he should only be able to see same level or
/// downwards when his options to assign an employee are presented."* A form that
/// offered a valid role beside an invalid place would fail on save for a reason
/// nobody could see, so the server answers both at once and this holds both.
///
/// ── Why none of it is worked out here ────────────────────────────
///
/// `/me` carries `companies` and `franchises` and it would be easy to build a
/// picker from those. It would also be the wrong list: those are what this
/// person can SEE, and this is what they may ASSIGN. Reimplementing the second
/// from the first would be a copy of an access-control rule living in Dart,
/// kept in step by hope, in the one place where drifting apart hands somebody
/// an employee in a franchise they were never meant to touch.
///
/// What IS done here is formatting: the server sends three flat lists with
/// parent ids on them, and a dropdown needs one list of rows that say which
/// Connecticut they are. That is presentation, and the set being presented is
/// still entirely the server's.
@immutable
class AssignmentOptions {
  const AssignmentOptions({required this.scopes, required this.roles});

  static const AssignmentOptions none = AssignmentOptions(
    scopes: [],
    roles: [],
  );

  /// Every place, flattened and each carrying its ancestry.
  final List<ScopeOption> scopes;

  /// Every role in the organization — including the ones this person may not
  /// hand out, which arrive marked rather than missing. Same reasoning as the
  /// role editor's locked actions: a role that is simply absent leaves somebody
  /// unable to tell "we do not have that role" from "I may not give it".
  final List<AssignableRole> roles;

  factory AssignmentOptions.fromJson(Map<String, dynamic> json) {
    final companies = (json['companies'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    /// A company's ancestry, ending in itself.
    ///
    /// §3 fixes the depth at Organization → Company → Sub Company → Franchise,
    /// so this terminates in at most two steps; the `seen` guard is for data
    /// that disagrees with the plan, not for a depth the plan allows. Same
    /// shape, and the same reason, as widgets/franchise_field.dart.
    List<String> ancestryOf(Map<String, dynamic>? company) {
      final parts = <String>[];
      final seen = <String>{};

      var current = company;

      while (current != null && seen.add(current['id'].toString())) {
        parts.insert(0, current['name'] as String? ?? '');

        final parent = current['parent_company_id'] as String?;

        current = parent == null
            ? null
            : companies
                  .where((c) => c['id'].toString() == parent)
                  .firstOrNull;
      }

      return parts;
    }

    final scopes = <ScopeOption>[];

    // The organization leads, because it is the widest thing on offer and the
    // parent of everything under it. It is present only for somebody standing
    // at organization level — the owner's rule at its top tier.
    if (json['organization'] case final Map<String, dynamic> organization) {
      scopes.add(
        ScopeOption(
          type: 'organization',
          id: organization['id'].toString(),
          label: organization['name'] as String? ?? '',
          path: [organization['name'] as String? ?? ''],
          isActive: organization['is_active'] != false,
        ),
      );
    }

    final rest = <ScopeOption>[
      for (final company in companies)
        ScopeOption(
          type: 'company',
          id: company['id'].toString(),
          label: company['name'] as String? ?? '',
          path: ancestryOf(company),
          isActive: company['is_active'] != false,
        ),
      for (final franchise in (json['franchises'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>())
        ScopeOption(
          type: 'franchise',
          id: franchise['id'].toString(),
          label: franchise['name'] as String? ?? '',
          path: [
            ...ancestryOf(
              companies
                  .where(
                    (c) => c['id'].toString() == franchise['company_id'],
                  )
                  .firstOrNull,
            ),
            franchise['name'] as String? ?? '',
          ],
          isActive: franchise['is_active'] != false,
        ),
    ];

    // Sorted by the whole path, so a company is followed by the franchises
    // under it rather than by an alphabetical run in which the four
    // Connecticuts sit together and nothing tells them apart. Unsorted, the
    // list arrives as two concatenated queries and the repetition reads as a
    // glitch — the argument widgets/franchise_field.dart already paid for.
    rest.sort((a, b) => a.pathLabel.compareTo(b.pathLabel));

    return AssignmentOptions(
      scopes: [...scopes, ...rest],
      roles: (json['roles'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(AssignableRole.fromJson)
          .toList(growable: false),
    );
  }
}

/// One place somebody may be assigned.
@immutable
class ScopeOption {
  const ScopeOption({
    required this.type,
    required this.id,
    required this.label,
    required this.path,
    required this.isActive,
  });

  /// organization · company · franchise — the three levels one assignment row
  /// can target (§4's XOR constraint: exactly one, never two).
  final String type;

  final String id;

  /// The scoped thing's own name — "Connecticut".
  final String label;

  /// Its ancestry, ending in itself: ["Everdoors", "Serenisima", "Connecticut"].
  final List<String> path;

  /// §4: an assignment to a deactivated scope grants nothing. Offered as a
  /// choice, it is a mistake with no symptom — so the row says so and refuses.
  final bool isActive;

  /// What a dropdown holds, and what splits back into the two fields
  /// `POST /hr/invitations` takes. The same `type:id` spelling the scope
  /// switcher already uses, so there is one convention rather than two.
  String get value => '$type:$id';

  /// The whole ancestry, or the bare name when there is no ancestry to show.
  String get pathLabel =>
      path.where((p) => p.isNotEmpty).isEmpty ? label : path.join(' › ');
}

/// One of the organization's roles, and whether this person may hand it out.
@immutable
class AssignableRole {
  const AssignableRole({
    required this.id,
    required this.name,
    required this.assignable,
  });

  final String id;
  final String name;

  /// §4's escalation bound: nobody grants what they do not hold themselves. The
  /// server decides; the client draws the answer and never works it out from
  /// the role's NAME, which is the bug class the roles editor documents at
  /// length — "the role called Administrator" and "the built-in administrator
  /// role" are not the same thing.
  final bool assignable;

  factory AssignableRole.fromJson(Map<String, dynamic> json) => AssignableRole(
    id: json['id'].toString(),
    name: json['name'] as String? ?? '',
    // Absent counts as assignable, matching PermissionAction.grantable: a
    // server that does not qualify an entry is not being restrictive about it,
    // and refusing everything on silence renders a dead form.
    assignable: json['assignable'] != false,
  );
}

/// A status as a person reads it.
///
/// The six are the owner's own, settled: sent · received · in progress ·
/// completed · abandoned · revoked. They are compiled in under §2.1's carve-out
/// for API contracts — an organization does not get to invent a seventh — and
/// anything this build has not heard of is shown exactly as the server sent it
/// rather than as "unknown", which would hide the one fact worth knowing.
///
/// `revoked` was the fifth-and-a-half for a while: the server reported a
/// withdrawn invitation as `abandoned` and carried `revoked_at` beside it, and
/// this list had no word for it. It is a word now (decided 2026-08-11), and the
/// two must not be shown as the same thing — see [invitationStatusNeedsAttention].
String invitationStatusLabel(String status) => switch (status) {
  'sent' => 'Sent',
  'received' => 'Received',
  'in_progress' => 'In progress',
  'completed' => 'Completed',
  'abandoned' => 'Abandoned',
  'revoked' => 'Revoked',
  _ => status,
};

/// Whether a status is something the reader needs to notice rather than merely
/// know. An abandoned invitation is somebody who was asked to join and did not,
/// which is the one row on the list that wants doing something about.
///
/// Revoked deliberately does NOT count. Somebody here decided to withdraw it, so
/// there is nothing to chase and nothing went wrong — marking it would train the
/// reader to ignore the marker, which costs them the rows that do need chasing.
bool invitationStatusNeedsAttention(String status) => status == 'abandoned';
