import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'core/api_client.dart';
import 'core/contracts.dart';
import 'core/deployment.dart';
import 'core/files.dart';
import 'core/module_screens.dart';
import 'core/session.dart';
import 'core/staff_session.dart';
import 'core/theme.dart';
import 'core/ui_strings.dart';
import 'core/version_gate.dart';
import 'screens/development_screen.dart';
import 'screens/hr/onboarding_form.dart';
import 'screens/invitation_screen.dart';
import 'screens/login_screen.dart';
import 'screens/organization_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/reset_password_screen.dart';
import 'screens/signup_screen.dart';
import 'screens/settings_shell.dart';
import 'screens/staff/staff_login_screen.dart';
import 'screens/staff/staff_shell.dart';
import 'screens/update_required_screen.dart';
import 'widgets/build_stamp.dart';
import 'widgets/notification_bell.dart';
import 'widgets/scope_switcher.dart';
import 'widgets/user_avatar.dart';

void main() => runApp(const EzBiziApp());

class EzBiziApp extends StatefulWidget {
  const EzBiziApp({super.key});

  @override
  State<EzBiziApp> createState() => _EzBiziAppState();
}

class _EzBiziAppState extends State<EzBiziApp> {
  // Two clients, deliberately. A tenant token and a staff token must never sit
  // in the same place — the server refuses each on the other's endpoints, and
  // the client should not be trying it.
  late final ApiClient _api = ApiClient();
  late final Session _session = Session(_api);

  late final ApiClient _staffApi = ApiClient();
  late final StaffSession _staffSession = StaffSession(_staffApi);

  /// Decided by the host the browser asked for, once, at launch. There is no
  /// switching between the two faces at runtime — a customer host has no staff
  /// form to reach and the control-plane host has no customer app behind it.
  final AppFace _face = Deployment.face();

  /// Whether the server has refused this build for being too old.
  ///
  /// One gate across BOTH clients. Being out of date is a fact about the binary,
  /// not about a session, so a customer and a staff member running the same
  /// stale build must both be told — see core/version_gate.dart.
  final VersionGate _versionGate = VersionGate();

  @override
  void initState() {
    super.initState();
    _session.addListener(() => setState(() {}));
    _staffSession.addListener(() => setState(() {}));

    _versionGate
      ..addListener(() => setState(() {}))
      // Once, for every ApiClient in the process — including the ones the
      // Development section and staff enrolment build for themselves, which a
      // per-client registration here silently missed.
      ..install();

    // A refresh used to sign people out, because the token lived only in
    // memory. It is picked back up here and verified before it is trusted.
    if (_face == AppFace.customer) {
      _session.restore();
    } else {
      _staffSession.restore();
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'EzBizi',
      debugShowCheckedModeBanner: false,
      theme: EzBiziTheme.light(),
      darkTheme: EzBiziTheme.dark(),
      /*
       * The signed-in person's choice (§2.1 category 6), falling back to the
       * device before anyone has signed in — there is nobody to have a
       * preference yet at that point.
       *
       * Distinct from §12's theming decision, which is about an ORGANIZATION's
       * brand: its colours and logo. Somebody preferring dark mode at night is
       * not a branding decision and does not belong to their employer.
       */
      themeMode: EzBiziTheme.modeFor(_session.me?.theme ?? 'system'),
      home: _home(),
    );
  }

  /// Set once a reset link has been dealt with, so "Back to sign in" does not
  /// bounce straight back into the reset screen off the same URL.
  bool _resetHandled = false;

  /// The same latch for an invitation. Without it, accepting would sign the
  /// person in and then be handed straight back the token still sitting in the
  /// address bar — a spent one, so they would land on "this link has already
  /// been used" seconds after it worked.
  bool _inviteHandled = false;

  /// Whether the signup form is being shown instead of the sign-in one.
  ///
  /// The public screens are swapped here rather than pushed, because there is no
  /// router — the same way the reset screen is reached. Kept below the staff
  /// return in _home() so the control plane cannot show it whatever this holds:
  /// §4.2 gives platform staff their own accounts, created by staff, and nobody
  /// self-registers there.
  bool _signingUp = false;

