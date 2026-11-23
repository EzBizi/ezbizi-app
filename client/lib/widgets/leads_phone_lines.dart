// Leads › Manage › Phone lines (Jose, 2026-11-17: "should it be able to see
// where the call came from as in from which ring central number account ?"):
// every number of every connected RingCentral account, read from RingCentral,
// each given the company or branch its calls are filed under. A number left
// unassigned is still filed by its name and account, and the server logs it.

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'scope_choice_field.dart';

/// The tab and its edits need this grant, as the routes do.
const leadsPhoneLinesPermission = 'leads.manage';

class LeadsPhoneLines extends StatefulWidget {
  const LeadsPhoneLines({super.key, required this.api, this.session});

  final ApiClient api;
  final Session? session;

  @override
  State<LeadsPhoneLines> createState() => _LeadsPhoneLinesState();
}

class _LeadsPhoneLinesState extends State<LeadsPhoneLines> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _accounts = const [];
  String _query = '';
  final _busy = <String>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/leads/phone-lines');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _accounts = ((response.data as Map<String, dynamic>)['accounts'] as List)
          .cast<Map<String, dynamic>>();
    });
  }

  Future<void> _assign(Map<String, dynamic> line, String? place) async {
    final number = '${line['number']}';
    setState(() => _busy.add(number));
    final response = await widget.api.put('/leads/phone-lines', {
      'number': number,
      'place': place,
    });
    if (!mounted) return;
    setState(() {
      _busy.remove(number);
      if (response.ok) line['place'] = place;
    });
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(content: Text(response.ok ? 'Saved.' : response.message)),
    );
  }

  bool _matches(Map<String, dynamic> line) {
    final q = _query.toLowerCase();
    final digits = q.replaceAll(RegExp(r'\D'), '');
    return '${line['label']}'.toLowerCase().contains(q) ||
        (digits.isNotEmpty && '${line['number']}'.contains(digits));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Text(_error!));
    final me = widget.session?.me;
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('phone-lines-list'),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Each RingCentral number and whose calls it takes. Calls to a number '
          'with no company are filed by the line name.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('phone-lines-search'),
          decoration: const InputDecoration(
            isDense: true,
            prefixIcon: Icon(Icons.search, size: 18),
            hintText: 'Search lines or numbers',
          ),
          onChanged: (v) => setState(() => _query = v.trim()),
        ),
        if (_accounts.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Text('No RingCentral account is connected.'),
          ),
        for (final account in _accounts) ...[
          const SizedBox(height: 16),
          Text('${account['name']}', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          for (final line
              in (account['numbers'] as List)
                  .cast<Map<String, dynamic>>()
                  .where(_matches))
            Padding(
              key: ValueKey('phone-line-${line['number']}'),
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${line['label']}'),
                        Text(
                          '${line['number']}',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  SizedBox(
                    width: 320,
                    child: me == null
                        ? const SizedBox.shrink()
                        : ScopeChoiceField(
                            key: ValueKey('phone-line-place-${line['number']}'),
                            label: 'Company · Branch',
                            isDense: true,
                            value: line['place'] as String?,
                            places: {
                              for (final c in me.companies)
                                'company:${c.id}': c.name,
                              for (final f in me.franchises)
                                'franchise:${f.id}': f.name,
                            },
                            companies: me.companies,
                            branches: me.franchises,
                            onChanged: _busy.contains('${line['number']}')
                                ? null
                                : (place) => _assign(line, place),
                          ),
                  ),
                ],
              ),
            ),
        ],
      ],
    );
  }
}
