import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/tag.dart';

/// Builder: named inspections on the left, the selected field in the middle,
/// a live phone on the right. Below 900px the three panes stack. No modal.
class BuilderTab extends StatefulWidget {
  const BuilderTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  static const double stackBelow = 900;

  @override
  State<BuilderTab> createState() => _BuilderTabState();
}

class _BuilderTabState extends State<BuilderTab> {
  bool _loading = true;
  String? _error;
  int _request = 0;

  List<Map<String, dynamic>> _definitions = const [];
  List<Map<String, dynamic>> _fieldTypes = const [];
  List<Map<String, dynamic>> _presets = const [];
  List<Map<String, dynamic>> _bindable = const [];

  String? _selectedId;
  int _screenIndex = 0;
  int _fieldIndex = 0;
  String? _busy;
  final _name = TextEditingController();
  final _intro = TextEditingController();
  final _label = TextEditingController();

  /// The choices on a `choice` field, one per line.
  ///
  /// One box rather than a list of rows with add and remove buttons: the same
  /// answer with a fraction of the widgets, and it is how somebody with a list
  /// already written actually enters one — paste it.
  final _options = TextEditingController();

  Map<String, dynamic>? get _selected {
    for (final row in _definitions) {
      if (row['id'] == _selectedId) return row;
    }
    return null;
  }

  List<Map<String, dynamic>> get _screens {
    final raw = _selected?['screens'];
    if (raw is! List) return const [];
    return [
      for (final s in raw)
        if (s is Map) Map<String, dynamic>.from(s),
    ];
  }

  List<Map<String, dynamic>> _fieldsOf(Map<String, dynamic> screen) {
    final raw = screen['fields'];
    if (raw is! List) return const [];
    return [
      for (final f in raw)
        if (f is Map) Map<String, dynamic>.from(f),
    ];
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _intro.dispose();
    _label.dispose();
    _options.dispose();
    super.dispose();
  }

