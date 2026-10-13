import 'package:flutter/material.dart';
import '../../core/address.dart';
import '../../core/staff_session.dart';
import '../../core/theme.dart';
import '../../widgets/enrolment_dialog.dart';
import '../../widgets/side_panel.dart';
import 'client_versions_screen.dart';
import 'help_videos_screen.dart';
import 'health_screen.dart';
import 'impersonations_screen.dart';
import 'modules_screen.dart';
import 'organizations_screen.dart';
import 'phone_requests_screen.dart';
import 'staff_accounts_screen.dart';

/// The Development tab (plan §2.1, §4.2).
///
/// Visibly not the customer app: the banner is there so nobody spends ten
/// minutes wondering why their organization's data looks wrong. Staff have no
/// organization at all — the server puts them in platform scope, deliberately
/// distinct from "no tenant set", so a bug cannot pass for staff access.
class StaffShell extends StatefulWidget {
  const StaffShell({super.key, required this.staff, this.address});

  final StaffSession staff;
  final Address? address;

  @override
  State<StaffShell> createState() => _StaffShellState();
}

class _StaffShellState extends State<StaffShell> {
  int _index = 0;
  void Function()? _stopListening;

  @override
  void initState() {
    super.initState();
    _apply(widget.address?.path);
    _stopListening = widget.address?.listen((path) {
      if (!mounted) return;
      setState(() => _apply(path));
    });
  }

  @override
  void dispose() {
    _stopListening?.call();
    super.dispose();
  }

  void _apply(String? path) {
    final id = Address.idOf(path ?? '');
    if (id == null) return;
    final at = _ids.indexWhere((candidate) => candidate == id);
    if (at >= 0) _index = at;
  }

  void _go(int index) {
    setState(() => _index = index);
    final ids = _visibleIds;
    if (index >= 0 && index < ids.length) {
      widget.address?.push(Address.pathOf(ids[index]));
    }
  }

  List<String> get _visibleIds {
    final me = widget.staff.me;
    return [
      for (final id in _ids)
        if (id != 'staff' || (me?.isLead ?? false)) id,
    ];
  }

  // In the order [destinations] draws them: the index is shared, so a missing
  // id sends every later item to its neighbour's address.
  static const _ids = [
    'organizations',
    'modules',
    'phone-requests',
    'client-versions',
    'help-videos',
    'client-access',
    'health',
    'staff',
  ];

  Future<void> _signOut() async {
    await widget.staff.api.post('/staff/auth/logout', {});
    widget.staff.signOut();
  }

