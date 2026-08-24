import 'package:flutter/foundation.dart';
import 'api_client.dart';
import 'token_store.dart';

/// Who is signed in to the control plane (plan §4.2).
///
/// Deliberately NOT [Session]. Staff are a separate identity domain with no
/// organization — folding them into the tenant session would mean every screen
/// that reads `session.me!.organization` needs a null check it should never
/// have, and one missed check shows staff a tenant UI with no tenant behind it.
///
/// Its own [ApiClient] too, so a staff token can never end up on a tenant
/// request or the reverse. The server refuses both, but the client should not
/// be trying.
class StaffSession extends ChangeNotifier {
  StaffSession(this._api);

  final ApiClient _api;
  ApiClient get api => _api;

  StaffMe? _me;
  StaffMe? get me => _me;
  bool get signedIn => _me != null;

  Future<bool> adopt(String token, {bool persist = true}) async {
    _api.setToken(token);
    await refresh();

    if (signedIn && persist) {
      TokenStore.save(staff: token);
    }

    return signedIn;
  }

  void release() {
    _me = null;
    _api.setToken(null);
    notifyListeners();
  }

  /// Pick up a session left by a refresh, verifying it before trusting it.
  Future<void> restore() async {
    final token = TokenStore.staffToken;

    if (token == null) {
      _restored = true;
      notifyListeners();
      return;
    }

    _api.setToken(token);
    await refresh();

    if (!signedIn) {
      TokenStore.clear();
    }

    _restored = true;
    notifyListeners();
  }

  bool _restored = false;
  bool get restored => _restored;

  Future<void> refresh() async {
    final response = await _api.get('/staff/me');

    if (response.ok) {
      _me = StaffMe.fromJson(response.data as Map<String, dynamic>);
    } else {
      _me = null;
      _api.setToken(null);
    }

    notifyListeners();
  }

  void signOut() {
    _me = null;
    _api.setToken(null);
    TokenStore.clear();
    notifyListeners();
  }
}

class StaffMe {
  const StaffMe({
    required this.id,
    required this.name,
    required this.email,
    required this.tier,
    required this.mayEnterWithoutApproval,
    required this.recoveryCodeCount,
  });

  final String id;
  final String name;
  final String email;
  final String tier;

  /// Lead Developer only (§4.2). Drives whether the impersonation dialog offers
  /// to proceed without a client approver.
  final bool mayEnterWithoutApproval;

  final int recoveryCodeCount;

  bool get isLead => tier == 'lead_developer';

  String get tierLabel => isLead ? 'Lead Developer' : 'Developer';

  factory StaffMe.fromJson(Map<String, dynamic> json) => StaffMe(
        id: json['id'] as String,
        name: json['name'] as String,
        email: json['email'] as String,
        tier: json['tier'] as String,
        mayEnterWithoutApproval: json['may_enter_without_approval'] as bool? ?? false,
        recoveryCodeCount: json['recovery_code_count'] as int? ?? 0,
      );
}