  Widget _home() {
    /*
     * FIRST, above even the reset link, and it is the only thing that belongs
     * above it.
     *
     * Everything below this line assumes the app can talk to the server. Once
     * the server has said this build is too old, none of it can: the reset form
     * would submit into a 426, the invitation screen could not fetch what the
     * invitation is for, and the sign-in form would refuse a correct password
     * with no visible reason. Each of those is a worse version of the same
     * message, delivered later and less clearly.
     *
     * Note that this cannot delay anybody who is fine. Nothing is checked in
     * advance and no request is made to find out — the gate only ever holds a
     * refusal the server has already sent, so on the overwhelmingly common path
     * this is one null check.
     */
    final blocked = _versionGate.notice;

    if (blocked != null) {
      return UpdateRequiredScreen(
        notice: blocked,
        onTryAgain: _versionGate.clear,
        // Offered only when there is a session to end. A sign-out button on a
        // sign-in screen is a control that does nothing to somebody who is
        // already out, which reads as the app being confused about who they are.
        //
        // Local, and it must be: signing out asks the server to revoke a token,
        // and that request would come straight back 426 like every other. So the
        // token is dropped on this side regardless. A stale token on a server
        // that will not accept this client anyway is not a risk worth trapping
        // somebody on this screen over.
        onSignOut: _signedInSomewhere ? _signOutEverywhere : null,
      );
    }

    // Before anything else, including the restore gate: somebody following a
    // reset link is by definition not signed in, and making them wait on a
    // session check to see a form that does not need one is the kind of delay
    // that gets read as a broken link.
    final token = _resetHandled ? null : Deployment.resetToken();

    if (token != null) {
      return ResetPasswordScreen(
        api: _api,
        token: token,
        onDone: () {
          Files.clearQuery();
          setState(() => _resetHandled = true);
        },
      );
    }

    // Held until a stored token has been checked. Without this the sign-in
    // screen flashes for a moment on every refresh, which reads as being
    // logged out and then not.
    final restoring = _face == AppFace.staff
        ? !_staffSession.restored
        : !_session.restored;

    if (restoring) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (_face == AppFace.staff) {
      return _staffSession.signedIn
          ? StaffShell(staff: _staffSession)
          : StaffLoginScreen(staff: _staffSession);
    }

    // An invitation link, swapped in the way the reset screen is — there is no
    // router, so the public screens are chosen here.
    //
    // Two things put it BELOW the staff branch and the restore gate, where the
    // reset screen sits above both:
    //
    //   - §4.2. Platform staff have their own accounts in their own control
    //     plane, created by staff. Nobody is invited into it, so the customer
    //     face is the only face that offers this;
    //   - accepting SIGNS THEM IN. A restore still in flight would be racing to
    //     put a stored token back over the one acceptance just adopted. The
    //     screen fetches what the invitation is for before it can draw anything
    //     anyway, so waiting for the restore costs nothing anybody can see.
    //
    // Above the signed-in branch, though: somebody who follows an invitation
    // while already signed in elsewhere means to join the place that invited
    // them, and one account belongs to one organization (§4).
    final invite = _inviteHandled ? null : Deployment.inviteToken();

    if (invite != null) {
      return InvitationScreen(
        api: _api,
        session: _session,
        token: invite,
        onDone: () {
          Files.clearQuery();
          setState(() => _inviteHandled = true);
        },
      );
    }

    // Before the signup branch, so a successful signup lands in the app rather
    // than back on the form it just submitted — the session adopted there
    // notifies this widget, and _signingUp is still set when it rebuilds.
    if (_session.signedIn) {
      // And cleared here, because ordering alone only MASKS it. The flag was
      // set on the way in and never unset on success, so it stayed true for the
      // life of the app: the moment the session ended — signing out, or a token
      // that stopped being accepted — the branch below took over and the person
      // who had just created a business was shown "Create your account" with no
      // sign-in field anywhere. Filling it in built a SECOND organization with
      // the same name and the same administrator, which nothing refuses (an
      // address may belong to several organizations by design, and the slug
      // quietly becomes `-2`).
      //
      // A plain assignment rather than setState: this build is already showing
      // the shell, so nothing needs to rebuild. The flag only decides what a
      // LATER build does, and by then it is right.
      _signingUp = false;

      return HomeShell(api: _api, session: _session);
    }

    if (_signingUp) {
      return SignupScreen(
        api: _api,
        session: _session,
        onSignIn: () => setState(() => _signingUp = false),
      );
    }

    return LoginScreen(
      api: _api,
      session: _session,
      onCreateAccount: () => setState(() => _signingUp = true),
    );
  }

  /// Either face has somebody signed in.
  ///
  /// Asked rather than assumed from [_face], because the update screen is shown
  /// above the branch that picks a face and therefore has no business knowing
  /// which one it is standing in front of.
  bool get _signedInSomewhere => _session.signedIn || _staffSession.signedIn;

  /// End whichever session exists. Both, without checking, because signing out
  /// of one while the other lingers is how somebody ends up believing they have
  /// left — the same reasoning as TokenStore.clear, which both of these call.
  void _signOutEverywhere() {
    _session.signOut();
    _staffSession.signOut();
  }
}

