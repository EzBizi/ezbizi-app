import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import 'dashboard_card.dart';
import 'side_panel.dart';
import 'status_pill.dart';

/// Every Workiz account's tags against one approved standard (design 191).
///
/// Jose, 11-10: "a sub tab where we can see all existing tags per account so
/// if someone in the future changes a tag, we know about it and get a
/// warning". Drawn under the Workiz provider list, for whoever may connect
/// Workiz (the server's gate on both endpoints).
class WorkizTagsSection extends StatefulWidget {
  const WorkizTagsSection({super.key, required this.api});

  final ApiClient api;

  @override
  State<WorkizTagsSection> createState() => _WorkizTagsSectionState();
}

class _WorkizTagsSectionState extends State<WorkizTagsSection> {
  final _search = TextEditingController();
  final _logKey = GlobalKey();
  Map<String, dynamic>? _data;
  String? _error;
  bool _onlyDifferences = false;
  bool _approving = false;

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
    final r = await widget.api.get('/workiz/tags');
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        _data = (r.body['data'] as Map).cast<String, dynamic>();
        _error = null;
      } else {
        _error = 'The Workiz tags could not be loaded.';
      }
    });
  }

  List<Map<String, dynamic>> get _accounts =>
      ((_data?['accounts'] as List?) ?? const [])
          .map((a) => (a as Map).cast<String, dynamic>())
          .toList();

  List<Map<String, dynamic>> get _rows =>
      ((_data?['rows'] as List?) ?? const [])
          .map((r) => (r as Map).cast<String, dynamic>())
          .toList();

  List<Map<String, dynamic>> get _changes =>
      ((_data?['changes'] as List?) ?? const [])
          .map((c) => (c as Map).cast<String, dynamic>())
          .toList();

  int get _recent {
    final since = DateTime.now().subtract(const Duration(days: 7));
    return _changes.where((c) {
      final at = DateTime.tryParse('${c['occurred_at'] ?? ''}');
      return at != null && at.isAfter(since);
    }).length;
  }

  Future<void> _approve() async {
    final sure = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Approve current tags as standard',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('workiz-tags-approve-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Approve'),
          ),
        ],
        child: const Text(
          'The tags as they are now become the standard. From then on, any '
          'account whose tag is renamed, recoloured, added or deleted shows '
          'here and warns the admins.',
        ),
      ),
    );
    if (sure != true || !mounted) return;
    setState(() => _approving = true);
    final r = await widget.api.post('/workiz/tags/approve', const {});
    if (!mounted) return;
    setState(() {
      _approving = false;
      if (!r.ok) _error = 'The standard could not be saved.';
    });
    if (r.ok) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_data == null) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: _error == null
            ? const Center(child: CircularProgressIndicator())
            : DashboardNote(_error!),
      );
    }

    final anyDiffer = _rows.any((r) => r['differs'] == true);
    final baseline = _data!['baseline_set'] == true;
    final q = _search.text.trim().toLowerCase();
    final rows = _rows.where((r) {
      if (_onlyDifferences && r['differs'] != true) return false;
      if (q.isEmpty) return true;
      final names = [
        (r['standard'] as Map?)?['name'],
        for (final c in ((r['accounts'] as Map?) ?? const {}).values)
          (c as Map?)?['name'],
      ];
      return names.any((n) => '${n ?? ''}'.toLowerCase().contains(q));
    }).toList();
    final recent = _recent;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        if (recent > 0) ...[
          _Banner(
            text: recent == 1
                ? '1 tag change in Workiz this week'
                : '$recent tag changes in Workiz this week',
            onSee: () => Scrollable.ensureVisible(
              _logKey.currentContext!,
              duration: const Duration(milliseconds: 300),
            ),
          ),
          const SizedBox(height: 12),
        ],
        Row(
          children: [
            Expanded(
              child: Text(
                'Tags · ${_rows.length} tags · ${_accounts.length} accounts',
                style: theme.textTheme.titleSmall,
              ),
            ),
            Tooltip(
              message: anyDiffer || !baseline
                  ? ''
                  : 'Every account already matches the standard.',
              child: FilledButton(
                key: const Key('workiz-tags-approve'),
                onPressed: _approving || (baseline && !anyDiffer)
                    ? null
                    : _approve,
                child: const Text('Approve current tags as standard'),
              ),
            ),
          ],
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          DashboardNote(_error!),
        ],
        if (!baseline) ...[
          const SizedBox(height: 8),
          const DashboardNote(
            'No standard yet. Approve the current tags as the standard, and '
            'any change after that is flagged here.',
          ),
        ],
        const SizedBox(height: 8),
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SizedBox(
              width: 260,
              child: TextField(
                key: const Key('workiz-tags-search'),
                controller: _search,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  isDense: true,
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search tags',
                ),
              ),
            ),
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Switch(
                  key: const Key('workiz-tags-only-differences'),
                  value: _onlyDifferences,
                  onChanged: (v) => setState(() => _onlyDifferences = v),
                ),
                const Text('Show only differences'),
              ],
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (rows.isEmpty)
          const DashboardNote('No tags to show.')
        else
          _Matrix(accounts: _accounts, rows: rows),
        const SizedBox(height: 16),
        Text('Changes', key: _logKey, style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        if (_changes.isEmpty)
          const DashboardNote('No tag changes recorded yet.')
        else
          for (final c in _changes) _ChangeRow(change: c),
      ],
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.text, required this.onSee});

  final String text;
  final VoidCallback onSee;

  @override
  Widget build(BuildContext context) {
    final tone = ToneColours.of(context, Tone.caution);
    return Container(
      key: const Key('workiz-tags-banner'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: tone.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(Icons.warning_amber_rounded, color: tone.ink, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(color: tone.ink, fontWeight: FontWeight.w700),
            ),
          ),
          TextButton(onPressed: onSee, child: const Text('See the log')),
        ],
      ),
    );
  }
}

