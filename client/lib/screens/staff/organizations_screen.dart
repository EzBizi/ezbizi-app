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
  String _query = '';

  /// The organizations whose visible text contains the search,
  /// case-insensitively.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _organizations;
    return _organizations.where((org) {
      final text = [
        org['name'],
        org['franchise_label'],
        if (org['is_demo'] == true) 'Demo',
        if (org['is_read_only'] == true) 'Read only',
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. Refresh
      // is the only way back in here, and it should read as the list catching
      // up rather than the screen starting over. The count in the header is
      // already guarded on `_loading`, so it keeps matching the rows below it.
      _loading = _organizations.isEmpty;
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
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Standard field height, as tall as Leads search (Jose, 2026-10-27).
              SizedBox(
                width: 240,
                child: TextField(
                  key: const Key('organizations-search'),
                  textAlignVertical: TextAlignVertical.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIconConstraints: BoxConstraints(minWidth: 36),
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find an organization',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              IconButton(
                tooltip: 'Refresh',
                onPressed: _load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _organizations.isEmpty
                ? const Center(child: Text('No organizations yet.'))
                : _shown.isEmpty
                ? Padding(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    child: Text(
                      'No organizations match "${_query.trim()}".',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  )
                : LayoutBuilder(
                    builder: (context, box) => box.maxWidth < 600
                        ? ListView.separated(
                            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                            itemCount: _shown.length,
                            separatorBuilder: (_, _) => const SizedBox(height: 8),
                            itemBuilder: (context, i) => _row(context, _shown[i]),
                          )
                        : _table(context),
                  ),
          ),
        ),
      ],
    );
  }

  /// One row per organization (UI queue 168): 13 cards filled the screen.
  Widget _table(BuildContext context) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.bold,
    );
    Widget line(List<Widget> cells, {Key? key}) => Padding(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          Expanded(flex: 4, child: cells[0]),
          Expanded(child: cells[1]),
          Expanded(child: cells[2]),
          Expanded(child: cells[3]),
          SizedBox(width: 110, child: Align(alignment: Alignment.centerRight, child: cells[4])),
        ],
      ),
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      children: [
        Card(
          margin: EdgeInsets.zero,
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              line([
                Text('ORGANIZATION', style: head),
                Text('USERS', style: head),
                Text('COMPANIES', style: head),
                Text('BRANCHES', style: head),
                const SizedBox.shrink(),
              ]),
              for (final org in _shown) ...[
                const Divider(height: 1),
                line(key: ValueKey('organization-row-${org['id']}'), [
                  _name(context, org),
                  Text('${(org['counts'] as Map)['users']}'),
                  Text('${(org['counts'] as Map)['companies']}'),
                  Text('${(org['counts'] as Map)['franchises']}'),
                  _enter(org),
                ]),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _name(BuildContext context, Map<String, dynamic> org) => Row(
    children: [
      Flexible(
        child: Text(
          org['name'] as String,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleSmall,
        ),
      ),
      if (org['is_demo'] as bool? ?? false) const Tag('Demo'),
      // Stated up front (§13) rather than discovered on a failed write.
      if (org['is_read_only'] as bool? ?? false) const Tag('Read only', warn: true),
    ],
  );

  Widget _enter(Map<String, dynamic> org) => OutlinedButton.icon(
    onPressed: () => _impersonate(org),
    icon: const Icon(Icons.login, size: 18),
    label: const Text('Enter'),
  );

  /// "1 user", "4 branches": the count and the word agree.
  static String counted(Object? n, String one) {
    final many = RegExp(r'[^aeiou]y$').hasMatch(one)
        ? '${one.substring(0, one.length - 1)}ies'
        : RegExp(r'(s|x|ch|sh)$').hasMatch(one)
        ? '${one}es'
        : '${one}s';
    return '$n ${n == 1 ? one : many}';
  }

  /// A phone: one compact card per organization.
  Widget _row(BuildContext context, Map<String, dynamic> org) {
    final counts = org['counts'] as Map<String, dynamic>;
    final branch = ((org['franchise_label'] as String?) ?? 'branch').toLowerCase();

    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        key: ValueKey('organization-row-${org['id']}'),
        dense: true,
        title: _name(context, org),
        subtitle: Text(
          '${counted(counts['users'], 'user')} · '
          '${counted(counts['companies'], 'company')} · '
          '${counted(counts['franchises'], branch)}',
        ),
        trailing: _enter(org),
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
        SnackBar(
          content: Text('Session opened on ${org['name']}. It is recorded.'),
        ),
      );
    }
  }
}
