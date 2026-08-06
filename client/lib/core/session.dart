import 'package:flutter/foundation.dart';
import 'api_client.dart';

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
  Future<bool> adopt(String token) async {
    _api.setToken(token);
    await refresh();
    return signedIn;
  }

  Future<void> refresh() async {
    final response = await _api.get('/me');

    if (response.ok) {
      _me = Me.fromJson(response.data as Map<String, dynamic>);
    } else {
      _me = null;
      _api.setToken(null);
    }

    notifyListeners();
  }

  void signOut() {
    _me = null;
    readOnlyNotice = null;
    _api.setToken(null);
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
    required this.development,
    required this.organization,
    required this.franchises,
  });

  final String id;
  final String name;
  final String email;
  final String? roleName;

  /// Set when this person is also platform staff (§4.2, revised 2026-08-06).
  ///
  /// One human, two identity records, linked. Its presence is what puts the
  /// Development section in the sidebar — so the section cannot appear for
  /// somebody with no staff account behind them, whatever the client believes.
  final DevelopmentAccess? development;

  final OrganizationSummary organization;

  /// Only franchises this user can actually act on (§4). The server sends reach,
  /// not the full list, so the UI never offers something that will fail on save.
  final List<FranchiseSummary> franchises;

  factory Me.fromJson(Map<String, dynamic> json) => Me(
        id: json['id'] as String,
        name: json['name'] as String,
        email: json['email'] as String,
        roleName: (json['role'] as Map<String, dynamic>?)?['name'] as String?,
        development: json['development'] == null
            ? null
            : DevelopmentAccess.fromJson(json['development'] as Map<String, dynamic>),
        organization:
            OrganizationSummary.fromJson(json['organization'] as Map<String, dynamic>),
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
