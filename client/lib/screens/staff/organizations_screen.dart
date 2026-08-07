import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'impersonation_panel.dart';

/// Every organization on the platform (plan §4.2).
///
/// Metadata only — names, counts, subscription state. Reaching the actual
/// records behind them needs an impersonation session with a stated reason,
/// which is what the button on each row opens.
class OrganizationsScreen extends StatefulWidget {
  const OrganizationsScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<OrganizationsScreen> createState() => _OrganizationsScreenState();
}

class _OrganizationsScreenState extends State<OrganizationsScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _organizations = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/organizations');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _organizations = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Organizations',
          // Only claim a count when there is actually a loaded list behind it —
          // "0 on the platform" over a failed request reads as a fact.
          note: (_loading || _error != null)
              ? 'Opening a client\'s records needs an impersonation session.'
              : '${_organizations.length} on the platform. '
                  'Opening a client\'s records needs an impersonation session.',
          action: IconButton(
            tooltip: 'Refresh',
            onPressed: _load,
            icon: const Icon(Icons.refresh),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _organizations.isEmpty
                ? const Center(child: Text('No organizations yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _organizations.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _organizations[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> org) {
    final counts = org['counts'] as Map<String, dynamic>;
    final readOnly = org['is_read_only'] as bool? ?? false;
    final demo = org['is_demo'] as bool? ?? false;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
        title: Row(
          children: [
            Flexible(
              child: Text(
                org['name'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (demo) const Tag('Demo'),
            // Stated up front (§13) rather than discovered on a failed write.
            if (readOnly) const Tag('Read only', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            '${counts['users']} users · ${counts['companies']} companies · '
            '${counts['franchises']} ${org['franchise_label'] ?? 'franchises'}',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        trailing: OutlinedButton.icon(
          onPressed: () => _impersonate(org),
          icon: const Icon(Icons.login, size: 18),
          label: const Text('Enter'),
        ),
      ),
    );
  }

  Future<void> _impersonate(Map<String, dynamic> org) async {
    final started = await showSidePanel<bool>(
      context,
      builder: (_) => ImpersonationPanel(
        staff: widget.staff,
        organizationId: org['id'] as String,
        organizationName: org['name'] as String,
      ),
    );

    if (started == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Session opened on ${org['name']}. It is recorded.')),
      );
    }
  }
}