/// The signed-in shell.
///
/// Its business destinations are not written down here. They are built from the
/// `modules` block on `/me`, which answers both gates in one list: whether the
/// PLATFORM permits a module for this organization (§13) and whether this
/// person's role holds its view permission (§4). The sidebar renders exactly
/// those marked reachable, in the order the server sent them.
///
/// Three things that were wrong while the list was compiled in:
///
///   - a technician with `customers.view` and nothing else saw five tabs, four
///     of which answered 403 the moment they were tapped;
///   - the platform kill switch was built to be used mid-incident, and left
///     every tab drawn with every action inside it failing;
///   - "which tabs do I see" had two answers, the server's and Dart's, and only
///     one of them was enforced.
///
/// See core/module_screens.dart for the only part that stays in code — which
/// widget draws which module — and why that is not the same list.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

/// Which group holds the current selection. The profile is one of them: it is
/// not a rail destination, but it is somewhere the shell can be pointed.
enum _Rail { modules, pinned, staff, profile }

class _HomeShellState extends State<HomeShell> {
  _Rail _rail = _Rail.modules;

  /// WHICH destination within that group, by id — a module key, or a pinned
  /// destination's own name. Null means "whatever comes first".
  ///
  /// This used to be one `int` across all three groups, with a comment warning
  /// that the arithmetic was delicate. That warning was written about lists
  /// whose lengths were fixed when the app was compiled. The business
  /// destinations are now assembled per person, and the list can change length
  /// WHILE somebody is looking at it: EzBizi switches a module off mid-incident
  /// (§13), or an administrator edits the role they hold.
  ///
  /// Under an index, that is silent and wrong. Every destination after the one
  /// that vanished shifts up a slot, so the person is quietly moved to a
  /// different screen; if it was the last one, the index points past the end
  /// and the next frame throws — a blank page with no way back.
  ///
  /// A name cannot shift, and "the thing you were looking at is gone" is a
  /// question with an obvious answer rather than an off-by-one. See `_resolve`
  /// in build().
  String? _selected;

  void _show(_Rail rail, String id) => setState(() {
    _rail = rail;
    _selected = id;
  });

  /// "Your account" is reached from the avatar, not from a rail, so it has no
  /// id of its own.
  void _showProfile() => setState(() {
    _rail = _Rail.profile;
    _selected = null;
  });

