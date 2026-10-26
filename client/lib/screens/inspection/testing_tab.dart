import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/step_timeline.dart';
import '../../widgets/suggest_search.dart';
import 'option.dart';

/// Testing: walk any version of a form, the draft included, and see why each
/// screen and question showed or was skipped, and the report it would make.
///
/// Nothing is saved — POST /inspections/{id}/rehearse writes no run, report,
/// event or audit row. Every answer change re-runs it after [debounce], and
/// the answers it returns (prefilled from a test client, frozen to what the
/// form would ask) replace the ones on screen.
///
/// The controls are the ones New Inspection draws (CalendarDayField,
/// SearchPickerField, CheckboxListTile, TextField). A photograph or signature
/// cannot be taken without a run to hold it, so those say "No photograph".
///
/// The preview is the server's report HTML drawn by flutter_html — rendered
/// rich text, the renderer the onboarding contract already uses — not a
/// browser, so print-only CSS is approximate.
class TestingTab extends StatefulWidget {
  const TestingTab({
    super.key,
    required this.api,
    required this.session,
    this.debounce = const Duration(milliseconds: 400),
  });

  final ApiClient api;
  final Session session;
  final Duration debounce;

  @override
  State<TestingTab> createState() => _TestingTabState();
}

class _TestingTabState extends State<TestingTab> {
  List<Map<String, dynamic>> _definitions = const [];
  String? _definitionId;
  Map<String, dynamic>? _definition;
  String _version = 'draft';

  final _search = TextEditingController();
  List<Map<String, dynamic>> _hits = const [];
  Map<String, dynamic>? _scope;
  int _searchTicket = 0;

  final Map<String, dynamic> _answers = {};
  final Map<String, TextEditingController> _fields = {};

