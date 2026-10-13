import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../core/session.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'condition.dart';
import 'option.dart';
import 'photo_field.dart';
import 'services_field.dart';
import 'signature_field.dart';

/// New Inspection: search a client or job, pick a named inspection, fill, submit.
///
/// Resume vs start again is a panel, not a modal. Next names the first missing
/// required field in a sentence under the button.
class NewInspectionTab extends StatefulWidget {
  const NewInspectionTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NewInspectionTab> createState() => _NewInspectionTabState();
}

class _NewInspectionTabState extends State<NewInspectionTab> {
  final _search = TextEditingController();
  String? _error;
  String? _empty;
  List<Map<String, dynamic>> _hits = const [];
  List<Map<String, dynamic>> _picker = const [];
  Map<String, dynamic>? _scope;
  Map<String, dynamic>? _choice;
  Map<String, dynamic>? _run;
  int _screen = 0;
  final Map<String, TextEditingController> _fields = {};

  /// Photo answers, which are lists of attachment ids rather than text.
  ///
  /// A second map instead of squeezing them into `_fields`: a controller holds
  /// a String, and a comma-joined list of uuids in a text box is the kind of
  /// thing that works until somebody's answer contains a comma.
  final Map<String, List<String>> _photos = {};

  /// Tick-box answers, which are bools rather than text or lists.
  final Map<String, bool> _checks = {};

  /// Recommended-work answers: a list of priced picks per field.
  final Map<String, List<Map<String, dynamic>>> _work = {};

  /// The organization's priced services, from the run payload.
  List<Map<String, dynamic>> _catalogue = const [];
  String? _blocked;

