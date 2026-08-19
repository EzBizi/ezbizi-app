import 'package:flutter/foundation.dart';

/// What a role may hold, and what this person may reach (plan §4).
///
/// Everything here is DATA the server sends. Nothing in this file knows that
/// there is a module called Customers, that it has four actions, or that one of
/// them is called Create — the catalogue arrives from `/roles/catalogue` and the
/// person's own reach arrives on `/me`. That is the whole point: a module added
/// to the server's registry appears in the editor and in the sidebar without a
/// line changing here.
///
/// ── The one action name that is not data ─────────────────────────
///
/// [viewAction] is compiled in, deliberately, under §2.1's carve-out for
/// security invariants. "The tab is reachable" and "the role holds this
/// module's view permission" are the same statement — that is the invariant the
/// owner's one-switch design rests on, and it is what makes the states "sees the
/// tab but may view nothing" and "may edit but the tab is hidden" impossible
/// rather than merely unlikely. A tenant cannot rename it and no organization
/// gets a different answer, so it is not tenant data.
const String viewAction = 'view';

/// A module as it reaches THIS person — the `modules` block on `/me`.
@immutable
class ModuleAccess {
  const ModuleAccess({
    required this.key,
    required this.label,
    required this.available,
    required this.reachable,
  });

  final String key;

  /// The server's word for it. Never derived from [key] — see the class comment
  /// on PermissionModule for what upper-casing an identifier produces.
  final String label;

  /// The PLATFORM permits it for this organization (§13 entitlement).
  ///
  /// Carried separately from [reachable] so a screen can say "your plan does
  /// not include this" rather than silently omitting it.
  final bool available;

  /// AND this person's role holds the module's view permission.
  final bool reachable;

  /// Absent means available, absent means NOT reachable — and the asymmetry is
  /// deliberate, mirroring the server (`ModuleAvailability` fails open because
  /// no flag row means nobody ever restricted it; permissions fail closed
  /// because a missing grant is not a grant).
  factory ModuleAccess.fromJson(Map<String, dynamic> json) => ModuleAccess(
    key: json['key'] as String,
    label: json['label'] as String? ?? json['key'] as String,
    available: json['available'] != false,
    reachable: json['reachable'] == true,
  );

  /// Null when the server did not send the block at all, as distinct from
  /// sending an empty one.
  ///
  /// The two mean opposite things and the callers need to tell them apart: an
  /// empty list is "you can reach nothing", which is a real answer worth
  /// rendering. A missing list is a server that has not been told to answer the
  /// question, and the client has no way to guess.
  static List<ModuleAccess>? listFrom(dynamic json) => json is List
      ? json
            .whereType<Map<String, dynamic>>()
            .map(ModuleAccess.fromJson)
            .toList(growable: false)
      : null;
}

/// One action in the tree — `customers.create`, shown as "Create".
@immutable
class PermissionAction {
  const PermissionAction({
    required this.key,
    required this.action,
    required this.label,
    required this.grantable,
  });

  /// The permission key itself, which is what gets stored and sent.
  final String key;

  /// Its bare name within the module — `view`, `create`, `edit`, `delete`, or
  /// whatever else a module chooses to declare. Compared against [viewAction]
  /// and against nothing else.
  final String action;

  final String label;

  /// Whether the person doing the editing holds this themselves (§4: nobody
  /// grants what they do not have).
  ///
  /// Per ACTION, not per module, because that is where the server draws the
  /// bound: somebody with `customers.view` and `customers.edit` may hand out
  /// exactly those two and not `customers.delete`.
  ///
  /// Absent counts as grantable. The endpoint is called the grantable tree; a
  /// server that does not qualify an entry is saying the whole entry is one,
  /// and refusing everything on silence would render a dead editor against an
  /// answer that was never restrictive.
  final bool grantable;

  factory PermissionAction.fromJson(Map<String, dynamic> json) =>
      PermissionAction(
        key: json['key'] as String,
        action: json['action'] as String? ?? '',
        label: json['label'] as String? ?? json['key'] as String,
        grantable: json['grantable'] != false,
      );
}

/// One module in the grantable tree — a tab, and the actions inside it.
///
/// The label travels with the module rather than being worked out from the key.
/// The settings screen learned this the hard way: it split keys on the dot and
/// upper-cased the front, so people read headings like "NUMBERING" that nobody
/// had written. `roles` is called "Roles & permissions" and no amount of string
/// manipulation recovers that.
@immutable
class PermissionModule {
  const PermissionModule({
    required this.key,
    required this.label,
    required this.available,
    required this.actions,
    this.unavailableReason,
    this.groupKey = 'tab',
    this.groupLabel = '',
    this.groupHint,
  });

  final String key;
  final String label;

  /// Where this grant belongs on the sidebar: a work tab, Organization, or
  /// Settings. Absent or `tab` means this module IS a tab.
  final String groupKey;
  final String groupLabel;
  final String? groupHint;

  bool get isSidebarTab => groupKey == 'tab';

  /// The platform permits it for this organization (§13). A module switched off
  /// is shown, greyed, with the reason — never hidden, because somebody looking
  /// for Jobs and not finding it concludes the app is broken.
  final bool available;

  /// Why it is off, in the server's words where it sends any.
  final String? unavailableReason;

  final List<PermissionAction> actions;

  /// The permission that makes the tab reachable, or null for a module that
  /// declares no view action at all.
  ///
  /// Null is not hypothetical: a module is free to declare `['export']` and
  /// nothing else. Such a module has no tab to switch on, and the editor says
  /// so rather than quietly binding the switch to whichever action happens to
  /// come first.
  String? get viewKey {
    for (final action in actions) {
      if (action.action == viewAction) return action.key;
    }

    return null;
  }

