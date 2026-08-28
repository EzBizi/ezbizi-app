import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../core/session.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/error_note.dart';
import 'photo_field.dart';
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
    final answers = run['answers'] is Map
        ? Map<String, dynamic>.from(run['answers'] as Map)
        : const <String, dynamic>{};
    for (final screen in _screensOf(run)) {
      for (final field in _fieldsOf(screen)) {
        final key = '${field['key']}';
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
      };

  List<Map<String, dynamic>> _screensOf(Map<String, dynamic> run) => [
        for (final s in (run['screens'] as List? ?? const []))
          if (s is Map) Map<String, dynamic>.from(s),
      ];

  List<Map<String, dynamic>> _fieldsOf(Map<String, dynamic> screen) => [
        for (final f in (screen['fields'] as List? ?? const []))
          if (f is Map) Map<String, dynamic>.from(f),
      ];

  String? _missingOn(Map<String, dynamic> screen) {
    final answers = _answers();
    for (final field in _fieldsOf(screen)) {
      final required = field['required'] == true;
      final rule = field['required_if'];
      var needs = required;
      if (rule is Map) {
        final other = '${rule['field'] ?? ''}';
        final equals = '${rule['equals'] ?? rule['value'] ?? ''}';
        needs = needs || answers[other] == equals;
      }
      if (!needs) continue;
      final value = answers['${field['key']}'] ?? '';
      // A photo answer is a list, and an empty one is the missing case. `''`
      // would never match it and a required photo would wave everybody through.
      final empty =
          value is List ? value.isEmpty : value.toString().trim().isEmpty;
      if (empty) {
        return '${field['label'] ?? field['key']}';
      }
    }
    return null;
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
        final options = [
          for (final o in (field['options'] as List? ?? const [])) '$o',
        ];
        final current = _fields[key]?.text ?? '';

        return DropdownButtonFormField<String>(
          key: widgetKey,
          initialValue: options.contains(current) && current != '' ? current : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: label),
          items: [
            for (final option in options)
              DropdownMenuItem(value: option, child: Text(option)),
            // An answer already given against an option since removed. Dropping
            // it silently would change a saved answer by redrawing the screen.
            if (current != '' && !options.contains(current))
              DropdownMenuItem(
                value: current,
                child: Text('$current — no longer offered'),
              ),
          ],
          onChanged: (value) {
            _fields[key]?.text = value ?? '';
            setState(() {});
            _save();
          },
        );

      case 'number':
        return TextField(
          key: widgetKey,
          controller: _fields[key],
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: label),
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
                    const SizedBox(height: 12),
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
