/// AI Assistance › Parts, Manuals and Answers — the Chimney Tech Assistant
/// (approved mockup tech-assistant.html, 2026-10-23).
///
/// Parts is what the assistant may name; Manuals what it reads; Answers every
/// answer it gave, each marked Correct or Incorrect. An incorrect answer's
/// correction becomes a Library entry the assistant reads from then on.
library;

import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

const partStatuses = {
  'confirmed': ('Confirmed', Tone.ok),
  'high_confidence': ('High confidence', Tone.info),
  'possible': ('Possible', Tone.caution),
  'unverified': ('Unverified', Tone.neutral),
  'needs_review': ('Needs review', Tone.caution),
  'discontinued': ('Discontinued', Tone.bad),
};

const partSources = {
  'manual': 'Manual',
  'manufacturer': 'Manufacturer',
  'supplier': 'Supplier site',
  'tech_correction': 'Tech correction',
};

String _s(Object? v) => v == null ? '' : '$v';

List<String> _list(Object? v) =>
    v is List ? v.map((e) => '$e').toList() : const [];

/// A search box as tall as the buttons beside it, as the Library's.
Widget _search(Key key, String hint, ValueChanged<String> onChanged) =>
    SizedBox(
      width: 260,
      child: ListSearch(
        key: key,
        hint: hint,
        onChanged: onChanged,
      ),
    );

// ── Parts ───────────────────────────────────────────────────────────

class PartsTab extends StatefulWidget {
  const PartsTab({super.key, required this.api, required this.canEdit});

  final ApiClient api;
  final bool canEdit;

  @override
  State<PartsTab> createState() => _PartsTabState();
}

class _PartsTabState extends State<PartsTab> {
  List<Map<String, dynamic>>? _parts;
  Map<String, dynamic> _summary = const {};
  String? _error;
  String? _status;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/ai-assistance/parts');
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _parts = (response.body['data'] as List).cast<Map<String, dynamic>>();
        _summary =
            (response.body['summary'] as Map?)?.cast<String, dynamic>() ??
            const {};
      }
    });
  }

  bool _matches(Map<String, dynamic> p) {
    if (_status != null && p['status'] != _status) return false;
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      p['brand'],
      p['model'],
      p['part_name'],
      p['part_number'],
      ..._list(p['part_aliases']),
      ..._list(p['model_aliases']),
    ].any((v) => _s(v).toLowerCase().contains(q));
  }

  Future<void> _edit([Map<String, dynamic>? part]) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _PartPanel(api: widget.api, part: part),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final parts = _parts;
    if (_error != null) return Center(child: ErrorNote(_error!));
    if (parts == null) return const Center(child: CircularProgressIndicator());
    final shown = parts.where(_matches).toList();
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('ai-parts'),
      padding: const EdgeInsets.all(16),
      children: [
        FilterTiles(
          prefix: 'ai-parts',
          picked: _status,
          onPick: (k) => setState(() => _status = k),
          tiles: [
            FilterTile(
              key: null,
              label: 'All parts',
              count: _summary['all'] as int?,
              note: 'across ${_summary['models'] ?? 0} models',
            ),
            FilterTile(
              key: 'confirmed',
              label: 'Confirmed',
              count: _summary['confirmed'] as int?,
              note: 'from a manual page',
              tone: Tone.ok,
            ),
            FilterTile(
              key: 'needs_review',
              label: 'Needs review',
              count: _summary['needs_review'] as int?,
              note: 'flagged by techs',
              tone: Tone.caution,
            ),
            FilterTile(
              key: 'discontinued',
              label: 'Discontinued',
              count: _summary['discontinued'] as int?,
              note: 'with replacement',
              tone: Tone.bad,
            ),
          ],
        ),
        const SizedBox(height: 14),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          alignment: WrapAlignment.spaceBetween,
          children: [
            _search(
              const Key('ai-parts-search'),
              'Find a part, model or number',
              (v) => setState(() => _query = v),
            ),
            if (widget.canEdit)
              FilledButton.icon(
                key: const Key('ai-parts-add'),
                onPressed: () => _edit(),
                icon: const Icon(Icons.add),
                label: const Text('Add part'),
              ),
          ],
        ),
        const SizedBox(height: 14),
        if (shown.isEmpty)
          Text(
            parts.isEmpty
                ? 'No parts yet. The assistant names only part numbers listed '
                      'here or printed in a manual.'
                : 'No parts match.',
            style: theme.textTheme.bodyMedium,
          )
        else
          ListTable(
            columns: const [
              ListColumn('Part', flex: 3),
              ListColumn('Part #'),
              ListColumn('Fits', flex: 3),
              ListColumn('Fuel', flex: 1),
              ListColumn('Source'),
              ListColumn('Status'),
            ],
            rowKey: (i) => ValueKey('ai-part-${shown[i]['id']}'),
            onTap: widget.canEdit ? (i) => _edit(shown[i]) : null,
            rows: [for (final p in shown) _row(p, theme)],
          ),
      ],
    );
  }

  List<Widget> _row(Map<String, dynamic> p, ThemeData theme) {
    final aliases = _list(p['part_aliases']);
    final status =
        partStatuses[p['status']] ?? ('${p['status']}', Tone.neutral);
    final serial = [
      if (_s(p['serial_from']).isNotEmpty) 'serial ≥ ${p['serial_from']}',
      if (_s(p['serial_to']).isNotEmpty) 'serial ≤ ${p['serial_to']}',
    ].join(', ');
    final superseded = _s(p['superseded_by']);

    return [
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(_s(p['part_name'])),
          if (aliases.isNotEmpty)
            Text(
              'aka ${aliases.map((a) => '"$a"').join(', ')}',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
      Text(_s(p['part_number']).isEmpty ? '—' : _s(p['part_number'])),
      Text(
        [
          '${p['brand']} ${p['model']}',
          if (serial.isNotEmpty) serial,
        ].join(', '),
      ),
      Text(p['fuel'] == 'both' ? 'NG / LP' : _s(p['fuel'])),
      Text(
        [
          partSources[p['source_type']] ?? _s(p['source_type']),
          if (_s(p['source_ref']).isNotEmpty) _s(p['source_ref']),
        ].join(' · '),
      ),
      Align(
        alignment: Alignment.centerLeft,
        child: StatusPill(
          superseded.isNotEmpty && p['status'] == 'discontinued'
              ? '${status.$1} → $superseded'
              : status.$1,
          tone: status.$2,
        ),
      ),
    ];
  }
}

