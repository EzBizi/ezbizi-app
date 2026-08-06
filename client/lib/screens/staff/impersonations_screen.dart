import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
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

    final response = await widget.staff.api.get(
      '/staff/impersonations',
      _activeOnly ? {'active': '1'} : null,
    );

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
    final response =
        await widget.staff.api.delete('/staff/impersonations/${session['id']}');

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(response.message)));
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
          note: 'Every time EzBizi staff opened a client\'s data. Written for '
              'every tier, including Lead Developer.',
          action: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
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
            child: _sessions.isEmpty
                ? const Center(child: Text('Nothing recorded yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _sessions.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _sessions[i]),
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
                  TextButton(onPressed: () => _end(session), child: const Text('End now')),
              ],
            ),
            const SizedBox(height: 8),
            Text(session['reason'] as String? ?? '',
                style: Theme.of(context).textTheme.bodyMedium),
            const SizedBox(height: 10),
            Wrap(
              spacing: 16,
              runSpacing: 4,
              children: [
                _meta(context, '${who['name'] ?? 'unknown'} <${who['email'] ?? ''}>'),
                _meta(context, 'Started ${_stamp(session['started_at'])}'),
                _meta(
                  context,
                  session['ended_at'] != null
                      ? 'Ended ${_stamp(session['ended_at'])}'
                      : 'Expires ${_stamp(session['expires_at'])}',
                ),
                _meta(context, approved ? 'Client approved' : 'No client approval'),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _meta(BuildContext context, String text) =>
      Text(text, style: Theme.of(context).textTheme.bodyMedium);

  /// ISO-8601 from the server, shown in the reader's own zone. Formatting is
  /// deliberately plain — a wrong "2 hours ago" is worse than an exact stamp.
  String _stamp(dynamic iso) {
    if (iso is! String) return '—';

    final at = DateTime.tryParse(iso)?.toLocal();
    if (at == null) return '—';

    String two(int n) => n.toString().padLeft(2, '0');

    return '${at.year}-${two(at.month)}-${two(at.day)} ${two(at.hour)}:${two(at.minute)}';
  }
}