  Future<void> _signOut() async {
    await widget.api.post('/auth/logout', {});
    widget.session.signOut();
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final wide = MediaQuery.sizeOf(context).width >= 900;

    // Labels beside icons, unless this person has collapsed it or prefers the
    // stacked "blocks" shape. Stored, so it survives a reload — a sidebar that
    // springs back open every time is a toggle nobody uses twice.
    final expanded = me.navigationStyle == 'list' && !me.navigationCollapsed;

    // The business destinations, as the server describes them.
    //
    // Order is the server's, and so is every label — the module registry that
    // decides what a module IS decides where it sits and what it is called,
    // rather than a second opinion in Dart drifting away from it. Dashboard
    // still leads the list, but because the server puts it there rather than
    // because this file does.
    //
    // Two consequences, both deliberate:
    //
    //   - a module the server does not declare gets no destination, however
    //     well this build knows how to draw it. Silence is not permission, and
    //     there is no label to print for something we were never told about;
    //   - a module the server DOES declare that this build has no screen for is
    //     skipped rather than drawn as a tab that opens nothing.
    //
    // See core/module_screens.dart.
    final destinations = <Destination>[
      // Their own paperwork, first, while it is unfinished.
      //
      // The one destination here that is not a module, and it earns the
      // exception. A new starter accepts an invitation, which signs them in,
      // and the form their employer began for them is the only thing they are
      // in this app to do — but Human Resources is the EMPLOYER's module and
      // their role does not reach it, so without this they land in a shell with
      // nothing in it and no way to find their own contract.
      //
      // It is not compiled in: it exists exactly when `/me` says there is an
      // onboarding about this person, and it disappears when the server stops
      // saying so. Being first is what makes it the landing page — `railIndex`
      // falls back to the first destination — so accepting an invitation puts
      // the form on screen without a link to keep or a step to remember, and
      // everything else is still one click away.
      if (me.onboardingId case final mine?)
        Destination(
          'Your paperwork',
          Icons.assignment_outlined,
          OnboardingForm(
            api: widget.api,
            onboardingId: mine,
            // The employee cannot read the Contracts catalogue — it belongs to
            // the module they have no permission for — so the words for a kind
            // of question travel with the onboarding itself. See
            // OnboardingForm._vocabulary.
            vocabulary: ContractVocabulary.none,
            formats: me.display,
            // Signing it finishes it, and the destination should go when it
            // does. /me is where its existence is declared, so /me is what has
            // to be asked again.
            onChanged: widget.session.refresh,
          ),
          // Not a module key, and spelled so it cannot collide with one: module
          // keys are bare words (`hr`, `jobs`) and this is the shell's own name
          // for a destination the module registry knows nothing about.
          id: 'my-onboarding',
        ),
      for (final module in me.reachableModules)
        if (moduleScreens[module.key] case final screen?)
          Destination(
            module.label,
            screen.icon,
            screen.build(widget.api, widget.session),
            id: module.key,
          ),
    ];

    // Pinned to the bottom, under a rule and the empty space.
    //
    // Neither is a place you go to do the work. Settings is where you go to
    // change how the work behaves, and Development is the platform underneath
    // the product rather than part of it (§4.2) — both belong away from the
    // list of things you actually open all day.
    final pinned = <Destination>[
      // Above Settings, because it is the thing Settings configures: every
      // scope in that screen is a row in this one.
      Destination(
        'Organization',
        Icons.hub_outlined,
        OrganizationScreen(api: widget.api, session: widget.session),
      ),
      // Settings, and the organization's roles behind a second tab of it —
      // see settings_shell.dart for why roles belong here rather than in the
      // list above.
      Destination(
        UiStrings.t('shell.settings'),
        Icons.settings_outlined,
        SettingsShell(api: widget.api, session: widget.session),
      ),
    ];

    // Development gets its own group, below its own rule.
    //
    // It was the third row of `pinned`, which said it was the same KIND of thing
    // as Organization and Settings. It is not: those configure the business and
    // every administrator has them. This is the platform underneath the product
    // (§4.2), it exists only when the server says this person is also EzBizi
    // staff, and most people signed into this app will never see it at all.
    //
    // A third rail rather than a divider between destinations, for the reason
    // the second one exists: NavigationRail cannot put a rule between its own
    // items, and the alternative is a hand-drawn row that does not match the
    // real ones. That was tried and produced four separate defects.
    final staffOnly = <Destination>[
      if (me.development != null)
        Destination(
          'Development',
          Icons.terminal_outlined,
          DevelopmentScreen(session: widget.session),
        ),
    ];

    // "Your account" is deliberately NOT a rail destination. It is reached from
    // the avatar in the top bar, where every other app puts it — it is about the
    // person, not about the business, and a tab beside Customers and Jobs
    // implies it is one more thing to work on.
    //
    // Resolved from the NAME held in `_selected`, every build. Each rail is
    // asked only when it is the one holding the selection, so exactly one of
    // these can be non-null — which is what NavigationRail's own assert wants.
    int? resolve(List<Destination> group, _Rail rail) {
      if (_rail != rail) return null;

      final at = group.indexWhere((d) => d.id == _selected);

      return at < 0 ? null : at;
    }

    final showingProfile = _rail == _Rail.profile;
    final pinnedIndex = resolve(pinned, _Rail.pinned);
    final staffIndex = resolve(staffOnly, _Rail.staff);

    // Falls back to the first business destination whenever the selection no
    // longer names anything: the module was switched off under them, their role
    // stopped reaching it, or a staff link was removed and the group it was in
    // has gone. Null when there is nothing to fall back to, which is a real
    // state — somebody whose role reaches no module at all.
    final railIndex =
        (showingProfile || pinnedIndex != null || staffIndex != null)
        ? null
        : resolve(destinations, _Rail.modules) ??
              (destinations.isEmpty ? null : 0);

    return Scaffold(
      // The top bar belongs to the CHROME, not to the page.
      //
      // In a mixed mode it takes the sidebar's theme, so the bar and the
      // sidebar read as one continuous frame around a page in the other theme.
      // Siding it with the page instead put a seam across the top of the
      // sidebar, which made the two halves look misaligned rather than
      // deliberately different.
      //
      // PreferredSize because Scaffold.appBar demands one and a Theme is not:
      // the height is the toolbar plus the one-pixel rule below it.
      appBar: PreferredSize(
        preferredSize: const Size.fromHeight(kToolbarHeight + 1),
        child: _maybeThemed(
          EzBiziTheme.navigationOverride(me.theme),
          builder: (context) => AppBar(
            // A rule under the top bar.
            //
            // The bar and the rail were both plain surface, so on a light theme
            // they met as one continuous white field and the sidebar read as part
            // of the header. One pixel fixes it; nothing else would.
            bottom: PreferredSize(
              preferredSize: const Size.fromHeight(1),
              child: Container(height: 1, color: EzBiziTheme.edge(context)),
            ),
            leading: wide
                ? IconButton(
                    tooltip: expanded
                        ? 'Collapse the sidebar'
                        : 'Expand the sidebar',
                    icon: Icon(expanded ? Icons.menu_open : Icons.menu),
                    // Written through the same path as every other preference, so
                    // it is the server's answer that wins and the value is there on
                    // the next device somebody signs in from.
                    onPressed: () => widget.session.setPreference(
                      'navigation.collapsed',
                      !me.navigationCollapsed,
                    ),
                  )
                : null,
            titleSpacing: 0,
            /*
         * The wordmark sits beside the collapse button, in the header band above
         * the sidebar — the product's identity belongs to the application
         * chrome, not to one column of it.
         *
         * The ORGANIZATION does not live here. It is the sidebar's subject:
         * every destination below it is scoped to that organization, so it is
         * stated at the top of the thing it scopes.
         *
         * Except when the sidebar is collapsed, when there is no room for it
         * there — then it appears here instead. Which one is showing is decided
         * by where there is space to say it, and it is never said twice.
         */
            // The organization's NAME is gone from here, replaced by the switcher
            // that shows where you are standing. Two controls saying almost the
            // same thing — a fixed name and a changeable view — is how somebody
            // ends up sure they are in one place while the lists show another.
            title: Row(
              children: [
                EzBiziTheme.wordmark(context, size: 20),
                const SizedBox(width: 14),
                Container(
                  width: 1,
                  height: 18,
                  color: Theme.of(context).dividerColor,
                ),
                const SizedBox(width: 14),
                // The brand first, then the control. Reading order matches what the
                // eye needs: whose business am I in, then where in it.
                ScopeSwitcher.logo(widget.api, widget.session),
                const SizedBox(width: 12),
                Flexible(
                  child: ScopeSwitcher(
                    api: widget.api,
                    session: widget.session,
                  ),
                ),
              ],
            ),
            actions: [
              // Light / dark, one tap, from anywhere.
              //
              // It is the same preference the account screen holds (§2.1 category
              // 6), written through the same path — this is a shortcut to it, not a
              // second setting. Two places that both claim to control the theme and
              // disagree is worse than one place that is hard to find.
              //
              // Cycles system -> light -> dark rather than toggling two states: the
              // default is "match my device", and a two-way switch would silently
              // throw that away the first time anybody touched it.
              IconButton(
                tooltip: switch (me.theme) {
                  'light' => 'Light — switch to dark',
                  'dark' => 'Dark — match my device',
                  _ => 'Matching your device — switch to light',
                },
                icon: Icon(switch (me.theme) {
                  'light' => Icons.light_mode_outlined,
                  'dark' => Icons.dark_mode_outlined,
                  _ => Icons.brightness_auto_outlined,
                }),
                onPressed: () => widget.session.setPreference(
                  'appearance.theme',
                  switch (me.theme) {
                    'system' => 'light',
                    'light' => 'dark',
                    _ => 'system',
                  },
                ),
              ),
              NotificationBell(api: widget.api),
              // The organization requires a second factor and this person has
              // not set one up (§6). Every screen except their own account is
              // answering 403 until they do, so the chrome has to say why and
              // point at the one place that fixes it — otherwise the app simply
              // looks broken.
              //
              // A chip beside the read-only one rather than a modal: same kind
              // of statement about the state of the session, and it stays
              // visible while they act on it.
              if (me.twoFactorEnrolmentRequired)
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: ActionChip(
                    avatar: const Icon(Icons.shield_outlined, size: 16),
                    label: const Text('Set up two-factor'),
                    tooltip: 'Your organization requires two-factor '
                        'authentication. The rest of the app is on hold until '
                        'your authenticator is set up.',
                    backgroundColor: Theme.of(
                      context,
                    ).colorScheme.errorContainer.withValues(alpha: 0.4),
                    onPressed: _showProfile,
                  ),
                ),
              if (me.organization.isReadOnly)
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: Chip(
                    avatar: const Icon(Icons.lock_outline, size: 16),
                    label: const Text('Read only'),
                    // §13 — stated up front, so a failed save is never a surprise.
                    backgroundColor: Theme.of(
                      context,
                    ).colorScheme.errorContainer.withValues(alpha: 0.4),
                  ),
                ),
              PopupMenuButton<String>(
                tooltip: '${UiStrings.t('shell.your_account')} — ${me.name} (${me.email})',
                // The photo, not just the initial. It was an initial always: the
                // profile screen was the only place a picture appeared, and even
                // there it did not, because it was fetched without the token the
                // endpoint requires. This is the same widget that card uses.
                icon: UserAvatar(
                  api: widget.api,
                  userId: me.id,
                  name: me.name,
                  radius: 15,
                  hasAvatar: me.hasAvatar,
                ),
                itemBuilder: (_) => [
                  PopupMenuItem(
                    enabled: false,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          me.name,
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        Text(me.email, style: const TextStyle(fontSize: 12)),
                        if (me.roleName != null)
                          Text(
                            me.roleName!,
                            style: const TextStyle(fontSize: 12),
                          ),
                      ],
                    ),
                  ),
                  const PopupMenuDivider(),
                  PopupMenuItem(value: 'me', child: Text(UiStrings.t('shell.your_account'))),
                  PopupMenuItem(value: 'out', child: Text(UiStrings.t('shell.sign_out'))),
                ],
                onSelected: (v) {
                  if (v == 'out') {
                    _signOut();
                    return;
                  }

                  _showProfile();
                },
              ),
              const SizedBox(width: 8),
            ],
          ),
        ),
      ),
      body: Row(
        children: [
          if (wide)
            // Two shapes, and the default is the list.
            //
            // A list reads faster: the eye scans labels down a column, and the
            // icons are a secondary cue rather than the thing being read.
            // Blocks fit more in less width and some people prefer them, so
            // they stay available — as the user's own preference, not an
            // opinion imposed by whoever wrote this.
            _maybeThemed(
              // In a mixed mode the sidebar runs on the opposite theme to the
              // page. Wrapping only the sidebar — rather than building two
              // MaterialApps or threading colours by hand — means every widget
              // inside it, including NavigationRail's own indicator and hover
              // colours, resolves against the right scheme without knowing that
              // anything unusual is happening.
              //
              // The top bar takes this same override, so the chrome is one
              // theme and the page is the other.
              EzBiziTheme.navigationOverride(me.theme),
              builder: (context) => NavigationSidebar(
                expanded: expanded,
                width: me.navigationWidth,
                destinations: destinations,
                pinned: pinned,
                staffOnly: staffOnly,
                selectedIndex: railIndex,
                pinnedIndex: pinnedIndex,
                staffIndex: staffIndex,
                onSelected: (i) => _show(_Rail.modules, destinations[i].id),
                onPinnedSelected: (i) => _show(_Rail.pinned, pinned[i].id),
                onStaffSelected: (i) => _show(_Rail.staff, staffOnly[i].id),
                // Written through the same path as every other preference, once
                // the drag settles.
                onResized: (w) =>
                    widget.session.setPreference('navigation.width', w.round()),
              ),
            ),
          Expanded(
            // Keyed by the current view, so changing it REMOUNTS the screen and
            // its initState fetches again.
            //
            // Every screen loads once in initState — the ordinary Flutter
            // shape — so without this the switcher changed the label and left
            // Connecticut's jobs on the page while claiming to be in Texas.
            // Making the scope part of the screen's identity is the fix that
            // needs no screen to know the switcher exists.
            child: KeyedSubtree(
              key: ValueKey('${me.scope.type}:${me.scope.id}'),
              // Their own account is the last fallback, and the only honest
              // one: it is the single screen that is theirs by definition and
              // cannot answer 403. It is what somebody whose role reaches no
              // module at all lands on, rather than an empty frame.
              child: pinnedIndex != null
                  ? pinned[pinnedIndex].screen
                  : staffIndex != null
                  ? staffOnly[staffIndex].screen
                  : (showingProfile || railIndex == null)
                  ? ProfileScreen(api: widget.api, session: widget.session)
                  : destinations[railIndex].screen,
            ),
          ),
        ],
      ),
      /*
       * Narrow layout. Everything gets an ordinary tab, in the same order.
       *
       * The grouping the sidebar draws with rules cannot be expressed here —
       * NavigationBar has no separators — and hiding the tail would make
       * Settings, and Development for staff, unreachable on a phone. Order is
       * the only signal available, so order is what carries it.
       *
       * One flat list here, so the tab index is a position in it and nothing
       * else. NavigationBar demands a selected index in range and at least two
       * destinations; the pinned pair guarantees both, whatever the modules
       * above them come to.
       *
       * The profile has no tab — it is reached from the avatar, on this layout
       * as on the wide one — so while it is showing, the first tab carries the
       * highlight. A bar cannot say "none of these".
       */
      bottomNavigationBar: wide
          ? null
          : Builder(
              builder: (context) {
                final tabs = [...destinations, ...pinned, ...staffOnly];

                return NavigationBar(
                  selectedIndex: pinnedIndex != null
                      ? destinations.length + pinnedIndex
                      : staffIndex != null
                      ? destinations.length + pinned.length + staffIndex
                      : railIndex ?? 0,
                  onDestinationSelected: (i) {
                    if (i < destinations.length) {
                      _show(_Rail.modules, tabs[i].id);
                    } else if (i < destinations.length + pinned.length) {
                      _show(_Rail.pinned, tabs[i].id);
                    } else {
                      _show(_Rail.staff, tabs[i].id);
                    }
                  },
                  destinations: [
                    for (final d in tabs)
                      NavigationDestination(icon: Icon(d.icon), label: d.label),
                  ],
                );
              },
            ),
    );
  }
}

