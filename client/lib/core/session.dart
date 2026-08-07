import 'package:flutter/foundation.dart';
import 'api_client.dart';
import 'token_store.dart';

/// Who is signed in, and what they can reach.
///
/// Holds the answer to "which organization am I in" for the whole app — the
/// client-side counterpart of the server's TenantContext. Nothing else stores
/// it, so there is one place to clear on sign-out.
class Session extends ChangeNotifier {
  Session(this._api);

  final ApiClient _api;

  Me? _me;
  Me? get me => _me;
  bool get signedIn => _me != null;

  /// Set when a lapsed subscription blocks a write (§13), so the UI can explain
  /// what happened instead of showing a generic failure.
  String? readOnlyNotice;

  /// The staff token issued alongside the tenant one for a linked account.
  ///
  /// Kept on its OWN client (see main.dart) rather than being set on this one.
  /// Both factors were proven at sign-in, so a second challenge would be
  /// ceremony — but the two tokens stay separate, because a tenant session that
  /// could reach the control plane is exactly what the split prevents.
  String? staffToken;

  /// Returns whether the token actually produced a session — /me can fail even
  /// when the login succeeded, and the caller has to know so it can stop showing
  /// a spinner and say something.
  Future<bool> adopt(String token, {bool persist = false}) async {
    _api.setToken(token);
    await refresh();

    // Written only after /me answered. Saving a token that turns out not to
    // work would mean every future launch tries it, fails, and clears it —
    // a slow sign-in instead of a fast sign-out.
    if (signedIn) {
      TokenStore.save(tenant: token, staff: staffToken, persist: persist);
    }

    return signedIn;
  }

  /// Pick up a session left by a refresh.
  ///
  /// The token is verified against /me before it is trusted — it may have
  /// expired, been revoked by a password change, or belong to an account that
  /// has since been deactivated. refresh() clears it on any of those, so a stale
  /// token means the sign-in screen rather than a broken shell.
  Future<void> restore() async {
    final token = TokenStore.tenantToken;

    if (token == null) {
      _restored = true;
      notifyListeners();
      return;
    }

    staffToken = TokenStore.staffToken;
    _api.setToken(token);

    await refresh();

    // Only when the server actually refused it. Clearing on an unreachable
    // server means a refresh during an outage permanently loses the session.
    if (!signedIn && !_unreachable) {
      TokenStore.clear();
    }

    _restored = true;
    notifyListeners();
  }

  /// False until restore() has finished, so the app can hold the first frame
  /// rather than flashing the sign-in screen at somebody who is signed in.
  bool _restored = false;
  bool get restored => _restored;

  Future<void> refresh() async {
    final response = await _api.get('/me');

    if (response.ok) {
      _me = Me.fromJson(response.data as Map<String, dynamic>);
      _unreachable = false;
      notifyListeners();
      return;
    }

    // A 401 means the token is genuinely no longer good — expired, revoked by a
    // password change, or the account was deactivated. Anything else means the
    // server did not answer, and throwing the session away for a network blip
    // or a 500 signs somebody out of a session that is perfectly valid, with no
    // way to tell the difference from the outside.
    if (response.unauthorized) {
      _me = null;
      _unreachable = false;
      _api.setToken(null);
    } else {
      _unreachable = true;
    }

    notifyListeners();
  }

  /// The last refresh could not reach the server, as opposed to being refused.
  ///
  /// Kept apart from `signedIn` because the two need opposite responses: one is
  /// "sign in again", the other is "try again in a moment".
  bool _unreachable = false;
  bool get unreachable => _unreachable;

  /// Change one preference and repaint.
  ///
  /// Optimistic: the value is applied locally before the request lands, because
  /// a theme that flickers back to the old one for half a second while a round
  /// trip completes looks broken. If the server refuses it, refresh() below puts
  /// the truth back.
  Future<void> setPreference(String key, Object value) async {
    final me = _me;
    if (me == null) return;

    _me = me.withPreference(key, value);
    notifyListeners();

    final response = await _api.patch('/me/preferences', {
      'preferences': {key: value},
    });

    if (!response.ok) {
      await refresh();
    }
  }

