import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/side_panel.dart';
import 'listing_copy.dart';

/// A listing's categories and services on Google, with Copy to locations
/// (design 154 #4). Primary category first, additional ones as chips; the
/// services as compact rows grouped by category, each edited in a SidePanel;
/// Copy previews the per-listing diff before anything is written.
class ListingServices extends StatefulWidget {
  const ListingServices({
    super.key,
    required this.api,
    required this.locationId,
    required this.others,
  });

  final ApiClient api;
  final String locationId;
  final List<ListingTarget> others;

  @override
  State<ListingServices> createState() => _ListingServicesState();
}

/// What Google calls a service: a free-form one's own name, or a structured
/// one's type id made readable.
String serviceName(Map s) {
  final free = s['freeFormServiceItem'];
  if (free is Map) {
    final name = (free['label'] as Map?)?['displayName'];
    if (name is String && name.isNotEmpty) return name;
  }
  final structured = s['structuredServiceItem'];
  if (structured is Map) {
    final id = '${structured['serviceTypeId'] ?? ''}';
    final tail = id.contains(':') ? id.substring(id.indexOf(':') + 1) : id;
    final words = tail.replaceAll('_', ' ').trim();
    if (words.isNotEmpty) return words[0].toUpperCase() + words.substring(1);
  }
  return 'Unnamed service';
}

String _description(Map s) =>
    '${((s['freeFormServiceItem'] as Map?)?['label'] as Map?)?['description'] ?? (s['structuredServiceItem'] as Map?)?['description'] ?? ''}';

String _categoryOf(Map s) =>
    '${(s['freeFormServiceItem'] as Map?)?['category'] ?? ''}';

String _price(Map s) {
  final price = s['price'];
  if (price is! Map) return 'Free quote';
  final units = price['units'];
  if (units == null) return 'Free quote';
  return price['currencyCode'] == 'USD' || price['currencyCode'] == null
      ? '\$$units'
      : '$units ${price['currencyCode']}';
}

class _ListingServicesState extends State<ListingServices> {
  Map<String, dynamic>? _data;
  String? _error;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ListingServices old) {
    super.didUpdateWidget(old);
    if (old.locationId != widget.locationId) {
      _data = null;
      _load();
    }
  }

  String get _path => '/business-profile/locations/${widget.locationId}';

  Future<void> _load() async {
    final r = await widget.api.get('$_path/services');
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _data = r.ok ? Map<String, dynamic>.from(r.data as Map) : null;
    });
  }

  List<Map<String, dynamic>> get _services => [
    for (final s in (_data?['services'] as List? ?? const []).whereType<Map>())
      Map<String, dynamic>.from(s),
  ];

  /// gcid → its name, for grouping services under their category.
  Map<String, String> get _categoryNames => {
    for (final c in [
      _data?['primary'],
      ...(_data?['additional'] as List? ?? const []),
    ].whereType<Map>())
      '${c['name']}': '${c['displayName'] ?? c['name']}',
  };

  Future<void> _edit(int index) async {
    final services = _services;
    final saved = await showSidePanel<List<Map<String, dynamic>>>(
      context,
      builder: (_) => _ServicePanel(
        api: widget.api,
        path: '$_path/services',
        services: services,
        index: index,
      ),
    );
    if (saved != null && mounted) {
      setState(() => _data = {...?_data, 'services': saved});
    }
  }

  Future<void> _copy() => showSidePanel<bool>(
    context,
    builder: (_) =>
        CopyServicesPanel(api: widget.api, path: _path, others: widget.others),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: refusedNote(context, _error!),
      );
    }
    final data = _data;
    if (data == null) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final primary = data['primary'] as Map?;
    final additional = (data['additional'] as List? ?? const [])
        .whereType<Map>();
    final names = _categoryNames;
    final q = _q.trim().toLowerCase();
    final services = _services;
    final groups = <String, List<int>>{};
    for (var i = 0; i < services.length; i++) {
      final s = services[i];
      if (q.isNotEmpty &&
          !serviceName(s).toLowerCase().contains(q) &&
          !_description(s).toLowerCase().contains(q)) {
        continue;
      }
      final group = names[_categoryOf(s)] ?? 'Other services';
      groups.putIfAbsent(group, () => []).add(i);
    }

    return Column(
      key: const ValueKey('cx-services'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Categories', style: theme.textTheme.titleSmall),
            ),
            TextButton(
              key: const ValueKey('cx-services-copy'),
              onPressed: widget.others.isEmpty || services.isEmpty
                  ? null
                  : _copy,
              child: const Text('Copy to locations…'),
            ),
          ],
        ),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            if (primary != null)
              Chip(
                key: const ValueKey('cx-category-primary'),
                avatar: const Icon(Icons.star, size: 16),
                label: Text('${primary['displayName'] ?? primary['name']}'),
              ),
            for (final c in additional)
              Chip(label: Text('${c['displayName'] ?? c['name']}')),
          ],
        ),
        const SizedBox(height: 12),
        Text(
          'Services · ${services.length}',
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 6),
        TextField(
          key: const ValueKey('cx-services-search'),
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search, size: 18),
            hintText: 'Search services',
            isDense: true,
          ),
          onChanged: (v) => setState(() => _q = v),
        ),
        const SizedBox(height: 6),
        if (groups.isEmpty)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              services.isEmpty
                  ? 'Google lists no services on this listing.'
                  : 'No service matches "$_q".',
              style: theme.textTheme.bodySmall,
            ),
          ),
        for (final MapEntry(key: group, value: rows) in groups.entries) ...[
          Padding(
            padding: const EdgeInsets.only(top: 8, bottom: 2),
            child: Text(group.toUpperCase(), style: theme.textTheme.labelSmall),
          ),
          for (final i in rows)
            InkWell(
              key: ValueKey('cx-service-$i'),
              onTap: () => _edit(i),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            serviceName(services[i]),
                            style: theme.textTheme.bodyMedium,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          if (_description(services[i]).isNotEmpty)
                            Text(
                              _description(services[i]),
                              style: theme.textTheme.bodySmall,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(_price(services[i]), style: theme.textTheme.bodySmall),
                  ],
                ),
              ),
            ),
        ],
      ],
    );
  }
}