/// Wrap in a different theme, or do not wrap at all.
///
/// Null means the two halves of the shell match, and an identical Theme pushed
/// down the tree would be a rebuild that changes nothing.
///
/// ── Why a builder and not a child ────────────────────────────────
///
/// It took a `child`, and that quietly did not work for anything that read the
/// theme while being CONSTRUCTED rather than while being laid out. `AppBar(...)`
/// and all of its arguments are built in the enclosing method's context — which
/// is above this Theme — so `EzBiziTheme.wordmark(context)` and
/// `Theme.of(context).dividerColor` in the title row resolved against the PAGE's
/// theme, not the chrome's.
///
/// In a mixed mode that is the opposite theme. The wordmark's second half is
/// drawn in the on-surface colour, so on a light bar it came out white: "Ez" and
/// then nothing. The divider beside it was wrong in the same direction and just
/// looked faint.
///
/// A builder makes the override reach everything by construction, so no widget
/// placed in that bar in future has to know any of this.
Widget _maybeThemed(ThemeData? theme, {required WidgetBuilder builder}) =>
    theme == null
    ? Builder(builder: builder)
    : Theme(
        data: theme,
        child: Builder(builder: builder),
      );

/// The sidebar: the modules, a rule, then the pinned group.
///
/// A widget rather than inline in HomeShell's build so it can be laid out in a
/// test at a real size. Its subtle failure — a Divider with no width to inherit
/// — depends entirely on the constraints it is given, which is not something
/// reading the code reliably catches.
class NavigationSidebar extends StatefulWidget {
  const NavigationSidebar({
    super.key,
    required this.expanded,
    required this.width,
    required this.destinations,
    required this.pinned,
    required this.staffOnly,
    required this.selectedIndex,
    required this.pinnedIndex,
    required this.staffIndex,
    required this.onSelected,
    required this.onPinnedSelected,
    required this.onStaffSelected,
    this.onResized,
  });

