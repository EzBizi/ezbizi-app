import 'package:flutter/material.dart';
import 'core/api_client.dart';
import 'core/deployment.dart';
import 'core/files.dart';
import 'core/session.dart';
import 'core/staff_session.dart';
import 'core/theme.dart';
import 'screens/customers_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/data_screen.dart';
import 'screens/development_screen.dart';
import 'screens/jobs_screen.dart';
import 'screens/login_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/reset_password_screen.dart';
import 'screens/workflows_screen.dart';
import 'screens/settings_screen.dart';
import 'screens/staff/staff_login_screen.dart';
import 'screens/staff/staff_shell.dart';
import 'widgets/notification_bell.dart';

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

  @override
  void initState() {
    super.initState();
    _session.addListener(() => setState(() {}));
    _staffSession.addListener(() => setState(() {}));

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
      themeMode: switch (_session.me?.theme) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        _ => ThemeMode.system,
      },
      home: _home(),
    );
  }

  /// Set once a reset link has been dealt with, so "Back to sign in" does not
  /// bounce straight back into the reset screen off the same URL.
  bool _resetHandled = false;

  Widget _home() {
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

    return _session.signedIn
        ? HomeShell(api: _api, session: _session)
        : LoginScreen(api: _api, session: _session);
  }
}

/// The signed-in shell.
///
/// Deliberately thin: one destination per module, added as modules are built
/// (§14). Settings is the only real one so far — anything else would be a
/// placeholder pretending to be a feature.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

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

    // Dashboard first, deliberately.
    //
    // It is the screen somebody opens the app to look at — what is happening
    // today — and the one they return to between tasks. Everything below it is
    // a place you go to do a specific thing.
    final destinations = <_Destination>[
      _Destination(
        'Dashboard',
        Icons.dashboard_outlined,
        DashboardScreen(api: widget.api, session: widget.session),
      ),
      _Destination(
        'Customers',
        Icons.people_outline,
        CustomersScreen(api: widget.api, session: widget.session),
      ),
      _Destination(
        'Jobs',
        Icons.construction_outlined,
        JobsScreen(api: widget.api, session: widget.session),
      ),
      _Destination(
        'Workflows',
        Icons.bolt_outlined,
        WorkflowsScreen(api: widget.api, session: widget.session),
      ),
      _Destination(
        'Import & export',
        Icons.import_export_outlined,
        DataScreen(api: widget.api, session: widget.session),
      ),
    ];

    // Pinned to the bottom, under a rule and the empty space.
    //
    // Neither is a place you go to do the work. Settings is where you go to
    // change how the work behaves, and Development is the platform underneath
    // the product rather than part of it (§4.2) — both belong away from the
    // list of things you actually open all day.
    final pinned = <_Destination>[
      _Destination(
        'Settings',
        Icons.settings_outlined,
        SettingsScreen(api: widget.api, session: widget.session),
      ),
      if (me.development != null)
        _Destination(
          'Development',
          Icons.terminal_outlined,
          DevelopmentScreen(session: widget.session),
        ),
    ];

    // Two screens that are deliberately NOT rail destinations.
    //
    // "Your account" is reached from the avatar in the top bar, where every
    // other app puts it — it is about the person, not about the business, and a
    // tab beside Customers and Jobs implies it is one more thing to work on.
    //
    // Development is the platform underneath the product rather than part of it
    // (§4.2, revised), so it is pinned to the actual bottom of the rail, below
    // the empty space and behind a rule. It exists only when the server says
    // this person is also staff; a client deciding that for itself could show
    // the tab to somebody with nothing behind it.
    //
    // Both live one past the end of the destination list. Keeping them out of
    // the list is what allows one to be hidden and the other pinned; this index
    // arithmetic is the price, and it is confined to the four lines below.
    // "Your account" is reached from the avatar in the top bar, where every
    // other app puts it — it is about the person, not the business.
    //
    // Everything off the main list lives past its end. Keeping them out of the
    // list is what allows one to be hidden and the others pinned; this index
    // arithmetic is the price, and it is confined to these few lines.
    final profileIndex = destinations.length;
    final pinnedBase = destinations.length + 1;

    final showingProfile = _index == profileIndex;
    final pinnedIndex = (_index >= pinnedBase && _index < pinnedBase + pinned.length)
        ? _index - pinnedBase
        : null;

    // Clamped every build. The list shrinks when a staff link is removed or a
    // module is switched off platform-wide, and an index left pointing past the
    // end throws on the next frame — a blank screen with no way back.
    final railIndex = (showingProfile || pinnedIndex != null)
        ? null
        : _index.clamp(0, destinations.length - 1);

    return Scaffold(
      appBar: AppBar(
        // A rule under the top bar.
        //
        // The bar and the rail were both plain surface, so on a light theme
        // they met as one continuous white field and the sidebar read as part
        // of the header. One pixel fixes it; nothing else would.
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(height: 1, color: Theme.of(context).dividerColor),
        ),
        leading: wide
            ? IconButton(
                tooltip: expanded ? 'Collapse the sidebar' : 'Expand the sidebar',
                icon: Icon(expanded ? Icons.menu_open : Icons.menu),
                // Written through the same path as every other preference, so
                // it is the server's answer that wins and the value is there on
                // the next device somebody signs in from.
                onPressed: () => widget.session.setPreference(
                    'navigation.collapsed', !me.navigationCollapsed),
              )
            : null,
        titleSpacing: wide ? 0 : null,
        // The wordmark and the organization moved into the sidebar, which is
        // where the thing they identify actually lives. On a narrow screen
        // there is no sidebar to hold them, so they come back here.
        title: wide
            ? const SizedBox.shrink()
            : Row(children: [
                EzBiziTheme.wordmark(context, size: 20),
                const SizedBox(width: 12),
                Flexible(
                  child: Text(
                    me.organization.name,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
              ]),
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
          if (me.organization.isReadOnly)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Chip(
                avatar: const Icon(Icons.lock_outline, size: 16),
                label: const Text('Read only'),
                // §13 — stated up front, so a failed save is never a surprise.
                backgroundColor: Theme.of(context)
                    .colorScheme
                    .errorContainer
                    .withValues(alpha: 0.4),
              ),
            ),
          PopupMenuButton<String>(
            tooltip: me.email,
            icon: CircleAvatar(
              radius: 15,
              backgroundColor: EzBiziTheme.accent,
              child: Text(
                me.name.isNotEmpty ? me.name[0].toUpperCase() : '?',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
              ),
            ),
            itemBuilder: (_) => [
              PopupMenuItem(
                enabled: false,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(me.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(me.email, style: const TextStyle(fontSize: 12)),
                    if (me.roleName != null)
                      Text(me.roleName!, style: const TextStyle(fontSize: 12)),
                  ],
                ),
              ),
              const PopupMenuDivider(),
              const PopupMenuItem(value: 'me', child: Text('Your account')),
              const PopupMenuItem(value: 'out', child: Text('Sign out')),
            ],
            onSelected: (v) {
              if (v == 'out') {
                _signOut();
                return;
              }

              setState(() => _index = profileIndex);
            },
          ),
          const SizedBox(width: 8),
        ],
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
            // Tinted, and against a rule.
            //
            // The rail and the top bar were both plain surface, so on a light
            // theme they were one continuous white field with no edge between
            // them — the sidebar read as part of the header rather than as a
            // column of its own.
            DecoratedBox(
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerLow,
                border: Border(
                  right: BorderSide(color: Theme.of(context).dividerColor),
                ),
              ),
              // TWO rails, not one rail with a hand-drawn footer.
              //
              // Settings and Development used to be a Material + InkWell built
              // to look like destinations. It never quite did: the hover lit the
              // whole row instead of the indicator pill, the icons sat 20px left
              // of the column above them, there was no selection animation, and
              // every change to the rail's width moved them again. Four defects,
              // one cause — they were not destinations.
              //
              // NavigationRail has no minimum destination count, so the pinned
              // group can simply be another rail. Hover, splash, indicator
              // shape, selection animation, icon column, label style and row
              // height are then identical because they are the same widget, not
              // because constants were copied out of its source.
              child: Column(
                children: [
                  Expanded(
                    child: _rail(
                      context,
                      expanded: expanded,
                      destinations: destinations,
                      selectedIndex: railIndex,
                      onSelected: (i) => setState(() => _index = i),
                      leading: _RailHeader(
                        expanded: expanded,
                        organization: me.organization.name,
                      ),
                    ),
                  ),

                  // The rule that says the group below is a different kind of
                  // thing: places you go to change how the work behaves, rather
                  // than places you go to do it.
                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: expanded ? 16 : 12),
                    child: const Divider(height: 1),
                  ),

                  // IntrinsicHeight because a rail's root column is
                  // mainAxisSize.max — it expects to own the full height. This
                  // asks it for exactly the height its destinations need.
                  IntrinsicHeight(
                    child: _rail(
                      context,
                      expanded: expanded,
                      destinations: pinned,
                      selectedIndex: pinnedIndex,
                      onSelected: (i) => setState(() => _index = pinnedBase + i),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
              ),
            ),
          Expanded(
            child: pinnedIndex != null
                ? pinned[pinnedIndex].screen
                : showingProfile
                    ? ProfileScreen(api: widget.api, session: widget.session)
                    : destinations[railIndex ?? 0].screen,
          ),
        ],
      ),
      // Narrow layout. Development gets a tab here rather than a footer —
      // there is no rail to pin it to the bottom of, and hiding it entirely
      // would make the section unreachable on a phone. Your account stays in
      // the avatar menu on both, which is where people look for it.
      // Narrow layout. The pinned items get ordinary tabs here — there is no
      // rail to pin them to the bottom of, and hiding them would make Settings
      // unreachable on a phone. Your account stays in the avatar menu on both,
      // which is where people look for it.
      bottomNavigationBar: wide
          ? null
          : NavigationBar(
              selectedIndex: showingProfile
                  ? 0
                  : (railIndex ?? destinations.length + (pinnedIndex ?? 0)),
              onDestinationSelected: (i) => setState(() => _index =
                  i < destinations.length ? i : pinnedBase + (i - destinations.length)),
              destinations: [
                for (final d in [...destinations, ...pinned])
                  NavigationDestination(icon: Icon(d.icon), label: d.label),
              ],
            ),
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
  required List<_Destination> destinations,
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
    minWidth: 80,
    minExtendedWidth: 220,
    leading: leading,
    destinations: destinations
        .map((d) => NavigationRailDestination(
              icon: Icon(d.icon),
              label: Text(d.label),
            ))
        .toList(),
  );
}