/// One service edited: its name and description, then the whole list is
/// replaced on Google (that is how Google's update works).
class _ServicePanel extends StatefulWidget {
  const _ServicePanel({
    required this.api,
    required this.path,
    required this.services,
    required this.index,
  });

  final ApiClient api;
  final String path;
  final List<Map<String, dynamic>> services;
  final int index;

  @override
  State<_ServicePanel> createState() => _ServicePanelState();
}

class _ServicePanelState extends State<_ServicePanel> {
  late final Map<String, dynamic> _s = widget.services[widget.index];
  late final _name = TextEditingController(text: serviceName(_s));
  late final _about = TextEditingController(text: _description(_s));
  bool _busy = false;
  String? _refused;

  bool get _freeForm => _s['freeFormServiceItem'] is Map;

  @override
  void dispose() {
    _name.dispose();
    _about.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final edited = Map<String, dynamic>.from(_s);
    if (_freeForm) {
      final free = Map<String, dynamic>.from(_s['freeFormServiceItem'] as Map);
      free['label'] = {
        ...?(free['label'] as Map?)?.cast<String, dynamic>(),
        'displayName': _name.text.trim(),
        'description': _about.text.trim(),
      };
      edited['freeFormServiceItem'] = free;
    } else {
      edited['structuredServiceItem'] = {
        ...(_s['structuredServiceItem'] as Map).cast<String, dynamic>(),
        'description': _about.text.trim(),
      };
    }
    final list = [...widget.services]..[widget.index] = edited;
    setState(() {
      _busy = true;
      _refused = null;
    });
    final r = await widget.api.put(widget.path, {'services': list});
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _busy = false;
        _refused = r.message;
      });
      return;
    }
    Navigator.of(context).pop([
      for (final s in (r.data as List? ?? list).whereType<Map>())
        Map<String, dynamic>.from(s),
    ]);
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: serviceName(_s),
    actions: [
      FilledButton(
        key: const ValueKey('cx-service-save'),
        onPressed: _busy ? null : _save,
        child: const Text('Save'),
      ),
    ],
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_refused != null) refusedNote(context, _refused!),
        TextField(
          key: const ValueKey('cx-service-name'),
          controller: _name,
          enabled: _freeForm,
          decoration: const InputDecoration(labelText: 'Service'),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('cx-service-about'),
          controller: _about,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Description'),
        ),
        const SizedBox(height: 12),
        Text(
          'Google may take up to a day to show edits.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    ),
  );
}

/// Copy this listing's services: tick listings, Preview the per-listing
/// diff, then Apply, which replaces each one's list and reports each result.
class CopyServicesPanel extends StatefulWidget {
  const CopyServicesPanel({
    super.key,
    required this.api,
    required this.path,
    required this.others,
  });