  /// Change which part of the business you are looking at (§3 roll-ups).
  ///
  /// Not `setPreference`. That applies the value locally first, which works for
  /// a theme — the client knows what "dark" looks like — and cannot work here:
  /// `scope` is RESOLVED by the server, which validates the choice against
  /// reach and quietly falls back to the organization when it no longer holds.
  /// Guessing the outcome in Dart would mean a second copy of ViewScope's
  /// fallback rules, kept in step by hope.
  ///
  /// So it writes, then asks. One round trip, and the answer is authoritative.
  Future<void> setScope(String value) async {
    final response = await _api.patch('/me/preferences', {
      'preferences': {'navigation.scope': value},
    });

    // Refreshed either way. On success to pick up the resolved scope; on
    // failure to put back whatever is actually stored, rather than leaving the
    // switcher showing a view the server never accepted.
    await refresh();

    if (!response.ok) {
      _unreachable = true;
      notifyListeners();
    }
  }

  void signOut() {
    _me = null;
    readOnlyNotice = null;
    staffToken = null;
    _api.setToken(null);
    TokenStore.clear();
    notifyListeners();
  }

  void noteReadOnly(String message) {
    readOnlyNotice = message;
    notifyListeners();
  }
}

class Me {
  const Me({
    required this.id,
    required this.name,
    required this.email,
    required this.roleName,
    required this.preferences,
    required this.development,
    required this.organization,
    required this.scope,
    required this.companies,
    required this.franchises,
  });

  final String id;
  final String name;
  final String email;
  final String? roleName;

  /// Theirs, nobody else's (§2.1 category 6). Always complete — the server
  /// merges stored values over the registry's defaults, so the client never has
  /// to know a default and a preference added later simply appears.
  final Map<String, dynamic> preferences;

  /// Set when this person is also platform staff (§4.2, revised 2026-08-06).
  ///
  /// One human, two identity records, linked. Its presence is what puts the
  /// Development section in the sidebar — so the section cannot appear for
  /// somebody with no staff account behind them, whatever the client believes.
  final DevelopmentAccess? development;

  final OrganizationSummary organization;

  /// Only franchises this user can actually act on (§4). The server sends reach,
  /// not the full list, so the UI never offers something that will fail on save.
  /// Where this person is standing (§3 roll-ups).
  ///
  /// The RESOLVED scope, not the stored preference. A saved view that has
  /// stopped being valid — a franchise removed, an assignment narrowed — comes
  /// back as the organization rather than as something that no longer exists.
  final ViewScope scope;

  /// The companies behind the franchises this person can reach (§6). Needed so
  /// the settings screen can offer the middle tier of the cascade, which was
  /// supported by the API and unreachable from the UI.
  final List<CompanySummary> companies;

  final List<FranchiseSummary> franchises;

  /// A copy with one preference changed. Me is immutable, which is what makes
  /// "did the theme actually change" a question with one answer.
  Me withPreference(String key, Object value) => Me(
        id: id,
        name: name,
        email: email,
        roleName: roleName,
        preferences: {...preferences, key: value},
        development: development,
        organization: organization,
        scope: scope,
        companies: companies,
        franchises: franchises,
      );

  /// Never null and never unknown — an unrecognised stored value falls back to
  /// following the device rather than leaving the app with no theme at all.
  String get theme => switch (preferences['appearance.theme']) {
        'light' => 'light',
        'dark' => 'dark',
        'mixed_dark_nav' => 'mixed_dark_nav',
        'mixed_light_nav' => 'mixed_light_nav',
        _ => 'system',
      };

  String get navigationStyle =>
      preferences['navigation.style'] == 'blocks' ? 'blocks' : 'list';

  bool get navigationCollapsed => preferences['navigation.collapsed'] == true;

  /// How wide the expanded sidebar is. Clamped on read as well as on write:
  /// a stored value from an older build, or one the server has since tightened
  /// the bounds on, must not be able to render an unusable shell.
  double get navigationWidth {
    final stored = preferences['navigation.width'];
    final value = stored is num ? stored.toDouble() : 220.0;

    return value.clamp(180.0, 420.0);
  }

  factory Me.fromJson(Map<String, dynamic> json) => Me(
        id: json['id'] as String,
        name: json['name'] as String,
        email: json['email'] as String,
        roleName: (json['role'] as Map<String, dynamic>?)?['name'] as String?,
        preferences: Map<String, dynamic>.from(
            json['preferences'] as Map<String, dynamic>? ?? const {}),
        development: json['development'] == null
            ? null
            : DevelopmentAccess.fromJson(json['development'] as Map<String, dynamic>),
        organization:
            OrganizationSummary.fromJson(json['organization'] as Map<String, dynamic>),
        scope: ViewScope.fromJson(
            json['scope'] as Map<String, dynamic>? ?? const {}),
        companies: (json['companies'] as List<dynamic>? ?? [])
            .map((c) => CompanySummary.fromJson(c as Map<String, dynamic>))
            .toList(),
        franchises: (json['franchises'] as List<dynamic>? ?? [])
            .map((f) => FranchiseSummary.fromJson(f as Map<String, dynamic>))
            .toList(),
      );
}

