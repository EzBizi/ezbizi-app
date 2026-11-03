import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import 'status_pill.dart';

/// Leads > Manage > Templates: the canned replies beside Send, one list per
/// company, edited where they are used (Jose, 2026-09-30: "add a template
/// manager so they can be changed from within the app"). Each brand signs its
/// own name, which is why the list is per company - a garage-door customer
/// was sent the chimney prices the same day.
///
/// UI queue 38's 1 and 4 (Jose, 2026-09-30, "as u recommend", after "it
/// looks terrible"): on a desk the companies, that company's templates and
/// the open template side by side; on a phone the same three as steps, each
/// with ← back. Codes go in with Insert buttons, and the customer's version
/// shows as it is typed.
class ReplyTemplatesManager extends StatefulWidget {
  const ReplyTemplatesManager({super.key, required this.api, this.onDeeper});

  final ApiClient api;

  /// True while a phone shows a step past the first. That step takes the
  /// whole area, so the page around this hides its own header and tabs.
  final ValueChanged<bool>? onDeeper;

  /// Wide enough for the three columns side by side.
  static const double sideBySideFrom = 860;

  @override
  State<ReplyTemplatesManager> createState() => _ManagerState();
}

/// The codes a template may carry, as LeadsController fills them in.
const templateCodes = <({String code, String label})>[
  (code: '{{greeting}}', label: 'Greeting'),
  (code: '{{company}}', label: 'Company name'),
  (code: '{{missing}}', label: "What's missing"),
  (code: '{{availability}}', label: 'Availability'),
];

/// A template as a customer called Mark, with an address still to give,
/// would get it from [company].
String templatePreview(String text, String company) => text
    .replaceAll('{{greeting}}', 'Hi Mark')
    .replaceAll('{{company}}', company)
    .replaceAll('{{missing}}', 'full service address')
    .replaceAll('{{availability}}', 'Thursday: 8-11am or 2-5pm');

enum _Step { companies, templates, template }

class _ManagerState extends State<ReplyTemplatesManager> {
  bool _loading = true;
  bool _mayEdit = false;
  String? _loadError;
  List<({String id, String name})> _companies = const [];

  /// What the server holds, per company.
  final _saved = <String, List<({String name, String text})>>{};

  /// What is being edited, per company, made the first time a company is
  /// opened. A company keeps its draft while another one is open, so moving
  /// between them loses nothing.
  final _drafts = <String, List<_Draft>>{};

  String? _companyId;
  int _open = 0;
  String _query = '';
  _Step _step = _Step.companies;

  bool _saving = false;
  String? _error;
  String? _note;