class _PartPanel extends StatefulWidget {
  const _PartPanel({required this.api, this.part});

  final ApiClient api;
  final Map<String, dynamic>? part;

  @override
  State<_PartPanel> createState() => _PartPanelState();
}

class _PartPanelState extends State<_PartPanel> {
  late final Map<String, TextEditingController> _c = {
    for (final k in [
      'brand',
      'model',
      'part_name',
      'part_number',
      'serial_from',
      'serial_to',
      'superseded_by',
      'source_ref',
      'notes',
    ])
      k: TextEditingController(text: _s(widget.part?[k])),
    'model_aliases': TextEditingController(
      text: _list(widget.part?['model_aliases']).join(', '),
    ),
    'part_aliases': TextEditingController(
      text: _list(widget.part?['part_aliases']).join(', '),
    ),
  };
  late String _fuel = _s(widget.part?['fuel']).isEmpty
      ? 'both'
      : _s(widget.part?['fuel']);
  late String _status = _s(widget.part?['status']).isEmpty
      ? 'unverified'
      : _s(widget.part?['status']);
  late String _source = _s(widget.part?['source_type']).isEmpty
      ? 'manual'
      : _s(widget.part?['source_type']);
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  List<String> _split(String key) => _c[key]!.text
      .split(',')
      .map((s) => s.trim())
      .where((s) => s.isNotEmpty)
      .toList();

  String? _orNull(String key) =>
      _c[key]!.text.trim().isEmpty ? null : _c[key]!.text.trim();

