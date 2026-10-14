import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/us_states.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
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

  /// On a phone: one branch opened in place.
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

  /// The page's first question, as its headline (UI queue 64): who serves
  /// this address. The button says what it does rather than an arrow.
  Widget _lookupBox(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final edge = OutlineInputBorder(
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      borderSide: BorderSide(color: theme.colorScheme.primary, width: 1.5),
    );

    return Row(
      children: [
        Expanded(
          child: TextField(
            key: const Key('locations-lookup'),
            controller: _address,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _lookup(),
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: phone
                  ? 'Who serves this address?'
                  : "Who serves this address? Type a customer's address or ZIP",
              border: edge,
              enabledBorder: edge,
              suffixIcon: _looking
                  ? const Padding(
                      padding: EdgeInsets.all(14),
                      child: SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : phone
                  ? IconButton(
                      tooltip: 'Find the closest',
                      icon: const Icon(Icons.arrow_forward),
                      onPressed: _lookup,
                    )
                  : Padding(
                      padding: const EdgeInsets.all(6),
                      child: FilledButton(
                        onPressed: _lookup,
                        child: const Text('Find the closest'),
                      ),
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
  }

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
            '${l['name'] ?? ''}',
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w800,
            ),
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

  /// One branch: who it is, then each fact with what can be done with it
  /// (UI queue 64) — the number with Copy (and Call on a phone), the address
  /// with Copy and Directions — and availability as the one big button.
  Widget _detail(
    BuildContext context,
    Map<String, dynamic> l, {
    bool phone = false,
  }) {
    final theme = Theme.of(context);
    final tel = '${l['phone'] ?? ''}'.trim();
    final email = '${l['email'] ?? ''}'.trim();
    final serves = '${l['service_area'] ?? ''}'.trim();
    final area = '${(l['area'] as Map?)?['name'] ?? ''}'.trim();
    final company = '${(l['company'] as Map?)?['name'] ?? ''}'.trim();
    final small = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w800,
      letterSpacing: 0.6,
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget block(
      String label,
      Widget value, [
      List<Widget> actions = const [],
    ]) => Container(
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.only(top: 10),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label.toUpperCase(), style: small),
          const SizedBox(height: 2),
          Row(
            children: [
              Expanded(child: value),
              ...actions,
            ],
          ),
        ],
      ),
    );

    Widget action(String tooltip, IconData icon, VoidCallback onPressed) =>
        IconButton(
          tooltip: tooltip,
          visualDensity: VisualDensity.compact,
          icon: Icon(icon, size: 20),
          onPressed: onPressed,
        );

    return Column(
      key: Key('locations-detail-${l['id']}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (company.isNotEmpty) Text(company.toUpperCase(), style: small),
        Text(
          '${l['name'] ?? ''}',
          style: theme.textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w800,
          ),
        ),
        if (area.isNotEmpty) ...[
          const SizedBox(height: 4),
          Align(
            alignment: Alignment.centerLeft,
            child: StatusPill(area, tone: Tone.info),
          ),
        ],
        if (tel.isNotEmpty)
          block(
            'Phone',
            SelectableText(
              tel,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            [
              if (phone) action('Call $tel', Icons.call, () => _call(tel)),
              action(
                'Copy the phone number',
                Icons.copy,
                () => _copy(tel, 'Phone number'),
              ),
            ],
          ),
        if (email.isNotEmpty)
          block('Email', SelectableText(email), [
            action('Copy the email', Icons.copy, () => _copy(email, 'Email')),
          ]),
        block('Address', SelectableText(_addressOf(l)), [
          action(
            'Copy the address',
            Icons.copy,
            () => _copy(_addressOf(l), 'Address'),
          ),
          action('Directions', Icons.directions_outlined, () => _directions(l)),
        ]),
        if (serves.isNotEmpty) block('Serves', Text(serves)),
        const SizedBox(height: 14),
        _note(context, l),
        const SizedBox(height: 14),
        FilledButton.icon(
          onPressed: () => _availability(l),
          icon: const Icon(Icons.event_available_outlined),
          label: const Text('Check availability'),
        ),
      ],
    );
  }

  /// The branches the search and the state leave, by state.
  Map<String, List<Map<String, dynamic>>> get _grouped {
    final words = _find.text.trim().toLowerCase();
    final shown = [
      for (final l in _locations)
        if ((_state == null || '${l['state'] ?? ''}'.trim() == _state) &&
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
      out.entries.toList()
        ..sort((a, b) => _stateName(a.key).compareTo(_stateName(b.key))),
    );
  }

  /// The branch the card shows, shaded in the list: the one chosen, or
  /// until then the first the list shows.
  String? get _shownId {
    if (_selectedId != null) return _selectedId;
    final first = _grouped.values.expand((rows) => rows).firstOrNull;
    return first == null ? null : '${first['id']}';
  }

  /// A state as people say it: "Connecticut", not "CT". The website row's
  /// own words when they are not a postal code (Scheduling: `state` is not
  /// guaranteed to be one).
  static String _stateName(String code) => usStates[code.toUpperCase()] ?? code;

  Widget _directory(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final states = <String, int>{};
    for (final l in _locations) {
      final s = '${l['state'] ?? ''}'.trim();
      if (s.isNotEmpty) states[s] = (states[s] ?? 0) + 1;
    }
    final grouped = _grouped;
    final shown = grouped.values.fold<int>(0, (n, rows) => n + rows.length);
    final quiet = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w800,
      letterSpacing: 0.6,
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: phone ? MainAxisSize.min : MainAxisSize.max,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  key: const Key('locations-find'),
                  controller: _find,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    prefixIcon: const Icon(Icons.filter_list),
                    hintText: phone
                        ? 'Filter'
                        : 'Filter branches by name or city',
                    border: const OutlineInputBorder(),
                    isDense: true,
                  ),
                ),
              ),
              // One picker for the states, where 26 chips took two rows.
              if (states.length > 1) ...[
                const SizedBox(width: 8),
                SizedBox(
                  width: phone ? 140 : 220,
                  child: SearchPickerField<String>(
                    key: const Key('locations-state'),
                    value: _state ?? '',
                    isDense: true,
                    options: [
                      SearchOption(
                        value: '',
                        label: 'All states · ${_locations.length}',
                      ),
                      for (final MapEntry(key: s, value: n)
                          in (states.entries.toList()..sort(
                            (a, b) =>
                                _stateName(a.key).compareTo(_stateName(b.key)),
                          )))
                        SearchOption(value: s, label: '${_stateName(s)} · $n'),
                    ],
                    onChanged: (value) => setState(
                      () => _state = value == null || value.isEmpty
                          ? null
                          : value,
                    ),
                  ),
                ),
              ],
              if (!phone) ...[
                const SizedBox(width: 10),
                Text(
                  '$shown ${shown == 1 ? 'branch' : 'branches'}',
                  key: const Key('locations-count'),
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ],
          ),
        ),
        if (!phone && grouped.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 6),
            child: Row(
              children: [
                Expanded(flex: 5, child: Text('BRANCH', style: quiet)),
                const SizedBox(width: 10),
                Expanded(flex: 3, child: Text('SERVES', style: quiet)),
                SizedBox(
                  width: 130,
                  child: Text('PHONE', style: quiet, textAlign: TextAlign.end),
                ),
              ],
            ),
          ),
        Flexible(
          fit: phone ? FlexFit.loose : FlexFit.tight,
          child: ListView(
            key: const ValueKey('tour-scheduling-branches'),
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
                  Container(
                    key: Key('locations-state-$state'),
                    color: theme.colorScheme.surfaceContainerLow,
                    padding: const EdgeInsets.fromLTRB(14, 8, 14, 6),
                    child: Row(
                      children: [
                        Text(
                          _stateName(state),
                          style: theme.textTheme.labelLarge?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          '· ${rows.length}',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                for (final l in rows) _row(context, l, phone: phone),
              ],
            ],
          ),
        ),
      ],
    );
  }

  /// One branch on a line: its name once, its company and city small under
  /// it, then what it serves and its phone in columns of their own; on a
  /// phone, the number under the name and a button that calls it.
  Widget _row(
    BuildContext context,
    Map<String, dynamic> l, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final selected = !phone && '${l['id']}' == _shownId;
    final tel = '${l['phone'] ?? ''}'.trim();
    final company = '${(l['company'] as Map?)?['name'] ?? ''}'.trim();
    final city = '${l['city'] ?? ''}'.trim();
    final serves = '${l['service_area'] ?? ''}'.trim();

    final name = Text(
      '${l['name'] ?? ''}',
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodyMedium?.copyWith(
        fontWeight: FontWeight.w700,
        color: selected ? theme.colorScheme.onPrimaryContainer : null,
      ),
    );
    final under = Text(
      [
        if (!phone && company.isNotEmpty) company.toUpperCase(),
        if (city.isNotEmpty) city,
        if (phone && tel.isNotEmpty) tel,
      ].join(' · '),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodySmall,
    );

    return Material(
      color: selected ? theme.colorScheme.primaryContainer : Colors.transparent,
      child: InkWell(
        key: Key('locations-row-${l['id']}'),
        onTap: () => setState(() {
          _selectedId = '${l['id']}';
          _opened = phone;
        }),
        child: Container(
          padding: EdgeInsets.fromLTRB(14, 8, phone ? 4 : 14, 8),
          decoration: BoxDecoration(
            border: Border(
              top: BorderSide(
                color: EzBiziTheme.edge(context).withValues(alpha: 0.5),
              ),
            ),
          ),
          child: Row(
            children: [
              Expanded(
                flex: 5,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [name, under],
                ),
              ),
              if (phone) ...[
                if (tel.isNotEmpty)
                  IconButton(
                    tooltip: 'Call ${l['name'] ?? ''}',
                    icon: const Icon(Icons.call_outlined),
                    onPressed: () => _call(tel),
                  ),
              ] else ...[
                const SizedBox(width: 10),
                Expanded(
                  flex: 3,
                  child: Text(
                    serves,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ),
                SizedBox(
                  width: 130,
                  child: Text(
                    tel,
                    textAlign: TextAlign.end,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
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
    final selected = _byId(_shownId);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _lookupBox(context, phone: false),
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
                  key: const ValueKey('tour-scheduling-branch'),
                  width: 380,
                  child: _card(
                    padding: const EdgeInsets.all(16),
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
            key: const ValueKey('tour-scheduling-branch'),
            padding: const EdgeInsets.only(left: 12),
            child: _detail(context, opened, phone: true),
          ),
        ],
      );
    }

    final answers = _answers;
    final best = answers.firstOrNull;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      children: [
        _lookupBox(context, phone: true),
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
        // The list right under the lookup (phone option 4): the filter and
        // the state picker make 59 branches short.
        _card(child: _directory(context, phone: true)),
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
        key: const ValueKey('tour-scheduling-dispatcher-notes'),
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
              key: const ValueKey('tour-scheduling-notes-read-only'),
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