  factory PermissionModule.fromJson(Map<String, dynamic> json) {
    final group = json['group'] is Map<String, dynamic>
        ? json['group'] as Map<String, dynamic>
        : const <String, dynamic>{};

    return PermissionModule(
      key: json['key'] as String,
      label: json['label'] as String? ?? json['key'] as String,
      available: json['available'] != false,
      unavailableReason:
          json['unavailable_reason'] as String? ?? json['reason'] as String?,
      groupKey: group['key'] as String? ?? 'tab',
      groupLabel: group['label'] as String? ?? '',
      groupHint: group['hint'] as String?,
      actions: (json['actions'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(PermissionAction.fromJson)
          .toList(growable: false),
    );
  }
}

/// Everything a role could be granted, as the server declares it.
@immutable
class PermissionCatalogue {
  const PermissionCatalogue(this.modules);

  final List<PermissionModule> modules;

  bool get isEmpty => modules.isEmpty;

  /// May this key be put in a save?
  ///
  /// Three ways it may not, and all three end the same way — the server refuses
  /// the whole request with a 422 rather than ignoring the one key:
  ///
  ///   - it is not in the tree, so no module declares it;
  ///   - the platform has its module switched off for this organization (§13),
  ///     which makes it ungrantable to anybody until it comes back;
  ///   - the person editing does not hold it themselves.
  ///
  /// In every one of those the server PRESERVES whatever the role already had —
  /// leaving a key out is not a decision to remove it, because the editor never
  /// offered a way to remove it. So the payload is the sendable subset, and the
  /// rest stays exactly as it was without either end having to guess.
  bool mayGrant(String key) {
    for (final module in modules) {
      for (final action in module.actions) {
        if (action.key == key) return module.available && action.grantable;
      }
    }

    return false;
  }

  /// Read from whatever shape the endpoint answers with.
  ///
  /// Tolerant in exactly one place, on purpose. Nearly every endpoint in this
  /// app wraps its payload in `data`; this one answers with `modules` at the
  /// top level. A client that accepts only one of the two turns a wording
  /// difference between two halves of a contract into a screen that renders
  /// nothing and says nothing about why.
  factory PermissionCatalogue.fromResponse(dynamic payload) {
    // `data` is unwrapped only when there is no `modules` beside it, so a body
    // that carries both is read the way it reads.
    final node = payload is Map<String, dynamic> && payload['modules'] == null
        ? payload['data']
        : payload;

    final list = node is Map<String, dynamic> ? node['modules'] : node;

    return PermissionCatalogue(
      list is List
          ? list
                .whereType<Map<String, dynamic>>()
                .map(PermissionModule.fromJson)
                .toList(growable: false)
          : const [],
    );
  }
}

/// One of the organization's roles.
@immutable
class RoleSummary {
  const RoleSummary({
    required this.id,
    required this.name,
    required this.userCount,
    required this.permissions,
    required this.editable,
    required this.mayEdit,
    required this.mayDelete,
    this.lockedReason,
  });

  final String id;
  final String name;

  /// How many people hold it. Deleting a role that somebody is using is the
  /// mistake worth showing a number to prevent.
  final int userCount;

  final Set<String> permissions;

  /// Whether this role may be changed at all.
  ///
  /// The SERVER decides, and the client never infers it from the name. The
  /// Administrator role is the one that cannot be edited — the owner's reason
  /// was that it could otherwise remove its own powers and lock the
  /// organization out — but "the role called Administrator" and "the role that
  /// is the organization's built-in administrator" are not the same thing.
  /// Matching on the word would break the moment an organization creates a role
  /// called "Administrators", and it is the same class of bug as the server-side
  /// short-circuit this whole change exists to remove.
  final bool editable;

  /// Whether the person LOOKING at it may change it — the server's own answer,
  /// from the policy that will be asked again when the save lands.
  ///
  /// Distinct from [editable], which is about the role. Both have to hold, and
  /// they fail for different reasons that deserve different sentences: "nobody
  /// may edit this one" and "you may not edit roles".
  final bool mayEdit;

  final bool mayDelete;

  /// Why not, in the server's words. Shown instead of the editor.
  final String? lockedReason;

  factory RoleSummary.fromJson(Map<String, dynamic> json) => RoleSummary(
    id: json['id'].toString(),
    name: json['name'] as String? ?? '',
    userCount: (json['user_count'] as num?)?.toInt() ?? 0,
    permissions: ((json['permissions'] as List<dynamic>?) ?? const [])
        .whereType<String>()
        .toSet(),
    // Two spellings of the same statement, and the explicit one wins. `editable`
    // says what the client needs to know; `is_administrator` says what the row
    // is, and a built-in administrator is the one role that cannot be edited.
    // Neither present means an ordinary role.
    editable: json['editable'] as bool? ?? json['is_administrator'] != true,
    // Absent falls back to the role's own editability rather than to false, so
    // a payload that does not carry the policy's answer does not render every
    // role read-only. The save is authorized again server-side regardless —
    // this decides what the screen OFFERS, never what it is allowed to do.
    mayEdit:
        json['may_edit'] as bool? ??
        json['editable'] as bool? ??
        json['is_administrator'] != true,
    mayDelete:
        json['may_delete'] as bool? ??
        json['editable'] as bool? ??
        json['is_administrator'] != true,
    lockedReason: json['locked_reason'] as String?,
  );

  static List<RoleSummary> listFrom(dynamic data) => data is List
      ? data
            .whereType<Map<String, dynamic>>()
            .map(RoleSummary.fromJson)
            .toList(growable: false)
      : const [];
}
