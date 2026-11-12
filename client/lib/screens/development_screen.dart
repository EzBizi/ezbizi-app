import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/staff_session.dart';
import '../widgets/error_note.dart';
import '../widgets/tab_bar_like.dart';
import 'staff/client_versions_screen.dart';
import 'staff/health_screen.dart';
import 'staff/impersonations_screen.dart';
import 'staff/modules_screen.dart';
import 'staff/organizations_screen.dart';
import 'staff/releases_screen.dart';
import 'staff/staff_accounts_screen.dart';
import 'staff/suggestions_queue_screen.dart';

/// The Development section, inside the customer app (plan §4.2, revised).
///
/// Opening this tab asks for the authenticator, then holds a staff token on a
/// SEPARATE ApiClient while the tab is mounted. Leaving drops that session.
class DevelopmentScreen extends StatefulWidget {
  const DevelopmentScreen({super.key, required this.session, this.staffApi});

  final Session session;
  final ApiClient? staffApi;

  @override
  State<DevelopmentScreen> createState() => _DevelopmentScreenState();
}

class _DevelopmentScreenState extends State<DevelopmentScreen> {
  late final ApiClient _staffApi = widget.staffApi ?? ApiClient();
  late final StaffSession _staff = StaffSession(_staffApi);
  final _code = TextEditingController();
  final _codeFormKey = GlobalKey<FormState>();

  /// Null until a tab is picked: Development opens on Releases (Jose 11-12).
  int? _index;

  /// The tabs opened at least once, kept alive behind the one on screen.
  final Set<int> _opened = {0};
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    _staff.release();
    super.dispose();
  }

  Future<void> _unlock() async {
    if (!(_codeFormKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.session.api.post('/me/development/unlock', {
      'code': _code.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
        _code.clear();
      });
      return;
    }

    final token = response.body['token'] as String?;
    if (token == null || token.isEmpty) {
      setState(() {
        _busy = false;
        _error = 'The authenticator was accepted, but no session came back.';
      });
      return;
    }

    final signedIn = await _staff.adopt(token, persist: false);

    if (!mounted) return;

    if (!signedIn) {
      setState(() {
        _busy = false;
        _error =
            'The authenticator was accepted, but the session could not be loaded.';
        _code.clear();
      });
      return;
    }

    setState(() {
      _busy = false;
      _error = null;
      _code.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_staff.signedIn) {
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _codeFormKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.lock_outline, size: 32),
                  const SizedBox(height: 12),
                  Text(
                    'Enter your authenticator code',
                    style: Theme.of(context).textTheme.titleMedium,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'This tab reaches every organization on the platform. '
                    'The code is asked each time you open it.',
                    style: Theme.of(context).textTheme.bodyMedium,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 24),
                  TextFormField(
                    controller: _code,
                    decoration: const InputDecoration(
                      labelText: 'Authenticator code',
                      helperText: 'Six digits, or a recovery code',
                    ),
                    autofocus: true,
                    keyboardType: TextInputType.number,
                    autofillHints: const [AutofillHints.oneTimeCode],
                    enabled: !_busy,
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'Enter the code'
                        : null,
                    onFieldSubmitted: (_) => _unlock(),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    ErrorNote(_error!),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _unlock,
                    child: _busy
                        ? const SizedBox(
                            height: 18,
                            width: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Continue'),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    final me = _staff.me!;

    final tabs = <(String, IconData, Widget)>[
      (
        'Organizations',
        Icons.apartment_outlined,
        OrganizationsScreen(staff: _staff),
      ),
      ('Modules', Icons.toggle_on_outlined, ModulesScreen(staff: _staff)),
      (
        'Client versions',
        Icons.devices_outlined,
        ClientVersionsScreen(staff: _staff),
      ),
      // The release in flight, stage by stage, with its percentage.
      ('Releases', Icons.rocket_launch_outlined, ReleasesScreen(staff: _staff)),
      (
        'Client access',
        Icons.history_outlined,
        ImpersonationsScreen(staff: _staff),
      ),
      ('Health', Icons.favorite_outline, HealthScreen(staff: _staff)),
      (
        'Suggestions',
        Icons.lightbulb_outline,
        SuggestionsQueueScreen(staff: _staff),
      ),
      // Conditional and last on purpose: a fixed tab added after this one
      // would change position depending on who is looking.
      if (me.isLead)
        ('Staff', Icons.badge_outlined, StaffAccountsScreen(staff: _staff)),
    ];

    final index = (_index ?? tabs.indexWhere((t) => t.$1 == 'Releases'))
        .clamp(0, tabs.length - 1);
    _opened.add(index);

    return Column(
      children: [
        _Banner(
          isLead: me.isLead,
          focusedAt: widget.session.me?.development?.focusedAt,
        ),
        Material(
          key: const ValueKey('tour-development-tabs'),
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: tabs.map((t) => (t.$1, t.$2)).toList(),
            selected: index,
            onSelected: (i) => setState(() {
              _index = i;
              _opened.add(i);
            }),
          ),
        ),
        const Divider(height: 1),
        // Each tab opened stays alive, as Settings' do (UI queue 99): coming
        // back to Health or Modules no longer reloads it from scratch.
        Expanded(
          child: Stack(
            children: [
              for (final (i, t) in tabs.indexed)
                if (_opened.contains(i))
                  Offstage(offstage: i != index, child: t.$3),
            ],
          ),
        ),
      ],
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.isLead, this.focusedAt});

  final bool isLead;
  final String? focusedAt;

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
                  ? 'Platform control plane: every organization. Sign-ins, staff '
                        'changes and client access are audited; other activity is not.'
                  : 'Platform control plane: every organization. Everything you do '
                        'here is recorded.',
              style: TextStyle(fontSize: 12, color: scheme.error),
            ),
          ),
          if (focusedAt != null) ...[
            const SizedBox(width: 12),
            Text(
              'Focused view: $focusedAt',
              style: TextStyle(fontSize: 12, color: scheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