  final bool expanded;

  /// The stored width, honoured only while expanded — collapsed, the rail is
  /// its icon column and nothing else, at any setting.
  final double width;

  final List<Destination> destinations;
  final List<Destination> pinned;

  /// Development, when this person is also EzBizi staff (§4.2). Its own group
  /// because it is a different kind of thing, not a third setting.
  final List<Destination> staffOnly;

  /// Exactly one of these is non-null: the selection lives in one rail or the
  /// other, never both.
  final int? selectedIndex;
  final int? pinnedIndex;
  final int? staffIndex;

  final ValueChanged<int> onSelected;
  final ValueChanged<int> onPinnedSelected;
  final ValueChanged<int> onStaffSelected;

  /// Called once, on release. Not on every drag frame — that would be a request
  /// per pixel of travel.
  final ValueChanged<double>? onResized;

  static const double collapsedWidth = 80;
  static const double minWidth = 180;
  static const double maxWidth = 420;

  @override
  State<NavigationSidebar> createState() => _NavigationSidebarState();
}

class _NavigationSidebarState extends State<NavigationSidebar> {
  /// The width during a drag, before it is committed. Null when not dragging.
  double? _dragWidth;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final width = widget.expanded
        ? (_dragWidth ?? widget.width)
        : NavigationSidebar.collapsedWidth;

