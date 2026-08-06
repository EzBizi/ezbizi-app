import 'package:flutter/material.dart';
import 'core/api_client.dart';
import 'core/deployment.dart';
import 'core/session.dart';
import 'core/staff_session.dart';
import 'core/theme.dart';
import 'screens/customers_screen.dart';
import 'screens/development_screen.dart';
import 'screens/jobs_screen.dart';
import 'screens/login_screen.dart';
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
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'EzBizi',
      debugShowCheckedModeBanner: false,
      theme: EzBiziTheme.light(),
      darkTheme: EzBiziTheme.dark(),
      // Follows the device until organizations can define their own themes
      // (§12) — at which point this reads from the organization's settings.
      themeMode: ThemeMode.system,
      home: _home(),
    );
  }

  Widget _home() {
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

    final destinations = <_Destination>[
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
        'Dashboard',
        Icons.dashboard_outlined,
        const _Placeholder(
          title: 'Dashboard',
          // §18 Phase 4: dashboards come AFTER modules produce data worth
          // showing. A page of empty widgets teaches you nothing about whether
          // the foundation works.
          note: 'Widgets appear here once there is data worth showing — Customers '
              'and Jobs are producing it now. Nothing is faked in the meantime.',
        ),
      ),
      _Destination(
        'Settings',
        Icons.settings_outlined,
        SettingsScreen(api: widget.api, session: widget.session),
      ),

      // Last in the rail, deliberately (§4.2, revised). It is not part of
      // running the business — it is the platform underneath it — and it only
      // exists when the server says this person is also staff. A client that
      // decided this for itself could show the tab to somebody with nothing
      // behind it.
      if (me.development != null)
        _Destination(
          'Development',
          Icons.terminal_outlined,
          DevelopmentScreen(session: widget.session),
        ),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Row(children: [
          EzBiziTheme.wordmark(context, size: 20),
          const SizedBox(width: 16),
          Container(width: 1, height: 20, color: Theme.of(context).dividerColor),
          const SizedBox(width: 16),
          Flexible(
            child: Text(
              me.organization.name,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ),
        ]),
        actions: [
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
              const PopupMenuItem(value: 'out', child: Text('Sign out')),
            ],
            onSelected: (v) => v == 'out' ? _signOut() : null,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Row(
        children: [
          if (wide)
            NavigationRail(
              selectedIndex: _index,
              onDestinationSelected: (i) => setState(() => _index = i),
              labelType: NavigationRailLabelType.all,
              destinations: destinations
                  .map((d) => NavigationRailDestination(
                        icon: Icon(d.icon),
                        label: Text(d.label),
                      ))
                  .toList(),
            ),
          if (wide) const VerticalDivider(width: 1),
          Expanded(child: destinations[_index].screen),
        ],
      ),
      bottomNavigationBar: wide
          ? null
          : NavigationBar(
              selectedIndex: _index,
              onDestinationSelected: (i) => setState(() => _index = i),
              destinations: destinations
                  .map((d) => NavigationDestination(icon: Icon(d.icon), label: d.label))
                  .toList(),
            ),
    );
  }
}

class _Destination {
  const _Destination(this.label, this.icon, this.screen);
  final String label;
  final IconData icon;
  final Widget screen;
}

class _Placeholder extends StatelessWidget {
  const _Placeholder({required this.title, required this.note});

  final String title;
  final String note;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(title, style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 10),
            Text(
              note,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }
}