class _Destination {
  const _Destination(this.label, this.icon, this.screen);
  final String label;
  final IconData icon;
  final Widget screen;
}

/// The wordmark and the organization, at the top of the sidebar.
///
/// They were in the top bar. Moving them here puts the identity against the
/// thing it identifies — the sidebar IS the organization's workspace, and a
/// wordmark floating above a full-width header belongs to the page rather than
/// to anything in particular.
///
/// ── Deliberately not a workspace card ────────────────────────────
///
/// The first version of this was a bordered pill holding a circular avatar with
/// the organization's initial, a small uppercase label and the name — which is
/// a shape borrowed wholesale from another product rather than drawn from this
/// one. It looked like that product with different words in it.
///
/// This uses the vocabulary the rest of the app already has: the accent spine
/// is the same device the rail uses to mark a live destination, so the current
/// organization is stated in a language somebody has already learnt from the
/// screen below it. No avatar, because a coloured circle with "A" in it
/// identifies nothing that the name beside it does not say better.
class _RailHeader extends StatelessWidget {
  const _RailHeader({required this.expanded, required this.organization});

  final bool expanded;
  final String organization;

  @override
  Widget build(BuildContext context) {
    final mark = Container(
      width: 30,
      height: 30,
      decoration: BoxDecoration(
        color: EzBiziTheme.accent,
        borderRadius: BorderRadius.circular(8),
      ),
      child: const Icon(Icons.bolt, color: Colors.white, size: 19),
    );

    if (!expanded) {
      return Padding(
        padding: const EdgeInsets.only(top: 10, bottom: 16),
        child: Center(child: mark),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 10, 16, 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            mark,
            const SizedBox(width: 10),
            EzBiziTheme.wordmark(context, size: 19),
          ]),
          const SizedBox(height: 18),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // The spine, not a border. A rule down one side reads as "you
                // are here"; a box around the name reads as a control, and this
                // is not one — there is no organization to switch to.
                Container(
                  width: 3,
                  decoration: BoxDecoration(
                    color: EzBiziTheme.accent,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      organization,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 15,
                        height: 1.25,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