  /// Moving your own authenticator to a new phone.
  ///
  /// The working secret stays working until the new one is confirmed — the
  /// server does that deliberately, so a mis-scan cannot lock you out of the
  /// account that manages every other account.
  Future<void> _rotateAuthenticator() async {
    // Both factors again, not just the session. A staff token lives twelve
    // hours; without re-proving them, whoever stole one could bind their own
    // phone as a permanent second factor.
    final proof = await showSidePanel<Map<String, String>>(
      context,
      builder: (_) => const _ReauthPanel(),
    );

    if (proof == null || !mounted) return;

    final response = await widget.staff.api.post('/staff/me/totp', proof);

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    final enrolment = response.body['enrolment'] as Map<String, dynamic>;
    final me = widget.staff.me!;

    // The undismissable barrier this used to have is gone: a panel is always
    // closeable, by design. Nothing is lost by leaving early — the old secret
    // keeps working until a code confirms the new one.
    await showSidePanel<bool>(
      context,
      builder: (_) => EnrolmentDialog(
        title: 'Move your authenticator',
        email: me.email,
        secret: enrolment['secret'] as String,
        uri: enrolment['uri'] as String,
        onConfirm: (code) =>
            widget.staff.api.post('/staff/me/totp/confirm', {'code': code}),
      ),
    );

    if (mounted) await widget.staff.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.staff.me!;
    final wide = MediaQuery.sizeOf(context).width >= 900;

    final destinations = <_Destination>[
      _Destination(
        'Organizations',
        Icons.apartment_outlined,
        OrganizationsScreen(staff: widget.staff),
        id: 'organizations',
      ),
      _Destination(
        'Modules',
        Icons.toggle_on_outlined,
        ModulesScreen(staff: widget.staff),
        id: 'modules',
      ),
      // Beside Modules because it is the same kind of control — a platform-wide
      // switch owned by staff that changes what every organization experiences
      // at once. It is the only one that can stop a customer's app from
      // starting, which is why it must be reachable in the product rather than
      // by hand-issuing a request with a bearer token.
      // Beside Modules: both are EzBizi deciding what a customer may have.
      // Modules is platform-wide; this is one organization asking for a paid
      // extra and somebody answering.
      _Destination(
        'Phone requests',
        Icons.ring_volume_outlined,
        PhoneRequestsScreen(staff: widget.staff),
        id: 'phone-requests',
      ),
      _Destination(
        'Client versions',
        Icons.devices_outlined,
        ClientVersionsScreen(staff: widget.staff),
        id: 'client-versions',
      ),
      // UI queue 81: which tab's ▶ plays which video, for every organization.
      _Destination(
        'Help videos',
        Icons.smart_display_outlined,
        HelpVideosScreen(staff: widget.staff),
        id: 'help-videos',
      ),
      _Destination(
        'Client access',
        Icons.history_outlined,
        ImpersonationsScreen(staff: widget.staff),
        id: 'client-access',
      ),
      _Destination(
        'Health',
        Icons.favorite_outline,
        HealthScreen(staff: widget.staff),
        id: 'health',
      ),
      // Lead Developer only. The server refuses either way; hiding it keeps a
      // Developer from clicking into a wall.
      if (me.isLead)
        _Destination(
          'Staff',
          Icons.badge_outlined,
          StaffAccountsScreen(staff: widget.staff),
          id: 'staff',
        ),
    ];

    final index = _index.clamp(0, destinations.length - 1);
    final shown = Address.pathOf(destinations[index].id);
    if (widget.address != null && widget.address!.path != shown) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        widget.address?.replace(shown);
      });
    }

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            EzBiziTheme.wordmark(context, size: 20),
            const SizedBox(width: 12),
            Container(
              width: 1,
              height: 20,
              color: Theme.of(context).dividerColor,
            ),
            const SizedBox(width: 12),
            Text(
              'CONTROL PLANE',
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ],
        ),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Chip(
              avatar: Icon(
                me.isLead ? Icons.shield_outlined : Icons.build_outlined,
                size: 16,
              ),
              label: Text(me.tierLabel),
              visualDensity: VisualDensity.compact,
            ),
          ),
          PopupMenuButton<String>(
            // The control is the account menu; the email is whose. Reading out
            // an address alone does not say what pressing it does.
            tooltip: 'Your staff account — ${me.email}',
            icon: CircleAvatar(
              radius: 15,
              backgroundColor: EzBiziTheme.accent,
              child: Text(
                me.name.isNotEmpty ? me.name[0].toUpperCase() : '?',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                ),
              ),
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
                    Text(
                      '${me.recoveryCodeCount} recovery codes left',
                      style: const TextStyle(fontSize: 12),
                    ),
                  ],
                ),
              ),
              const PopupMenuDivider(),
              const PopupMenuItem(
                value: 'totp',
                child: Text('Move authenticator'),
              ),
              const PopupMenuItem(value: 'out', child: Text('Sign out')),
            ],
            onSelected: (v) {
              if (v == 'out') _signOut();
              if (v == 'totp') _rotateAuthenticator();
            },
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          _Banner(isLead: me.isLead),
          Expanded(
            child: Row(
              children: [
                if (wide)
                  NavigationRail(
                    selectedIndex: index,
                    onDestinationSelected: _go,
                    labelType: NavigationRailLabelType.all,
                    destinations: destinations
                        .map(
                          (d) => NavigationRailDestination(
                            icon: Icon(d.icon),
                            label: Text(d.label),
                          ),
                        )
                        .toList(),
                  ),
                if (wide) const VerticalDivider(width: 1),
                Expanded(child: destinations[index].screen),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: wide
          ? null
          : NavigationBar(
              selectedIndex: index,
              onDestinationSelected: _go,
              destinations: destinations
                  .map(
                    (d) => NavigationDestination(
                      icon: Icon(d.icon),
                      label: d.label,
                    ),
                  )
                  .toList(),
            ),
    );
  }
}

/// Says plainly what this account is and what it is not audited for.
///
/// The Lead Developer's ordinary activity is deliberately outside the business
/// audit trail (§4.2). That is the owner's decision, and the person using the
/// account should be reminded of it rather than have it live only in a document.
class _Banner extends StatelessWidget {
  const _Banner({required this.isLead});

  final bool isLead;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: double.infinity,
      color: scheme.errorContainer.withValues(alpha: 0.35),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      child: Row(
        children: [
          Icon(Icons.info_outline, size: 16, color: scheme.error),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              isLead
                  ? 'Platform control plane. Your ordinary activity here is not written '
                        'to the business audit trail — sign-ins, staff changes and client '
                        'access always are.'
                  : 'Platform control plane. Everything you do here is recorded.',
              style: TextStyle(fontSize: 12, color: scheme.error),
            ),
          ),
        ],
      ),
    );
  }
}

class _Destination {
  const _Destination(this.label, this.icon, this.screen, {String? id})
    : id = id ?? label;

  final String label;
  final IconData icon;
  final Widget screen;
  final String id;
}

/// Re-proving both factors before binding a new one.
///
/// A StatefulWidget purely so the controllers get disposed — a panel that
/// builds them in a closure leaks one per opening.
class _ReauthPanel extends StatefulWidget {
  const _ReauthPanel();

  @override
  State<_ReauthPanel> createState() => _ReauthPanelState();
}

class _ReauthPanelState extends State<_ReauthPanel> {
  final _password = TextEditingController();
  final _code = TextEditingController();

  @override
  void dispose() {
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  void _submit() => Navigator.of(
    context,
  ).pop({'password': _password.text, 'code': _code.text.trim()});

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Confirm it is you',
      subtitle:
          'Binding a new authenticator needs your password and a code from '
          'the current one — a session on its own is not enough.',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(onPressed: _submit, child: const Text('Continue')),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _password,
            decoration: const InputDecoration(labelText: 'Password'),
            obscureText: true,
            autofocus: true,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _code,
            decoration: const InputDecoration(
              labelText: 'Code from your current app',
            ),
            onSubmitted: (_) => _submit(),
          ),
        ],
      ),
    );
  }
}
