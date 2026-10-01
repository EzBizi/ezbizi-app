import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'availability_pane.dart';

/// Scheduling › Locations (UI queue 49, Jose: 1 and 4): every branch, the
/// one closest to a customer's address, and who to call (Jovanni Martin,
/// suggestion 0080: "company addresses with a feature that shows the closest
/// location to the inputed address ... as well as contact info for newbie
/// dispatchers").
///
/// The branches are the ones the websites publish; the lookup is the
/// server's, straight-line miles from the typed address. The branch whose
/// service area takes the ZIP is put first even when another is nearer: a
/// dispatcher is asking who serves it.
class LocationsPane extends StatefulWidget {
  const LocationsPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<LocationsPane> createState() => _LocationsPaneState();
}

class _LocationsPaneState extends State<LocationsPane> {
  final _address = TextEditingController();
  final _find = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _locations = const [];
  Map<String, String> _notes = const {};

  bool _looking = false;
  String? _lookupError;
  Map<String, dynamic>? _result;

  String? _selectedId;
  String? _state;

  /// On a phone: the whole list, and one branch opened in place.
  bool _showAll = false;
  bool _opened = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant LocationsPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _address.dispose();
    _find.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/scheduling/locations');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      _locations = (response.body['data'] as List? ?? const [])
          .whereType<Map>()
          .map(Map<String, dynamic>.from)
          .toList();
      final notes = (response.body['meta'] as Map?)?['notes'] as List?;
      _notes = {
        for (final n in notes ?? const [])
          if (n is Map && '${n['text'] ?? ''}'.trim().isNotEmpty)
            '${n['company_id']}': '${n['text']}'.trim(),
      };
    });
  }

  Future<void> _lookup() async {
    final q = _address.text.trim();
    if (q.isEmpty) return;
    setState(() {
      _looking = true;
      _lookupError = null;
    });
    final response = await widget.api.get('/scheduling/locations/nearest', {
      'q': q,
    });
    if (!mounted) return;
    setState(() {
      _looking = false;
      if (!response.ok) {
        _result = null;
        _lookupError = response.reason;
        return;
      }
      _result = response.data is Map
          ? Map<String, dynamic>.from(response.data as Map)
          : null;
      final best = _answers.firstOrNull;
      if (best != null) _selectedId = '${best.$1['id']}';
    });
  }

  void _clear() => setState(() {
    _address.clear();
    _result = null;
    _lookupError = null;
  });

  Map<String, dynamic>? _byId(Object? id) =>
      _locations.where((l) => '${l['id']}' == '$id').firstOrNull;

  /// The answer: the branch serving the ZIP first, then the nearest by miles,
  /// three at most, each with its miles when the server measured it.
  List<(Map<String, dynamic>, double?, bool)> get _answers {
    final result = _result;
    if (result == null) return const [];
    final serving = (result['serving'] as Map?)?['location_id'];
    final miles = <String, double>{
      for (final n in result['nearest'] as List? ?? const [])
        if (n is Map && n['miles'] is num)
          '${n['location_id']}': (n['miles'] as num).toDouble(),
    };
    final order = [
      if (serving != null) '$serving',
      for (final id in miles.keys)
        if (id != '$serving') id,
    ];
    return [
      for (final id in order.take(3))
        if (_byId(id) case final location?)
          (location, miles[id], id == '$serving'),
    ];
  }

  String _addressOf(Map<String, dynamic> l, {bool withZip = true}) {
    final region = [
      '${l['state'] ?? ''}'.trim(),
      if (withZip) '${l['postal_code'] ?? ''}'.trim(),
    ].where((s) => s.isNotEmpty).join(' ');
    return [
      '${l['address_line1'] ?? ''}'.trim(),
      '${l['address_line2'] ?? ''}'.trim(),
      '${l['city'] ?? ''}'.trim(),
      region,
    ].where((s) => s.isNotEmpty).join(', ');
  }

  String _name(Map<String, dynamic> l) => [
    '${(l['company'] as Map?)?['name'] ?? ''}'.trim(),
    '${l['name'] ?? ''}'.trim(),
  ].where((s) => s.isNotEmpty).join(' · ');

  String _miles(double m) =>
      '${m >= 100 ? m.round() : m.toStringAsFixed(1)} mi';

  Future<void> _call(String phone) => launchUrl(
    Uri(scheme: 'tel', path: phone.replaceAll(RegExp(r'[^0-9+]'), '')),
  );

  Future<void> _directions(Map<String, dynamic> l) async {
    final url = '${l['maps_url'] ?? ''}'.trim();
    final target = url.isNotEmpty
        ? Uri.parse(url)
        : Uri.https('www.google.com', '/maps/search/', {
            'api': '1',
            'query': _addressOf(l),
          });
    await launchUrl(target, mode: LaunchMode.externalApplication);
  }

  Future<void> _copy(String text, String what) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text('$what copied.')));
  }

  /// Availability for the typed ZIP, or the branch's own, beside the tab as
  /// Leads opens it.
  Future<void> _availability(Map<String, dynamic> l) {
    final typed = '${(_result?['place'] as Map?)?['postal_code'] ?? ''}'.trim();
    final zip = typed.isNotEmpty ? typed : '${l['postal_code'] ?? ''}'.trim();
    return showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Availability',
        scrollable: false,
        child: AvailabilityPane(
          api: widget.api,
          session: widget.session,
          visible: true,
          initialZip: zip.isEmpty ? null : zip,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => AsyncBody(
    loading: _loading,
    error: _error,
    onRetry: _load,
    child: LayoutBuilder(
      builder: (context, box) =>
          box.maxWidth >= 900 ? _desk(context) : _phone(context),
    ),
  );

  // ── the parts both use ───────────────────────────────────────────

  Widget _lookupBox(BuildContext context) => Row(
    children: [
      Expanded(
        child: TextField(
          key: const Key('locations-lookup'),
          controller: _address,
          textInputAction: TextInputAction.search,
          onSubmitted: (_) => _lookup(),
          decoration: InputDecoration(
            prefixIcon: const Icon(Icons.search),
            hintText: "A customer's address or ZIP",
            border: const OutlineInputBorder(),
            suffixIcon: _looking
                ? const Padding(
                    padding: EdgeInsets.all(14),
                    child: SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : IconButton(
                    tooltip: 'Find the closest',
                    icon: const Icon(Icons.arrow_forward),
                    onPressed: _lookup,
                  ),
          ),
        ),
      ),
      if (_result != null) ...[
        const SizedBox(width: 8),
        OutlinedButton(onPressed: _clear, child: const Text('Clear')),
      ],
    ],
  );

  Widget _answerCard(
    BuildContext context,
    (Map<String, dynamic>, double?, bool) answer, {
    required bool first,
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final (l, miles, serves) = answer;
    final zip = '${(_result?['place'] as Map?)?['postal_code'] ?? ''}'.trim();
    final tel = '${l['phone'] ?? ''}'.trim();

    return Container(
      key: Key('locations-answer-${l['id']}'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(
          color: first ? theme.colorScheme.primary : EzBiziTheme.edge(context),
          width: first ? 1.5 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    if (first) const StatusPill('Closest', tone: Tone.ok),
                    if (serves)
                      StatusPill(
                        zip.isEmpty ? 'Serves it' : 'Serves $zip',
                        tone: Tone.info,
                      ),
                  ],
                ),
              ),
              if (miles != null)
                Text(
                  _miles(miles),
                  style:
                      (first
                              ? theme.textTheme.titleLarge
                              : theme.textTheme.titleMedium)
                          ?.copyWith(fontWeight: FontWeight.w800),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            first ? _name(l) : '${l['name'] ?? ''}',
            style: theme.textTheme.titleSmall,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          Text(
            [_addressOf(l), if (tel.isNotEmpty && !phone) tel].join(' · '),
            style: theme.textTheme.bodySmall,
            maxLines: first ? 2 : 1,
            overflow: TextOverflow.ellipsis,
          ),
          if (first) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (phone && tel.isNotEmpty)
                  FilledButton.icon(
                    onPressed: () => _call(tel),
                    icon: const Icon(Icons.call, size: 18),
                    label: const Text('Call'),
                  ),
                FilledButton(
                  key: const Key('locations-availability'),
                  onPressed: () => _availability(l),
                  child: const Text('Check availability'),
                ),
                OutlinedButton(
                  onPressed: () => _directions(l),
                  child: const Text('Directions'),
                ),
                if (!phone && tel.isNotEmpty)
                  OutlinedButton(
                    onPressed: () => _copy(tel, 'Phone number'),
                    child: const Text('Copy phone'),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _note(BuildContext context, Map<String, dynamic> l) {
    final theme = Theme.of(context);
    final company = (l['company'] as Map?)?['id'];
    final text = _notes['$company'];
    if (text == null) return const SizedBox.shrink();
    final c = ToneColours.of(context, Tone.caution);

    return Container(
      key: const Key('locations-note'),
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'For new dispatchers',
            style: theme.textTheme.titleSmall?.copyWith(color: c.ink),
          ),
          const SizedBox(height: 4),
          Text(text, style: theme.textTheme.bodyMedium?.copyWith(color: c.ink)),
        ],
      ),
    );
  }

  Widget _detail(BuildContext context, Map<String, dynamic> l) {
    final theme = Theme.of(context);
    final tel = '${l['phone'] ?? ''}'.trim();
    final email = '${l['email'] ?? ''}'.trim();
    final serves = '${l['service_area'] ?? ''}'.trim();
    final area = '${(l['area'] as Map?)?['name'] ?? ''}'.trim();
    Widget line(String label, Widget value) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 80,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: value),
        ],
      ),
    );

    return Column(
      key: Key('locations-detail-${l['id']}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: Text(_name(l), style: theme.textTheme.titleMedium)),
            if (area.isNotEmpty) StatusPill(area, tone: Tone.info),
          ],
        ),
        const SizedBox(height: 10),
        line('Address', SelectableText(_addressOf(l))),
        if (tel.isNotEmpty)
          line(
            'Phone',
            SelectableText(
              tel,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        if (email.isNotEmpty) line('Email', SelectableText(email)),
        if (serves.isNotEmpty) line('Serves', Text(serves)),
        const SizedBox(height: 6),
        _note(context, l),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton(
              onPressed: () => _availability(l),
              child: const Text('Check availability'),
            ),
            OutlinedButton(
              onPressed: () => _directions(l),
              child: const Text('Directions'),
            ),
            OutlinedButton(
              onPressed: () => _copy(_addressOf(l), 'Address'),
              child: const Text('Copy address'),
            ),
          ],
        ),
      ],
    );
  }

  /// The branches the search and the state chip leave, by state.
  Map<String, List<Map<String, dynamic>>> get _grouped {
    final words = _find.text.trim().toLowerCase();
    final shown = [
      for (final l in _locations)
        if ((_state == null || '${l['state']}' == _state) &&
            (words.isEmpty ||
                '${_name(l)} ${_addressOf(l)} ${l['service_area'] ?? ''}'
                    .toLowerCase()
                    .contains(words)))
          l,
    ]..sort((a, b) => _name(a).compareTo(_name(b)));
    final out = <String, List<Map<String, dynamic>>>{};
    for (final l in shown) {
      (out['${l['state'] ?? ''}'.trim()] ??= []).add(l);
    }
    return Map.fromEntries(
      out.entries.toList()..sort((a, b) => a.key.compareTo(b.key)),
    );
  }

  Widget _directory(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final states = <String, int>{};
    for (final l in _locations) {
      final s = '${l['state'] ?? ''}'.trim();
      if (s.isNotEmpty) states[s] = (states[s] ?? 0) + 1;
    }
    final grouped = _grouped;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: phone ? MainAxisSize.min : MainAxisSize.max,
      children: [
        Padding(
          padding: const EdgeInsets.all(10),
          child: TextField(
            key: const Key('locations-find'),
            controller: _find,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search),
              hintText: 'Find a branch',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
        ),
        if (states.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 0, 10, 6),
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                ChoiceChip(
                  label: Text('All ${_locations.length}'),
                  selected: _state == null,
                  onSelected: (_) => setState(() => _state = null),
                ),
                for (final MapEntry(key: s, value: n)
                    in (states.entries.toList()
                      ..sort((a, b) => a.key.compareTo(b.key))))
                  ChoiceChip(
                    label: Text('$s $n'),
                    selected: _state == s,
                    onSelected: (on) => setState(() => _state = on ? s : null),
                  ),
              ],
            ),
          ),
        Flexible(
          fit: phone ? FlexFit.loose : FlexFit.tight,
          child: ListView(
            shrinkWrap: phone,
            physics: phone ? const NeverScrollableScrollPhysics() : null,
            children: [
              if (grouped.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    _locations.isEmpty
                        ? 'No branches are published yet.'
                        : 'No branch matches that.',
                  ),
                ),
              for (final MapEntry(key: state, value: rows)
                  in grouped.entries) ...[
                if (state.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
                    child: Text(
                      state.toUpperCase(),
                      style: theme.textTheme.labelSmall?.copyWith(
                        letterSpacing: 0.8,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                for (final l in rows)
                  ListTile(
                    key: Key('locations-row-${l['id']}'),
                    dense: true,
                    selected: !phone && '${l['id']}' == _selectedId,
                    title: Text(_name(l)),
                    subtitle: Text(
                      [
                        '${l['city'] ?? ''}'.trim(),
                        if ('${l['service_area'] ?? ''}'.trim().isNotEmpty)
                          'serves ${l['service_area']}',
                      ].where((s) => s.isNotEmpty).join(' · '),
                    ),
                    trailing: phone
                        ? const Icon(Icons.chevron_right)
                        : Text(
                            '${l['phone'] ?? ''}',
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                    onTap: () => setState(() {
                      _selectedId = '${l['id']}';
                      _opened = phone;
                    }),
                  ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _card({required Widget child, EdgeInsets? padding}) => Builder(
    builder: (context) => Container(
      padding: padding,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: child,
    ),
  );

  // ── option 1: the desk ───────────────────────────────────────────

  Widget _desk(BuildContext context) {
    final answers = _answers;
    final selected =
        _byId(_selectedId) ?? (_locations.isEmpty ? null : _locations.first);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _lookupBox(context),
          if (_lookupError != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_lookupError!),
          ],
          if (answers.isNotEmpty) ...[
            const SizedBox(height: 10),
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final (i, answer) in answers.indexed) ...[
                    if (i > 0) const SizedBox(width: 10),
                    Expanded(
                      flex: i == 0 ? 13 : 10,
                      child: _answerCard(
                        context,
                        answer,
                        first: i == 0,
                        phone: false,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
          const SizedBox(height: 10),
          Expanded(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(
                  child: _card(child: _directory(context, phone: false)),
                ),
                const SizedBox(width: 10),
                SizedBox(
                  width: 380,
                  child: _card(
                    padding: const EdgeInsets.all(14),
                    child: selected == null
                        ? const Text('Pick a branch to see it here.')
                        : SingleChildScrollView(
                            child: _detail(context, selected),
                          ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── option 4: the phone ──────────────────────────────────────────

  Widget _phone(BuildContext context) {
    final theme = Theme.of(context);
    final opened = _opened ? _byId(_selectedId) : null;

    // One branch, in place, with its own way back (containment).
    if (opened != null) {
      return ListView(
        padding: const EdgeInsets.fromLTRB(4, 4, 16, 24),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('locations-back'),
              onPressed: () => setState(() => _opened = false),
              icon: const Icon(Icons.arrow_back),
              label: const Text('Locations'),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(left: 12),
            child: _detail(context, opened),
          ),
        ],
      );
    }

    final answers = _answers;
    final best = answers.firstOrNull;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      children: [
        _lookupBox(context),
        if (_lookupError != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_lookupError!),
        ],
        if (best != null) ...[
          const SizedBox(height: 10),
          _answerCard(context, best, first: true, phone: true),
          if (answers.length > 1) ...[
            const SizedBox(height: 12),
            Text('ALSO NEAR', style: theme.textTheme.labelSmall),
            const SizedBox(height: 4),
            _card(
              child: Column(
                children: [
                  for (final (l, miles, _) in answers.skip(1))
                    ListTile(
                      dense: true,
                      title: Text('${l['name'] ?? ''}'),
                      trailing: miles == null
                          ? null
                          : Text(
                              _miles(miles),
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                      onTap: () => setState(() {
                        _selectedId = '${l['id']}';
                        _opened = true;
                      }),
                    ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 12),
          _note(context, best.$1),
        ],
        const SizedBox(height: 12),
        _card(
          child: _showAll
              ? _directory(context, phone: true)
              : ListTile(
                  key: const Key('locations-all'),
                  title: Text('All ${_locations.length} branches'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => setState(() => _showAll = true),
                ),
        ),
      ],
    );
  }
}

/// Scheduling › Settings › Locations: each company's note for new
/// dispatchers (UI queue 49), shown with its branches on the Locations tab.
/// Who to call, extensions, house rules: words somebody keeps, not data.
class DispatcherNotesSettings extends StatefulWidget {
  const DispatcherNotesSettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<DispatcherNotesSettings> createState() =>
      _DispatcherNotesSettingsState();
}

class _DispatcherNotesSettingsState extends State<DispatcherNotesSettings> {
  final _boxes = <String, TextEditingController>{};
  final _saved = <String, String>{};
  String? _saving;
  final _said = <String, String>{};
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant DispatcherNotesSettings old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    for (final box in _boxes.values) {
      box.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/scheduling/locations');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      for (final n
          in ((response.body['meta'] as Map?)?['notes'] as List?) ?? const []) {
        if (n is! Map) continue;
        final id = '${n['company_id']}';
        _saved[id] = '${n['text'] ?? ''}';
        _box(id).text = _saved[id]!;
      }
    });
  }

  TextEditingController _box(String id) =>
      _boxes.putIfAbsent(id, TextEditingController.new);

  Future<void> _save(String id) async {
    final text = _box(id).text.trim();
    setState(() {
      _saving = id;
      _said.remove(id);
    });
    final response = await widget.api.put('/scheduling/locations/notes/$id', {
      'text': text,
    });
    if (!mounted) return;
    setState(() {
      _saving = null;
      if (response.ok) _saved[id] = text;
      _said[id] = response.ok ? 'Saved.' : response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final companies = widget.session.me?.companiesInView ?? const [];
    // Writing one also takes scheduling.edit (Scheduling, 6b7c282f): without
    // it the notes are read, not offered for editing.
    final canEdit =
        widget.session.me?.permissions.contains('scheduling.edit') ?? false;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        children: [
          Text(
            'What a new dispatcher should know, per company: who to call, '
            'extensions, house rules. It shows with that company\'s branches '
            'on the Locations tab.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          if (!canEdit)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                'Changing them takes the right to edit Scheduling.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (companies.isEmpty)
            const Text('There is no company in this view.'),
          for (final c in companies) ...[
            Text(c.name, style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            TextField(
              key: Key('dispatcher-note-${c.id}'),
              controller: _box(c.id),
              readOnly: !canEdit,
              minLines: 3,
              maxLines: 8,
              onChanged: (_) => setState(() => _said.remove(c.id)),
              decoration: const InputDecoration(
                hintText: 'Office lead: …, ext … · After 6 PM: …',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 6),
            if (canEdit)
              Row(
                children: [
                  FilledButton(
                    key: Key('dispatcher-note-save-${c.id}'),
                    onPressed:
                        _saving != null ||
                            _box(c.id).text.trim() ==
                                (_saved[c.id] ?? '').trim()
                        ? null
                        : () => _save(c.id),
                    child: Text(_saving == c.id ? 'Saving…' : 'Save'),
                  ),
                  const SizedBox(width: 12),
                  if (_said[c.id] case final said?)
                    Flexible(
                      child: Text(said, style: theme.textTheme.bodySmall),
                    ),
                ],
              ),
            const SizedBox(height: 18),
          ],
        ],
      ),
    );
  }
}