  /// Copy to other companies: the list is open, and who is ticked.
  bool _copying = false;
  final _copyTo = <String>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final list in _drafts.values) {
      for (final d in list) {
        d.dispose();
      }
    }
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/leads/reply-templates');
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _loading = false;
        _loadError = response.reason;
      });
      return;
    }
    final body = response.body as Map<String, dynamic>? ?? const {};
    final rows = (body['data'] as List? ?? const []).cast<Map>();
    setState(() {
      _loading = false;
      _mayEdit = (body['meta'] as Map?)?['may_edit'] == true;
      _companies = [
        for (final r in rows)
          (
            id: '${(r['company'] as Map)['id']}',
            name: '${(r['company'] as Map)['name']}',
          ),
      ];
      for (final r in rows) {
        _saved['${(r['company'] as Map)['id']}'] = _rowsOf(r['templates']);
      }
      _companyId = _companies.isEmpty ? null : _companies.first.id;
      _step = _companies.length > 1 ? _Step.companies : _Step.templates;
    });
  }

  static List<({String name, String text})> _rowsOf(Object? raw) => [
    for (final t in (raw as List? ?? const []).cast<Map>())
      (name: '${t['name']}', text: '${t['text']}'),
  ];

  String get _companyName =>
      _companies.firstWhere((c) => c.id == _companyId).name;

  List<_Draft> _draftOf(String id) => _drafts[id] ??= [
    for (final t in _saved[id] ?? const <({String name, String text})>[])
      _Draft(t.name, t.text, _changed),
  ];

  void _changed() => setState(() {});

  /// [_open] kept inside a list of [n], which may have just shrunk.
  int _openWithin(int n) => n == 0 ? 0 : _open.clamp(0, n - 1);

  bool _dirty(String id) {
    final draft = _drafts[id];
    if (draft == null) return false;
    final saved = _saved[id] ?? const [];
    if (draft.length != saved.length) return true;
    for (var i = 0; i < draft.length; i++) {
      if (draft[i].name.text.trim() != saved[i].name ||
          draft[i].text.text.trim() != saved[i].text) {
        return true;
      }
    }
    return false;
  }

  void _dropDraft(String id) {
    for (final d in _drafts.remove(id) ?? const <_Draft>[]) {
      d.dispose();
    }
  }

  /// A phone's steps past the first take the page; say so to it.
  void _go(_Step step) {
    _step = step;
    final first = _companies.length > 1 ? _Step.companies : _Step.templates;
    widget.onDeeper?.call(step != first);
  }

  void _pick(String id) => setState(() {
    _companyId = id;
    _open = 0;
    _note = null;
    _error = null;
    _copying = false;
    _copyTo.clear();
    _go(_Step.templates);
  });

  void _openTemplate(int i) => setState(() {
    _open = i;
    _copying = false;
    _copyTo.clear();
    _go(_Step.template);
  });

  void _back() => setState(
    () => _go(_step == _Step.template ? _Step.templates : _Step.companies),
  );

  void _add() => setState(() {
    final draft = _draftOf(_companyId!);
    draft.add(_Draft('', '', _changed));
    _open = draft.length - 1;
    _note = null;
    _go(_Step.template);
  });

  void _delete() => setState(() {
    final draft = _draftOf(_companyId!);
    draft.removeAt(_open).dispose();
    _open = _openWithin(draft.length);
    _go(_Step.templates);
  });

  void _reorder(int from, int to) => setState(() {
    final draft = _draftOf(_companyId!);
    final open = draft[_open];
    if (to > from) to -= 1;
    draft.insert(to, draft.removeAt(from));
    _open = draft.indexOf(open);
  });

  void _discard() => setState(() {
    _dropDraft(_companyId!);
    _open = _openWithin(_draftOf(_companyId!).length);
    _error = null;
    _note = null;
  });

  /// At the cursor, or at the end when the message was never touched.
  void _insert(String code) {
    final text = _draftOf(_companyId!)[_open].text;
    final at = text.selection.isValid
        ? text.selection
        : TextSelection.collapsed(offset: text.text.length);
    text.value = TextEditingValue(
      text: text.text.replaceRange(at.start, at.end, code),
      selection: TextSelection.collapsed(offset: at.start + code.length),
    );
  }

  Future<void> _save() async {
    final id = _companyId!;
    final draft = _draftOf(id);
    if (draft.any(
      (d) => d.name.text.trim().isEmpty || d.text.text.trim().isEmpty,
    )) {
      setState(() => _error = 'Every template needs a name and a message.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _note = null;
    });
    final response = await widget.api.put('/leads/reply-templates/$id', {
      'templates': [
        for (final d in draft)
          {'name': d.name.text.trim(), 'text': d.text.text.trim()},
      ],
    });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _saved[id] = _rowsOf(
        ((response.body as Map)['data'] as Map)['templates'],
      );
      _dropDraft(id);
      _open = _openWithin(_draftOf(id).length);
      _note = 'Saved.';
    });
  }

  /// The open template into each ticked company: over one of the same name,
  /// or at the end of its list. Each company saves as it goes.
  Future<void> _copy() async {
    final source = _draftOf(_companyId!)[_open];
    final name = source.name.text.trim();
    final text = source.text.text.trim();
    if (name.isEmpty || text.isEmpty) {
      setState(() => _error = 'Give it a name and a message first.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _note = null;
    });
    var done = 0;
    final failed = <String>[];
    for (final id in _copyTo.toList()) {
      final rows = [...?_saved[id]];
      final same = rows.indexWhere(
        (t) => t.name.toLowerCase() == name.toLowerCase(),
      );
      if (same >= 0) {
        rows[same] = (name: name, text: text);
      } else {
        rows.add((name: name, text: text));
      }
      final response = await widget.api.put('/leads/reply-templates/$id', {
        'templates': [
          for (final t in rows) {'name': t.name, 'text': t.text},
        ],
      });
      if (!mounted) return;
      if (response.ok) {
        _saved[id] = _rowsOf(
          ((response.body as Map)['data'] as Map)['templates'],
        );
        _dropDraft(id);
        done++;
      } else {
        failed.add(_companies.firstWhere((c) => c.id == id).name);
      }
    }
    setState(() {
      _saving = false;
      _copying = false;
      _copyTo.clear();
      _note = done == 0
          ? null
          : 'Copied to $done ${done == 1 ? 'company' : 'companies'}.';
      _error = failed.isEmpty ? null : 'Not copied to ${failed.join(', ')}.';
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_companies.isEmpty) {
      return Center(child: Text(_loadError ?? 'No companies to show.'));
    }

    return LayoutBuilder(
      key: const ValueKey('reply-templates-manager'),
      builder: (context, box) =>
          box.maxWidth >= ReplyTemplatesManager.sideBySideFrom
          ? Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(width: 220, child: _companyList(context, wide: true)),
                const VerticalDivider(width: 1),
                SizedBox(width: 300, child: _templateList(context, wide: true)),
                const VerticalDivider(width: 1),
                Expanded(child: _editor(context)),
              ],
            )
          : switch (_step) {
              _Step.companies => _companyList(context, wide: false),
              _Step.templates => _templateList(context, wide: false),
              _Step.template => _editor(context, phone: true),
            },
    );
  }

  // ── the companies ───────────────────────────────────────────────

  Widget _companyList(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final unsaved = ToneColours.of(context, Tone.caution).ink;
    final shown = [
      for (final c in _companies)
        if (c.name.toLowerCase().contains(_query.toLowerCase())) c,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_companies.length > 8)
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
            child: ListSearch(
              key: const ValueKey('templates-company-search'),
              hint: 'Find a company',
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(8),
            children: [
              for (final c in shown)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: ListTile(
                    key: ValueKey('templates-company-${c.id}'),
                    // Full height on a phone, where it is a thumb's target.
                    dense: wide,
                    selected: wide && c.id == _companyId,
                    selectedTileColor: theme.colorScheme.primaryContainer,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(
                        EzBiziTheme.controlRadius,
                      ),
                    ),
                    title: Text(
                      c.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (_dirty(c.id))
                          Tooltip(
                            message: 'Unsaved changes',
                            child: Icon(Icons.circle, size: 8, color: unsaved),
                          ),
                        const SizedBox(width: 6),
                        Text(
                          '${(_drafts[c.id] ?? _saved[c.id] ?? const []).length}',
                          style: theme.textTheme.labelSmall,
                        ),
                        if (!wide) const Icon(Icons.chevron_right, size: 18),
                      ],
                    ),
                    onTap: () => _pick(c.id),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  // ── the company's templates ─────────────────────────────────────

  Widget _templateList(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final id = _companyId!;
    final draft = _draftOf(id);
    final dirty = _dirty(id);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(wide ? 12 : 4, 8, 12, 8),
          child: Row(
            children: [
              if (!wide && _companies.length > 1)
                IconButton(
                  tooltip: 'Back to the companies',
                  icon: const Icon(Icons.arrow_back),
                  onPressed: _back,
                ),
              Expanded(
                child: Text(
                  _companyName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
              ),
              // A phone has no editor beside the list to hold Save, so a
              // reorder is saved from here.
              if (!wide && dirty && _mayEdit) ...[
                const StatusPill('Unsaved', tone: Tone.caution),
                const SizedBox(width: 8),
                _saveButton(),
              ] else if (_mayEdit)
                OutlinedButton.icon(
                  key: const ValueKey('template-add'),
                  onPressed: draft.length >= 30 ? null : _add,
                  icon: const Icon(Icons.add, size: 18),
                  label: Text(wide ? 'New template' : 'New'),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: draft.isEmpty
              ? Center(
                  child: Text(
                    'No templates for $_companyName yet.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : ReorderableListView.builder(
                  buildDefaultDragHandles: false,
                  itemCount: draft.length,
                  onReorder: _reorder,
                  itemBuilder: (context, i) {
                    final d = draft[i];
                    final name = d.name.text.trim();

                    return ListTile(
                      key: ValueKey('template-row-${d.id}'),
                      selected: wide && i == _open,
                      selectedTileColor: theme.colorScheme.primaryContainer,
                      leading: _mayEdit
                          ? ReorderableDragStartListener(
                              index: i,
                              child: const Icon(Icons.drag_indicator),
                            )
                          : null,
                      title: Text(
                        name.isEmpty ? 'New template' : name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      subtitle: Text(
                        templatePreview(d.text.text, _companyName),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      trailing: wide ? null : const Icon(Icons.chevron_right),
                      onTap: () => _openTemplate(i),
                    );
                  },
                ),
        ),
        if (_mayEdit && draft.length > 1)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              'Drag to change the order of the buttons beside Send.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
      ],
    );
  }

  // ── the open template ───────────────────────────────────────────

  Widget _saveButton() => FilledButton(
    key: const ValueKey('templates-save'),
    onPressed: _saving ? null : _save,
    child: Text(_saving ? 'Saving…' : 'Save'),
  );

  Widget _editor(BuildContext context, {bool phone = false}) {
    final theme = Theme.of(context);
    final id = _companyId!;
    final draft = _draftOf(id);
    final d = draft.isEmpty ? null : draft[_openWithin(draft.length)];
    final title = d == null || d.name.text.trim().isEmpty
        ? 'New template'
        : d.name.text.trim();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(phone ? 4 : 18, 8, 12, 8),
          child: Row(
            children: [
              if (phone)
                IconButton(
                  tooltip: 'Back to the templates',
                  icon: const Icon(Icons.arrow_back),
                  onPressed: _back,
                ),
              Expanded(
                child: Text(
                  d == null ? _companyName : title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleMedium,
                ),
              ),
              if (_dirty(id) && !phone) ...[
                const StatusPill('Unsaved changes', tone: Tone.caution),
                const SizedBox(width: 4),
                TextButton(onPressed: _discard, child: const Text('Discard')),
              ],
              if (_mayEdit) ...[const SizedBox(width: 4), _saveButton()],
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: d == null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('No templates for $_companyName yet.'),
                      if (_mayEdit) ...[
                        const SizedBox(height: 12),
                        FilledButton.icon(
                          onPressed: _add,
                          icon: const Icon(Icons.add),
                          label: const Text('New template'),
                        ),
                      ],
                    ],
                  ),
                )
              // A reading width: across a desk the message ran 1,000px wide.
              : Align(
                  alignment: Alignment.topLeft,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 760),
                    child: _fields(context, d, draft.length),
                  ),
                ),
        ),
      ],
    );
  }

  Widget _fields(BuildContext context, _Draft d, int count) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
      children: [
        if (!_mayEdit)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'Changing these needs permission to edit Leads.',
              style: muted,
            ),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              _error!,
              key: const ValueKey('templates-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        TextField(
          key: const ValueKey('template-name'),
          controller: d.name,
          enabled: _mayEdit,
          maxLength: 40,
          decoration: const InputDecoration(
            labelText: 'Button name',
            counterText: '',
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('template-text'),
          controller: d.text,
          enabled: _mayEdit,
          minLines: 4,
          maxLines: 12,
          maxLength: 2000,
          decoration: const InputDecoration(
            labelText: 'Message',
            alignLabelWithHint: true,
            counterText: '',
          ),
        ),
        if (_mayEdit) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Insert', style: muted),
              for (final c in templateCodes)
                ActionChip(
                  key: ValueKey('template-insert-${c.code}'),
                  avatar: const Icon(Icons.add, size: 16),
                  label: Text(c.label),
                  onPressed: () => _insert(c.code),
                ),
            ],
          ),
        ],
        const SizedBox(height: 6),
        Text(
          'Greeting becomes "Hi Mark" (or "Hi there"). What\'s missing asks '
          'only for what the customer has not given yet. Availability names '
          'the first open day for their ZIP.',
          style: muted,
        ),
        const SizedBox(height: 18),
        Text('What the customer gets', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          alignment: Alignment.centerLeft,
          child: Container(
            key: const ValueKey('template-preview'),
            constraints: const BoxConstraints(maxWidth: 520),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(
              color: theme.colorScheme.primary,
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: Text(
              d.text.text.trim().isEmpty
                  ? 'The message shows here as you type it.'
                  : templatePreview(d.text.text.trim(), _companyName),
              style: TextStyle(color: theme.colorScheme.onPrimary),
            ),
          ),
        ),
        if (_mayEdit) ...[
          const SizedBox(height: 14),
          // A Wrap: side by side on a desk, one under the other on a phone.
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            spacing: 8,
            runSpacing: 8,
            children: [
              if (_companies.length > 1)
                OutlinedButton(
                  key: const ValueKey('template-copy'),
                  onPressed: _saving
                      ? null
                      : () => setState(() {
                          _copying = !_copying;
                          _copyTo.clear();
                        }),
                  child: const Text('Copy to other companies…'),
                ),
              // The server keeps at least one per company.
              TextButton(
                key: const ValueKey('template-delete'),
                style: TextButton.styleFrom(
                  foregroundColor: theme.colorScheme.error,
                ),
                onPressed: count == 1 ? null : _delete,
                child: Text(
                  count == 1 ? 'The last one stays' : 'Delete template',
                ),
              ),
            ],
          ),
          if (_copying) _copyList(context),
        ],
        if (_note != null)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Text(_note!, key: const ValueKey('templates-note')),
          ),
      ],
    );
  }

  /// Every other company, ticked to receive this template. One with unsaved
  /// changes of its own is left out: copying would save them with it.
  Widget _copyList(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('template-copy-list'),
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final c in _companies)
            if (c.id != _companyId)
              CheckboxListTile(
                key: ValueKey('template-copy-${c.id}'),
                dense: true,
                controlAffinity: ListTileControlAffinity.leading,
                value: _copyTo.contains(c.id),
                onChanged: _dirty(c.id)
                    ? null
                    : (on) => setState(
                        () => on == true
                            ? _copyTo.add(c.id)
                            : _copyTo.remove(c.id),
                      ),
                title: Text(c.name),
                subtitle: _dirty(c.id)
                    ? const Text('Has unsaved changes - save it first')
                    : null,
              ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: FilledButton(
              key: const ValueKey('template-copy-go'),
              onPressed: _copyTo.isEmpty || _saving ? null : _copy,
              child: Text(
                _copyTo.isEmpty
                    ? 'Copy'
                    : 'Copy to ${_copyTo.length} '
                          '${_copyTo.length == 1 ? 'company' : 'companies'}',
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One template being edited. [id] is stable across reorders, for the
/// list's keys.
class _Draft {
  _Draft(String name, String text, VoidCallback onChange)
    : id = _next++,
      name = TextEditingController(text: name),
      text = _CodesController(text) {
    this.name.addListener(onChange);
    this.text.addListener(onChange);
  }

  static int _next = 0;

  final int id;
  final TextEditingController name;
  final _CodesController text;

  void dispose() {
    name.dispose();
    text.dispose();
  }
}

/// The message field, with each code it knows drawn as a highlighted word so
/// it reads as something that will be filled in.
class _CodesController extends TextEditingController {
  _CodesController(String text) : super(text: text);

  static final _code = RegExp(
    r'\{\{(greeting|company|missing|availability)\}\}',
  );

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) {
    if (withComposing && value.isComposingRangeValid) {
      return super.buildTextSpan(
        context: context,
        style: style,
        withComposing: withComposing,
      );
    }
    final colours = ToneColours.of(context, Tone.info);
    final spans = <TextSpan>[];
    var at = 0;
    for (final m in _code.allMatches(text)) {
      if (m.start > at) spans.add(TextSpan(text: text.substring(at, m.start)));
      spans.add(
        TextSpan(
          text: m[0],
          style: TextStyle(
            backgroundColor: colours.fill,
            color: colours.ink,
            fontWeight: FontWeight.w700,
          ),
        ),
      );
      at = m.end;
    }
    if (at < text.length) spans.add(TextSpan(text: text.substring(at)));
    return TextSpan(style: style, children: spans);
  }
}
