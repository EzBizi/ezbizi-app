import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/list_search.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

/// Auto-replies (Jose, 2026-11-10: "are auto-replies going out? I'd like a
/// sub-tab where I can see them, so it shows the customer review and our
/// reply"). Every review the AI answered or drafted, newest first, with the
/// counts on top; a waiting draft can be approved, edited or discarded here.
class AutoRepliesTab extends StatefulWidget {
  const AutoRepliesTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<AutoRepliesTab> createState() => _AutoRepliesTabState();
}

const _statusWords = {
  'published': ('Published', Tone.ok),
  'draft': ('Waiting', Tone.caution),
  'failed': ('Failed', Tone.bad),
};

class _AutoRepliesTabState extends State<AutoRepliesTab> {
  List<Map<String, dynamic>>? _rows;
  Map<String, dynamic> _counts = const {};
  String? _note;
  String? _error;
  String? _notice;
  String? _status;
  String? _branch;
  int? _stars;
  int? _days = 30;
  String _q = '';
  String? _editing;
  final _edit = TextEditingController();
  final Set<String> _busy = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _edit.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/reviews/auto-replies', {
      'status': ?_status,
      'branch': ?_branch,
      if (_stars != null) 'stars': '$_stars',
      if (_days != null)
        'from': DateTime.now()
            .subtract(Duration(days: _days!))
            .toUtc()
            .toIso8601String(),
      if (_q.trim().isNotEmpty) 'q': _q.trim(),
    });
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _rows = r.ok
          ? [
              for (final m in (r.data as List? ?? const []).whereType<Map>())
                Map<String, dynamic>.from(m),
            ]
          : null;
      final meta = (r.body['meta'] as Map?) ?? const {};
      _counts = Map<String, dynamic>.from(meta['counts'] as Map? ?? const {});
      _note = meta['drafts_note'] as String?;
    });
  }

  Future<void> _act(
    Map<String, dynamic> row,
    Future<ApiResponse> call,
    String done,
  ) async {
    final id = '${row['id']}';
    setState(() => _busy.add(id));
    final r = await call;
    if (!mounted) return;
    setState(() {
      _busy.remove(id);
      _notice = r.ok ? done : r.message;
      if (r.ok) _editing = null;
    });
    if (r.ok) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    int count(String k) => (_counts[k] as num?)?.toInt() ?? 0;
    final branches = <String, String>{
      for (final r in rows ?? const <Map<String, dynamic>>[])
        if (r['franchise'] case final Map f) '${f['id']}': '${f['name']}',
    };

    return ListView(
      key: const ValueKey('cx-auto-replies'),
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ChoiceChip(
              key: const ValueKey('auto-replies-all'),
              label: Text(
                'All · ${count('published') + count('draft') + count('failed')}',
              ),
              selected: _status == null,
              onSelected: (_) {
                setState(() => _status = null);
                _load();
              },
            ),
            for (final MapEntry(key: k, value: (word, _))
                in _statusWords.entries)
              ChoiceChip(
                key: ValueKey('auto-replies-$k'),
                label: Text('$word · ${count(k)}'),
                selected: _status == k,
                onSelected: (_) {
                  setState(() => _status = _status == k ? null : k);
                  _load();
                },
              ),
          ],
        ),
        if (_note != null && count('draft') > 0)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _note!,
              key: const ValueKey('auto-replies-why'),
              style: theme.textTheme.bodySmall,
            ),
          ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SizedBox(
              width: 240,
              child: ListSearch(
                key: const ValueKey('auto-replies-search'),
                hint: 'Name or words in the review',
                onSubmitted: (v) {
                  _q = v;
                  _load();
                },
                onClear: () {
                  _q = '';
                  _load();
                },
              ),
            ),
            SizedBox(
              width: 180,
              child: SearchPickerField<String?>(
                key: const ValueKey('auto-replies-branch'),
                value: branches.containsKey(_branch) ? _branch : null,
                hint: 'All branches',
                isDense: true,
                options: [
                  const SearchOption(value: null, label: 'All branches'),
                  for (final MapEntry(key: id, value: name) in branches.entries)
                    SearchOption(value: id, label: name),
                ],
                onChanged: (v) {
                  setState(() => _branch = v);
                  _load();
                },
              ),
            ),
            SizedBox(
              width: 180,
              child: SearchPickerField<int?>(
                key: const ValueKey('auto-replies-stars'),
                value: _stars,
                hint: 'Any stars',
                isDense: true,
                options: [
                  const SearchOption(value: null, label: 'Any stars'),
                  for (var s = 5; s >= 1; s--)
                    SearchOption(value: s, label: '$s ★'),
                ],
                onChanged: (v) {
                  setState(() => _stars = v);
                  _load();
                },
              ),
            ),
            SizedBox(
              width: 180,
              child: SearchPickerField<int?>(
                key: const ValueKey('auto-replies-days'),
                value: _days,
                options: const [
                  SearchOption(value: 7, label: 'Last 7 days'),
                  SearchOption(value: 30, label: 'Last 30 days'),
                  SearchOption(value: 90, label: 'Last 90 days'),
                  SearchOption(value: null, label: 'All time'),
                ],
                onChanged: (v) {
                  setState(() => _days = v);
                  _load();
                },
              ),
            ),
          ],
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_notice!, style: theme.textTheme.bodySmall),
          ),
        const SizedBox(height: 12),
        if (_error != null)
          Text(_error!, style: TextStyle(color: theme.colorScheme.error))
        else if (rows == null)
          const Center(child: CircularProgressIndicator())
        else if (rows.isEmpty)
          Text(
            'No AI replies match these filters.',
            key: const ValueKey('auto-replies-empty'),
            style: theme.textTheme.bodyMedium,
          )
        else
          for (final r in rows) _card(theme, r),
      ],
    );
  }

  Widget _card(ThemeData theme, Map<String, dynamic> r) {
    final reply = Map<String, dynamic>.from(r['reply'] as Map? ?? const {});
    final (word, tone) =
        _statusWords[reply['status']] ?? ('${reply['status']}', Tone.neutral);
    final stars = (r['rating'] as num?)?.toInt() ?? 0;
    final when = DateTime.tryParse('${r['reviewed_at'] ?? ''}')?.toLocal();
    final sent = DateTime.tryParse('${reply['published_at'] ?? ''}')?.toLocal();
    final place = [
      (r['company'] as Map?)?['name'],
      (r['franchise'] as Map?)?['name'],
    ].whereType<String>().join(' · ');
    final id = '${r['id']}';
    final busy = _busy.contains(id);
    final draft = reply['status'] == 'draft' || reply['status'] == 'failed';
    String date(DateTime d) => '${d.month}/${d.day}/${d.year}';

    return Card(
      key: ValueKey('auto-reply-$id'),
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${r['reviewer_name'] ?? 'A Google user'} · ${'★' * stars}',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                StatusPill(word, tone: tone),
              ],
            ),
            Text(
              [
                if (place.isNotEmpty) place,
                if (when != null) date(when),
              ].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
            if ('${r['comment'] ?? ''}'.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  '${r['comment']}',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border(
                  left: BorderSide(color: EzBiziTheme.edge(context), width: 3),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    [
                      'Our reply · AI',
                      if (sent != null) 'published ${date(sent)}',
                      if (reply['failure_reason'] != null)
                        '${reply['failure_reason']}',
                    ].join(' · '),
                    style: theme.textTheme.labelSmall,
                  ),
                  const SizedBox(height: 4),
                  if (_editing == id)
                    TextField(
                      key: const ValueKey('auto-reply-edit-field'),
                      controller: _edit,
                      maxLines: null,
                      maxLength: 4096,
                    )
                  else
                    Text(
                      '${reply['body'] ?? ''}',
                      style: theme.textTheme.bodyMedium,
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (draft) ...[
                  FilledButton(
                    key: const ValueKey('auto-reply-publish'),
                    onPressed: busy
                        ? null
                        : () async {
                            if (_editing == id) {
                              final saved = await widget.api.put(
                                '/reviews/$id/reply',
                                {'body': _edit.text.trim()},
                              );
                              if (!saved.ok) {
                                setState(() => _notice = saved.message);
                                return;
                              }
                            }
                            await _act(
                              r,
                              widget.api.post(
                                '/reviews/$id/reply/publish',
                                const {},
                              ),
                              'Published on Google.',
                            );
                          },
                    child: const Text('Approve & publish'),
                  ),
                  OutlinedButton(
                    key: const ValueKey('auto-reply-edit'),
                    onPressed: busy || _editing == id
                        ? null
                        : () => setState(() {
                            _editing = id;
                            _edit.text = '${reply['body'] ?? ''}';
                          }),
                    child: const Text('Edit'),
                  ),
                  OutlinedButton(
                    key: const ValueKey('auto-reply-discard'),
                    onPressed: busy
                        ? null
                        : () => _act(
                            r,
                            widget.api.delete('/reviews/$id/reply'),
                            'Draft discarded.',
                          ),
                    child: const Text('Discard'),
                  ),
                ],
                TextButton.icon(
                  key: const ValueKey('auto-reply-google'),
                  onPressed: () => launchUrl(
                    Uri.https('www.google.com', '/maps/search/', {
                      'api': '1',
                      'query': place.isEmpty
                          ? '${r['reviewer_name']} review'
                          : place,
                    }),
                    mode: LaunchMode.externalApplication,
                  ),
                  icon: const Icon(Icons.open_in_new, size: 16),
                  label: const Text('Open on Google'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
