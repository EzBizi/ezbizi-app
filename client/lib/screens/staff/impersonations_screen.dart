import 'dart:async';

import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';

/// Who opened which client's data, when, and why (plan §4.2).
///
/// The whole history, not just your own. A log only its author can read
/// supervises nobody — and this is the record shown to a client who asks.
class ImpersonationsScreen extends StatefulWidget {
  const ImpersonationsScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<ImpersonationsScreen> createState() => _ImpersonationsScreenState();
}

class _ImpersonationsScreenState extends State<ImpersonationsScreen> {
  bool _loading = true;
  bool _activeOnly = false;
  String? _error;
  List<Map<String, dynamic>> _sessions = [];
  String _query = '';
  Timer? _debounce;

  /// The sessions whose visible text contains the search, case-insensitively.
  ///
  /// Until StaffController@impersonations reads q, the loaded rows are
  /// filtered here too.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _sessions;
    return _sessions.where((session) {
      final org = session['organization'] as Map? ?? const {};
      final who = session['staff'] as Map? ?? const {};
      final text = [
        org['name'],
        who['name'],
        who['email'],
        session['reason'],
        session['client_approved'] == true
            ? 'Client approved'
            : 'No client approval',
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. Refresh,
      // the "Active only" filter and "End now" all arrive here with rows on
      // screen; only the first load has nothing to preserve. The filter shows
      // the previous rows for the length of one request, which is the same
      // history either way — the chip is what says a narrower set is coming.
      _loading = _sessions.isEmpty;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/impersonations', {
      if (_activeOnly) 'active': '1',
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _sessions = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _end(Map<String, dynamic> session) async {
    final response = await widget.staff.api.delete(
      '/staff/impersonations/${session['id']}',
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Client access',
          note:
              'Every time EzBizi staff opened a client\'s data. Written for '
              'every tier, including Lead Developer.',
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Standard field height, as tall as Leads search (Jose, 2026-10-27).
              SizedBox(
                width: 240,
                child: TextField(
                  key: const Key('impersonations-search'),
                  textAlignVertical: TextAlignVertical.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIconConstraints: BoxConstraints(minWidth: 36),
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find a session',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) {
                    setState(() => _query = v);
                    _debounce?.cancel();
                    _debounce = Timer(const Duration(milliseconds: 400), _load);
                  },
                ),
              ),
              FilterChip(
                label: const Text('Active only'),
                selected: _activeOnly,
                onSelected: (v) {
                  setState(() => _activeOnly = v);
                  _load();
                },
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
            child: _shown.isEmpty && _query.trim().isNotEmpty
                ? Padding(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    child: Text(
                      'No sessions match "${_query.trim()}".',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  )
                : _sessions.isEmpty
                ? const Center(child: Text('Nothing recorded yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _shown.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _shown[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> session) {
    final org = session['organization'] as Map<String, dynamic>;
    final who = session['staff'] as Map<String, dynamic>;
    final active = session['is_active'] as bool? ?? false;
    final approved = session['client_approved'] as bool? ?? false;
    final mine = who['id'] == widget.staff.me!.id;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  active ? Icons.lock_open : Icons.lock_outline,
                  size: 18,
                  color: active ? Theme.of(context).colorScheme.error : null,
                  semanticLabel: active ? 'Open now' : 'Closed',
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${org['name'] ?? 'Unknown organization'}',
                    style: Theme.of(context).textTheme.titleMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                // Only the staff member who opened it may close it — anyone else
                // would write an end time attributed to the wrong person.
                if (active && mine)
                  TextButton(
                    onPressed: () => _end(session),
                    child: const Text('End now'),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              session['reason'] as String? ?? '',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 16,
              runSpacing: 4,
              children: [
                _meta(
                  context,
                  '${who['name'] ?? 'unknown'} <${who['email'] ?? ''}>',
                ),
                _meta(context, 'Started ${_stamp(session['started_at'])}'),
                _meta(
                  context,
                  session['ended_at'] != null
                      ? 'Ended ${_stamp(session['ended_at'])}'
                      : 'Expires ${_stamp(session['expires_at'])}',
                ),
                _meta(
                  context,
                  approved ? 'Client approved' : 'No client approval',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _meta(BuildContext context, String text) =>
      Text(text, style: Theme.of(context).textTheme.bodyMedium);

  /// The control plane spans every tenant (§4.2), so there is no organization
  /// whose `date_format` could apply — a list of impersonations across four
  /// customers has four answers and must pick none of them. It states the
  /// platform format rather than defaulting to it, and ISO is the right choice
  /// for a reader comparing timestamps between organizations anyway.
  String _stamp(dynamic iso) =>
      formatStamp(iso, '—', formats: DisplayFormats.platform);
}