  bool get _mayDiscard =>
      widget.session.me?.permissions.contains('inspection.delete') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void dispose() {
    _search.dispose();
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _doSearch() async {
    final q = _search.text.trim();
    setState(() {
      _error = null;
      _run = null;
      _choice = null;
      _picker = const [];
      _scope = null;
    });
    if (q.isEmpty) {
      setState(() {
        _hits = const [];
        _empty = null;
      });
      return;
    }
    final response = await widget.api.get('/inspections/search', {'q': q});
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final hits = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    setState(() {
      _hits = hits;
      _empty = hits.isEmpty
          ? (response.body['message'] as String? ?? 'Client not found')
          : null;
    });
  }

  Future<void> _pickHit(Map<String, dynamic> hit) async {
    setState(() {
      _scope = hit;
      _error = null;
    });
    final query = <String, String>{
      'customer_id': '${hit['customer_id']}',
      if (hit['job_id'] != null) 'job_id': '${hit['job_id']}',
    };
    final response = await widget.api.get('/inspections/startable', query);
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final rows = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    setState(() {
      _picker = rows;
      _empty = rows.isEmpty
          ? (response.body['message'] as String? ??
              'This organization has not offered any inspections to this role yet.')
          : null;
    });
  }

  Future<void> _start(Map<String, dynamic> named) async {
    final scope = _scope;
    if (scope == null) return;
    final draftId = named['draft_id'];
    if (draftId is String && draftId.isNotEmpty) {
      final existing = await widget.api.get('/inspections/runs/$draftId');
      if (!mounted) return;
      if (existing.ok) {
        setState(() {
          _choice = {
            'run': Map<String, dynamic>.from(existing.data as Map),
            'named': named,
          };
        });
        return;
      }
    }

    final response = await widget.api.post('/inspections/runs', {
      'customer_id': scope['customer_id'],
      'job_id': scope['job_id'],
      'inspection_definition_id': named['id'],
    });
    if (!mounted) return;
    if (response.statusCode == 409 && response.data is Map) {
      setState(() {
        _choice = Map<String, dynamic>.from(response.data as Map);
        _choice!['named'] = named;
      });
      return;
    }
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    _adoptRun(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _resume() async {
    final run = _choice?['run'];
    if (run is! Map) return;
    _adoptRun(Map<String, dynamic>.from(run));
  }

  Future<void> _startAgain() async {
    final run = _choice?['run'];
    if (run is! Map) return;
    final response = await widget.api.post(
      '/inspections/runs/${run['id']}/restart',
      {},
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    _adoptRun(Map<String, dynamic>.from(response.data as Map));
  }

  void _adoptRun(Map<String, dynamic> run) {
    for (final c in _fields.values) {
      c.dispose();
    }
    _fields.clear();
    _photos.clear();
    _checks.clear();
    _work.clear();
    _catalogue = [
      for (final s in (run['services'] as List? ?? const []))
        if (s is Map) Map<String, dynamic>.from(s),
    ];
    final answers = run['answers'] is Map
        ? Map<String, dynamic>.from(run['answers'] as Map)
        : const <String, dynamic>{};
    // Every field, not only the visible ones: a technician who answers the wood
    // branch, switches to masonry and switches back must find their answer
    // still there. The server drops what the report will not ask, once, at
    // submit — this end never throws an answer away mid-run.
    // Flattened: a nested field's answer lives in the same map as any other,
    // so it needs a controller like any other. Built for EVERY option's fields,
    // chosen or not, for the same reason the top-level ones are — switching
    // away and back must not lose what was typed.
    List<Map<String, dynamic>> withNested(Map<String, dynamic> screen) => [
      for (final field in _allFieldsOf(screen)) ...[
        field,
        for (final option in InspectionOption.allOf(field['options']))
          ...option.fields,
      ],
    ];

    for (final screen in _allScreensOf(run)) {
      for (final field in withNested(screen)) {
        final key = '${field['key']}';
        // Furniture stores nothing, so it needs no controller.
        if (field['type'] == 'divider' || field['type'] == 'alert') {
          continue;
        }

        if (field['type'] == 'services') {
          _work[key] = [
            for (final p in (answers[key] as List? ?? const []))
              if (p is Map) Map<String, dynamic>.from(p),
          ];
          continue;
        }

        if (field['type'] == 'checkbox') {
          _checks[key] = answers[key] == true;
          continue;
        }

        if (field['type'] == 'photo' || field['type'] == 'signature') {
          _photos[key] = [
            for (final id in (answers[key] as List? ?? const [])) '$id',
          ];
          continue;
        }
        _fields[key] = TextEditingController(text: '${answers[key] ?? ''}');
      }
    }
    setState(() {
      _run = run;
      _choice = null;
      _screen = 0;
      _blocked = null;
      _hits = const [];
      _picker = const [];
    });
  }

  Future<void> _save() async {
    final run = _run;
    if (run == null) return;
    await widget.api.patch('/inspections/runs/${run['id']}', {
      'answers': _answers(),
    });
  }

  /// What gets sent. Text fields and photo fields, in one map.
  Map<String, dynamic> _answers() => {
        for (final e in _fields.entries) e.key: e.value.text,
        for (final e in _photos.entries) e.key: e.value,
        for (final e in _checks.entries) e.key: e.value,
        for (final e in _work.entries) e.key: e.value,
      };

  /// Every screen this version declares, shown or not.
  ///
  /// Kept separate from [_screensOf] because saving and freezing reason about
  /// all of them while the step strip only walks the visible ones.
  List<Map<String, dynamic>> _allScreensOf(Map<String, dynamic> run) => [
        for (final s in (run['screens'] as List? ?? const []))
          if (s is Map) Map<String, dynamic>.from(s),
      ];

  /// The screens actually asked, given what has been answered so far.
  ///
  /// A screen with a false condition is not skipped over on Next — it is not in
  /// the list at all, so the step strip counts what the technician will really
  /// be asked rather than showing "3 of 9" and then jumping.
  List<Map<String, dynamic>> _screensOf(Map<String, dynamic> run) {
    final answers = _answers();

    return [
      for (final screen in _allScreensOf(run))
        if (conditionPasses(screen['visible_if'], answers)) screen,
    ];
  }

  /// Every field this screen declares, shown or not.
  List<Map<String, dynamic>> _allFieldsOf(Map<String, dynamic> screen) => [
        for (final f in (screen['fields'] as List? ?? const []))
          if (f is Map) Map<String, dynamic>.from(f),
      ];

  /// The building code frozen onto this field at publish, if it cites one.
  ///
  /// Read from the version, never fetched: the wording is part of what was
  /// published, so a library corrected afterwards does not change what this run
  /// is being held to.
  Widget _citation(Map<String, dynamic> field) {
    final code = field['code'];
    if (code is! Map) return const SizedBox.shrink();

    final title = '${code['title'] ?? code['code'] ?? ''}'.trim();
    final body = '${code['body'] ?? ''}'.trim();
    if (title.isEmpty && body.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Container(
        padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(6),
          border: Border(
            left: BorderSide(color: theme.colorScheme.outlineVariant, width: 3),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (title.isNotEmpty)
              Text(title,
                  style: theme.textTheme.labelSmall
                      ?.copyWith(fontWeight: FontWeight.w700)),
            if (body.isNotEmpty) ...[
              if (title.isNotEmpty) const SizedBox(height: 2),
              Text(body,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ],
          ],
        ),
      ),
    );
  }

  /// The fields this field's chosen option reveals, that are themselves shown.
  ///
  /// One level: a revealed field cannot reveal more. The server refuses depth
  /// two in FieldSet, so this end does not have to defend against it.
  List<Map<String, dynamic>> _revealed(Map<String, dynamic> field) {
    final answers = _answers();

    return [
      for (final nested in InspectionOption.revealedBy(field, answers))
        if (conditionPasses(nested['visible_if'], answers)) nested,
    ];
  }

  /// The fields actually asked on this screen.
  ///
  /// The server holds the same rule twice over — RequiredFields skips hidden
  /// fields, and BoundAnswers drops their answers at submit — so a client that
  /// disagreed here would show a question the report then throws away.
  List<Map<String, dynamic>> _fieldsOf(Map<String, dynamic> screen) {
    final answers = _answers();

    return [
      for (final field in _allFieldsOf(screen))
        if (conditionPasses(field['visible_if'], answers)) field,
    ];
  }

  String? _missingOn(Map<String, dynamic> screen) {
    final answers = _answers();

    for (final field in _fieldsOf(screen)) {
      // The field itself, then what its answer revealed — the order they are
      // drawn, and the order a technician can act on. The server's
      // RequiredFields walks the same order, so the two name the same field.
      for (final candidate in [field, ..._revealed(field)]) {
        final label = _missingLabel(candidate, answers);
        if (label != null) return label;
      }
    }

    return null;
  }

  /// This one field's label if it is required and empty, otherwise null.
  String? _missingLabel(Map<String, dynamic> field, Map<String, dynamic> answers) {
    final rule = field['required_if'];
    var needs = field['required'] == true;

    if (rule is Map) {
      final other = '${rule['field'] ?? ''}';
      final equals = '${rule['equals'] ?? rule['value'] ?? ''}';
      needs = needs || answers[other] == equals;
    }

    if (!needs) return null;

    final value = answers['${field['key']}'] ?? '';
    // A photo, signature or multi-choice answer is a list, and an empty one is
    // the missing case. `''` would never match it and a required photo would
    // wave everybody through.
    final empty = value is List ? value.isEmpty : value.toString().trim().isEmpty;

    return empty ? '${field['label'] ?? field['key']}' : null;
  }

  Future<void> _next() async {
    final run = _run;
    if (run == null) return;
    final screens = _screensOf(run);
    if (screens.isEmpty) return;
    final missing = _missingOn(screens[_screen.clamp(0, screens.length - 1)]);
    if (missing != null) {
      setState(() => _blocked = '$missing needs an answer before you can continue.');
      return;
    }
    await _save();
    if (_screen >= screens.length - 1) {
      await _submit();
      return;
    }
    setState(() {
      _screen += 1;
      _blocked = null;
    });
  }

  Future<void> _submit() async {
    final run = _run;
    if (run == null) return;
    await _save();
    final response = await widget.api.post(
      '/inspections/runs/${run['id']}/submit',
      {},
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _blocked = response.reason;
        _error = response.reason;
      });
      return;
    }
    setState(() {
      _run = null;
      _empty = 'Submitted. The report is under Generated Reports.';
      _blocked = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_run != null) {
      return _fill(context, _run!);
    }
    if (_choice != null) {
      return _resumePanel(context);
    }

    return Padding(
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('tour-inspection-search'),
            controller: _search,
            decoration: const InputDecoration(
              hintText: 'Client name or job number',
              prefixIcon: Icon(Icons.search),
            ),
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _doSearch(),
          ),
          const SizedBox(height: 12),
          if (_error != null) ErrorNote(_error!),
          if (_empty != null) DashboardNote(_empty!),
          if (_hits.isEmpty && _picker.isEmpty && _empty == null && _error == null)
            const DashboardNote(
              'Search for a client by name, or a job by its number, to start.',
            ),
          if (_hits.isNotEmpty && _picker.isEmpty)
            Expanded(
              child: ListView(
                children: [
                  for (final hit in _hits)
                    ListTile(
                      title: Text('${hit['label'] ?? hit['customer_name']}'),
                      onTap: () => _pickHit(hit),
                    ),
                ],
              ),
            ),
          if (_picker.isNotEmpty)
            Expanded(
              child: ListView(
                children: [
                  for (final named in _picker)
                    ListTile(
                      title: Text('${named['name']}'),
                      onTap: () => _start(named),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _resumePanel(BuildContext context) {
    final named = _choice?['named'];
    final label = named is Map ? '${named['name'] ?? 'this inspection'}' : 'this inspection';

    return Padding(
      key: const Key('inspection-resume-panel'),
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'You already have $label in progress for this client.',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          const DashboardNote(
            'Resume to keep what you typed, or start again to throw this draft away and begin a new one.',
          ),
          const SizedBox(height: 16),
          FilledButton(onPressed: _resume, child: const Text('Resume')),
          const SizedBox(height: 8),
          if (_mayDiscard)
            OutlinedButton(onPressed: _startAgain, child: const Text('Start again')),
        ],
      ),
    );
  }

  /// The control for one field, chosen by its declared type.
  ///
  /// Which widget draws a kind of question is CODE — a server cannot send a
  /// widget — and the same argument onboarding_form.dart makes applies here: a
  /// type this build has never met gets a plain box and says so, rather than
  /// vanishing from a form somebody is being asked to complete.
  ///
  /// Everything used to be a TextField. That shipped, and it meant a date was
  /// typed by hand on a phone in the rain and a one-of-a-list was typed from
  /// memory — the two ways a form produces answers nobody can report on later.
  Widget _control(Map<String, dynamic> field, Map<String, dynamic> run) {
    final key = '${field['key']}';
    final label = '${field['label'] ?? key}';
    final widgetKey = Key('field-$key');

    switch ('${field['type'] ?? 'text'}') {
      case 'photo':
        return InspectionPhotoField(
          key: widgetKey,
          api: widget.api,
          runId: '${run['id']}',
          label: label,
          maxCount: (field['max_count'] as num?)?.toInt() ?? 1,
          ids: _photos[key] ?? const [],
          onChanged: (ids) {
            setState(() => _photos[key] = ids);
            _save();
          },
        );

      case 'date':
        return CalendarDayField(
          key: widgetKey,
          controller: _fields[key]!,
          formats: _formats,
          label: label,
          onChanged: (_) => _save(),
        );

      case 'choice':
        // Both shapes: a definition stored before options became objects
        // keeps its strings until somebody saves it through the Builder.
        final choices = InspectionOption.allOf(field['options']);
        final options = [for (final o in choices) o.value];
        final current = _fields[key]?.text ?? '';

        return SearchPickerField<String>(
                 key: widgetKey,
                 value: options.contains(current) && current != '' ? current : null,
                 label: label,
                 options: [
            for (final choice in choices)
              SearchOption(value: choice.value, label: choice.label),
            // An answer already given against an option since removed. Dropping
            // it silently would change a saved answer by redrawing the screen.
            if (current != '' && !options.contains(current))
              SearchOption(value: current, label: '$current — no longer offered'),
          ],
                 onChanged: (value) {
            _fields[key]?.text = value ?? '';
            setState(() {});
            _save();
          },
               );

      case 'divider':
        // A heading between fields. Not a control, so it takes no key and
        // answers nothing — see FieldType::FURNITURE.
        return Padding(
          key: widgetKey,
          padding: const EdgeInsets.only(top: 8, bottom: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (label.isNotEmpty)
                Text(label, style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: 6),
              Divider(height: 1, color: Theme.of(context).colorScheme.outlineVariant),
            ],
          ),
        );

      case 'alert':
        final title = '${field['title'] ?? ''}'.trim();
        final body = '${field['body'] ?? ''}'.trim();

        return Container(
          key: widgetKey,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.secondaryContainer,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (title.isNotEmpty)
                Text(title,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          color: Theme.of(context).colorScheme.onSecondaryContainer,
                        )),
              if (body.isNotEmpty) ...[
                if (title.isNotEmpty) const SizedBox(height: 4),
                Text(body,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSecondaryContainer,
                        )),
              ],
            ],
          ),
        );

      case 'checkbox':
        // The answer is a bool, not text, so it does not use a controller.
        // `_photos` already holds the non-text answers; a third map would be a
        // third place to remember.
        final ticked = _checks[key] ?? false;

        return CheckboxListTile(
          key: widgetKey,
          value: ticked,
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: EdgeInsets.zero,
          title: Text(label),
          onChanged: (value) {
            setState(() => _checks[key] = value ?? false);
            _save();
          },
        );

      case 'textarea':
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          minLines: 3,
          maxLines: 6,
          decoration: InputDecoration(labelText: label, alignLabelWithHint: true),
        );

      case 'email':
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          decoration: InputDecoration(labelText: label),
        );

      case 'phone':
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          keyboardType: TextInputType.phone,
          inputFormatters: const [PhoneNumberInputFormatter()],
          decoration: InputDecoration(labelText: label),
        );

