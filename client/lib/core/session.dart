import 'package:flutter/foundation.dart';
import 'api_client.dart';
import 'password_policy.dart';
import 'permissions.dart';
import 'timestamps.dart';
import 'token_store.dart';
import 'ui_strings.dart';

/// Who is signed in, and what they can reach.
///
/// Holds the answer to "which organization am I in" for the whole app — the
/// client-side counterpart of the server's TenantContext. Nothing else stores
/// it, so there is one place to clear on sign-out.
class Session extends ChangeNotifier {
  Session(this._api) {
    // Every request goes through one client, so the session ends from one
    // place. See ApiClient.onUnauthorized for why it is noticed there.
    _api.onUnauthorized = sessionExpired;
  }

  final ApiClient _api;
  ApiClient get api => _api;

  Me? _me;
  int _identityGeneration = 0;
  int _refreshGeneration = 0;
  int _scopeGeneration = 0;
  bool _switchingScope = false;
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

  /// Enter the app on the strength of a successful authentication response.
  ///
  /// Takes the whole body rather than the token, because two endpoints return
  /// this body: /auth/login and /auth/signup, which answer identically by
  /// design. Pulling the two fields apart at each call site is how the second
  /// one ends up forgetting the staff token — a linked account that signs in
  /// and finds the Development section missing, with nothing failing loudly.
  Future<bool> adoptAuthResponse(
    Map<String, dynamic> body, {
    bool persist = false,
  }) {
    staffToken = null;

    return adopt(body['token'] as String, persist: persist);
  }