  void _adoptControllers() {
    final current = _selected;
    _name.text = '${current?['name'] ?? ''}';
    _intro.text = '${current?['intro'] ?? ''}';
    final screens = _screens;
    final screen = screens.isEmpty
        ? null
        : screens[_screenIndex.clamp(0, screens.length - 1)];
    final fields = screen == null ? const <Map<String, dynamic>>[] : _fieldsOf(screen);
    final field = fields.isEmpty
        ? null
        : fields[_fieldIndex.clamp(0, fields.length - 1)];
    _label.text = '${field?['label'] ?? ''}';
    _options.text = [
      for (final option in (field?['options'] as List? ?? const [])) '$option',
    ].join('\n');
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _definitions.isEmpty;
      _error = null;
    });

    final answers = await Future.wait([
      widget.api.get('/inspections'),
      widget.api.get('/inspections/catalogue'),
    ]);
    if (!mounted || request != _request) return;

    final list = answers[0];
    final catalogue = answers[1];

    setState(() {
      _loading = false;
      if (!list.ok) {
        _error = list.reason;
        return;
      }
      _definitions = _maps(list.data);
      if (_selectedId == null && _definitions.isNotEmpty) {
        _selectedId = _definitions.first['id'] as String?;
      }
      if (catalogue.ok && catalogue.data is Map) {
        final data = Map<String, dynamic>.from(catalogue.data as Map);
        _fieldTypes = _maps(data['field_types']);
        _presets = _maps(data['layout_presets']);
        _bindable = _maps(data['bindable']);
      }
    });
    _adoptControllers();
  }

  Future<void> _create() async {
    setState(() => _busy = 'create');
    final response = await widget.api.post('/inspections', {});
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final row = Map<String, dynamic>.from(response.data as Map);
    setState(() {
      _definitions = [..._definitions, row];
      _selectedId = row['id'] as String?;
      _screenIndex = 0;
      _fieldIndex = 0;
    });
    _adoptControllers();
  }

  Future<void> _save() async {
    final current = _selected;
    if (current == null) return;
    setState(() => _busy = 'save');
    final response = await widget.api.patch(
      '/inspections/${current['id']}',
      {
        'name': current['name'],
        'intro': current['intro'],
        'layout_preset': current['layout_preset'],
        'screens': current['screens'],
      },
    );
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _publish() async {
    await _save();
    final current = _selected;
    if (current == null) return;
    setState(() => _busy = 'publish');
    final response =
        await widget.api.post('/inspections/${current['id']}/publish', {});
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _deleteDraft() async {
    final current = _selected;
    if (current == null || !_isDeletableDraft(current)) return;
    setState(() => _busy = 'delete');
    final response = await widget.api.delete('/inspections/${current['id']}');
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    setState(() {
      _definitions = [
        for (final row in _definitions)
          if (row['id'] != current['id']) row,
      ];
      _selectedId = _definitions.isEmpty
          ? null
          : _definitions.first['id'] as String?;
      _screenIndex = 0;
      _fieldIndex = 0;
    });
    _adoptControllers();
  }

  static bool _isDeletableDraft(Map<String, dynamic> row) =>
      row['published'] != true && row['current_version'] == null;

  Future<void> _unpublish() async {
    final current = _selected;
    if (current == null) return;
    setState(() => _busy = 'unpublish');
    final response =
        await widget.api.post('/inspections/${current['id']}/unpublish', {});
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  void _replace(Map<String, dynamic> row) {
    setState(() {
      _definitions = [
        for (final existing in _definitions)
          if (existing['id'] == row['id']) row else existing,
      ];
    });
  }

  void _mutate(void Function(Map<String, dynamic> row) fn) {
    final current = _selected;
    if (current == null) return;
    final copy = Map<String, dynamic>.from(current);
    copy['screens'] = [
      for (final screen in _screens) Map<String, dynamic>.from(screen),
    ];
    fn(copy);
    _replace(copy);
  }

  void _addScreen() {
    _mutate((row) {
      final screens = List<Map<String, dynamic>>.from(
        (row['screens'] as List? ?? const []).whereType<Map>().map(
          Map<String, dynamic>.from,
        ),
      );
      screens.add({
        'name': 'Screen ${screens.length + 1}',
        'fields': <Map<String, dynamic>>[],
      });
      row['screens'] = screens;
      _screenIndex = screens.length - 1;
      _fieldIndex = 0;
    });
    _adoptControllers();
  }

  void _addField() {
    _mutate((row) {
      final screens = _asScreens(row);
      if (screens.isEmpty) return;
      final index = _screenIndex.clamp(0, screens.length - 1);
      final fields = _asFields(screens[index]);
      fields.add({
        'key': 'field_${fields.length + 1}',
        'label': 'Question ${fields.length + 1}',
        'type': 'text',
        'required': false,
      });
      screens[index]['fields'] = fields;
      row['screens'] = screens;
      _fieldIndex = fields.length - 1;
    });
    _adoptControllers();
  }

  void _moveScreen(int from, int to) {
    _mutate((row) {
      final screens = _asScreens(row);
      if (to < 0 || to >= screens.length) return;
      final item = screens.removeAt(from);
      screens.insert(to, item);
      row['screens'] = screens;
      _screenIndex = to;
    });
  }

  void _moveField(int from, int to) {
    _mutate((row) {
      final screens = _asScreens(row);
      if (screens.isEmpty) return;
      final fields = _asFields(screens[_screenIndex.clamp(0, screens.length - 1)]);
      if (to < 0 || to >= fields.length) return;
      final item = fields.removeAt(from);
      fields.insert(to, item);
      screens[_screenIndex]['fields'] = fields;
      row['screens'] = screens;
      _fieldIndex = to;
    });
  }

  static List<Map<String, dynamic>> _asScreens(Map<String, dynamic> row) => [
        for (final s in (row['screens'] as List? ?? const []))
          if (s is Map) Map<String, dynamic>.from(s),
      ];

  static List<Map<String, dynamic>> _asFields(Map<String, dynamic> screen) => [
        for (final f in (screen['fields'] as List? ?? const []))
          if (f is Map) Map<String, dynamic>.from(f),
      ];

  static List<Map<String, dynamic>> _maps(dynamic raw) {
    if (raw is! List) return const [];
    return [
      for (final row in raw)
        if (row is Map) Map<String, dynamic>.from(row),
    ];
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final stacked = constraints.maxWidth < BuilderTab.stackBelow;
          final panes = [
            _pane(
              key: const Key('inspection-builder-list'),
              child: _listPane(context),
            ),
            _pane(
              key: const Key('inspection-builder-fields'),
              child: _fieldsPane(context),
            ),
            _pane(
              key: const Key('inspection-builder-phone'),
              child: InspectionPhonePreview(
                definition: _selected,
                screenIndex: _screenIndex,
                presets: _presets,
              ),
            ),
          ];

          if (stacked) {
            return SingleChildScrollView(
              key: const Key('inspection-builder'),
              child: Column(
                children: [
                  SizedBox(height: 280, child: panes[0]),
                  SizedBox(height: 480, child: panes[1]),
                  SizedBox(height: 520, child: panes[2]),
                ],
              ),
            );
          }

          return Row(
            key: const Key('inspection-builder'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(flex: 2, child: panes[0]),
              Expanded(flex: 3, child: panes[1]),
              Expanded(flex: 2, child: panes[2]),
            ],
          );
        },
      ),
    );
  }

  Widget _pane({required Key key, required Widget child}) {
    return Container(
      key: key,
      decoration: BoxDecoration(
        border: Border(
          right: BorderSide(color: EzBiziTheme.edge(context)),
          bottom: BorderSide(color: EzBiziTheme.edge(context)),
        ),
      ),
      child: child,
    );
  }

  Widget _listPane(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.all(DashboardCard.padding),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  'Named inspections',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              FilledButton(
                onPressed: _busy == 'create' ? null : _create,
                child: const Text('Create'),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: _definitions.isEmpty
              ? const Padding(
                  padding: EdgeInsets.all(DashboardCard.padding),
                  child: DashboardNote(
                    'No named inspections yet. Create one to decide the steps, '
                    'the look, and what has to be photographed.',
                  ),
                )
              : ListView.builder(
                  itemCount: _definitions.length,
                  itemBuilder: (context, index) {
                    final row = _definitions[index];
                    final selected = row['id'] == _selectedId;
                    return ListTile(
                      selected: selected,
                      title: Text('${row['name'] ?? ''}'),
                      trailing: Tag(
                        row['published'] == true ? 'Published' : 'Draft',
                      ),
                      onTap: () {
                        setState(() {
                          _selectedId = row['id'] as String?;
                          _screenIndex = 0;
                          _fieldIndex = 0;
                        });
                        _adoptControllers();
                      },
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _fieldsPane(BuildContext context) {
    final current = _selected;
    if (current == null) {
      return const Padding(
        padding: EdgeInsets.all(DashboardCard.padding),
        child: DashboardNote(
          'Select a named inspection, or create one.',
        ),
      );
    }

    final screens = _screens;
    final screen = screens.isEmpty
        ? null
        : screens[_screenIndex.clamp(0, screens.length - 1)];
    final fields = screen == null ? const <Map<String, dynamic>>[] : _fieldsOf(screen);
    final field = fields.isEmpty
        ? null
        : fields[_fieldIndex.clamp(0, fields.length - 1)];

    return ListView(
      padding: const EdgeInsets.all(DashboardCard.padding),
      children: [
        TextField(
          controller: _name,
          decoration: const InputDecoration(labelText: 'Name'),
          onChanged: (value) => _mutate((row) => row['name'] = value),
        ),
        const SizedBox(height: 12),
        Text('Look', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final preset in _presets)
              ChoiceChip(
                label: Text('${preset['label'] ?? preset['key']}'),
                selected: current['layout_preset'] == preset['key'],
                onSelected: (_) =>
                    _mutate((row) => row['layout_preset'] = preset['key']),
              ),
          ],
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _intro,
          decoration: const InputDecoration(
            labelText: 'Intro',
            hintText: 'Shown at the start of a run. Optional.',
          ),
          maxLines: 3,
          onChanged: (value) => _mutate((row) => row['intro'] = value),
        ),
        const SizedBox(height: 20),
        Row(
          children: [
            Expanded(
              child: Text('Screens', style: Theme.of(context).textTheme.titleSmall),
            ),
            TextButton(onPressed: _addScreen, child: const Text('Add screen')),
          ],
        ),
        for (var i = 0; i < screens.length; i++)
          ListTile(
            contentPadding: EdgeInsets.zero,
            selected: i == _screenIndex,
            title: Text('${screens[i]['name'] ?? 'Screen ${i + 1}'}'),
            leading: IconButton(
              tooltip: 'Move up',
              onPressed: i == 0 ? null : () => _moveScreen(i, i - 1),
              icon: const Icon(Icons.arrow_upward),
            ),
            trailing: IconButton(
              tooltip: 'Move down',
              onPressed: i == screens.length - 1
                  ? null
                  : () => _moveScreen(i, i + 1),
              icon: const Icon(Icons.arrow_downward),
            ),
            onTap: () {
              setState(() {
                _screenIndex = i;
                _fieldIndex = 0;
              });
              _adoptControllers();
            },
          ),
        if (screen != null) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text('Fields', style: Theme.of(context).textTheme.titleSmall),
              ),
              TextButton(onPressed: _addField, child: const Text('Add field')),
            ],
          ),
          for (var i = 0; i < fields.length; i++)
            ListTile(
              contentPadding: EdgeInsets.zero,
              selected: i == _fieldIndex,
              title: Text('${fields[i]['label'] ?? fields[i]['key']}'),
              subtitle: Text('${fields[i]['type'] ?? ''}'),
              leading: IconButton(
                tooltip: 'Move up',
                onPressed: i == 0 ? null : () => _moveField(i, i - 1),
                icon: const Icon(Icons.arrow_upward),
              ),
              trailing: IconButton(
                tooltip: 'Move down',
                onPressed: i == fields.length - 1
                    ? null
                    : () => _moveField(i, i + 1),
                icon: const Icon(Icons.arrow_downward),
              ),
              onTap: () {
                setState(() => _fieldIndex = i);
                _adoptControllers();
              },
            ),
        ],
        if (field != null) ...[
          const SizedBox(height: 16),
          Text('This field', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 8),
          TextField(
            controller: _label,
            decoration: const InputDecoration(labelText: 'Label'),
            onChanged: (value) => _updateField((row) => row['label'] = value),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey('type-$_selectedId-$_screenIndex-$_fieldIndex'),
            initialValue: '${field['type'] ?? 'text'}',
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Type'),
            items: [
              for (final type in _typesFor(field))
                DropdownMenuItem(
                  value: '${type['key']}',
                  child: Text('${type['label'] ?? type['key']}'),
                ),
            ],
            onChanged: (value) {
              if (value == null) return;
              _updateField((row) => row['type'] = value);
            },
          ),
          // The list a `choice` offers. Without this the type was pickable and
          // its options were not, so a company could ask "one of a list" and
          // never say which list — an empty dropdown on the technician's phone.
          if (field['type'] == 'choice') ...[
            const SizedBox(height: 12),
            TextField(
              key: const Key('field-options'),
              controller: _options,
              minLines: 3,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'Choices',
                helperText: 'One per line. Blank lines are ignored.',
                alignLabelWithHint: true,
              ),
              onChanged: (value) => _updateField((row) => row['options'] = [
                    for (final line in value.split('\n'))
                      if (line.trim().isNotEmpty) line.trim(),
                  ]),
            ),
          ],
          if (field['type'] == 'photo') ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              key: ValueKey('max-$_selectedId-$_screenIndex-$_fieldIndex'),
              initialValue: (field['max_count'] as num?)?.toInt() ?? 1,
              decoration: const InputDecoration(labelText: 'How many photos'),
              items: [
                for (final n in [1, 2, 3, 4, 6, 8, 12])
                  DropdownMenuItem(value: n, child: Text('$n')),
              ],
              onChanged: (value) => _updateField(
                (row) => row['max_count'] = value ?? 1,
              ),
            ),
          ],
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Required'),
            value: field['required'] == true,
            onChanged: (value) => _updateField((row) => row['required'] = value),
          ),
          DropdownButtonFormField<String>(
            key: ValueKey('bind-$_selectedId-$_screenIndex-$_fieldIndex'),
            initialValue: field['bind'] is String ? field['bind'] as String : '',
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Prefill from'),
            items: [
              const DropdownMenuItem(value: '', child: Text('Nothing')),
              for (final bind in _bindable)
                DropdownMenuItem(
                  value: '${bind['key']}',
                  child: Text('${bind['label'] ?? bind['key']}'),
                ),
            ],
            onChanged: (value) => _updateField((row) {
              if (value == null || value.isEmpty) {
                row.remove('bind');
              } else {
                row['bind'] = value;
              }
            }),
          ),
        ],
        const SizedBox(height: 24),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton(
              onPressed: _busy == 'save' ? null : _save,
              child: const Text('Save'),
            ),
            FilledButton(
              onPressed: _busy == 'publish' ? null : _publish,
              child: const Text('Publish'),
            ),
            if (current['published'] == true)
              TextButton(
                onPressed: _busy == 'unpublish' ? null : _unpublish,
                child: const Text('Unpublish'),
              ),
            if (_isDeletableDraft(current))
              TextButton(
                onPressed: _busy == 'delete' ? null : _deleteDraft,
                child: const Text('Delete draft'),
              ),
          ],
        ),
      ],
    );
  }

  List<Map<String, dynamic>> _typesFor(Map<String, dynamic> field) {
    if (_fieldTypes.isNotEmpty) return _fieldTypes;
    final type = '${field['type'] ?? 'text'}';
    return [
      {'key': type, 'label': type},
    ];
  }

  void _updateField(void Function(Map<String, dynamic> field) fn) {
    _mutate((row) {
      final screens = _asScreens(row);
      if (screens.isEmpty) return;
      final si = _screenIndex.clamp(0, screens.length - 1);
      final fields = _asFields(screens[si]);
      if (fields.isEmpty) return;
      final fi = _fieldIndex.clamp(0, fields.length - 1);
      fn(fields[fi]);
      screens[si]['fields'] = fields;
      row['screens'] = screens;
    });
  }
}