class OrganizationSummary {
  const OrganizationSummary({
    required this.id,
    required this.name,
    required this.franchiseLabel,
    required this.isReadOnly,
  });

  final String id;
  final String name;

  /// Organizations rename "Franchise" (§8) — never hardcode the word in the UI.
  final String franchiseLabel;
  final bool isReadOnly;

  factory OrganizationSummary.fromJson(Map<String, dynamic> json) => OrganizationSummary(
        id: json['id'] as String,
        name: json['name'] as String,
        franchiseLabel: json['franchise_label'] as String? ?? 'Franchise',
        isReadOnly: json['is_read_only'] as bool? ?? false,
      );
}

class ViewScope {
  const ViewScope({
    required this.type,
    required this.id,
    required this.label,
    required this.path,
    required this.logoPath,
    required this.logoAspect,
  });

  /// organization · company · franchise
  final String type;
  final String? id;

  /// The scoped thing's own name — "Connecticut".
  final String label;

  /// Its ancestry, ending in itself: ["Everdoors", "Connecticut"].
  ///
  /// Two companies can each operate a Connecticut, so the name alone names one
  /// of two different places with different data behind each. Everywhere the
  /// current view is stated, it is stated from this.
  final List<String> path;

  /// The branding endpoint whose logo applies to this view (§12).
  final String logoPath;

  /// 'square', 'wide', or null when there is no logo to show.
  final String? logoAspect;

  /// The last two segments — "Everdoors › Connecticut".
  ///
  /// Enough to disambiguate in a top bar without spending the width on a full
  /// chain; the whole path goes in the tooltip and on the settings screen,
  /// where there is room to be complete.
  String get shortPath =>
      path.length <= 2 ? path.join(' › ') : path.sublist(path.length - 2).join(' › ');

  String get fullPath => path.join(' › ');

  factory ViewScope.fromJson(Map<String, dynamic> json) => ViewScope(
        type: json['type'] as String? ?? 'organization',
        id: json['id'] as String?,
        label: json['label'] as String? ?? '',
        path: (json['path'] as List<dynamic>? ?? []).cast<String>(),
        logoPath: json['logo_path'] as String? ?? '/branding/organization',
        logoAspect: json['logo_aspect'] as String?,
      );
}

class CompanySummary {
  const CompanySummary({
    required this.id,
    required this.name,
    required this.parentCompanyId,
  });

  final String id;
  final String name;

  /// Null for a top-level company; set for a Sub Company (§3).
  ///
  /// Carried so the client can draw the structure rather than a flat row. A
  /// Sub Company rendered beside its own parent says the two are siblings,
  /// which is wrong about what it inherits and wrong about who manages it.
  final String? parentCompanyId;

  factory CompanySummary.fromJson(Map<String, dynamic> json) => CompanySummary(
        id: json['id'] as String,
        name: json['name'] as String,
        parentCompanyId: json['parent_company_id'] as String?,
      );
}

class FranchiseSummary {
  const FranchiseSummary({required this.id, required this.name, this.companyId});

  final String id;
  final String name;
  final String? companyId;

  factory FranchiseSummary.fromJson(Map<String, dynamic> json) => FranchiseSummary(
        id: json['id'] as String,
        name: json['name'] as String,
        companyId: json['company_id'] as String?,
      );
}

/// Platform-staff access held by the signed-in person (§4.2, revised).
class DevelopmentAccess {
  const DevelopmentAccess({
    required this.tier,
    required this.staffName,
    required this.focusedAt,
  });

  final String tier;
  final String staffName;

  /// The focused control-plane host. The Development section here is the same
  /// screens embedded in the CRM; dev.ezbizi.com is them on their own, which is
  /// what you want when that is all you are doing.
  final String focusedAt;

  bool get isLead => tier == 'lead_developer';

  String get tierLabel => isLead ? 'Lead Developer' : 'Developer';

  factory DevelopmentAccess.fromJson(Map<String, dynamic> json) => DevelopmentAccess(
        tier: json['tier'] as String? ?? 'developer',
        staffName: json['staff_name'] as String? ?? '',
        focusedAt: json['focused_at'] as String? ?? 'https://dev.ezbizi.com',
      );
}
