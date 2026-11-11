import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/list_search.dart';

/// Posts to approve (design 176, Jose picked layout 1 + phone 4): the weekly
/// AI Google post for each listing waits here. Approve publishes it (Edit
/// first if wanted), Disapprove drops it with an optional reason opened in
/// place, never a dialog. "Publish without approval" is per listing, off.
class PostQueueView extends StatefulWidget {
  const PostQueueView({super.key, required this.api});

  final ApiClient api;

  @override
  State<PostQueueView> createState() => _PostQueueViewState();
}

/// Below this the list gives way to one post at a time (phone 4).
const double _oneAtATimeBelow = 760;

class _PostQueueViewState extends State<PostQueueView> {
  List<Map<String, dynamic>>? _rows;
  String? _error;
  String? _notice;
  String _q = '';
  String? _openId;
  bool _editing = false;
  bool _disapproving = false;
  bool _busy = false;
  final _body = TextEditingController();
  final _reason = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _body.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/business-profile/post-queue');
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _rows = r.ok
          ? [
              for (final m in (r.data as List? ?? const []).whereType<Map>())
                Map<String, dynamic>.from(m),
            ]
          : null;
      _open(_shown.isEmpty ? null : _shown.first);
    });
  }

  List<Map<String, dynamic>> get _shown {
    final q = _q.trim().toLowerCase();
    return [
      for (final r in _rows ?? const <Map<String, dynamic>>[])
        if (q.isEmpty || _name(r).toLowerCase().contains(q)) r,
    ];
  }

  Map<String, dynamic> _listing(Map<String, dynamic> r) =>
      Map<String, dynamic>.from(r['listing'] as Map? ?? const {});

  String _name(Map<String, dynamic> r) => '${_listing(r)['name'] ?? 'Listing'}';

  Map<String, dynamic>? get _current {
    for (final r in _shown) {
      if (r['id'] == _openId) return r;
    }
    return null;
  }

  void _open(Map<String, dynamic>? r) {
    _openId = r?['id'] as String?;
    _editing = false;
    _disapproving = false;
    _body.text = '${r?['body'] ?? ''}';
    _reason.clear();
  }

  Future<void> _decide(bool approve) async {
    final r = _current;
    if (r == null) return;
    setState(() => _busy = true);
    final res = approve
        ? await widget.api
              .post('/business-profile/post-queue/${r['id']}/approve', {
                if (_editing && _body.text.trim() != '${r['body']}')
                  'body': _body.text.trim(),
              })
        : await widget.api.post(
            '/business-profile/post-queue/${r['id']}/disapprove',
            {if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim()},
          );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!res.ok) {
        _notice = res.message;
        return;
      }
      _notice = approve
          ? 'Approved. Sending to Google; the result shows in '
                'Marketing › Social.'
          : 'Dropped: ${_name(r)}.';
      final i = _shown.indexWhere((x) => x['id'] == r['id']);
      _rows!.removeWhere((x) => x['id'] == r['id']);
      final rest = _shown;
      _open(rest.isEmpty ? null : rest[i.clamp(0, rest.length - 1)]);
    });
  }

  Future<void> _autoPost(Map<String, dynamic> r, bool on) async {
    final id = _listing(r)['id'];
    if (id == null) return;
    final res = await widget.api.put(
      '/business-profile/locations/$id/auto-post',
      {'on': on},
    );
    if (!mounted) return;
    setState(() {
      if (res.ok) {
        (r['listing'] as Map)['auto_post'] = on;
      } else {
        _notice = res.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;

    if (_error != null) {
      return Center(child: Text(_error!, style: theme.textTheme.bodyMedium));
    }
    if (rows == null) return const Center(child: CircularProgressIndicator());
    if (rows.isEmpty) {
      return Center(
        key: const ValueKey('gbp-posts-empty'),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No posts waiting. Each listing gets a new one as soon as the last is decided, and every day at 1 PM.',
            style: theme.textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < _oneAtATimeBelow;
        final shown = _shown;
        final current = _current;
        final search = ListSearch(
          key: const ValueKey('gbp-posts-search'),
          hint: 'Find a listing',
          onChanged: (v) => setState(() {
            _q = v;
            if (_current == null) _open(_shown.isEmpty ? null : _shown.first);
          }),
        );
        final notice = _notice == null
            ? null
            : Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Text(
                  _notice!,
                  key: const ValueKey('gbp-posts-notice'),
                  style: theme.textTheme.bodySmall,
                ),
              );

        if (phone) {
          final i = current == null ? -1 : shown.indexOf(current);
          return SingleChildScrollView(
            key: const ValueKey('gbp-posts'),
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                search,
                ?notice,
                const SizedBox(height: 8),
                if (current == null)
                  Text('Nothing matches.', style: theme.textTheme.bodySmall)
                else ...[
                  _pane(theme, current),
                  if (shown.length > 1)
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton(
                        key: const ValueKey('gbp-posts-next'),
                        onPressed: () => setState(
                          () => _open(shown[(i + 1) % shown.length]),
                        ),
                        child: Text(
                          'Next listing: ${i + 1} of ${shown.length} ›',
                        ),
                      ),
                    ),
                ],
              ],
            ),
          );
        }

        return Row(
          key: const ValueKey('gbp-posts'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: 320,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(padding: const EdgeInsets.all(12), child: search),
                  const Divider(height: 1),
                  Expanded(
                    child: ListView.separated(
                      itemCount: shown.length,
                      separatorBuilder: (_, _) =>
                          Divider(height: 1, color: EzBiziTheme.edge(context)),
                      itemBuilder: (context, i) {
                        final r = shown[i];
                        return ListTile(
                          key: ValueKey('gbp-post-row-${r['id']}'),
                          selected: r['id'] == _openId,
                          title: Text(_name(r)),
                          subtitle: Text(_drafted(r)),
                          onTap: () => setState(() => _open(r)),
                        );
                      },
                    ),
                  ),
                ],
              ),
            ),
            VerticalDivider(width: 1, color: EzBiziTheme.edge(context)),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  ?notice,
                  if (current == null)
                    Text('Nothing matches.', style: theme.textTheme.bodySmall)
                  else
                    Align(
                      alignment: Alignment.topLeft,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 560),
                        child: _pane(theme, current),
                      ),
                    ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  String _drafted(Map<String, dynamic> r) {
    final at = DateTime.tryParse('${r['created_at'] ?? ''}')?.toLocal();
    if (at == null) return 'Drafted';
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    return 'Drafted ${days[at.weekday - 1]} $h:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'AM' : 'PM'}';
  }

  /// The listing header with its switch, the post as Google shows it, and the three buttons.
  Widget _pane(ThemeData theme, Map<String, dynamic> r) {
    final l = _listing(r);
    final name = _name(r);
    final business = name.split(' · ').first;
    final city = '${l['address'] ?? ''}';

    return Column(
      key: const ValueKey('gbp-post-pane'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(name, style: theme.textTheme.titleMedium),
                  if (city.isNotEmpty)
                    Text(city, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            Text('Publish without approval', style: theme.textTheme.bodySmall),
            Switch(
              key: const ValueKey('gbp-post-auto'),
              value: l['auto_post'] == true,
              onChanged: (v) => _autoPost(r, v),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Card(
          key: const ValueKey('gbp-post-preview'),
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(business, style: theme.textTheme.titleSmall),
                Text(
                  [
                    if (city.isNotEmpty) city.split(',').first,
                    'Update',
                  ].join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 10),
                _editing
                    ? TextField(
                        key: const ValueKey('gbp-post-edit-field'),
                        controller: _body,
                        maxLines: null,
                        maxLength: 1500,
                      )
                    : Text(
                        '${r['body'] ?? ''}',
                        style: theme.textTheme.bodyMedium,
                      ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton.icon(
              key: const ValueKey('gbp-post-approve'),
              onPressed: _busy ? null : () => _decide(true),
              icon: const Icon(Icons.check),
              label: const Text('Approve'),
            ),
            OutlinedButton.icon(
              key: const ValueKey('gbp-post-edit'),
              onPressed: _busy || _editing
                  ? null
                  : () => setState(() => _editing = true),
              icon: const Icon(Icons.edit_outlined),
              label: const Text('Edit'),
            ),
            OutlinedButton.icon(
              key: const ValueKey('gbp-post-disapprove'),
              onPressed: _busy
                  ? null
                  : () => _disapproving
                        ? _decide(false)
                        : setState(() => _disapproving = true),
              icon: const Icon(Icons.close),
              label: Text(_disapproving ? 'Disapprove' : 'Disapprove…'),
            ),
          ],
        ),
        if (_disapproving)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              key: const ValueKey('gbp-post-reason'),
              controller: _reason,
              decoration: const InputDecoration(labelText: 'Why? (optional)'),
              onSubmitted: (_) => _decide(false),
            ),
          ),
      ],
    );
  }
}
