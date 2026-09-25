/// The team and the locations, edited beside the widgets that draw them.
///
/// Jose, 2026-09-25: "whatever is missing that is being used add it to
/// ezbizi". A1 Tools' team page, franchise block and store locator each drew a
/// list A1 Tools held; these are EzBizi's lists, and this is where they are
/// kept. Opened from the Team, Branches and Store locator cards.
///
/// The whole list is saved at once, like the contact form's questions, so a
/// reorder and a removal land together. Both lists belong to the COMPANY
/// except the branches, which are this website's own — the header says which,
/// because editing the team from one subdomain changes it on all of them.
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import 'seo_tab.dart' show firstApiError;

/// Which list, and what each entry asks for.
enum SiteListKind { team, branches, stores }

class _Field {
  const _Field(this.key, this.label, {this.lines = 1, this.number = false});

  final String key;
  final String label;
  final int lines;
  final bool number;
}

const List<_Field> _teamFields = [
  _Field('name', 'Name'),
  _Field('role', 'Job title'),
  _Field('category', 'Listed under', ),
  _Field('photo_url', 'Photo link'),
  _Field('bio', 'About them', lines: 3),
];

const List<_Field> _locationFields = [
  _Field('name', 'Name'),
  _Field('phone', 'Phone'),
  _Field('email', 'Email'),
  _Field('address_line1', 'Street address'),
  _Field('address_line2', 'Suite or unit'),
  _Field('city', 'City'),
  _Field('state', 'State'),
  _Field('postal_code', 'ZIP'),
  _Field('maps_url', 'Google Maps link'),
  _Field('latitude', 'Latitude', number: true),
  _Field('longitude', 'Longitude', number: true),
];

const List<_Field> _storeExtras = [
  _Field('website_url', 'Website'),
  _Field('image_url', 'Picture link'),
];

class SiteListEditor extends StatefulWidget {
  const SiteListEditor({
    super.key,
    required this.api,
    required this.siteId,
    required this.canEdit,
    required this.kind,
  });

  final ApiClient api;
  final String siteId;
  final bool canEdit;
  final SiteListKind kind;

  @override
  State<SiteListEditor> createState() => _SiteListEditorState();
}

class _Row {
  _Row(this.id, List<_Field> fields, Map<String, dynamic> values)
      : boxes = {
          for (final f in fields)
            f.key: TextEditingController(text: '${values[f.key] ?? ''}'),
        };

  final int id;
  final Map<String, TextEditingController> boxes;

  void dispose() {
    for (final b in boxes.values) {
      b.dispose();
    }
  }
}

class _SiteListEditorState extends State<SiteListEditor> {
  final List<_Row> _rows = [];
  int _next = 0;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;

  List<_Field> get _fields => switch (widget.kind) {
        SiteListKind.team => _teamFields,
        SiteListKind.branches => _locationFields,
        SiteListKind.stores => [..._locationFields, ..._storeExtras],
      };

  String get _path => widget.kind == SiteListKind.team
      ? '/web/sites/${widget.siteId}/team'
      : '/web/sites/${widget.siteId}/locations';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(SiteListEditor old) {
    super.didUpdateWidget(old);
    if (old.siteId != widget.siteId || old.kind != widget.kind) _load();
  }

