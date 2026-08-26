import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';

/// Leads — enquiries from connected advertising (EZ Leads).
///
/// One list, because that is what the work is: somebody scans what came in and
/// decides what each one is. Filters and search sit above it rather than behind
/// a menu, since both are used while a customer is on the phone.
///
/// ── The empty state is the screen's real job ─────────────────────
///
/// "No leads" and "nothing is connected" render identically as an empty list
/// and mean opposite things — a quiet week, or nothing working at all. The API
/// sends `meta.connections` for exactly this, and the two cases say different
/// sentences and offer different buttons. Getting that wrong is the failure the
/// whole module exists to avoid: a screen that quietly shows nothing while an
/// enquiry sits unanswered.
class LeadsScreen extends StatefulWidget {
  const LeadsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LeadsScreen> createState() => _LeadsScreenState();
}

class _LeadsScreenState extends State<LeadsScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _leads = [];
  List<Map<String, dynamic>> _connections = [];
  int _total = 0;
  String _status = '';
  int _request = 0;

  /// Ours, not the source's. The wire values, and what a person calls them.
  static const _statuses = <String, String>{
    '': 'All',
    'new': 'New',
    'booked': 'Booked',
    'archived': 'Archived',
    'not_a_lead': 'Not a lead',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // "Nothing on screen yet", not "a request is in flight" — a refresh after
      // a status change keeps the list rendered rather than tearing it down.
      _loading = _leads.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/leads', {
      if (_status.isNotEmpty) 'status': _status,
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      // `data` is already the rows; `body` is the whole envelope. Reading
      // `response.data['data']` indexes a List with a String and throws.
      _leads = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _total = (meta['total'] as num?)?.toInt() ?? _leads.length;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
    });
  }

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/leads/sync', const {});

    if (!mounted) return;

    setState(() => _syncing = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final created = (data['created'] as num?)?.toInt() ?? 0;
    final fetched = (data['fetched'] as num?)?.toInt() ?? 0;

    setState(() {
      // Says what happened, including when the honest answer is "nothing new".
      // A silent refresh leaves somebody wondering whether it ran.
      _notice = created > 0
          ? '$created new ${created == 1 ? 'lead' : 'leads'}.'
          : fetched > 0
          ? 'Nothing new — $fetched already here.'
          : 'Nothing new.';
    });

    await _load();
  }

  Future<void> _setStatus(Map<String, dynamic> lead, String status) async {
    final response = await widget.api.patch('/leads/${lead['id']}', {
      'status': status,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    await _load();
  }

  bool get _anyGoogleAds =>
      _connections.any((c) => c['provider'] == 'google_ads');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _search,
                  onSubmitted: (_) => _load(),
                  decoration: InputDecoration(
                    isDense: true,
                    prefixIcon: const Icon(Icons.search, size: 20),
                    // Both, because somebody has one or the other in front of
                    // them and should not have to know which the box wants.
                    hintText: 'Search a name or phone number',
                    border: const OutlineInputBorder(),
                    suffixIcon: _search.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.close, size: 18),
                            onPressed: () {
                              _search.clear();
                              _load();
                            },
                          ),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              DropdownButton<String>(
                value: _status,
                underline: const SizedBox.shrink(),
                items: [
                  for (final entry in _statuses.entries)
                    DropdownMenuItem(value: entry.key, child: Text(entry.value)),
                ],
                onChanged: (value) {
                  setState(() => _status = value ?? '');
                  _load();
                },
              ),
              const SizedBox(width: 12),
              FilledButton.tonalIcon(
                onPressed: _syncing ? null : _sync,
                icon: _syncing
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.sync, size: 18),
                label: Text(_syncing ? 'Fetching…' : 'Fetch new'),
              ),
            ],
          ),
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(_notice!, style: theme.textTheme.bodySmall),
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () => setState(() => _notice = null),
                ),
              ],
            ),
          ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _leads.isEmpty ? _empty(theme) : _list(theme),
          ),
        ),
      ],
    );
  }

  /// Two different nothings, and they must not read the same.
  Widget _empty(ThemeData theme) {
    final connected = _anyGoogleAds;

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              connected ? Icons.inbox_outlined : Icons.link_off,
              size: 40,
              color: theme.colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              connected ? 'No enquiries yet' : 'No advertising account connected',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              connected
                  ? 'Nothing has come in through the connected account for the '
                        'filters above. Fetch new checks again straight away.'
                  : 'Leads arrive from a Google Ads account this organization '
                        'connects itself. Until one is connected there is '
                        'nothing to show here — this is not a quiet week.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            if (!connected) ...[
              const SizedBox(height: 18),
              // The button lives HERE rather than in Marketing: this is the
              // module that reads the account, and `leads.edit` is what gates
              // it. Somebody looking at an empty Leads tab should not have to
              // learn which other tab owns the connection.
              ConnectAccount(
                api: widget.api,
                label: 'Google Ads',
                startPath: '/social/google-ads/start',
                onConnected: _load,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _list(ThemeData theme) {
    return ListView.separated(
      itemCount: _leads.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _leads.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              '$_total ${_total == 1 ? 'lead' : 'leads'}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          );
        }

        final lead = _leads[index];
        final status = lead['status'] as String? ?? 'new';

        return ListTile(
          title: Text(
            (lead['contact_name'] as String?)?.trim().isNotEmpty == true
                ? lead['contact_name'] as String
                // A lead with no name is still a lead; the number is what
                // somebody would call back, so it takes the title.
                : (lead['phone'] as String? ?? 'Unnamed enquiry'),
          ),
          subtitle: Text(
            [
              if ((lead['phone'] as String?)?.isNotEmpty == true) lead['phone'],
              if ((lead['category'] as String?)?.isNotEmpty == true)
                (lead['category'] as String).replaceAll('_', ' '),
              if (lead['received_at'] != null)
                _when(lead['received_at'] as String),
            ].join('  ·  '),
            style: theme.textTheme.bodySmall,
          ),
          trailing: DropdownButton<String>(
            value: status,
            underline: const SizedBox.shrink(),
            items: [
              for (final entry in _statuses.entries)
                if (entry.key.isNotEmpty)
                  DropdownMenuItem(
                    value: entry.key,
                    child: Text(entry.value),
                  ),
            ],
            onChanged: (value) {
              if (value != null && value != status) _setStatus(lead, value);
            },
          ),
        );
      },
    );
  }

  /// Relative for anything recent, absolute once it stops being "today".
  String _when(String iso) {
    final at = DateTime.tryParse(iso)?.toLocal();
    if (at == null) return '';

    final ago = DateTime.now().difference(at);

    if (ago.inMinutes < 1) return 'just now';
    if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
    if (ago.inHours < 24) return '${ago.inHours}h ago';
    if (ago.inDays < 7) return '${ago.inDays}d ago';

    return '${at.year}-${at.month.toString().padLeft(2, '0')}-'
        '${at.day.toString().padLeft(2, '0')}';
  }
}