  final ApiClient api;
  final String path;
  final List<ListingTarget> others;

  @override
  State<CopyServicesPanel> createState() => _CopyServicesPanelState();
}

class _CopyServicesPanelState extends State<CopyServicesPanel> {
  final Set<String> _ticked = {};
  String _q = '';
  bool _busy = false;
  String? _refused;

  /// Replace each listing's list, or only add what it is missing.
  String _mode = 'replace';

  /// The preview, once asked for; Apply is offered only then.
  List<Map<String, dynamic>>? _diff;
  Map<String, CopyResult> _results = {};

  Future<void> _preview() async {
    setState(() {
      _busy = true;
      _refused = null;
      _results = {};
    });
    final r = await widget.api.post('${widget.path}/services/copy', {
      'targets': _ticked.toList(),
      'apply': false,
      'mode': _mode,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _refused = r.ok ? null : r.message;
      _diff = r.ok
          ? [
              for (final d in (r.data as List? ?? const []).whereType<Map>())
                Map<String, dynamic>.from(d),
            ]
          : null;
    });
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _refused = null;
    });
    final answer = await sendCopy(widget.api, '${widget.path}/services/copy', {
      'targets': _ticked.toList(),
      'apply': true,
      'mode': _mode,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _refused = answer.refused;
      _results = {for (final r in answer.results ?? const []) r.id: r};
    });
  }

  Widget _diffRow(ThemeData theme, Map<String, dynamic> d) {
    final adds = [...(d['adds'] as List? ?? const [])];
    final removes = [...(d['removes'] as List? ?? const [])];
    final has = [...(d['has'] as List? ?? const [])];
    final result = _results['${d['id']}'];
    final label = widget.others
        .where((t) => t.id == d['id'])
        .map((t) => t.label)
        .firstOrNull;
    return Padding(
      key: ValueKey('cx-diff-${d['id']}'),
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label ?? '${d['name']}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              if (result != null) copyResultPill(result),
            ],
          ),
          Text(
            has.isEmpty ? 'Has no services yet.' : 'Has: ${has.join(', ')}',
            key: ValueKey('cx-diff-has-${d['id']}'),
            style: theme.textTheme.bodySmall,
          ),
          if (adds.isEmpty && removes.isEmpty)
            Text('Already the same.', style: theme.textTheme.bodySmall),
          for (final a in adds)
            Text(
              '+ $a',
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, Tone.ok).ink,
              ),
            ),
          for (final x in removes)
            Text(
              '− $x',
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, Tone.bad).ink,
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final diff = _diff;
    return SidePanel(
      title: 'Copy services to…',
      onBack: diff == null ? null : () => setState(() => _diff = null),
      actions: [
        if (diff == null)
          FilledButton(
            key: const ValueKey('cx-services-preview'),
            onPressed: _busy || _ticked.isEmpty ? null : _preview,
            child: const Text('Preview changes'),
          )
        else
          FilledButton(
            key: const ValueKey('cx-services-apply'),
            onPressed: _busy || _results.isNotEmpty ? null : _apply,
            child: Text(
              '${_mode == 'replace' ? 'Replace' : 'Add'} on ${diff.length} '
              '${diff.length == 1 ? 'listing' : 'listings'}',
            ),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_refused != null) refusedNote(context, _refused!),
          SegmentedButton<String>(
            key: const ValueKey('cx-services-mode'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 'replace', label: Text('Replace')),
              ButtonSegment(
                value: 'add_missing',
                label: Text('Add missing only'),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: _busy || _results.isNotEmpty
                ? null
                : (v) {
                    setState(() => _mode = v.first);
                    // A preview shown is a preview of the mode chosen.
                    if (_diff != null) _preview();
                  },
          ),
          const SizedBox(height: 12),
          if (diff == null)
            ListingTargets(
              targets: widget.others,
              ticked: _ticked,
              query: _q,
              onQuery: (v) => setState(() => _q = v),
              onChanged: (v) => setState(() {
                _ticked
                  ..clear()
                  ..addAll(v);
              }),
            )
          else ...[
            Text(
              _mode == 'replace'
                  ? 'Each listing\'s services become this listing\'s. Nothing '
                        'is written until you replace.'
                  : 'Each listing keeps its services and gains the ones it is '
                        'missing. Nothing is written until you add.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final d in diff) _diffRow(theme, d),
          ],
        ],
      ),
    );
  }
}