  Future<void> _save() async {
    setState(() => _busy = true);
    final body = {
      'brand': _c['brand']!.text.trim(),
      'model': _c['model']!.text.trim(),
      'model_aliases': _split('model_aliases'),
      'part_name': _c['part_name']!.text.trim(),
      'part_aliases': _split('part_aliases'),
      'part_number': _orNull('part_number'),
      'fuel': _fuel,
      'serial_from': _orNull('serial_from'),
      'serial_to': _orNull('serial_to'),
      'status': _status,
      'superseded_by': _orNull('superseded_by'),
      'source_type': _source,
      'source_ref': _orNull('source_ref'),
      'notes': _orNull('notes'),
    };
    final id = widget.part?['id'];
    final response = id == null
        ? await widget.api.post('/ai-assistance/parts', body)
        : await widget.api.patch('/ai-assistance/parts/$id', body);
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.message;
    });
  }

  Future<void> _delete() async {
    final response = await widget.api.delete(
      '/ai-assistance/parts/${widget.part!['id']}',
    );
    if (!mounted) return;
    response.ok
        ? Navigator.of(context).pop(true)
        : setState(() => _error = response.message);
  }

  Widget _field(String key, String label, {int lines = 1}) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      key: Key('ai-part-$key'),
      controller: _c[key],
      minLines: lines,
      maxLines: lines,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
      ),
    ),
  );

  Widget _pick(
    String key,
    String label,
    String value,
    Map<String, String> options,
    ValueChanged<String> onChanged,
  ) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: SearchPickerField<String>(
      key: Key('ai-part-$key'),
      value: value,
      label: label,
      border: const OutlineInputBorder(),
      options: [
        for (final o in options.entries)
          SearchOption(value: o.key, label: o.value),
      ],
      onChanged: (v) => v == null ? null : setState(() => onChanged(v)),
    ),
  );

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.part == null ? 'Add part' : 'Edit part',
      actions: [
        if (widget.part != null)
          TextButton(
            key: const Key('ai-part-delete'),
            onPressed: _busy ? null : _delete,
            child: const Text('Delete'),
          ),
        FilledButton(
          key: const Key('ai-part-save'),
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ErrorNote(_error!),
          _field('brand', 'Brand'),
          _field('model', 'Model'),
          _field('model_aliases', 'Other model names (comma separated)'),
          _field('part_name', 'Part'),
          _field('part_aliases', 'Also called (comma separated)'),
          _field('part_number', 'Part #'),
          _pick('fuel', 'Fuel', _fuel, const {
            'NG': 'NG',
            'LP': 'LP',
            'both': 'NG / LP',
          }, (v) => _fuel = v),
          _field('serial_from', 'Serial from'),
          _field('serial_to', 'Serial to'),
          _pick('status', 'Status', _status, {
            for (final s in partStatuses.entries) s.key: s.value.$1,
          }, (v) => _status = v),
          _field('superseded_by', 'Replaced by part #'),
          _pick(
            'source_type',
            'Source',
            _source,
            partSources,
            (v) => _source = v,
          ),
          _field('source_ref', 'Where (manual, page, link)'),
          _field('notes', 'Notes', lines: 3),
        ],
      ),
    );
  }
}

// ── Manuals ─────────────────────────────────────────────────────────

class ManualsTab extends StatefulWidget {
  const ManualsTab({
    super.key,
    required this.api,
    required this.canEdit,
    this.pickFile = Files.pickBytes,
  });

  final ApiClient api;
  final bool canEdit;
  final Future<PickedBytes?> Function({String accept}) pickFile;

  @override
  State<ManualsTab> createState() => _ManualsTabState();
}