  /// Returns whether the token actually produced a session — /me can fail even
  /// when the login succeeded, and the caller has to know so it can stop showing
  /// a spinner and say something.
  Future<bool> adopt(String token, {bool persist = false}) async {
    final generation = ++_identityGeneration;
    _scopeGeneration++;
    _switchingScope = false;
    _me = null;
    _api.setToken(token);
    await refresh();
    if (generation != _identityGeneration) return false;

    // Written only after /me answered. Saving a token that turns out not to
    // work would mean every future launch tries it, fails, and clears it —
    // a slow sign-in instead of a fast sign-out.
    if (signedIn) {
      TokenStore.save(tenant: token, persist: persist);
      TokenStore.dropStaff();
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
    final generation = ++_identityGeneration;
    final token = TokenStore.tenantToken;

    if (token == null) {
      _restored = true;
      notifyListeners();
      return;
    }

    staffToken = TokenStore.staffToken;
    _api.setToken(token);

    await refresh();
    if (generation != _identityGeneration) {
      _restored = true;
      notifyListeners();
      return;
    }

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

  Future<void> refresh({String? view}) async {
    // A preference rollback/ordinary refresh must not supersede the explicit
    // target refresh while the old view is still on screen.
    if (_switchingScope && view == null) return;
    final identity = _identityGeneration;
    final generation = ++_refreshGeneration;
    final scopeGeneration = _scopeGeneration;
    final response = await _api.get(
      '/me',
      null,
      view == null ? null : {'X-EzBizi-View': view},
    );
    if (identity != _identityGeneration ||
        generation != _refreshGeneration ||
        scopeGeneration != _scopeGeneration) {
      return;
    }

    if (response.ok) {
      final data = response.data as Map<String, dynamic>;
      _me = Me.fromJson(data);
      final scope = _me!.scope;
      _api.setView(
        scope.type == 'organization'
            ? 'organization'
            : '${scope.type}:${scope.id}',
      );
      UiStrings.adopt(data);
      _unreachable = false;
      // Whatever brought them back, they are in now — so the sentence about a
      // session that ended stops being true here rather than lingering.
      expired = false;
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

  /// Change one preference and repaint.
  ///
  /// Optimistic: the value is applied locally before the request lands, because
  /// a theme that flickers back to the old one for half a second while a round
  /// trip completes looks broken. If the server refuses it, refresh() below puts
  /// the truth back.
  Future<void> setPreference(String key, Object value) async {
    final identity = _identityGeneration;
    final me = _me;
    if (me == null) return;

    _me = me.withPreference(key, value);
    notifyListeners();

    final response = await _api.patch('/me/preferences', {
      'preferences': {key: value},
    });
    if (identity != _identityGeneration) return;

    if (!response.ok) {
      await refresh();
    }
  }

  /// Record a preference this app has already saved by another route.
  ///
  /// Not [setPreference], which WRITES — through `PATCH /me/preferences` and
  /// `PreferenceRegistry`, the registry for the controls the Settings screen
  /// renders. Some preferences are not one of those and have an endpoint of
  /// their own: `dashboard.glances` goes through
  /// `DashboardController::saveLayout`, because a layout is not a setting
  /// somebody picks from a list and the registry's casts have nothing to say
  /// about it.
  ///
  /// The Organization tab's canvas arrangement USED to be the second example
  /// here, and it is deliberately not listed any more. That arrangement is one
  /// organization's setting now rather than one person's preference: it rides
  /// on the tree payload `GET /companies` answers with, which is refetched
  /// every time the screen opens, so a copy held here would be a stale second
  /// copy of a value whose whole point is that everybody sees the same one.
  /// Nothing in the app calls this for it, and nothing may.
  ///
  /// Those writes still have to land in the copy of `preferences` this session
  /// is holding. Without that, leaving the screen and coming back reads the
  /// value that was there BEFORE the change — a save that succeeded, undone on
  /// screen by a stale local copy.
  ///
  /// No request, because there is nothing to ask: the server has already
  /// confirmed this value and this is only the client agreeing with it. And a
  /// stale local blob could never clobber anything server-side even if it were
  /// wrong, because every writer merges per key rather than assigning the whole
  /// column.
  void rememberPreference(String key, Object value) {
    final me = _me;
    if (me == null) return;

    _me = me.withPreference(key, value);
    notifyListeners();
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
  ///
  /// That refetch is also what keeps [Me.display] honest. Settings cascade per
  /// scope (§6), so moving from the organization to a franchise that keeps a
  /// 24-hour clock changes how every date on screen should be written — and
  /// because the formats ride on /me rather than on a request of their own,
  /// they arrive with the new scope instead of needing a second call that
  /// somebody would eventually forget to make.
  Future<void> setScope(String value) async {
    final identity = _identityGeneration;
    final generation = ++_scopeGeneration;
    _switchingScope = true;
    try {
      final response = await _api.patch('/me/preferences', {
        'preferences': {'navigation.scope': value},
      });
      if (identity != _identityGeneration || generation != _scopeGeneration) {
        return;
      }

      // The old displayed view remains pinned until the requested view resolves.
      // Failed preference writes must never adopt another device's preference.
      if (response.ok) await refresh(view: value);
      if (identity != _identityGeneration || generation != _scopeGeneration) {
        return;
      }

      if (!response.ok) {
        _unreachable = true;
        notifyListeners();
      }
    } finally {
      if (identity == _identityGeneration && generation == _scopeGeneration) {
        _switchingScope = false;
      }
    }
  }

  void signOut() {
    _identityGeneration++;
    _scopeGeneration++;
    _switchingScope = false;
    _me = null;
    readOnlyNotice = null;
    staffToken = null;
    expired = false;
    _api.setToken(null);
    TokenStore.clear();
    notifyListeners();
  }

  /// The session ended on its own rather than because somebody pressed Sign out.
  ///
  /// Kept apart from simply being signed out because the sign-in screen owes
  /// two different people two different things: somebody who signed out meant
  /// to, and needs no explanation; somebody whose token expired overnight is
  /// looking at a login screen they did not ask for, and "why am I here" is the
  /// first thing they will wonder.
  ///
  /// Cleared by [signOut] and by a successful sign-in, so the sentence never
  /// outlives the event it describes.
  bool expired = false;

  /// The server refused a token we were holding: it expired, a password change
  /// revoked it, or the account was switched off.
  ///
  /// ── Why this ends the session rather than reporting a failure ─
  ///
  /// The owner left a tab open overnight:
  ///
  ///   *"today when i tried clicking on a tab it said not authenticated … it
  ///    should log me out to the login screen instead of letting me still click
  ///    through the app."*
  ///
  /// They were right, and the old behaviour was worse than untidy. The shell
  /// stayed up with a sidebar that still looked live, so every tab was a
  /// separate small failure in its own wording, and nothing anywhere said the
  /// one true thing — that the session was over. An app that looks signed in
  /// and cannot do anything invites somebody to keep trying.
  ///
  /// Idempotent, because several requests can be in flight when a token lapses
  /// and each will come back 401. The first one ends the session; the rest find
  /// it already ended and do nothing, rather than each firing a rebuild.
  void sessionExpired() {
    if (_me == null && !_api.hasToken) return;
    _identityGeneration++;
    _scopeGeneration++;
    _switchingScope = false;

    _me = null;
    readOnlyNotice = null;
    staffToken = null;
    _unreachable = false;
    expired = true;
    _api.setToken(null);
    TokenStore.clear();
    notifyListeners();
  }

  void noteReadOnly(String message) {
    readOnlyNotice = message;
    notifyListeners();
  }

  /// Write a punch response onto this device's session.
  ///
  /// The POST answers the same `time_clock` block `/me` carries. Applying it
  /// here is what flips Clock in to Clock out without a navigation or a second
  /// `/me` — other devices pick it up the next time they load, restore, or
  /// change place.
  void applyTimeClock(Map<String, dynamic> json) {
    final me = _me;
    if (me == null) return;

    _me = me.withTimeClock(TimeClockStatus.fromJson(json));
    notifyListeners();
  }
}

class Me {
  const Me({
    required this.id,
    required this.name,
    required this.email,
    required this.roleName,
    required this.hasAvatar,
    required this.permissions,
    required this.modules,
    required this.preferences,
    required this.development,
    required this.twoFactorEnrolmentRequired,
    required this.emailConfirmationRequired,
    required this.passwordMinLength,
    required this.organization,
    required this.scope,
    required this.display,
    required this.companies,
    required this.franchises,
    required this.onboardingId,
    this.screenMonitoringExempt = false,
    this.timeClock = TimeClockStatus.absent,
    this.phoneIncomingPopup = true,
    this.profileMissing = const [],
  });

  final String id;
  final String name;
  final String email;
  final String? roleName;

  /// Whether there is a profile picture to fetch. The top bar asks only when
  /// there is one; without this it either requested an avatar for every user
  /// who has none, or never requested one at all.
  final bool hasAvatar;

  /// What this person's own profile still lacks: `phone`, `address`,
  /// `gender`, `date_of_birth`, the names, and `photo` (User::PROFILE_FIELDS).
  /// Not empty, the top bar's picture pulses until it is (Jose, 2026-09-30,
  /// from A1 Tools). Empty from a server that does not say.
  final List<String> profileMissing;

  /// Every permission key this person's role holds (§4).
  ///
  /// The full list, even for an Administrator — never a wildcard. The client
  /// must have no reason to know which role is the administrator one, because
  /// the moment it does, `if (role == 'Administrator')` appears somewhere and
  /// the whole permission system has a second, wrong implementation in Dart.
  /// That is the same short-circuit being removed from the server.
  final Set<String> permissions;

  /// Which modules exist for this organization and which of them this person
  /// can reach (§4 permissions, §13 entitlement).
  ///
  /// Null means the server did not answer the question — a build older than
  /// this one. Empty means it answered "none". [reaches] treats them the same
  /// and refuses either way: the alternative is inventing a list of modules and
  /// the words for them in Dart, which is precisely the hardcoded sidebar this
  /// field replaces. Both halves ship in one deploy, so a server that cannot
  /// answer is not a state that outlives it.
  final List<ModuleAccess>? modules;

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

  /// The organization has made a second factor mandatory (§6
  /// `security.require_two_factor`) and this account has not set one up.
  ///
  /// Sign-in still succeeds — the server deliberately routes people TO enrolment
  /// rather than refusing them, because the only recovery from a mistaken
  /// lockout is a password reset email this platform cannot send. What the
  /// server does refuse is everything outside `/me`, so the shell reads this and
  /// says why instead of letting somebody meet a wall of failed screens.
  final bool twoFactorEnrolmentRequired;

  /// This address has not been confirmed, so every WRITE is refused until
  /// it is (TODO #22). Reads are untouched, which is why the app looks
  /// healthy right up until somebody tries to save.
  ///
  /// Same reason as [twoFactorEnrolmentRequired] for living on the session
  /// rather than being inferred from a failed request: a refresh restores
  /// the session from a stored token and never sees a login body, so
  /// without it the app comes back with saves failing and nothing on
  /// screen saying why.
  final bool emailConfirmationRequired;

  /// The minimum length THIS organization's passwords must reach — the platform
  /// floor, or higher where an administrator has raised it (§6
  /// `security.password_min_length`).
  ///
  /// Resolved by the server, because only the server knows the setting. The
  /// account screen states this number; sign-up and reset cannot, and state the
  /// platform floor instead — see core/password_policy.dart for why.
  final int passwordMinLength;

  final OrganizationSummary organization;

  /// Only franchises this user can actually act on (§4). The server sends reach,
  /// not the full list, so the UI never offers something that will fail on save.
  /// Where this person is standing (§3 roll-ups).
  ///
  /// The RESOLVED scope, not the stored preference. A saved view that has
  /// stopped being valid — a franchise removed, an assignment narrowed — comes
  /// back as the organization rather than as something that no longer exists.
  final ViewScope scope;

  /// How to write a date and a clock here (§6 `date_format`, `time_format`).
  ///
  /// Resolved by the server against [scope] — the SAME scope named above — so a
  /// franchise that keeps a 24-hour clock reads as one the moment the switcher
  /// points at it. It rides on /me for exactly that reason: /me is refetched by
  /// [Session.setScope], so the formats cannot drift out of step with the view
  /// they describe, and there is no second request to remember to make.
  ///
  /// Never null. An absent block means a server older than this build, and
  /// DisplayFormats.fallback answers in ISO rather than guessing the default.
  final DisplayFormats display;

  /// Every company this person can *reach* (§6). That is not a picker.
  ///
  /// `/me` sends the full reach so the switcher can move. Iterating this to
  /// offer Connect, Add, or a default `company_id` files on a sibling of the
  /// current view. Use [companiesInView] or [filingCompanyId].
  final List<CompanySummary> companies;

  /// Every franchise this person can reach. Same rule as [companies]: the
  /// switcher lists it; filing uses [franchisesInView].
  final List<FranchiseSummary> franchises;

  /// Where the view switcher may go (Jose, 2026-10-15: "if a company is
  /// switched off, it should not appear on the company picker"): companies
  /// that are on, under a parent that is on.
  List<CompanySummary> get switchableCompanies {
    final byId = {for (final c in companies) c.id: c};
    bool on(CompanySummary c) {
      final parent = c.parentCompanyId == null ? null : byId[c.parentCompanyId];
      return c.isActive && (parent == null || parent.isActive);
    }

    return companies.where(on).toList();
  }

  /// Places that are on, in a company that is on and switchable.
  List<FranchiseSummary> get switchableFranchises {
    final on = {for (final c in switchableCompanies) c.id};
    return franchises
        .where(
          (f) =>
              f.isActive && (f.companyId == null || on.contains(f.companyId)),
        )
        .toList();
  }

  /// Companies the current view covers — the scoped company plus its Sub
  /// Companies, already intersected with [companies] (reach).
  ///
  /// `/me` sends every company this person can act on. The switcher then
  /// narrows what they are looking at. A picker that offered the rest would
  /// let somebody standing in A-1 Chimney file a record on Everdoors.
  List<CompanySummary> get companiesInView {
    final scope = this.scope;
    if (scope.type == 'organization') return companies;
    if (scope.type == 'franchise') {
      final place = franchises.where((f) => f.id == scope.id).firstOrNull;
      if (place == null) return const [];
      return companies.where((c) => c.id == place.companyId).toList();
    }
    final rootId = scope.id;
    if (rootId == null) return const [];
    return companies.where((c) => _inCompanySubtree(c, rootId)).toList();
  }

  /// Franchises the current view covers. Organization: every reachable
  /// franchise. Company: that company and its Sub Companies. Franchise: that
  /// one branch.
  List<FranchiseSummary> get franchisesInView {
    final scope = this.scope;
    if (scope.type == 'organization') return franchises;
    if (scope.type == 'franchise') {
      return franchises.where((f) => f.id == scope.id).toList();
    }
    final ids = {for (final c in companiesInView) c.id};
    return franchises.where((f) => ids.contains(f.companyId)).toList();
  }

  /// Franchises of one company, still inside the current view.
  List<FranchiseSummary> franchisesOf(String? companyId) {
    if (companyId == null) return const [];
    return franchisesInView.where((f) => f.companyId == companyId).toList();
  }

  /// Company the current view has already named, or null at the group.
  ///
  /// Default for "file here". Never [companies].first — that is reach, and
  /// the first reachable company is often a sibling of the view.
  String? get filingCompanyId {
    switch (scope.type) {
      case 'organization':
        return null;
      case 'company':
        return scope.id;
      case 'franchise':
        return franchises.where((f) => f.id == scope.id).firstOrNull?.companyId;
      default:
        final inView = companiesInView;
        return inView.length == 1 ? inView.first.id : null;
    }
  }

  /// Franchise the current view has already named, or null.
  String? get filingFranchiseId => scope.type == 'franchise' ? scope.id : null;

  /// A company picker only earns its place when the view still leaves a
  /// choice: the group (org vs a company), or a parent with Sub Companies.
  /// A company view of A-1 Chimney with no children has one owner, and
  /// naming it again is how sibling companies leaked onto New location.
  bool get offersCompanyChoice =>
      scope.type == 'organization' ||
      (scope.type == 'company' && companiesInView.length > 1);

  /// General plus the company's places. A franchise view is that place, so
  /// the field would only repeat the switcher.
  bool get offersFranchiseChoice => scope.type != 'franchise';

  bool _inCompanySubtree(CompanySummary company, String rootId) {
    if (company.id == rootId) return true;
    var parentId = company.parentCompanyId;
    final seen = <String>{};
    while (parentId != null && seen.add(parentId)) {
      if (parentId == rootId) return true;
      parentId = companies
          .where((c) => c.id == parentId)
          .firstOrNull
          ?.parentCompanyId;
    }
    return false;
  }

  /// The onboarding that is about THIS person, while it is unfinished.
  ///
  /// The id and nothing else. Everything about the form — the questions, the
  /// answers, who filled what in — comes from `GET /hr/onboardings/{id}` when
  /// the screen opens; putting any of it here would mean fetching a contract's
  /// worth of fields on every session restore, for a screen most people open
  /// once.
  ///
  /// ── Why it rides on /me ──────────────────────────────────────────
  ///
  /// A new starter accepts their invitation, which signs them in, and the very
  /// next thing they need is the form the employer started for them. They
  /// cannot be sent to the Human Resources module to find it — that module is
  /// the employer's, and a technician's role does not reach it — and they must
  /// not be sent to a link they have to keep, because the whole point of save
  /// and resume is that closing the tab costs nothing.
  ///
  /// So it arrives the same way `two_factor_enrolment_required` does, and for
  /// the same reason: it is a thing the SHELL has to know about before it draws
  /// destinations, because the answer changes what the person sees first.
  ///
  /// Nothing polls it. The destination appears the next time /me is asked —
  /// signing in, restoring a session, changing scope, or the form itself
  /// reporting that something happened to it, which is what takes the
  /// destination away once the last signature is given. An employer starting an
  /// onboarding for somebody already signed in is therefore not visible to them
  /// until one of those; the alternative is a poll on every screen for a state
  /// most people are in once.
  ///
  /// It is never the onboardings this person RUNS for other people. Those are
  /// the Human Resources tab, gated by permission; this is the one they are the
  /// subject of, which needs no permission at all.
  ///
  /// Null when there is nothing waiting — including for a server that does not
  /// send the block, which is the same state as far as anybody can tell.
  final String? onboardingId;

  /// SECURITY exemption (Monitoring): this person's own device is never
  /// screen-captured, live-viewed or remote-controlled.
  ///
  /// The desktop agent reads this to skip constructing its capture services
  /// (ScreenshotService / LiveViewService / RemoteControlService) and to omit
  /// the `screenshot` capability from its heartbeat — so it still beats
  /// (online) and reports specs, but never advertises or produces a capture.
  /// The server strips those capabilities and refuses every screen-reaching
  /// endpoint regardless; this flag is what lets the agent not even try. False
  /// for a server that does not send the key — an older one, where the feature
  /// does not exist and nobody is exempt.
  final bool screenMonitoringExempt;

  /// Whether a ringing call opens the draggable card as well as the strip.
  ///
  /// The COMPANY's setting, `phone.incoming_popup` in the registry, not a
  /// person's: Jose, 2026-09-25, *"the company should decide"*. Anything but
  /// an explicit false is on, so a server that predates the key shows it.
  final bool phoneIncomingPopup;

  /// Punch-clock chrome from `/me`.
  ///
  /// The button is session chrome, the same reason two-factor rides here: it
  /// has to be right on the first frame. A punch writes this block back via
  /// [Session.applyTimeClock] so this device flips without another `/me`.
  /// [TimeClockStatus.absent] is an older server that never sent the key.
  final TimeClockStatus timeClock;

  /// Does this person's role hold this exact permission?
  ///
  /// Fails closed, including when the server sent no list at all. The server is
  /// asked again on every write regardless — this decides what a screen OFFERS,
  /// never what anybody is allowed to do.
  bool can(String permission) => permissions.contains(permission);

  /// Why a control is refused for want of a permission, or null when it is not.
  ///
  /// ── Why the sentence lives here ──────────────────────────────────
  ///
  /// Because it is the same sentence every time, and the moment each screen
  /// writes its own the wording drifts: half say "you do not have permission",
  /// half say "ask an administrator", and none of them say the one thing that
  /// makes the difference — that a permission belongs to a ROLE, so the person
  /// to ask is whoever edits roles rather than whoever set up the account.
  ///
  /// It deliberately does NOT answer the reach half of §4 — that is
  /// [nowhereToAct], because the two have different remedies and only the
  /// screen knows which of them it is looking at.
  ///
  /// [act] reads as an infinitive without "to": "add a customer", "delete this
  /// automation". Kept as the caller's words on purpose, because a permission
  /// key is a wire name and R8 forbids those on screen.
  String? refusal(String permission, String act) => can(permission)
      ? null
      : 'You do not have permission to $act. That is part of a role rather than '
            'of an account, so somebody who can edit roles can add it to yours.';

  /// The other half of §4: the permission is held and there is nowhere to use it.
  ///
  /// Read [franchises] to decide whether to call this — the server fills it
  /// with REACH rather than with everything that exists, so an empty list is
  /// the answer rather than something to work out.
  ///
  /// ── Why the sentence lives here too ──────────────────────────────
  ///
  /// Jobs and Customers each wrote their own and they had already drifted: one
  /// said "there is nowhere to put one" and the other "an administrator can
  /// assign you to one", against a server that says "there is nowhere to do it"
  /// and "an administrator can assign you to a franchise". A disabled control
  /// and the 403 behind it explaining the same refusal in two different ways is
  /// how somebody comes to believe they are two different problems.
  ///
  /// The words are Refusal::unassigned's, deliberately verbatim, and the pair
  /// has to be kept in step by hand. That is the price of answering without a
  /// round trip, and the alternative — every screen asking the server whether a
  /// button should be live — is a request per control per render.
  ///
  /// Not `nowhereToAct`'s, which is where they arrive from: that method is a
  /// ROUTER over two sentences, and only one of them is mirrored here. Which
  /// one, and why the other is left alone, is the section below.
  ///
  /// ── One server state this cannot see, and why that is all right ──
  ///
  /// Refusal::nowhereToAct's other branch — `nothingBelow` — tells a THIRD kind
  /// of person something different: somebody assigned above the level who has
  /// nothing beneath them yet, where the fix is a place rather than an
  /// assignment. This cannot distinguish that case — /me's `companies` is built
  /// from the companies BEHIND reachable franchises, so it is empty in exactly
  /// the cases [franchises] is — and it does not try to guess. §3 forbids a
  /// company without a place and every write path enforces it, so that state
  /// does not arise from anything the API will do; this sentence is correct for
  /// every state it can produce. If a company ever does turn up placeless, the
  /// server's 403 will be right and this tooltip will be the stale one, which is
  /// the safer way round.
  ///
  /// [note] is one more sentence for a screen where the rule itself is
  /// surprising, appended rather than spliced in — the same argument, and the
  /// same shape, as Refusal::outsideReach's own note argument.
  String nowhereToAct(String act, {String? note}) {
    final label = organization.franchiseLabel.toLowerCase();

    return 'You have permission to $act, but you are not assigned to any $label, '
        'so there is nowhere to do it. An administrator can assign you to a '
        '$label.${note == null ? '' : ' $note'}';
  }

  /// May this person reach this module — both gates, as [ModuleAccess] carries
  /// them: the platform permits it AND their role holds its view permission.
  bool reaches(String moduleKey) =>
      modules?.any((m) => m.key == moduleKey && m.reachable) ?? false;

  /// The module the server declared under this key, whether or not it is
  /// reachable. Null when it declared none — which is how the client tells "off
  /// for you" from "this build has never heard of it".
  ModuleAccess? module(String moduleKey) {
    for (final candidate in modules ?? const <ModuleAccess>[]) {
      if (candidate.key == moduleKey) return candidate;
    }

    return null;
  }

  /// The reachable modules, in the order the server listed them.
  ///
  /// The order is the server's on purpose. It is the same registry order that
  /// decides everything else about a module, so a module inserted in the middle
  /// of it lands in the same place in the sidebar as it does everywhere else,
  /// rather than wherever an alphabetical sort in Dart happens to put it.
  List<ModuleAccess> get reachableModules =>
      (modules ?? const <ModuleAccess>[]).where((m) => m.reachable).toList();

  /// A copy with one preference changed. Me is immutable, which is what makes
  /// "did the theme actually change" a question with one answer.
  Me withPreference(String key, Object value) => Me(
    id: id,
    name: name,
    email: email,
    roleName: roleName,
    hasAvatar: hasAvatar,
    permissions: permissions,
    modules: modules,
    preferences: {...preferences, key: value},
    development: development,
    twoFactorEnrolmentRequired: twoFactorEnrolmentRequired,
    emailConfirmationRequired: emailConfirmationRequired,
    passwordMinLength: passwordMinLength,
    organization: organization,
    scope: scope,
    display: display,
    companies: companies,
    franchises: franchises,
    onboardingId: onboardingId,
    screenMonitoringExempt: screenMonitoringExempt,
    phoneIncomingPopup: phoneIncomingPopup,
    timeClock: timeClock,
    profileMissing: profileMissing,
  );

  /// A copy with the punch-clock chrome replaced after a successful POST.
  Me withTimeClock(TimeClockStatus timeClock) => Me(
    id: id,
    name: name,
    email: email,
    roleName: roleName,
    hasAvatar: hasAvatar,
    permissions: permissions,
    modules: modules,
    preferences: preferences,
    development: development,
    twoFactorEnrolmentRequired: twoFactorEnrolmentRequired,
    emailConfirmationRequired: emailConfirmationRequired,
    passwordMinLength: passwordMinLength,
    organization: organization,
    scope: scope,
    display: display,
    companies: companies,
    franchises: franchises,
    onboardingId: onboardingId,
    screenMonitoringExempt: screenMonitoringExempt,
    phoneIncomingPopup: phoneIncomingPopup,
    timeClock: timeClock,
    profileMissing: profileMissing,
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

  /// This person's own switch in the bell panel: pop-ups, phone pushes and
  /// emails, never the bell list itself. Anything but an explicit false is on,
  /// so everybody who has never touched it keeps their alerts — the same
  /// reading NotificationDispatcher uses on the server.
  bool get notificationAlerts => preferences['notifications.alerts'] != false;

  /// How wide the expanded sidebar is. Clamped on read as well as on write:
  /// a stored value from an older build, or one the server has since tightened
  /// the bounds on, must not be able to render an unusable shell.
  double get navigationWidth {
    final stored = preferences['navigation.width'];
    final value = stored is num ? stored.toDouble() : 220.0;

    return value.clamp(180.0, 420.0);
  }

  /// The order this person dragged their sidebar into, with their dividers.
  ///
  /// Empty is the ordinary state — it means nobody has touched it, and the
  /// sidebar renders in the order the server sends the modules. Read leniently
  /// because it is arrangement rather than access: a stale entry costs a
  /// position, never a module.
  List<String> get navigationOrder =>
      (preferences['navigation.order'] as List<dynamic>? ?? const [])
          .whereType<String>()
          .toList();

  /// Whether the dial pad keeps its customer panel open between calls.
  ///
  /// A preference rather than screen state because the screen is thrown away
  /// every time somebody leaves Communication, and a pin that forgets itself
  /// on the way to Jobs and back is not a pin.
  bool get phoneCustomerPanelPinned =>
      preferences['phone.customer_panel_pinned'] == true;

  /// The two pane widths on Web Management → Widgets.
  ///
  /// Clamped on read for the same reason [navigationWidth] is: a value stored
  /// by an older build, or one whose bounds the server has since tightened,
  /// must not be able to render a pane nobody can use.
  double get webWidgetsListWidth =>
      _width('web.widgets.list_width', 260, 180, 460);

  double get webWidgetsPreviewWidth =>
      _width('web.widgets.preview_width', 360, 280, 640);

  /// The leads list's share of Leads, 30% unless dragged (Jose, 2026-09-29:
  /// "default should be 30 - 70"), and the people list's width on HR →
  /// Records. Preferences, so leaving the tab does not reset them.
  double get leadsListShare => _width('leads.list_percent', 30, 15, 70) / 100;

  double get hrRecordsListWidth =>
      _width('hr.records.list_width', 300, 240, 900);

  double _width(String key, double fallback, double min, double max) {
    final stored = preferences[key];
    final value = stored is num ? stored.toDouble() : fallback;

    return value.clamp(min, max);
  }

  factory Me.fromJson(Map<String, dynamic> json) => Me(
    id: json['id'] as String,
    name: json['name'] as String,
    email: json['email'] as String,
    roleName: (json['role'] as Map<String, dynamic>?)?['name'] as String?,
    hasAvatar: json['has_avatar'] == true,
    profileMissing: [
      for (final key in (json['profile_missing'] as List? ?? const []))
        if (key is String) key,
    ],
    permissions: ((json['permissions'] as List<dynamic>?) ?? const [])
        .whereType<String>()
        .toSet(),
    modules: ModuleAccess.listFrom(json['modules']),
    preferences: Map<String, dynamic>.from(
      json['preferences'] as Map<String, dynamic>? ?? const {},
    ),
    development: json['development'] == null
        ? null
        : DevelopmentAccess.fromJson(
            json['development'] as Map<String, dynamic>,
          ),
    twoFactorEnrolmentRequired: json['two_factor_enrolment_required'] == true,
    emailConfirmationRequired: json['email_confirmation_required'] == true,
    // Falls back to the compiled floor rather than to zero. A server that
    // does not send this is an older one, and its own minimum is the floor —
    // asking for nothing would be a form that promises to accept `a`.
    passwordMinLength:
        (json['password_min_length'] as num?)?.toInt() ??
        PasswordPolicy.minLength,
    organization: OrganizationSummary.fromJson(
      json['organization'] as Map<String, dynamic>,
    ),
    scope: ViewScope.fromJson(
      json['scope'] as Map<String, dynamic>? ?? const {},
    ),
    display: DisplayFormats.fromJson(json['display']),
    companies: (json['companies'] as List<dynamic>? ?? [])
        .map((c) => CompanySummary.fromJson(c as Map<String, dynamic>))
        .toList(),
    franchises: (json['franchises'] as List<dynamic>? ?? [])
        .map((f) => FranchiseSummary.fromJson(f as Map<String, dynamic>))
        .toList(),
    onboardingId: (json['onboarding'] as Map<String, dynamic>?)?['id']
        ?.toString(),
    // Absent (an older server) reads as not exempt — the safe default, and
    // the same state as a person who simply is not the Lead Developer.
    screenMonitoringExempt: json['screen_monitoring_exempt'] == true,
    phoneIncomingPopup:
        (json['display'] as Map<String, dynamic>?)?['phone.incoming_popup'] !=
        false,
    timeClock: TimeClockStatus.fromJson(json['time_clock']),
  );
}

/// `/me`'s `time_clock` block — whether the chrome draws Clock in / Clock out.
class TimeClockStatus {
  const TimeClockStatus({
    required this.organizationEnabled,
    required this.canClock,
    required this.showButton,
    this.open,
    this.lock = false,
    this.scheduledEndAt,
    this.warningMinutes = 5,
    this.extensionMinutes = 30,
    this.today,
    this.afterHours = false,
    this.todayLabel = '',
  });

  /// Key missing on `/me`: an older server. No button.
  static const absent = TimeClockStatus(
    organizationEnabled: false,
    canClock: false,
    showButton: false,
  );

  final bool organizationEnabled;
  final bool canClock;

  /// `(org enabled && can_clock) || open != null` — Clock out stays if they
  /// are still in even when the organization has switched Clock in off.
  final bool showButton;
  final TimeClockOpen? open;

  /// The server's answer to "show the lock screen now" (design 2026-09-08
  /// §3.8). Never derived from permissions on this side.
  final bool lock;

  /// Effective end of the open shift after extensions, UTC. Null when today
  /// has no window, or the shift started after it.
  final DateTime? scheduledEndAt;

  final int warningMinutes;
  final int extensionMinutes;

  /// Today's window as wall clock in the schedule's own zone, for display.
  final TimeClockWindow? today;

  /// Past today's scheduled end. The lock screen then says that clocking in
  /// buys one extension, instead of "start your day" at 5 PM (2026-09-24).
  final bool afterHours;

  /// "Wednesday, September 30": the server's today, in the org's language.
  final String todayLabel;

  factory TimeClockStatus.fromJson(Object? json) {
    if (json is! Map) return absent;
    final map = json is Map<String, dynamic>
        ? json
        : Map<String, dynamic>.from(json);
    final openRaw = map['open'];
    final todayRaw = map['today'];
    final endRaw = map['scheduled_end_at'];
    final warning = map['warning_minutes'];
    final extension = map['extension_minutes'];

    return TimeClockStatus(
      organizationEnabled: map['organization_enabled'] == true,
      canClock: map['can_clock'] == true,
      showButton: map['show_button'] == true,
      open: openRaw is Map<String, dynamic>
          ? TimeClockOpen.fromJson(openRaw)
          : openRaw is Map
          ? TimeClockOpen.fromJson(Map<String, dynamic>.from(openRaw))
          : null,
      lock: map['lock'] == true,
      scheduledEndAt: endRaw is String
          ? DateTime.tryParse(endRaw)?.toUtc()
          : null,
      warningMinutes: warning is int ? warning : 5,
      extensionMinutes: extension is int ? extension : 30,
      today: todayRaw is Map
          ? TimeClockWindow.fromJson(Map<String, dynamic>.from(todayRaw))
          : null,
      afterHours: map['after_hours'] == true,
      todayLabel: '${map['today_label'] ?? ''}',
    );
  }
}

/// One day's window, wall clock in the schedule's own zone.
class TimeClockWindow {
  const TimeClockWindow({required this.start, required this.end});

  final String start;
  final String end;

  factory TimeClockWindow.fromJson(Map<String, dynamic> json) =>
      TimeClockWindow(
        start: json['start'] as String? ?? '',
        end: json['end'] as String? ?? '',
      );
}

class TimeClockOpen {
  const TimeClockOpen({required this.id});

  final String id;

  factory TimeClockOpen.fromJson(Map<String, dynamic> json) =>
      TimeClockOpen(id: json['id'] as String);
}

class OrganizationSummary {
  const OrganizationSummary({
    required this.id,
    required this.name,
    required this.franchiseLabel,
    required this.isReadOnly,
    this.address,
    this.addressLine1,
    this.addressLine2,
    this.city,
    this.state,
    this.postalCode,
  });

  final String id;
  final String name;

  /// Organizations rename "Franchise" (§8) — never hardcode the word in the UI.
  final String franchiseLabel;
  final bool isReadOnly;

  /// The business address a contract merges as organization.address.
  final String? address;
  final String? addressLine1;
  final String? addressLine2;
  final String? city;
  final String? state;
  final String? postalCode;

  factory OrganizationSummary.fromJson(Map<String, dynamic> json) =>
      OrganizationSummary(
        id: json['id'] as String,
        name: json['name'] as String,
        franchiseLabel: json['franchise_label'] as String? ?? 'Franchise',
        isReadOnly: json['is_read_only'] as bool? ?? false,
        address: json['address'] as String?,
        addressLine1: json['address_line1'] as String?,
        addressLine2: json['address_line2'] as String?,
        city: json['city'] as String?,
        state: json['state'] as String?,
        postalCode: json['postal_code'] as String?,
      );
}

class ViewScope {
  const ViewScope({
    required this.type,
    required this.id,
    required this.label,
    required this.path,
    required this.logoPath,
    required this.logoVersion,
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

  /// Moves when that logo does, including when it is the organization's and
  /// the organization's changes.
  final String logoVersion;

  /// 'square', 'wide', or null when there is no logo to show.
  final String? logoAspect;

  /// The last two segments — "Everdoors › Connecticut".
  ///
  /// Enough to disambiguate in a top bar without spending the width on a full
  /// chain; the whole path goes in the tooltip and on the settings screen,
  /// where there is room to be complete.
  String get shortPath => path.length <= 2
      ? path.join(' › ')
      : path.sublist(path.length - 2).join(' › ');

  String get fullPath => path.join(' › ');

  factory ViewScope.fromJson(Map<String, dynamic> json) => ViewScope(
    type: json['type'] as String? ?? 'organization',
    id: json['id'] as String?,
    label: json['label'] as String? ?? '',
    path: (json['path'] as List<dynamic>? ?? []).cast<String>(),
    logoPath: json['logo_path'] as String? ?? '/branding/organization',
    logoVersion: json['logo_version'] as String? ?? '',
    logoAspect: json['logo_aspect'] as String?,
  );
}

class CompanySummary {
  const CompanySummary({
    required this.id,
    required this.name,
    required this.parentCompanyId,
    this.isActive = true,
  });

  final String id;
  final String name;

  /// False once switched off in Settings › Organization. It stays in reach
  /// (and on that screen, to be switched back on) but is not somewhere to
  /// stand (Jose, 2026-10-15).
  final bool isActive;

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
    isActive: json['is_active'] != false,
  );
}

class FranchiseSummary {
  const FranchiseSummary({
    required this.id,
    required this.name,
    this.companyId,
    this.isActive = true,
  });

  final String id;
  final String name;
  final String? companyId;

  /// False once the place is switched off (see [CompanySummary.isActive]).
  final bool isActive;

  factory FranchiseSummary.fromJson(Map<String, dynamic> json) =>
      FranchiseSummary(
        id: json['id'] as String,
        name: json['name'] as String,
        companyId: json['company_id'] as String?,
        isActive: json['is_active'] != false,
      );
}

/// Platform-staff access held by the signed-in person (§4.2, revised).
class DevelopmentAccess {
  const DevelopmentAccess({required this.tier, required this.focusedAt});

  final String tier;

  /// The focused control-plane host. The Development section here is the same
  /// screens embedded in the CRM; dev.ezbizi.com is them on their own, which is
  /// what you want when that is all you are doing.
  final String focusedAt;

  bool get isLead => tier == 'lead_developer';

  String get tierLabel => isLead ? 'Lead Developer' : 'Developer';

  factory DevelopmentAccess.fromJson(Map<String, dynamic> json) =>
      DevelopmentAccess(
        tier: json['tier'] as String? ?? 'developer',
        focusedAt: json['focused_at'] as String? ?? 'https://dev.ezbizi.com',
      );
}