/// Tags down, accounts across; the standard column stays while the accounts
/// scroll sideways.
// ponytail: the header row scrolls with the page, not pinned; pin it if
// tag lists grow past a screen.
class _Matrix extends StatelessWidget {
  const _Matrix({required this.accounts, required this.rows});

  final List<Map<String, dynamic>> accounts;
  final List<Map<String, dynamic>> rows;

  static const _rowHeight = 40.0;
  static const _cellWidth = 180.0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edge = EzBiziTheme.edge(context);
    final caution = ToneColours.of(context, Tone.caution);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w800,
    );

    Widget cell(Widget child, {Color? fill, double width = _cellWidth}) =>
        Container(
          width: width,
          height: _rowHeight,
          padding: const EdgeInsets.symmetric(horizontal: 10),
          alignment: Alignment.centerLeft,
          decoration: BoxDecoration(
            color: fill,
            border: Border(top: BorderSide(color: edge)),
          ),
          child: child,
        );

    final standard = Column(
      children: [
        cell(Text('STANDARD', style: head)),
        for (final r in rows)
          cell(
            r['standard'] == null
                ? Text(
                    '— not in standard',
                    style: TextStyle(color: caution.ink),
                  )
                : _TagFace(face: (r['standard'] as Map).cast()),
          ),
      ],
    );

    final columns = [
      for (final a in accounts)
        Column(
          children: [
            cell(
              Text(
                '${a['name']}'.toUpperCase(),
                style: head,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            for (final r in rows) _accountCell(r, '${a['id']}', cell, caution),
          ],
        ),
    ];

    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: edge),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              border: Border(right: BorderSide(color: edge)),
            ),
            child: standard,
          ),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(children: columns),
            ),
          ),
        ],
      ),
    );
  }

  Widget _accountCell(
    Map<String, dynamic> row,
    String accountId,
    Widget Function(Widget, {Color? fill, double width}) cell,
    ToneColours caution,
  ) {
    final face = ((row['accounts'] as Map?) ?? const {})[accountId] as Map?;
    final standard = row['standard'] as Map?;
    if (face == null) {
      return cell(
        Text(
          '— missing',
          key: Key('workiz-tag-missing:${row['key']}:$accountId'),
          style: TextStyle(color: caution.ink, fontWeight: FontWeight.w700),
        ),
        fill: caution.fill,
      );
    }
    final differs =
        standard == null ||
        face['name'] != standard['name'] ||
        face['color'] != standard['color'];
    return cell(
      _TagFace(
        key: Key('workiz-tag:${row['key']}:$accountId'),
        face: face.cast(),
      ),
      fill: differs ? caution.fill : null,
    );
  }
}

class _TagFace extends StatelessWidget {
  const _TagFace({super.key, required this.face});

  final Map<String, dynamic> face;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Container(
        width: 10,
        height: 10,
        decoration: BoxDecoration(
          color: _colour('${face['color'] ?? ''}', context),
          borderRadius: BorderRadius.circular(3),
        ),
      ),
      const SizedBox(width: 6),
      Flexible(child: Text('${face['name']}', overflow: TextOverflow.ellipsis)),
    ],
  );

  /// Workiz's own colour, shown as Workiz shows it; anything that is not a
  /// hex value falls back to the neutral ink.
  static Color _colour(String raw, BuildContext context) {
    final hex = raw.replaceFirst('#', '');
    final value = int.tryParse(hex, radix: 16);
    if (hex.length != 6 || value == null) {
      return ToneColours.of(context, Tone.neutral).ink;
    }
    return Color(value | 0xFF000000);
  }
}

class _ChangeRow extends StatelessWidget {
  const _ChangeRow({required this.change});

  final Map<String, dynamic> change;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final at = DateTime.tryParse('${change['occurred_at'] ?? ''}')?.toLocal();
    final kind = '${change['change']}';
    return Container(
      constraints: const BoxConstraints(minHeight: 40),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Row(
        children: [
          SizedBox(
            width: 96,
            child: Text(
              at == null
                  ? ''
                  : MaterialLocalizations.of(context).formatShortDate(at),
              style: theme.textTheme.bodySmall,
            ),
          ),
          Expanded(
            child: Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: '${change['account'] ?? ''}  ',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  TextSpan(text: '${change['summary'] ?? ''}'),
                ],
              ),
            ),
          ),
          StatusPill(switch (kind) {
            'renamed' => 'Renamed',
            'recoloured' => 'Recoloured',
            'added' => 'Added',
            'deleted' => 'Deleted',
            _ => kind,
          }, tone: kind == 'deleted' ? Tone.bad : Tone.caution),
        ],
      ),
    );
  }
}