class _ManualsTabState extends State<ManualsTab> {
  List<Map<String, dynamic>>? _manuals;
  String? _error;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/ai-assistance/manuals');
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _manuals = (response.body['data'] as List).cast<Map<String, dynamic>>();
      }
    });
  }

  Future<void> _upload() async {
    final picked = await widget.pickFile(accept: '.pdf');
    if (picked == null || !mounted) return;
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _ManualPanel(api: widget.api, file: picked),
    );
    if (saved == true) _load();
  }

  Future<void> _delete(Map<String, dynamic> m) async {
    final response = await widget.api.delete(
      '/ai-assistance/manuals/${m['id']}',
    );
    if (!mounted) return;
    response.ok ? _load() : setState(() => _error = response.message);
  }

  @override
  Widget build(BuildContext context) {
    final manuals = _manuals;
    if (_error != null) return Center(child: ErrorNote(_error!));
    if (manuals == null) {
      return const Center(child: CircularProgressIndicator());
    }
    final q = _query.trim().toLowerCase();
    final shown = manuals
        .where(
          (m) => [
            m['brand'],
            m['models'],
            m['title'],
            m['revision'],
          ].any((v) => _s(v).toLowerCase().contains(q)),
        )
        .toList();

    return ListView(
      key: const ValueKey('ai-manuals'),
      padding: const EdgeInsets.all(16),
      children: [
        FilterTiles(
          prefix: 'ai-manuals',
          picked: null,
          onPick: (_) {},
          tiles: [
            FilterTile(
              key: null,
              label: 'Manuals',
              count: manuals.length,
              note: 'read by the assistant',
            ),
          ],
          facts: [
            FactTile(
              label: 'Pages',
              value:
                  '${manuals.fold<int>(0, (n, m) => n + ((m['page_count'] as int?) ?? 0))}',
              note: 'searched by model',
            ),
            // Four tiles, like the other AI tabs.
            FactTile(
              label: 'Brands',
              value:
                  '${manuals.map((m) => _s(m['brand']).toLowerCase()).where((b) => b.isNotEmpty).toSet().length}',
              note: 'with a manual',
            ),
            FactTile(
              label: 'Without pages',
              value:
                  '${manuals.where((m) => ((m['page_count'] as int?) ?? 0) == 0).length}',
              note: 'not readable yet',
            ),
          ],
        ),
        const SizedBox(height: 14),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          alignment: WrapAlignment.spaceBetween,
          children: [
            _search(
              const Key('ai-manuals-search'),
              'Find a manual',
              (v) => setState(() => _query = v),
            ),
            if (widget.canEdit)
              FilledButton.icon(
                key: const Key('ai-manuals-upload'),
                onPressed: _upload,
                icon: const Icon(Icons.upload_file),
                label: const Text('Upload manual'),
              ),
          ],
        ),
        const SizedBox(height: 14),
        if (shown.isEmpty)
          const Text(
            'No manuals yet. Upload a PDF and the assistant reads its pages '
            'when a tech asks about that model.',
          )
        else
          ListTable(
            columns: [
              const ListColumn('Manual', flex: 3),
              const ListColumn('Brand'),
              const ListColumn('Models', flex: 3),
              const ListColumn('Pages', flex: 1, end: true),
              if (widget.canEdit) const ListColumn('', flex: 1, end: true),
            ],
            rowKey: (i) => ValueKey('ai-manual-${shown[i]['id']}'),
            rows: [
              for (final m in shown)
                [
                  Text(
                    [
                      _s(m['title']),
                      if (_s(m['revision']).isNotEmpty) 'rev ${m['revision']}',
                    ].join(' · '),
                  ),
                  Text(_s(m['brand'])),
                  Text(_s(m['models'])),
                  m['readable'] == true
                      ? Text('${m['page_count']}')
                      : const StatusPill('Not read', tone: Tone.caution),
                  if (widget.canEdit)
                    IconButton(
                      key: ValueKey('ai-manual-delete-${m['id']}'),
                      tooltip: 'Delete',
                      icon: const Icon(Icons.delete_outline),
                      onPressed: () => _delete(m),
                    ),
                ],
            ],
          ),
      ],
    );
  }
}

class _ManualPanel extends StatefulWidget {
  const _ManualPanel({required this.api, required this.file});

  final ApiClient api;
  final PickedBytes file;

  @override
  State<_ManualPanel> createState() => _ManualPanelState();
}

class _ManualPanelState extends State<_ManualPanel> {
  final _brand = TextEditingController();
  final _models = TextEditingController();
  late final _title = TextEditingController(
    text: widget.file.name.replaceAll(
      RegExp(r'\.pdf$', caseSensitive: false),
      '',
    ),
  );
  final _revision = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_brand, _models, _title, _revision]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final response = await widget.api.postFile(
      '/ai-assistance/manuals',
      'file',
      widget.file.name,
      widget.file.bytes,
      fields: {
        'brand': _brand.text.trim(),
        'models': _models.text.trim(),
        'title': _title.text.trim(),
        'revision': _revision.text.trim(),
      },
    );
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.message;
    });
  }

  Widget _field(Key key, TextEditingController c, String label) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      key: key,
      controller: c,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
      ),
    ),
  );

  @override
  Widget build(BuildContext context) => SidePanel(
    title: 'Upload manual',
    subtitle: widget.file.name,
    actions: [
      FilledButton(
        key: const Key('ai-manual-save'),
        onPressed: _busy ? null : _save,
        child: Text(_busy ? 'Reading…' : 'Upload'),
      ),
    ],
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_error != null) ErrorNote(_error!),
        _field(const Key('ai-manual-brand'), _brand, 'Brand'),
        _field(
          const Key('ai-manual-models'),
          _models,
          'Models (comma separated)',
        ),
        _field(const Key('ai-manual-title'), _title, 'Title'),
        _field(const Key('ai-manual-revision'), _revision, 'Revision'),
      ],
    ),
  );
}