      case 'number':
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: label),
        );

      case 'services':
        // The catalogue rides along on the run payload — a picker that had to
        // fetch would be a spinner on a roof with one bar of signal.
        return InspectionServicesField(
          key: widgetKey,
          api: widget.api,
          label: label,
          picks: [
            for (final p in (_work[key] ?? const []))
              Map<String, dynamic>.from(p),
          ],
          catalogue: _catalogue,
          onChanged: (picks) {
            setState(() => _work[key] = picks);
            _save();
          },
        );

      case 'signature':
        // Stored the same way a photograph is — an attachment id in a list — so
        // the report renderer, the reports pane and the §9 purge treat a
        // signature as a picture without learning a fourth answer shape.
        return InspectionSignatureField(
          key: widgetKey,
          api: widget.api,
          runId: '${run['id']}',
          label: label,
          ids: _photos[key] ?? const [],
          onChanged: (ids) {
            setState(() => _photos[key] = ids);
            _save();
          },
        );

      default:
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          decoration: InputDecoration(labelText: label),
        );
    }
  }

  Widget _fill(BuildContext context, Map<String, dynamic> run) {
    final screens = _screensOf(run);
    final screen = screens.isEmpty
        ? null
        : screens[_screen.clamp(0, screens.length - 1)];
    final last = screens.isEmpty || _screen >= screens.length - 1;

    return Padding(
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '${run['definition_name'] ?? ''}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          Text(
            [
              if (run['customer_name'] != null) '${run['customer_name']}',
              if (run['job_number'] != null) '${run['job_number']}',
            ].join(' · '),
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          if (screen == null)
            const DashboardNote('This inspection has no screens yet.')
          else
            Expanded(
              child: ListView(
                children: [
                  Text(
                    '${screen['name'] ?? ''}',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 12),
                  for (final field in _fieldsOf(screen)) ...[
                    _control(field, run),
                    // The standard this finding is measured against, under the
                    // control. A technician certifying that something does not
                    // meet a code has to be able to read the code.
                    _citation(field),
                    const SizedBox(height: 12),
                    // What this answer revealed, indented under it. A failing
                    // finding demands its photograph HERE, beside the finding,
                    // rather than as a separate question further down the form.
                    for (final nested in _revealed(field)) ...[
                      Padding(
                        padding: const EdgeInsets.only(left: 16),
                        child: _control(nested, run),
                      ),
                      const SizedBox(height: 12),
                    ],
                  ],
                ],
              ),
            ),
          if (_blocked != null) ...[
            ErrorNote(_blocked!),
            const SizedBox(height: 8),
          ],
          FilledButton(
            onPressed: _next,
            child: Text(last ? 'Submit' : 'Next'),
          ),
        ],
      ),
    );
  }
}
