import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/staff_session.dart';
import 'staff/health_screen.dart';
import 'staff/impersonations_screen.dart';
import 'staff/modules_screen.dart';
import 'staff/organizations_screen.dart';
import 'staff/staff_accounts_screen.dart';

/// The Development section, inside the customer app (plan §4.2, revised).
///
/// The same control-plane screens dev.ezbizi.com serves, embedded here for
/// somebody whose tenant account is linked to a staff record. It appears only
/// when the server said so — `/me` reports the link, and without it this widget
/// is never built.
///
/// ── Why it has its own client and its own session ────────────────
///
/// The staff token that arrived with the tenant login goes on a SEPARATE
/// ApiClient. The two must not share one: a tenant token that could reach
/// /api/v1/staff is precisely the collapse the split identity exists to prevent,
/// and the server refuses both directions anyway. This keeps the client honest
/// about which credential it is presenting.
class DevelopmentScreen extends StatefulWidget {
  const DevelopmentScreen({super.key, required this.session});

  final Session session;

  @override
  State<DevelopmentScreen> createState() => _DevelopmentScreenState();
}

class _DevelopmentScreenState extends State<DevelopmentScreen> {
  late final ApiClient _staffApi = ApiClient();
  late final StaffSession _staff = StaffSession(_staffApi);

  int _index = 0;
  bool _adopting = true;

  @override
  void initState() {
    super.initState();
    _adopt();
  }

  Future<void> _adopt() async {
    final token = widget.session.staffToken;

    if (token == null) {
      setState(() => _adopting = false);
      return;
    }

    await _staff.adopt(token);

    if (mounted) setState(() => _adopting = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_adopting) {
      return const Center(child: CircularProgressIndicator());
    }

    if (!_staff.signedIn) {
      // The staff token is short-lived by design — twelve hours against the
      // tenant token's thirty days. When it lapses the CRM session is still
      // perfectly good, so this says what happened rather than signing anyone
      // out of everything.
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.lock_clock_outlined, size: 32),
              const SizedBox(height: 12),
              Text(
                'Your control-plane session has expired',
                style: Theme.of(context).textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                'It lasts twelve hours — much less than your CRM session, because '
                'it reaches every organization on the platform. Sign in again to '
                'renew it; your work here is unaffected.',
                style: Theme.of(context).textTheme.bodyMedium,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      );
    }

    final me = _staff.me!;

    final tabs = <(String, IconData, Widget)>[
      ('Organizations', Icons.apartment_outlined, OrganizationsScreen(staff: _staff)),
      ('Modules', Icons.toggle_on_outlined, ModulesScreen(staff: _staff)),
      ('Client access', Icons.history_outlined, ImpersonationsScreen(staff: _staff)),
      ('Health', Icons.favorite_outline, HealthScreen(staff: _staff)),
      if (me.isLead) ('Staff', Icons.badge_outlined, StaffAccountsScreen(staff: _staff)),
    ];

    final index = _index.clamp(0, tabs.length - 1);

    return Column(
      children: [
        _Banner(isLead: me.isLead, focusedAt: widget.session.me?.development?.focusedAt),
        // A second row of tabs, not a second sidebar. The section is one
        // destination in the CRM's navigation; nesting a full rail inside it
        // would make it look like a different application, which it is not.
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: tabs.map((t) => (t.$1, t.$2)).toList(),
            selected: index,
            onSelected: (i) => setState(() => _index = i),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[index].$3),
      ],
    );
  }
}

/// A simple segmented row of destinations.
///
/// Not a real TabBar: that needs a TabController and a DefaultTabController
/// ancestor, and the surrounding shell already owns navigation state. This keeps
/// the selection in one obvious place.
class TabBarLike extends StatelessWidget {
  const TabBarLike({
    super.key,
    required this.labels,
    required this.selected,
    required this.onSelected,
  });

  final List<(String, IconData)> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                avatar: Icon(labels[i].$2, size: 16),
                label: Text(labels[i].$1),
                selected: i == selected,
                onSelected: (_) => onSelected(i),
              ),
            ),
        ],
      ),
    );
  }
}

/// Says plainly what this section is, and where the focused version lives.
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
                  ? 'Platform control plane — every organization, not just this one. '
                      'Your ordinary activity here is not written to the business audit '
                      'trail; sign-ins, staff changes and client access always are.'
                  : 'Platform control plane — every organization, not just this one. '
                      'Everything you do here is recorded.',
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