// ── Answers ─────────────────────────────────────────────────────────

/// "whatsapp" -> "WhatsApp", "telegram" -> "Telegram", "app" (or none) -> "App".
String channelLabel(Object? channel) => switch ('${channel ?? ''}') {
  'whatsapp' => 'WhatsApp',
  'telegram' => 'Telegram',
  '' || 'app' => 'App',
  final other => other[0].toUpperCase() + other.substring(1),
};

class AnswersTab extends StatefulWidget {
  const AnswersTab({super.key, required this.api, required this.canEdit});

  final ApiClient api;
  final bool canEdit;

  @override
  State<AnswersTab> createState() => _AnswersTabState();
}

class _AnswersTabState extends State<AnswersTab> {
  List<Map<String, dynamic>>? _answers;
  bool _telegram = false;
  Map<String, dynamic> _summary = const {};
  String? _error;
  String? _filter;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/ai-assistance/answers');
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _answers = (response.body['data'] as List).cast<Map<String, dynamic>>();
        _summary =
            (response.body['summary'] as Map?)?.cast<String, dynamic>() ??
            const {};
        _telegram = response.body['telegram_configured'] == true;
      }
    });
  }

  bool _matches(Map<String, dynamic> a) {
    if (_filter == 'not_yet' && a['order_check'] != 'not_yet') return false;
    if ((_filter == 'correct' || _filter == 'incorrect') &&
        a['review'] != _filter) {
      return false;
    }
    final q = _query.trim().toLowerCase();
    return q.isEmpty ||
        [
          a['user'],
          a['question'],
          a['answer'],
        ].any((v) => _s(v).toLowerCase().contains(q));
  }

  Future<void> _review(
    Map<String, dynamic> a,
    String review, [
    String? correction,
  ]) async {
    final response = await widget.api.post(
      '/ai-assistance/answers/${a['id']}/review',
      {'review': review, 'correction': ?correction},
    );
    if (!mounted) return;
    response.ok ? _load() : setState(() => _error = response.message);
  }

  Future<void> _open(Map<String, dynamic> a) async {
    final correction = await showSidePanel<String>(
      context,
      builder: (_) => _AnswerPanel(answer: a, canEdit: widget.canEdit),
    );
    if (correction != null) await _review(a, 'incorrect', correction);
  }

  String _job(Map<String, dynamic> a) {
    final job = (a['job'] as Map?)?.cast<String, dynamic>();
    if (job == null) return '—';
    final model = _s(job['model']).isEmpty ? '?' : _s(job['model']);
    final fuel = _s(job['fuel']).isEmpty ? '?' : '${job['fuel']} ✔';
    return '$model · $fuel';
  }

  String _short(Map<String, dynamic> a) {
    final lines = _s(a['answer']).split('\n');
    final number = lines
        .firstWhere((l) => l.startsWith('PART #:'), orElse: () => '')
        .replaceFirst('PART #:', '')
        .trim();
    final check = switch (a['order_check']) {
      'ready' => 'Ready to order',
      'not_yet' => 'Do not order yet',
      _ => '',
    };
    final text = [
      if (number.isNotEmpty) number,
      if (check.isNotEmpty) check,
    ].join(' · ');
    return text.isEmpty ? lines.first : text;
  }

  Widget _reviewCell(Map<String, dynamic> a) {
    final review = a['review'];
    if (review == 'correct') return const StatusPill('Correct', tone: Tone.ok);
    if (review == 'incorrect') {
      return const StatusPill('Incorrect', tone: Tone.bad);
    }
    if (review == 'needs_review') {
      return const StatusPill('Needs review', tone: Tone.caution);
    }
    if (!widget.canEdit) return const Text('—');
    return Wrap(
      spacing: 6,
      children: [
        OutlinedButton(
          key: ValueKey('ai-answer-correct-${a['id']}'),
          onPressed: () => _review(a, 'correct'),
          child: const Text('✔ Correct'),
        ),
        OutlinedButton(
          key: ValueKey('ai-answer-incorrect-${a['id']}'),
          onPressed: () => _open(a),
          child: const Text('✘ Incorrect'),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final answers = _answers;
    if (_error != null) return Center(child: ErrorNote(_error!));
    if (answers == null) {
      return const Center(child: CircularProgressIndicator());
    }
    final shown = answers.where(_matches).toList();

    return ListView(
      key: const ValueKey('ai-answers'),
      padding: const EdgeInsets.all(16),
      children: [
        FilterTiles(
          prefix: 'ai-answers',
          picked: _filter,
          onPick: (k) => setState(() => _filter = k),
          tiles: [
            FilterTile(
              key: null,
              label: 'Answers this month',
              count: _summary['this_month'] as int?,
              note: 'WhatsApp, Telegram + app',
            ),
            FilterTile(
              key: 'correct',
              label: 'Marked correct',
              count: _summary['correct'] as int?,
              tone: Tone.ok,
            ),
            FilterTile(
              key: 'incorrect',
              label: 'Marked incorrect',
              count: _summary['incorrect'] as int?,
              note: 'each became a correction',
              tone: Tone.bad,
            ),
            FilterTile(
              key: 'not_yet',
              label: 'Told "do not order"',
              count: _summary['do_not_order'] as int?,
              note: 'info was missing',
              tone: Tone.caution,
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          _telegram
              ? 'Telegram bot: configured'
              : 'Telegram bot: not configured',
          key: const Key('ai-answers-telegram'),
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 14),
        _search(
          const Key('ai-answers-search'),
          'Find an answer',
          (v) => setState(() => _query = v),
        ),
        const SizedBox(height: 14),
        if (shown.isEmpty)
          const Text(
            'No answers yet. Every tech answer, on WhatsApp, Telegram or here, is listed.',
          )
        else
          ListTable(
            columns: const [
              ListColumn('Who'),
              ListColumn('Channel'),
              ListColumn('Question', flex: 3),
              ListColumn('Answer', flex: 3),
              ListColumn('Job'),
              ListColumn('Review', flex: 3),
            ],
            rowKey: (i) => ValueKey('ai-answer-${shown[i]['id']}'),
            onTap: (i) => _open(shown[i]),
            rows: [
              for (final a in shown)
                [
                  Text(_s(a['user']).isEmpty ? '—' : _s(a['user'])),
                  Text(channelLabel(a['channel'])),
                  Text(
                    _s(a['question']),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(_short(a), maxLines: 2, overflow: TextOverflow.ellipsis),
                  Text(_job(a)),
                  _reviewCell(a),
                ],
            ],
          ),
      ],
    );
  }
}

/// The full answer, what it looked at, and a box for the right one. Pops the
/// correction text when sent.
class _AnswerPanel extends StatefulWidget {
  const _AnswerPanel({required this.answer, required this.canEdit});

  final Map<String, dynamic> answer;
  final bool canEdit;

  @override
  State<_AnswerPanel> createState() => _AnswerPanelState();
}

class _AnswerPanelState extends State<_AnswerPanel> {
  late final _correction = TextEditingController(
    text: _s(widget.answer['correction']),
  );

  @override
  void dispose() {
    _correction.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.answer;
    final theme = Theme.of(context);
    final parts = _list(a['part_ids']).length;
    final pages = (a['manual_pages'] as List?)?.length ?? 0;

    return SidePanel(
      key: ValueKey('ai-answer-panel-${a['id']}'),
      title: _s(a['question']),
      subtitle: [
        _s(a['user']),
        channelLabel(a['channel']),
      ].where((s) => s.isNotEmpty).join(' · '),
      actions: [
        if (widget.canEdit)
          FilledButton(
            key: const Key('ai-answer-send-correction'),
            onPressed: () {
              final text = _correction.text.trim();
              if (text.isNotEmpty) Navigator.of(context).pop(text);
            },
            child: const Text('Save correction'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SelectableText(
            _s(a['answer']),
            style: theme.textTheme.bodyMedium?.copyWith(
              fontFamily: 'monospace',
            ),
          ),
          const SizedBox(height: 12),
          Text(
            'Looked at $parts part row${parts == 1 ? '' : 's'} and '
            '$pages manual page${pages == 1 ? '' : 's'}.',
            style: theme.textTheme.bodySmall,
          ),
          if (widget.canEdit) ...[
            const SizedBox(height: 16),
            TextField(
              key: const Key('ai-answer-correction'),
              controller: _correction,
              minLines: 3,
              maxLines: 8,
              decoration: const InputDecoration(
                labelText: 'The right answer',
                helperText:
                    'Saved as a Library entry the assistant reads from now on.',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