  @override
  void dispose() {
    for (final r in _rows) {
      r.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get(_path);
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = firstApiError(response);
        return;
      }

      final data = response.body['data'];
      final list = switch (widget.kind) {
        SiteListKind.team => data,
        SiteListKind.branches => (data as Map?)?['site'],
        SiteListKind.stores => (data as Map?)?['company'],
      };

      _retire(List.of(_rows));
      _rows
        ..clear()
        ..addAll([
          for (final item in (list as List? ?? const []).whereType<Map>())
            _Row(_next++, _fields, Map<String, dynamic>.from(item)),
        ]);
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
      _saved = null;
    });

    final items = [
      for (final r in _rows)
        {
          for (final f in _fields)
            f.key: () {
              final text = r.boxes[f.key]!.text.trim();
              if (text.isEmpty) return null;
              return f.number ? double.tryParse(text) : text;
            }(),
        },
    ];

    final response = await widget.api.put(
      _path,
      widget.kind == SiteListKind.team
          ? {'members': items}
          : {
              'scope': widget.kind == SiteListKind.branches ? 'site' : 'company',
              'locations': items,
            },
    );
    if (!mounted) return;

    setState(() {
      _saving = false;
      if (response.ok) {
        _saved = 'Saved. Websites pick this up within a few minutes.';
      } else {
        _error = firstApiError(response);
      }
    });
  }

  /// Disposed after the frame, not now: the fields still on screen hold
  /// these controllers until the rebuild lets go of them.
  void _retire(List<_Row> gone) =>
      WidgetsBinding.instance.addPostFrameCallback((_) {
        for (final r in gone) {
          r.dispose();
        }
      });

  void _move(int i, int by) => setState(() {
        final row = _rows.removeAt(i);
        _rows.insert(i + by, row);
        _saved = null;
      });

  String get _intro => switch (widget.kind) {
        SiteListKind.team =>
          'The people [ezbizi_team] shows. One list for every website this '
              'company runs, grouped under "Listed under".',
        SiteListKind.branches =>
          'The branches THIS website answers for, as [ezbizi_branches] shows '
              'them. Other websites keep their own.',
        SiteListKind.stores =>
          'Every location, for [ezbizi_store_locator]. One list for every '
              'website this company runs.',
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        Text(
          widget.kind == SiteListKind.team ? 'The team' : 'Locations',
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 4),
        Text(_intro, style: theme.textTheme.bodySmall),
        const SizedBox(height: 8),
        if (_rows.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Text(
              'Nothing here yet, so the widget draws nothing.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        for (var i = 0; i < _rows.length; i++) _entry(theme, i),
        if (widget.canEdit)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: () => setState(() {
                  _rows.add(_Row(_next++, _fields, const {}));
                  _saved = null;
                }),
                icon: const Icon(Icons.add, size: 18),
                label: Text(widget.kind == SiteListKind.team
                    ? 'Add a person'
                    : 'Add a location'),
              ),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save list'),
              ),
            ],
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
            ),
          ),
        if (_saved != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_saved!, style: theme.textTheme.bodySmall),
          ),
      ],
    );
  }

  Widget _entry(ThemeData theme, int i) {
    final row = _rows[i];
    final name = row.boxes['name']!.text.trim();

    return Card(
      key: ValueKey('site-list-${row.id}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        title: Text(name.isEmpty ? 'New entry' : name),
        subtitle: widget.kind == SiteListKind.team
            ? (row.boxes['role']!.text.trim().isEmpty ? null : Text(row.boxes['role']!.text))
            : (row.boxes['city']!.text.trim().isEmpty ? null : Text(row.boxes['city']!.text)),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        children: [
          for (final f in _fields)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: TextField(
                controller: row.boxes[f.key],
                enabled: widget.canEdit,
                minLines: f.lines,
                maxLines: f.lines == 1 ? 1 : 6,
                keyboardType: f.number
                    ? const TextInputType.numberWithOptions(decimal: true, signed: true)
                    : null,
                decoration: InputDecoration(labelText: f.label, isDense: true),
                onChanged: (_) => setState(() => _saved = null),
              ),
            ),
          if (widget.canEdit)
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                IconButton(
                  tooltip: 'Move up',
                  onPressed: i == 0 ? null : () => _move(i, -1),
                  icon: const Icon(Icons.arrow_upward, size: 18),
                ),
                IconButton(
                  tooltip: 'Move down',
                  onPressed: i == _rows.length - 1 ? null : () => _move(i, 1),
                  icon: const Icon(Icons.arrow_downward, size: 18),
                ),
                TextButton.icon(
                  onPressed: () => setState(() {
                    _retire([_rows.removeAt(i)]);
                    _saved = null;
                  }),
                  icon: const Icon(Icons.delete_outline, size: 18),
                  label: const Text('Remove'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