/// Phone preview of the selected screen. Answers are invented samples, never
/// a live client or job.
class InspectionPhonePreview extends StatelessWidget {
  const InspectionPhonePreview({
    super.key,
    required this.definition,
    required this.screenIndex,
    required this.presets,
  });

  final Map<String, dynamic>? definition;
  final int screenIndex;
  final List<Map<String, dynamic>> presets;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final current = definition;
    if (current == null) {
      return const Padding(
        padding: EdgeInsets.all(DashboardCard.padding),
        child: DashboardNote(
          'The phone shows this screen with sample answers, not a real client.',
        ),
      );
    }

    final screens = [
      for (final s in (current['screens'] as List? ?? const []))
        if (s is Map) Map<String, dynamic>.from(s),
    ];
    final screen = screens.isEmpty
        ? null
        : screens[screenIndex.clamp(0, screens.length - 1)];
    final preset = '${current['layout_preset'] ?? 'step_strip'}';
    final presetLabel = presets
        .where((row) => row['key'] == preset)
        .map((row) => '${row['label']}')
        .firstWhere((label) => label.isNotEmpty, orElse: () => preset);

    return Padding(
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: Column(
        children: [
          Text('Phone preview', style: theme.textTheme.titleSmall),
          const SizedBox(height: 4),
          Text(
            'Sample answers, not a real client. $presetLabel.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 12),
          Expanded(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 320),
                child: AspectRatio(
                  aspectRatio: 9 / 16,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surface,
                      border: Border.all(color: EzBiziTheme.edge(context)),
                      borderRadius: BorderRadius.circular(24),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 28, 16, 16),
                      child: screen == null
                          ? const DashboardNote(
                              'Add a screen to see how this inspection will look.',
                            )
                          : _screenBody(context, current, screen, preset),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _screenBody(
    BuildContext context,
    Map<String, dynamic> definition,
    Map<String, dynamic> screen,
    String preset,
  ) {
    final fields = [
      for (final f in (screen['fields'] as List? ?? const []))
        if (f is Map) Map<String, dynamic>.from(f),
    ];
    final intro = '${definition['intro'] ?? ''}';
    final heading = '${screen['name'] ?? definition['name'] ?? ''}';

    return ListView(
      children: [
        Text(
          '${definition['name'] ?? ''}',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        if (intro.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(intro, style: Theme.of(context).textTheme.bodySmall),
        ],
        const SizedBox(height: 12),
        if (preset == 'step_strip')
          Text(
            heading,
            style: Theme.of(context).textTheme.titleSmall,
          ),
        if (preset == 'document')
          Text(
            heading,
            style: Theme.of(context).textTheme.titleSmall,
          ),
        const SizedBox(height: 8),
        for (final field in fields) ...[
          Text(
            '${field['label'] ?? field['key']}',
            style: Theme.of(context).textTheme.labelMedium,
          ),
          const SizedBox(height: 4),
          Text(
            placeholderFor(field),
            key: Key('sample-${field['key']}'),
          ),
          const SizedBox(height: 12),
        ],
      ],
    );
  }

  static String placeholderFor(Map<String, dynamic> field) {
    final bind = field['bind'];
    if (bind == 'customer.name') return 'Sample client';
    if (bind == 'job.number') return 'J-0000';
    if (bind == 'job.scheduled_for') return '9:00 AM';
    final type = '${field['type'] ?? ''}';
    if (type == 'choice') {
      final options = field['options'];
      if (options is List && options.isNotEmpty) return '${options.first}';
    }
    if (type == 'signature') return 'Signed (sample)';
    if (type == 'photo') return 'Photo (sample)';
    return 'Sample answer';
  }
}
