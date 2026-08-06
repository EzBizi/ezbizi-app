import 'package:flutter/material.dart';
import 'core/api_client.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'screens/login_screen.dart';
import 'screens/settings_screen.dart';

void main() => runApp(const EzBiziApp());

class EzBiziApp extends StatefulWidget {
  const EzBiziApp({super.key});

  @override
  State<EzBiziApp> createState() => _EzBiziAppState();
}

class _EzBiziAppState extends State<EzBiziApp> {
  late final ApiClient _api = ApiClient();
  late final Session _session = Session(_api);

  @override
  void initState() {
    super.initState();
    _session.addListener(() => setState(() {}));
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
      home: _session.signedIn
          ? HomeShell(api: _api, session: _session)
          : LoginScreen(api: _api, session: _session),
    );
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
        'Dashboard',
        Icons.dashboard_outlined,
        const _Placeholder(
          title: 'Dashboard',
          note: 'Widgets appear here as modules are built — one per module, '
              'sized by you. Nothing is faked in the meantime.',
        ),
      ),
      _Destination(
        'Settings',
        Icons.settings_outlined,
        SettingsScreen(api: widget.api, session: widget.session),
      ),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Row(children: [
          EzBiziTheme.wordmark(size: 20),
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