    final sidebar = SizedBox(
      // Explicit, not intrinsic. A Row gives its non-flex children
      // `maxWidth: infinity`, and the Divider below has no width of its own —
      // under an unbounded width its line collapses to zero and it draws
      // nothing at all while still occupying its vertical space. A real width
      // is also what makes the rule's inset a proportion rather than a guess.
      width: width,
      child: DecoratedBox(
        decoration: BoxDecoration(
          // Tinted and edged. The rail and the top bar were both plain surface,
          // so on a light theme they met as one continuous field and the
          // sidebar read as part of the header.
          color: scheme.surfaceContainerLow,
          border: Border(right: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        // TWO rails, not one rail with a hand-drawn footer.
        //
        // Settings and Development used to be a Material + InkWell built to
        // look like destinations. It never quite did: the hover lit the whole
        // row instead of the indicator pill, the icons sat 20px left of the
        // column above them, there was no selection animation, and every change
        // to the rail's width moved them again. Four defects, one cause — they
        // were not destinations.
        //
        // NavigationRail has no minimum destination count, so the pinned group
        // is simply another rail. Hover, splash, indicator shape, selection
        // animation, icon column, label style and row height are identical
        // because they are the same widget, not because constants were copied
        // out of its source.
        child: Column(
          children: [
            Expanded(
              child: _rail(
                context,
                expanded: widget.expanded,
                width: width,
                destinations: widget.destinations,
                selectedIndex: widget.selectedIndex,
                onSelected: widget.onSelected,
              ),
            ),

            // The rule that says the group below is a different kind of thing:
            // places you go to change how the work behaves, rather than places
            // you go to do it.
            //
            // Inset to about 70% of the column. Edge to edge it reads as the
            // bottom of the sidebar — a border, the same language as the right
            // edge beside it. Pulled in, it reads as a break WITHIN one list,
            // which is what it is.
            Divider(
              height: 17,
              thickness: 1,
              indent: width * 0.15,
              endIndent: width * 0.15,
              color: scheme.outlineVariant,
            ),

            // IntrinsicHeight because a rail's root column is mainAxisSize.max
            // — it expects to own the full height. This asks it for exactly the
            // height its destinations need.
            IntrinsicHeight(
              child: _rail(
                context,
                expanded: widget.expanded,
                width: width,
                destinations: widget.pinned,
                selectedIndex: widget.pinnedIndex,
                onSelected: widget.onPinnedSelected,
              ),
            ),

            // And a third group, for staff only.
            //
            // Development sat in the group above, which said it was the same
            // kind of thing as Organization and Settings. Those configure the
            // business and every administrator has them; this is the platform
            // underneath the product (§4.2), it appears only when the server
            // says this person is also EzBizi staff, and most people signed
            // into this app will never see it.
            //
            // The whole group disappears with its rule when they are not, so
            // nobody is left looking at a divider with nothing under it.
            if (widget.staffOnly.isNotEmpty) ...[
              Divider(
                height: 17,
                thickness: 1,
                indent: width * 0.15,
                endIndent: width * 0.15,
                color: scheme.outlineVariant,
              ),
              IntrinsicHeight(
                child: _rail(
                  context,
                  expanded: widget.expanded,
                  width: width,
                  destinations: widget.staffOnly,
                  selectedIndex: widget.staffIndex,
                  onSelected: widget.onStaffSelected,
                ),
              ),
            ],

            // The version, at the very foot of the sidebar.
            //
            // Only when expanded: at 80px it would truncate, and half a build
            // id is worse than none — it invites people to compare the wrong
            // characters and conclude they are up to date when they are not.
            if (widget.expanded)
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 10, 12, 0),
                child: BuildStamp(),
              ),
            const SizedBox(height: 12),
          ],
        ),
      ),
    );

