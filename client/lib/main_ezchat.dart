// EzChat: the chat companion app (Jose, 2026-11-25: "separate the new
// Whatsapp style communication into it's own sister app ... easier to use for
// technician ... it would work under the same system though in terms of
// database, accounts, etc").
//
// Same repo, same backend, same EzBizi accounts. This entrypoint is the whole
// difference: sign in, then Chat and its calls, nothing else. Built with
// `-t lib/main_ezchat.dart` and the `ezchat` Android flavor / iOS target.

import 'dart:async';

import 'package:flutter/material.dart';

import 'core/api_client.dart';
import 'core/client_platform.dart';
import 'core/push_registration.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'core/token_store.dart';
import 'core/version_gate.dart';
import 'screens/login_screen.dart';
import 'screens/phone/chat/chat_calls.dart';
import 'screens/phone/chat/chat_tab.dart';
import 'screens/update_required_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await TokenStore.init();
  PushRegistration.app = 'ezchat';
  EzBiziTheme.isEzChat = true;
  // Accounts are made in EzBizi (an invitation); EzChat only signs in.
  LoginScreen.showCreateAccount = false;
  runApp(const EzChatApp());
}

class EzChatApp extends StatefulWidget {
  const EzChatApp({super.key, this.api});

  /// A test passes its own client.
  final ApiClient? api;

  @override
  State<EzChatApp> createState() => _EzChatAppState();
}

class _EzChatAppState extends State<EzChatApp> {
  late final ApiClient _api = widget.api ?? ApiClient();
  late final Session _session = Session(_api);
  final _versionGate = ValueNotifier<UpgradeRequired?>(null);

  @override
  void initState() {
    super.initState();
    _session.addListener(_changed);
    _versionGate.addListener(_changed);
    installVersionGate(_versionGate);
    _session.restore();
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'EzChat',
    debugShowCheckedModeBanner: false,
    theme: EzBiziTheme.ezChat(Brightness.light),
    darkTheme: EzBiziTheme.ezChat(Brightness.dark),
    themeMode: EzBiziTheme.modeFor(_session.me?.theme ?? 'system'),
    // The ring and the call above every screen, as in EzBizi.
    builder: (context, child) => Stack(
      children: [
        ?child,
        ChatCallLayer(api: _api, session: _session),
      ],
    ),
    home: _home(),
  );

  Widget _home() {
    final blocked = _versionGate.value;
    if (blocked != null) {
      return UpdateRequiredScreen(
        notice: blocked,
        onTryAgain: () => _versionGate.value = null,
        onSignOut: _session.signedIn ? _session.signOut : null,
      );
    }
    if (!_session.restored) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_session.signedIn) {
      return EzChatHome(api: _api, session: _session);
    }
    return LoginScreen(api: _api, session: _session, onCreateAccount: () {});
  }
}

/// Signed in: the chat list, a thread, a call. A role without Chat is told so.
class EzChatHome extends StatefulWidget {
  const EzChatHome({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<EzChatHome> createState() => _EzChatHomeState();
}

class _EzChatHomeState extends State<EzChatHome> {
  int _tab = 0;
  bool _inChat = false;

  @override
  void initState() {
    super.initState();
    if (!ClientPlatform.isWeb) unawaited(PushRegistration.start(widget.api));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = widget.session.me!;
    final mayChat = me.permissions.contains('phone.chat.view');
    // Chats · You (design 213 B1); Calls joins when GET chat/calls lands.
    // The bar steps aside inside a chat, as WhatsApp's does.
    return Scaffold(
      bottomNavigationBar: !mayChat || _inChat
          ? null
          : NavigationBar(
              key: const ValueKey('ezchat-bar'),
              selectedIndex: _tab,
              onDestinationSelected: (i) => setState(() => _tab = i),
              destinations: const [
                NavigationDestination(
                  icon: Icon(Icons.chat_bubble_outline),
                  selectedIcon: Icon(Icons.chat_bubble),
                  label: 'Chats',
                ),
                NavigationDestination(
                  icon: Icon(Icons.person_outline),
                  selectedIcon: Icon(Icons.person),
                  label: 'You',
                ),
              ],
            ),
      body: SafeArea(
        child: mayChat
            ? IndexedStack(
                index: _tab,
                children: [
                  ChatTab(
                    api: widget.api,
                    session: widget.session,
                    onOpenChanged: (open) => setState(() => _inChat = open),
                  ),
                  _You(session: widget.session),
                ],
              )
            : Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        Icons.forum_outlined,
                        size: 56,
                        color: theme.colorScheme.primary,
                      ),
                      const SizedBox(height: 12),
                      Text(
                        'Chat is not turned on for your role yet. An '
                        'administrator can turn it on under Settings › Roles '
                        'in EzBizi (Communication › Chat).',
                        key: const ValueKey('ezchat-no-access'),
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyLarge,
                      ),
                      const SizedBox(height: 16),
                      TextButton(
                        onPressed: widget.session.signOut,
                        child: Text('Sign out ${me.name}'),
                      ),
                    ],
                  ),
                ),
              ),
      ),
    );
  }
}

/// You: who is signed in, light or dark, and signing out.
class _You extends StatelessWidget {
  const _You({required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = session.me!;
    final light = EzBiziTheme.modeFor(me.theme) == ThemeMode.light;
    return ListView(
      key: const ValueKey('ezchat-you'),
      padding: const EdgeInsets.all(16),
      children: [
        Text('You', style: theme.textTheme.headlineSmall),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: ListTile(
            leading: CircleAvatar(
              backgroundColor: theme.colorScheme.primaryContainer,
              foregroundColor: theme.colorScheme.onPrimaryContainer,
              child: Text(me.name.isEmpty ? '?' : me.name[0].toUpperCase()),
            ),
            title: Text(me.name),
            subtitle: Text(me.email),
          ),
        ),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: SwitchListTile(
            key: const ValueKey('ezchat-dark'),
            title: const Text('Dark mode'),
            value: !light,
            onChanged: (dark) => session.setPreference(
              'appearance.theme',
              dark ? 'dark' : 'light',
            ),
          ),
        ),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: ListTile(
            key: const ValueKey('ezchat-sign-out'),
            leading: const Icon(Icons.logout),
            title: Text('Sign out ${me.name}'),
            onTap: session.signOut,
          ),
        ),
      ],
    );
  }
}