  Map<String, dynamic>? _result;
  String? _error;
  int _screen = 0;
  Timer? _timer;
  int _ticket = 0;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _loadDefinitions();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _search.dispose();
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadDefinitions() async {
    final response = await widget.api.get('/inspections');
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    setState(() {
      _definitions = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  Future<void> _pickDefinition(String? id) async {
    if (id == null) return;
    setState(() {
      _definitionId = id;
      _definition = null;
      _version = 'draft';
      _result = null;
      _screen = 0;
      _answers.clear();
      _error = null;
    });
    final response = await widget.api.get('/inspections/$id');
    if (!mounted || _definitionId != id) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    setState(
      () => _definition = Map<String, dynamic>.from(response.data as Map),
    );
    _rehearse();
  }

  List<Map<String, dynamic>> get _versions => [
    for (final v in (_definition?['versions'] as List? ?? const []))
      if (v is Map) Map<String, dynamic>.from(v),
  ];

  /// The screens of the version being tried, with their options — rehearse
  /// says what shows; this says how each question is asked.
  List<Map<String, dynamic>> get _declared {
    final Object? raw;
    if (_version == 'draft') {
      raw = _definition?['screens'];
    } else {
      raw = _versions.firstWhere(
        (v) => v['id'] == _version,
        orElse: () => const {},
      )['screens'];
    }
    return [
      for (final s in (raw as List? ?? const []))
        if (s is Map) Map<String, dynamic>.from(s),
    ];
  }

  Map<String, dynamic> _declaredField(String key) {
    for (final s in _declared) {
      for (final f in (s['fields'] as List? ?? const [])) {
        if (f is Map && f['key'] == key) return Map<String, dynamic>.from(f);
      }
    }
    return const {};
  }

  // ── Client or job ──────────────────────────────────────────────────

  Future<void> _doSearch(String raw) async {
    final q = raw.trim();
    final ticket = ++_searchTicket;
    if (q.isEmpty) {
      setState(() => _hits = const []);
      return;
    }
    final response = await widget.api.get('/inspections/search', {'q': q});
    if (!mounted || ticket != _searchTicket) return;
    setState(() {
      _hits = response.ok
          ? [
              for (final row in (response.data as List? ?? const []))
                if (row is Map) Map<String, dynamic>.from(row),
            ]
          : const [];
      if (!response.ok) _error = response.reason;
    });
  }

  void _pickHit(Map<String, dynamic> hit) {
    _searchTicket++;
    setState(() {
      _scope = hit;
      _hits = const [];
      _search.text = '${hit['label'] ?? hit['customer_name'] ?? ''}';
    });
    _rehearse();
  }

  void _clearScope() {
    setState(() {
      _scope = null;
      _search.clear();
    });
    _rehearse();
  }

  // ── Rehearse ───────────────────────────────────────────────────────

  void _changed(String key, dynamic value) {
    _answers[key] = value;
    setState(() {});
    _timer?.cancel();
    _timer = Timer(widget.debounce, _rehearse);
  }

  Future<void> _rehearse() async {
    _timer?.cancel();
    final id = _definitionId;
    if (id == null) return;
    final ticket = ++_ticket;
    final response = await widget.api.post('/inspections/$id/rehearse', {
      'version': _version,
      'answers': Map<String, dynamic>.from(_answers),
      if (_scope?['customer_id'] != null) 'customer_id': _scope!['customer_id'],
      if (_scope?['job_id'] != null) 'job_id': _scope!['job_id'],
    });
    // A newer edit or pick supersedes this answer.
    if (!mounted || ticket != _ticket || _timer?.isActive == true) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final data = Map<String, dynamic>.from(response.data as Map);
    final returned = data['answers'] is Map
        ? Map<String, dynamic>.from(data['answers'] as Map)
        : <String, dynamic>{};
    setState(() {
      _error = null;
      _result = data;
      _answers
        ..clear()
        ..addAll(returned);
      for (final e in _fields.entries) {
        final text = '${returned[e.key] ?? ''}';
        if (e.value.text != text) e.value.text = text;
      }
    });
  }

  List<Map<String, dynamic>> get _screens => [
    for (final s in (_result?['screens'] as List? ?? const []))
      if (s is Map) Map<String, dynamic>.from(s),
  ];

  List<Map<String, dynamic>> get _shownScreens => [
    for (final s in _screens)
      if (s['shown'] == true) s,
  ];

  List<Map<String, dynamic>> _fieldsOf(Map<String, dynamic> screen) => [
    for (final f in (screen['fields'] as List? ?? const []))
      if (f is Map) Map<String, dynamic>.from(f),
  ];

  String? get _firstMissing => _result?['first_missing'] as String?;

  void _jumpTo(Map<String, dynamic> screen) {
    final at = _shownScreens.indexWhere((s) => s['index'] == screen['index']);
    if (at >= 0) setState(() => _screen = at);
  }

  // ── Build ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 900;
        final header = _header(context);
        if (!wide) {
          return ListView(
            key: const ValueKey('testing-scroll'),
            padding: const EdgeInsets.all(DashboardCard.padding),
            children: [
              ...header,
              if (_result != null) ...[
                _answersPanel(context),
                const SizedBox(height: 16),
                _whyPanel(context),
                const SizedBox(height: 16),
                _preview(context),
              ],
            ],
          );
        }
        return ListView(
          key: const ValueKey('testing-scroll'),
          padding: const EdgeInsets.all(DashboardCard.padding),
          children: [
            ...header,
            if (_result != null)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 3, child: _answersPanel(context)),
                  const SizedBox(width: 16),
                  Expanded(
                    flex: 2,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _whyPanel(context),
                        const SizedBox(height: 16),
                        _preview(context),
                      ],
                    ),
                  ),
                ],
              ),
          ],
        );
      },
    );
  }

  List<Widget> _header(BuildContext context) {
    final theme = Theme.of(context);
    final info = ToneColours.of(context, Tone.info);
    return [
      Container(
        key: const ValueKey('testing-saves-nothing'),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: info.fill,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          'Nothing is saved. No inspection, report or message is made, and no '
          'automation runs.',
          style: theme.textTheme.bodySmall?.copyWith(color: info.ink),
        ),
      ),
      const SizedBox(height: 16),
      Wrap(
        spacing: 12,
        runSpacing: 12,
        children: [
          SizedBox(
            width: 280,
            child: SearchPickerField<String>(
              key: const ValueKey('testing-form'),
              label: 'Form',
              hint: 'Choose a form',
              value: _definitionId,
              options: [
                for (final d in _definitions)
                  SearchOption(
                    value: '${d['id']}',
                    label: '${d['name'] ?? ''}',
                  ),
              ],
              onChanged: _pickDefinition,
            ),
          ),
          SizedBox(
            width: 200,
            child: SearchPickerField<String>(
              key: const ValueKey('testing-version'),
              label: 'Version',
              value: _version,
              options: [
                const SearchOption(value: 'draft', label: 'Draft'),
                for (final v in _versions)
                  SearchOption(
                    value: '${v['id']}',
                    label: 'Version ${v['version']}',
                  ),
              ],
              onChanged: _definition == null
                  ? null
                  : (v) {
                      setState(() {
                        _version = v ?? 'draft';
                        _screen = 0;
                      });
                      _rehearse();
                    },
            ),
          ),
          SizedBox(
            width: 320,
            child: SuggestSearchField(
              key: const ValueKey('testing-client'),
              fieldKey: const ValueKey('testing-client-field'),
              controller: _search,
              hintText: 'Test client or job (optional)',
              prefixIcon: const Icon(Icons.person_search_outlined),
              suggestions: [for (final h in _hits) _suggestion(h)],
              onChanged: _doSearch,
              onPick: (i) => _pickHit(_hits[i]),
              trailing: _scope == null
                  ? null
                  : IconButton(
                      key: const ValueKey('testing-client-clear'),
                      tooltip: 'No test client',
                      icon: const Icon(Icons.close),
                      onPressed: _clearScope,
                    ),
            ),
          ),
        ],
      ),
      if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
      const SizedBox(height: 16),
      if (_definitionId == null)
        const DashboardNote('Choose a form to try it.'),
    ];
  }

  SuggestItem _suggestion(Map<String, dynamic> hit) {
    final isJob = hit['type'] == 'job';
    return SuggestItem(
      kind: isJob ? 'Job' : 'Client',
      icon: isJob ? Icons.work_outline : Icons.person_outline,
      title: '${hit['customer_name'] ?? hit['label'] ?? ''}',
      subtitle: isJob ? '${hit['job_number'] ?? ''}' : null,
    );
  }

  Widget _answersPanel(BuildContext context) {
    final theme = Theme.of(context);
    final screens = _shownScreens;
    final current = screens.isEmpty ? 0 : _screen.clamp(0, screens.length - 1);
    final screen = screens.isEmpty ? null : screens[current];

    return Card(
      key: const ValueKey('testing-answers'),
      child: Padding(
        padding: const EdgeInsets.all(DashboardCard.padding),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Answers', style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            if (screens.length > 1) ...[
              StepTimeline(
                keyPrefix: 'testing-step',
                labels: [for (final s in screens) '${s['name'] ?? ''}'],
                current: current,
                onTap: (i) => setState(() => _screen = i),
              ),
              const SizedBox(height: 16),
            ],
            if (screen == null)
              const DashboardNote('No screen is shown with these answers.')
            else ...[
              Text(
                '${screen['name'] ?? ''}',
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 12),
              for (final field in _fieldsOf(screen))
                if (field['shown'] == true) ...[
                  _control(field),
                  const SizedBox(height: 12),
                ],
              Row(
                children: [
                  if (current > 0)
                    OutlinedButton(
                      key: const ValueKey('testing-back'),
                      onPressed: () => setState(() => _screen = current - 1),
                      child: const Text('Back'),
                    ),
                  const Spacer(),
                  if (current < screens.length - 1)
                    FilledButton(
                      key: const ValueKey('testing-next'),
                      onPressed: () => setState(() => _screen = current + 1),
                      child: const Text('Next'),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  TextEditingController _controller(String key) => _fields.putIfAbsent(
    key,
    () => TextEditingController(text: '${_answers[key] ?? ''}'),
  );

  /// The same control New Inspection draws for each kind of question.
  Widget _control(Map<String, dynamic> field) {
    final key = '${field['key']}';
    final label = '${field['label'] ?? key}';
    final widgetKey = ValueKey('testing-field-$key');
    final declared = _declaredField(key);
    final theme = Theme.of(context);

    switch ('${field['type']}') {
      case 'photo':
      case 'signature':
        return InputDecorator(
          key: widgetKey,
          decoration: InputDecoration(labelText: label),
          child: Text(
            'No photograph',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        );
      case 'services':
        return InputDecorator(
          key: widgetKey,
          decoration: InputDecoration(labelText: label),
          child: Text(
            'Recommended work is not priced in a test.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        );
      case 'divider':
        return Text(label, key: widgetKey, style: theme.textTheme.titleSmall);
      case 'alert':
        return DashboardNote(
          '${declared['title'] ?? ''} ${declared['body'] ?? ''}'.trim(),
          key: widgetKey,
        );
      case 'date':
        return CalendarDayField(
          key: widgetKey,
          controller: _controller(key),
          formats: _formats,
          label: label,
          onChanged: (v) => _changed(key, v),
        );
      case 'choice':
        final choices = InspectionOption.allOf(declared['options']);
        final current = '${_answers[key] ?? ''}';
        return SearchPickerField<String>(
          key: widgetKey,
          label: label,
          value: current.isEmpty ? null : current,
          options: [
            for (final c in choices)
              SearchOption(value: c.value, label: c.label),
            if (current.isNotEmpty && !choices.any((c) => c.value == current))
              SearchOption(value: current, label: current),
          ],
          onChanged: (v) => _changed(key, v ?? ''),
        );
      case 'checkbox':
        return CheckboxListTile(
          key: widgetKey,
          value: _answers[key] == true,
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: EdgeInsets.zero,
          title: Text(label),
          onChanged: (v) => _changed(key, v ?? false),
        );
      case 'textarea':
        return TextField(
          key: widgetKey,
          controller: _controller(key),
          minLines: 3,
          maxLines: 6,
          decoration: InputDecoration(
            labelText: label,
            alignLabelWithHint: true,
          ),
          onChanged: (v) => _changed(key, v),
        );
      case 'phone':
        return TextField(
          key: widgetKey,
          controller: _controller(key),
          keyboardType: TextInputType.phone,
          inputFormatters: const [PhoneNumberInputFormatter()],
          decoration: InputDecoration(labelText: label),
          onChanged: (v) => _changed(key, v),
        );
      case 'number':
        return TextField(
          key: widgetKey,
          controller: _controller(key),
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: label),
          onChanged: (v) => _changed(key, v),
        );
      default:
        return TextField(
          key: widgetKey,
          controller: _controller(key),
          keyboardType: field['type'] == 'email'
              ? TextInputType.emailAddress
              : TextInputType.text,
          decoration: InputDecoration(labelText: label),
          onChanged: (v) => _changed(key, v),
        );
    }
  }

  Widget _whyPanel(BuildContext context) {
    final theme = Theme.of(context);
    final missing = _firstMissing;
    return Card(
      key: const ValueKey('testing-why'),
      child: Padding(
        padding: const EdgeInsets.all(DashboardCard.padding),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Why', style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              missing == null
                  ? 'Every required question is answered.'
                  : 'First missing: $missing',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final screen in _screens) ...[
              _whyRow(
                context,
                key: ValueKey('testing-why-screen-${screen['index']}'),
                title: '${screen['name'] ?? ''}',
                shown: screen['shown'] == true,
                why: '${screen['why'] ?? ''}',
                heading: true,
                onTap: screen['shown'] == true ? () => _jumpTo(screen) : null,
              ),
              for (final field in _fieldsOf(screen))
                _whyRow(
                  context,
                  key: ValueKey('testing-why-field-${field['key']}'),
                  title:
                      '${field['label'] ?? field['key']}'
                      '${field['required'] == true ? ' *' : ''}',
                  shown: field['shown'] == true,
                  why: '${field['why'] ?? ''}',
                  missing: missing != null && field['label'] == missing,
                  onTap: screen['shown'] == true ? () => _jumpTo(screen) : null,
                ),
              const SizedBox(height: 8),
            ],
          ],
        ),
      ),
    );
  }

  Widget _whyRow(
    BuildContext context, {
    required Key key,
    required String title,
    required bool shown,
    required String why,
    bool heading = false,
    bool missing = false,
    VoidCallback? onTap,
  }) {
    final theme = Theme.of(context);
    final tone = ToneColours.of(context, shown ? Tone.ok : Tone.neutral);
    final bad = ToneColours.of(context, Tone.bad);
    return InkWell(
      key: key,
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        padding: EdgeInsets.fromLTRB(heading ? 6 : 22, 6, 6, 6),
        decoration: missing
            ? BoxDecoration(
                color: bad.fill,
                borderRadius: BorderRadius.circular(6),
              )
            : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style:
                        (heading
                                ? theme.textTheme.labelLarge
                                : theme.textTheme.bodyMedium)
                            ?.copyWith(color: missing ? bad.ink : null),
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 8,
                    vertical: 2,
                  ),
                  decoration: BoxDecoration(
                    color: tone.fill,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    shown ? 'Shown' : 'Skipped',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: tone.ink,
                    ),
                  ),
                ),
              ],
            ),
            if (why.isNotEmpty)
              Text(
                why,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _preview(BuildContext context) {
    final theme = Theme.of(context);
    final html = '${_result?['preview_html'] ?? ''}';
    return Card(
      key: const ValueKey('testing-preview'),
      child: Padding(
        padding: const EdgeInsets.all(DashboardCard.padding),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Report preview', style: theme.textTheme.titleMedium),
            Text(
              'Drawn as rich text; the PDF may differ in spacing.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            Container(
              color: EzBiziTheme.paper,
              padding: const EdgeInsets.all(12),
              child: html.isEmpty
                  ? const SizedBox.shrink()
                  : Html(
                      data: html,
                      style: {
                        'body': Style(
                          margin: Margins.zero,
                          padding: HtmlPaddings.zero,
                          color: EzBiziTheme.paperInk,
                        ),
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