    // Collapsed has one right width and dragging it would be a fight with the
    // toggle. The handle only exists when there is a width to change.
    if (!widget.expanded || widget.onResized == null) return sidebar;

    return Stack(
      children: [
        sidebar,
        Positioned(
          top: 0,
          bottom: 0,
          // Straddles the border rather than sitting beside it, so the cursor
          // changes where the eye says the edge is.
          right: -3,
          width: 7,
          child: MouseRegion(
            cursor: SystemMouseCursors.resizeLeftRight,
            child: GestureDetector(
              behavior: HitTestBehavior.translucent,
              onHorizontalDragStart: (_) => setState(() => _dragWidth = width),
              onHorizontalDragUpdate: (d) => setState(() {
                _dragWidth = ((_dragWidth ?? width) + d.delta.dx).clamp(
                  NavigationSidebar.minWidth,
                  NavigationSidebar.maxWidth,
                );
              }),
              onHorizontalDragEnd: (_) {
                final settled = _dragWidth;
                setState(() => _dragWidth = null);
                if (settled != null) widget.onResized!(settled);
              },
              // Double-click puts it back, because a resize handle without one
              // means somebody who has dragged it somewhere odd has to find
              // their way back by feel.
              child: GestureDetector(
                onDoubleTap: () => widget.onResized!(220),
                behavior: HitTestBehavior.translucent,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// One rail, built one way.
///
/// Both the main group and the pinned group go through here, so there is no
/// route by which the two could end up with different widths, label types or
/// indicator behaviour. Getting that wrong is precisely what the hand-rolled
/// footer did.
Widget _rail(
  BuildContext context, {
  required bool expanded,
  required double width,
  required List<Destination> destinations,
  required int? selectedIndex,
  required ValueChanged<int> onSelected,
  Widget? leading,
}) {
  return NavigationRail(
    backgroundColor: Colors.transparent,
    // null when an off-rail screen is showing, or when the OTHER rail holds the
    // selection. The assert allows it; a stale index would throw.
    selectedIndex: selectedIndex,
    onDestinationSelected: onSelected,
    extended: expanded,
    labelType: expanded ? null : NavigationRailLabelType.none,
    minWidth: NavigationSidebar.collapsedWidth,
    // The rail fills the column it is given. minExtendedWidth is asserted to be
    // at least minWidth, so a drag below the icon column would otherwise throw
    // rather than simply stop.
    minExtendedWidth: math.max(width, NavigationSidebar.collapsedWidth),
    leading: leading,
    destinations: destinations
        .map(
          (d) => NavigationRailDestination(
            icon: Icon(d.icon),
            label: Text(d.label),
          ),
        )
        .toList(),
  );
}

class Destination {
  const Destination(this.label, this.icon, this.screen, {String? id})
    : id = id ?? label;

  /// What the shell remembers when this one is selected.
  ///
  /// For a module it is the module KEY, which is stable in a way the label is
  /// not: the label is the server's wording and an organization renaming things
  /// (§8) must not move somebody to a different screen. The fixed destinations
  /// have no key of their own and use their label, which is fixed with them.
  final String id;

  final String label;
  final IconData icon;
  final Widget screen;
}
