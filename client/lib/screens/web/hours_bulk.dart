import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/numbered_step.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';

/// Hours on several websites at once: Rodrigo's brief, drawn as design 26
/// (`docs/previews/web-hours-bulk-editor-layouts.html`), Jose's pick 2, the
/// numbered steps; a phone gets the same form stacked (5).
///
/// Nothing is written from the form. Review changes asks the server
/// (`POST /web/hours/review`), which reads every site's live hours and answers
/// per site and per day; Update applies exactly that review by its token
/// (`/web/hours/apply`), and a review over 30 minutes old is refused.
///
/// ── A pane, not a screen ──
///
/// It draws in the SEO tab, under the module's own tabs and beside the app's
/// sidebar, with a back control of its own. It shipped as a pushed full-screen
/// page and Jose named it the same day: "u broke the containment rule", the
/// rule he set on 2026-09-23 for the integration cards and the page editor.
class HoursBulkPane extends StatefulWidget {
  const HoursBulkPane({
    super.key,
    required this.api,
    required this.formats,
    required this.onClose,
    this.startWith,
    this.companies = const [],
  });

  final ApiClient api;
  final DisplayFormats formats;

  /// Back to the website it was opened from.
  final VoidCallback onClose;

  /// The website the editor was opened from, ticked to begin with.
  final String? startWith;

  /// The session's companies, so a sub company's websites are drawn after
  /// its parent's rather than wherever its name sorts.
  final List<CompanySummary> companies;

  @override
  State<HoursBulkPane> createState() => _HoursBulkPaneState();
}

enum _Act { keep, hours, closed }

/// One heading's worth of websites in the picker.
typedef _Group = ({
  String company,
  String title,
  String? caption,
  String key,
  List<Map<String, dynamic>> sites,
});

/// One day of the request. A null edge keeps what the site has.
class _Day {
  _Act act = _Act.keep;
  TimeOfDay? open;
  TimeOfDay? close;

  /// Set hours with both edges on Keep changes nothing, as the server reads it.
  bool get asks =>
      act == _Act.closed ||
      (act == _Act.hours && (open != null || close != null));
}

/// ponytail: the US zones, which is every tenant today. Read the settings
/// registry's timezone options instead when a tenant outside the US appears.
const hourZones = <(String, String)>[
  ('America/New_York', 'Eastern Time (ET)'),
  ('America/Chicago', 'Central Time (CT)'),
  ('America/Denver', 'Mountain Time (MT)'),
  ('America/Phoenix', 'Arizona (no daylight saving)'),
  ('America/Los_Angeles', 'Pacific Time (PT)'),
  ('America/Anchorage', 'Alaska Time (AKT)'),
  ('Pacific/Honolulu', 'Hawaii Time (HT)'),
];

class _HoursBulkPaneState extends State<HoursBulkPane> {
  List<Map<String, dynamic>> _sites = const [];
  final Set<String> _picked = {};

  /// Business Profile listings (UI queue 104, design 1): picked beside the
  /// websites, per company, and reviewed and applied with them.
  List<Map<String, dynamic>> _locations = const [];
  final Set<String> _pickedLocations = {};

  /// What the website list is narrowed to. A-1 Chimney runs about a hundred
  /// sites under several brands; typing "roofirst" and Select all is how one
  /// brand is picked without a hundred clicks.
  String _find = '';
  final List<_Day> _week = [for (var i = 0; i < 7; i++) _Day()];
  String? _zone;
  TimeOfDay? _notBefore;

  bool _loading = true;
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _review;
  Map<String, dynamic>? _result;
  int _tab = 0;
  TimeOfDay? _reviewedAt;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final locations = widget.api.get('/business-profile/locations');
    final response = await widget.api.get('/web/sites');
    final listed = await locations;
    if (!mounted) return;
    if (listed.ok && listed.body['data'] is List) {
      _locations = (listed.body['data'] as List)
          .whereType<Map<String, dynamic>>()
          .toList();
    }

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _sites = (response.body['data'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      if (_sites.any((s) => s['id'] == widget.startWith)) {
        _picked.add(widget.startWith!);
      }
    });
  }

  int get _daysAsked => _week.where((d) => d.asks).length;

  String _hhmm(TimeOfDay t) =>
      '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  String _clock(TimeOfDay t) => widget.formats.formatTime(t.hour, t.minute);

  /// "17:00" from the server, drawn the organization's way.
  String _wire(String? hhmm) =>
      hhmm == null ? '' : widget.formats.formatWallClock(hhmm);

  Map<String, dynamic> _edge(TimeOfDay? t) =>
      t == null ? {'action': 'keep'} : {'action': 'set', 'time': _hhmm(t)};

  Map<String, dynamic> get _body => {
    'site_ids': _picked.toList(),
    'location_ids': _pickedLocations.toList(),
    'destinations': [
      if (_picked.isNotEmpty) 'website',
      if (_pickedLocations.isNotEmpty) 'google_business_profile',
    ],
    'source_timezone': _zone,
    'not_before': _notBefore == null ? null : _hhmm(_notBefore!),
    'schedule': {
      for (var i = 0; i < 7; i++)
        if (_week[i].asks)
          longWeekdays[i].toLowerCase(): _week[i].act == _Act.closed
              ? {'action': 'closed'}
              : {
                  'action': 'set_hours',
                  'open': _edge(_week[i].open),
                  'close': _edge(_week[i].close),
                },
    },
  };

  Future<void> _askReview() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/web/hours/review', _body);
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) {
        _review = response.body['data'] as Map<String, dynamic>?;
        _reviewedAt = TimeOfDay.now();
        _tab = 0;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/web/hours/apply', {
      'token': _review!['token'],
    });
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) {
        _result = response.body['data'] as Map<String, dynamic>?;
      } else {
        // 409: expired, or not this person's. The form is kept; review again.
        _error = response.message;
        if (response.statusCode == 409) _review = null;
      }
    });
  }

  /// What needs a look first: the websites that will change, then the ones
  /// not written, then the rest, each group in the server's order. Forty-nine
  /// alphabetical chips hid the six that change among the thirty-nine that
  /// do not.
  List<Map<String, dynamic>> get _places {
    final all = ((_review?['places'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    int rank(Map<String, dynamic> place) => switch (place['status']) {
      'change' => 0,
      'blocked' => 1,
      'unchanged' => 2,
      _ => 3,
    };

    return [
      for (var r = 0; r < 4; r++) ...all.where((place) => rank(place) == r),
    ];
  }

  int get _willChange => _places.where((p) => p['status'] == 'change').length;

  /// A website by its host; a listing by its title, marked as Google's.
  static String _placeName(Map<String, dynamic> place) =>
      place['destination'] == 'google_business_profile'
      ? 'Google · ${place['name'] ?? ''}'
      : '${place['host'] ?? place['name'] ?? ''}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = _result != null
        ? 'Hours on several websites · done'
        : _review != null
        ? 'Hours on several websites · review'
        : 'Hours on several websites';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
          child: Row(
            children: [
              // The way back, and what Android's Back presses.
              IconButton(
                tooltip: 'Back to the website',
                icon: const Icon(Icons.arrow_back),
                onPressed: widget.onClose,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  title,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _result != null
              ? _done(context)
              : _review != null
              ? _reviewBody(context)
              : _form(context),
        ),
        if (!_loading) _bar(context),
      ],
    );
  }

  // ── the bar at the foot ──────────────────────────────────────────

  Widget _bar(BuildContext context) {
    final theme = Theme.of(context);
    // A phone has the app bar's back arrow, so no Cancel, and the note goes
    // above the buttons rather than being squeezed to "Revi…" beside them.
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final List<Widget> actions;
    final String note;

    if (_result != null) {
      note = '';
      actions = [
        FilledButton(onPressed: widget.onClose, child: const Text('Done')),
      ];
    } else if (_review != null) {
      note = 'Reviewed ${_clock(_reviewedAt!)} · good for 30 minutes';
      actions = [
        TextButton(
          onPressed: _busy ? null : () => setState(() => _review = null),
          child: Text(narrow ? 'Edit' : 'Back to the form'),
        ),
        FilledButton(
          key: const Key('hours-bulk-apply'),
          onPressed: _busy || _willChange == 0 ? null : _apply,
          child: Text(
            _willChange == 1
                ? 'Update 1 website'
                : 'Update $_willChange websites',
          ),
        ),
      ];
    } else {
      final sites = _picked.length + _pickedLocations.length;
      note =
          '${sites == 1 ? '1 place' : '$sites places'} · '
          '${_daysAsked == 1 ? '1 day' : '$_daysAsked days'}';
      actions = [
        if (!narrow)
          TextButton(onPressed: widget.onClose, child: const Text('Cancel')),
        FilledButton(
          key: const Key('hours-bulk-review'),
          onPressed: _busy || sites == 0 || _daysAsked == 0 ? null : _askReview,
          child: const Text('Review changes'),
        ),
      ];
    }

    final noteText = Text(
      note,
      style: theme.textTheme.bodySmall,
      overflow: TextOverflow.ellipsis,
    );
    final spinner = [
      if (_busy)
        const SizedBox(
          width: 18,
          height: 18,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
    ];

    return Material(
      color: theme.colorScheme.surface,
      child: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          decoration: BoxDecoration(
            border: Border(
              top: BorderSide(color: theme.colorScheme.outlineVariant),
            ),
          ),
          child: narrow
              // A Wrap, so two buttons too wide for a phone take two lines
              // rather than squeezing "Update 2 websites" onto two inside one.
              ? Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (note.isNotEmpty) ...[
                      noteText,
                      const SizedBox(height: 6),
                    ],
                    Wrap(
                      alignment: WrapAlignment.end,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 8,
                      runSpacing: 8,
                      children: [...spinner, ...actions],
                    ),
                  ],
                )
              : Row(
                  children: [
                    Expanded(child: noteText),
                    for (final control in [...spinner, ...actions]) ...[
                      const SizedBox(width: 8),
                      control,
                    ],
                  ],
                ),
        ),
      ),
    );
  }

  // ── the form ─────────────────────────────────────────────────────

  Widget _page({double maxWidth = 880, required List<Widget> children}) =>
      ListView(
        padding: const EdgeInsets.fromLTRB(16, 20, 16, 32),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: maxWidth),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children,
              ),
            ),
          ),
        ],
      );

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);

    return _page(
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 16)],
        NumberedStep(
          number: 1,
          title: 'Which places',
          note: 'Websites and Business Profile listings. Each keeps its own time zone.',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('WEBSITES', style: _groupLabel(theme)),
              const SizedBox(height: 6),
              _sites.isEmpty
                  ? Text(
                      'This company has no websites yet. Add one under SEO first.',
                      style: theme.textTheme.bodyMedium,
                    )
                  : _websites(context),
              if (_locations.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text('BUSINESS PROFILE', style: _groupLabel(theme)),
                const SizedBox(height: 6),
                _listings(context),
              ],
            ],
          ),
        ),
        const SizedBox(height: 24),
        NumberedStep(
          number: 2,
          title: 'The week',
          note:
              "Pick a day's action, then its times. A day on Keep existing is not touched.",
          child: LayoutBuilder(
            builder: (context, box) => Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (var i = 0; i < 7; i++)
                  box.maxWidth >= 620
                      ? _dayRow(context, i)
                      : _dayCard(context, i),
              ],
            ),
          ),
        ),
        const SizedBox(height: 24),
        NumberedStep(
          number: 3,
          title: 'Time zone and earliest opening',
          note: 'Both optional.',
          child: _options(context),
        ),
      ],
    );
  }

  TextStyle? _groupLabel(ThemeData theme) => theme.textTheme.labelSmall?.copyWith(
    color: theme.colorScheme.onSurfaceVariant,
    fontWeight: FontWeight.w800,
    letterSpacing: 0.6,
  );

  /// Business Profile listings under each company's name, found by the same
  /// box as the websites, with Select all.
  Widget _listings(BuildContext context) {
    final theme = Theme.of(context);
    final needle = _find.trim().toLowerCase();
    final shown = [
      for (final l in _locations)
        if ('${l['label'] ?? l['title'] ?? ''} ${l['address'] ?? ''}'.toLowerCase().contains(
          needle,
        ))
          l,
    ];
    final ids = {for (final l in shown) '${l['id']}'};
    final byCompany = <String, List<Map<String, dynamic>>>{};
    for (final l in shown) {
      byCompany
          .putIfAbsent('${(l['company'] as Map?)?['name'] ?? ''}', () => [])
          .add(l);
    }

    return Column(
      key: const Key('hours-bulk-listings'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              '${_pickedLocations.length} of ${_locations.length} selected',
              style: theme.textTheme.bodySmall,
            ),
            TextButton(
              key: const Key('hours-bulk-listings-all'),
              onPressed: ids.isEmpty || _pickedLocations.containsAll(ids)
                  ? null
                  : () => setState(() => _pickedLocations.addAll(ids)),
              child: const Text('Select all'),
            ),
            TextButton(
              onPressed: _pickedLocations.isEmpty
                  ? null
                  : () => setState(_pickedLocations.clear),
              child: const Text('Clear'),
            ),
          ],
        ),
        for (final entry in byCompany.entries) ...[
          if (byCompany.length > 1 && entry.key.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6, bottom: 2),
              child: Text(entry.key, style: theme.textTheme.titleSmall),
            ),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final l in entry.value)
                FilterChip(
                  key: ValueKey('hours-bulk-listing-${l['id']}'),
                  // Which listing it is (Jose, 2026-10-19).
                  label: Text('${l['label'] ?? l['title'] ?? ''}'),
                  tooltip: l['address'] as String?,
                  selected: _pickedLocations.contains('${l['id']}'),
                  onSelected: (on) => setState(
                    () => on
                        ? _pickedLocations.add('${l['id']}')
                        : _pickedLocations.remove('${l['id']}'),
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }

  /// The websites, found by typing, picked a few or all at once. Jose, on
  /// A-1 Chimney's hundred: "u cant expect a person to click 50 times".
  Widget _websites(BuildContext context) {
    final theme = Theme.of(context);
    final needle = _find.trim().toLowerCase();
    final shown = [
      for (final site in _sites)
        if ('${site['host'] ?? ''}'.toLowerCase().contains(needle)) site,
    ];
    final shownIds = {for (final site in shown) site['id'] as String};
    final allShown = shownIds.isNotEmpty && _picked.containsAll(shownIds);
    final groups = _groups(shown);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              '${_picked.length} of ${_sites.length} selected',
              style: theme.textTheme.bodySmall,
            ),
            TextButton(
              key: const Key('hours-bulk-select-all'),
              onPressed: allShown
                  ? null
                  : () => setState(() => _picked.addAll(shownIds)),
              child: Text(
                needle.isEmpty
                    ? 'Select all'
                    : shown.length == 1
                    ? 'Select the 1 shown'
                    : 'Select the ${shown.length} shown',
              ),
            ),
            TextButton(
              key: const Key('hours-bulk-clear'),
              onPressed: _picked.isEmpty ? null : () => setState(_picked.clear),
              child: const Text('Clear'),
            ),
          ],
        ),
        // ponytail: twelve, below which a list is short enough to look at.
        if (_sites.length > 12) ...[
          const SizedBox(height: 4),
          // Aligned, or the column's stretch overrides the width limit.
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 360),
              child: ListSearch(
                key: const Key('hours-bulk-find'),
                hint: 'Find a website',
                onChanged: (value) => setState(() => _find = value),
              ),
            ),
          ),
        ],
        const SizedBox(height: 10),
        // Held in a box of its own that scrolls, so a hundred websites do not
        // push the week off the page.
        Container(
          constraints: const BoxConstraints(maxHeight: 360),
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(12),
          ),
          child: shown.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'No website matches that.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : Scrollbar(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final (i, group) in groups.indexed) ...[
                          // A rule between companies; a gap between a
                          // company and its own subdomains.
                          if (i > 0)
                            groups[i - 1].company == group.company
                                ? const SizedBox(height: 14)
                                : const Divider(height: 29),
                          // One company and no subdomains: the Select all
                          // above already is this heading's.
                          if (groups.length > 1) _groupHead(context, group),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              for (final site in group.sites)
                                FilterChip(
                                  label: Text(site['host'] as String? ?? ''),
                                  selected: _picked.contains(site['id']),
                                  onSelected: (on) => setState(() {
                                    final id = site['id'] as String;
                                    on ? _picked.add(id) : _picked.remove(id);
                                  }),
                                ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
        ),
      ],
    );
  }

  /// The websites under the headings Jose drew (2026-09-29): "a1chimney,
  /// subdomains, sub company, sub company subdomains". Each company's own
  /// domains, then its subdomains, a parent before its sub companies.
  List<_Group> _groups(List<Map<String, dynamic>> shown) {
    String companyOf(Map site) => '${(site['company'] as Map?)?['id'] ?? ''}';
    final names = {for (final c in widget.companies) c.id: c.name};

    // Parents first, each followed by its sub companies, depth first; then
    // a company whose parent this view does not hold; then any company the
    // session does not list at all.
    final order = <String>[];
    void visit(String? parent) {
      for (final c in widget.companies) {
        if (c.parentCompanyId == parent && !order.contains(c.id)) {
          order.add(c.id);
          visit(c.id);
        }
      }
    }

    visit(null);
    for (final c in widget.companies) {
      if (!order.contains(c.id)) {
        order.add(c.id);
        visit(c.id);
      }
    }
    for (final site in _sites) {
      if (!order.contains(companyOf(site))) order.add(companyOf(site));
    }

    // A subdomain is a host under another registered one, so
    // atl.a-1chimney.com sits under a-1chimney.com wherever it is listed.
    final hosts = {for (final site in _sites) '${site['host'] ?? ''}'};
    bool isSub(Map site) {
      final host = '${site['host'] ?? ''}';
      return hosts.any((other) => other != host && host.endsWith('.$other'));
    }

    final groups = <_Group>[];
    for (final id in order) {
      final mine = [
        for (final site in shown)
          if (companyOf(site) == id) site,
      ];
      if (mine.isEmpty) continue;

      final name =
          names[id] ??
          '${(mine.first['company'] as Map?)?['name'] ?? 'Websites'}';
      final parent =
          names[widget.companies
              .where((c) => c.id == id)
              .firstOrNull
              ?.parentCompanyId];

      for (final sub in [false, true]) {
        final sites = [
          for (final site in mine)
            if (isSub(site) == sub) site,
        ];
        if (sites.isEmpty) continue;

        groups.add((
          company: id,
          title: sub ? '$name subdomains' : name,
          caption: !sub && parent != null ? 'sub company of $parent' : null,
          key: '$id-${sub ? 'sub' : 'own'}',
          sites: sites,
        ));
      }
    }

    return groups;
  }

  /// A heading, how many of its websites are ticked, and its own Select all.
  Widget _groupHead(BuildContext context, _Group group) {
    final theme = Theme.of(context);
    final ids = {for (final site in group.sites) site['id'] as String};
    final all = _picked.containsAll(ids);

    return Wrap(
      spacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(group.title, style: theme.textTheme.titleSmall),
        if (group.caption case final caption?)
          Text(caption, style: theme.textTheme.bodySmall),
        Text(
          '${ids.where(_picked.contains).length} of ${ids.length}',
          style: theme.textTheme.bodySmall,
        ),
        TextButton(
          key: Key('hours-bulk-group-${group.key}'),
          onPressed: () => setState(
            () => all ? _picked.removeAll(ids) : _picked.addAll(ids),
          ),
          child: Text(all ? 'Clear' : 'Select all'),
        ),
      ],
    );
  }

  /// Keep existing · Set hours · Closed all day — the one control per day
  /// the brief asks for, instead of hours beside an on/off switch.
  Widget _act(int day, {required bool short}) {
    return SegmentedButton<_Act>(
      key: Key('hours-bulk-act-$day'),
      showSelectedIcon: false,
      segments: [
        ButtonSegment(
          value: _Act.keep,
          label: Text(short ? 'Keep' : 'Keep existing'),
        ),
        ButtonSegment(
          value: _Act.hours,
          label: Text(short ? 'Hours' : 'Set hours'),
        ),
        ButtonSegment(
          value: _Act.closed,
          label: Text(short ? 'Closed' : 'Closed all day'),
        ),
      ],
      selected: {_week[day].act},
      onSelectionChanged: (picked) =>
          setState(() => _week[day].act = picked.first),
    );
  }

  Widget _edges(BuildContext context, int day) {
    final d = _week[day];

    return Wrap(
      spacing: 16,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _time(
          context,
          label: 'Opens',
          key: Key('hours-bulk-open-$day'),
          value: d.open,
          onChanged: (t) => setState(() => d.open = t),
        ),
        _time(
          context,
          label: 'Closes',
          key: Key('hours-bulk-close-$day'),
          value: d.close,
          onChanged: (t) => setState(() => d.close = t),
        ),
      ],
    );
  }

  /// "Keep" or a time; the × puts it back to Keep.
  Widget _time(
    BuildContext context, {
    required String label,
    required Key key,
    required TimeOfDay? value,
    required ValueChanged<TimeOfDay?> onChanged,
  }) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        OutlinedButton(
          key: key,
          onPressed: () async {
            final picked = await showTimePicker(
              context: context,
              initialTime: value ?? const TimeOfDay(hour: 9, minute: 0),
            );
            if (picked != null) onChanged(picked);
          },
          child: Text(value == null ? 'Keep' : _clock(value)),
        ),
        if (value != null)
          IconButton(
            tooltip: 'Keep the saved ${label.toLowerCase()} time',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.close, size: 16),
            onPressed: () => onChanged(null),
          ),
      ],
    );
  }

  Widget _dayRow(BuildContext context, int day) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Wrap(
        spacing: 16,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 104,
            child: Text(longWeekdays[day], style: theme.textTheme.titleSmall),
          ),
          _act(day, short: false),
          if (_week[day].act == _Act.hours) _edges(context, day),
        ],
      ),
    );
  }

  Widget _dayCard(BuildContext context, int day) {
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(longWeekdays[day], style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          _act(day, short: true),
          if (_week[day].act == _Act.hours) ...[
            const SizedBox(height: 10),
            _edges(context, day),
          ],
        ],
      ),
    );
  }

  Widget _options(BuildContext context) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 24,
      runSpacing: 16,
      children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 320),
          child: SearchPickerField<String?>(
            key: const Key('hours-bulk-zone'),
            value: _zone,
            label: 'Convert hours from',
            helperText: _zone == null
                ? 'Each website keeps the times as typed, in its own time.'
                : 'The times above are in this zone; each website converts '
                      'them into its own.',
            helperMaxLines: 3,
            options: [
              const SearchOption<String?>(
                value: null,
                label: 'No — each website\'s own time',
              ),
              for (final (id, label) in hourZones)
                SearchOption<String?>(value: id, label: label),
            ],
            onChanged: (zone) => setState(() => _zone = zone),
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            _time(
              context,
              label: "Don't open before",
              key: const Key('hours-bulk-not-before'),
              value: _notBefore,
              onChanged: (t) => setState(() => _notBefore = t),
            ),
            const SizedBox(height: 4),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 300),
              child: Text(
                'In each website\'s own time, after converting. Only the '
                'opening moves, never the closing.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ── the review ───────────────────────────────────────────────────

  static (String, Tone) _reviewPill(String? status) => switch (status) {
    'change' => ('Will change', Tone.ok),
    'unchanged' => ('Unchanged', Tone.neutral),
    'blocked' => ('Not written', Tone.caution),
    _ => ('Not available', Tone.neutral),
  };

  Widget _reviewBody(BuildContext context) {
    final places = _places;
    final wide = MediaQuery.sizeOf(context).width >= 760;
    final summary = (_review?['summary'] as Map?) ?? const {};

    int count(String key) => (summary[key] as num?)?.toInt() ?? 0;

    // Wider than the form: every website's chip is on show at once (Jose,
    // 2026-09-29), and 880 fitted four to a line.
    return _page(
      maxWidth: 1200,
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 12)],
        // The counts in the colours their websites carry below (Jose,
        // 2026-09-29: "add some colors, green red, for the changes ... make
        // it more lively").
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final (key, word) in [
              ('change', 'will change'),
              ('unchanged', 'unchanged'),
              ('blocked', 'not written'),
            ])
              if (count(key) > 0)
                StatusPill('${count(key)} $word', tone: _reviewPill(key).$2),
          ],
        ),
        const SizedBox(height: 12),
        if (wide && places.length > 1) ...[
          TabBarLike(
            labels: [for (final p in places) (_tabLabel(p), _placeIcon(p))],
            tones: [
              for (final p in places) _reviewPill(p['status'] as String?).$2,
            ],
            selected: _tab.clamp(0, places.length - 1),
            onSelected: (i) => setState(() => _tab = i),
          ),
          const SizedBox(height: 12),
          _place(context, places[_tab.clamp(0, places.length - 1)], wide: true),
        ] else
          for (final place in places) ...[
            _place(context, place, wide: wide),
            const SizedBox(height: 12),
          ],
      ],
    );
  }

  static IconData _placeIcon(Map<String, dynamic> place) =>
      switch (place['status']) {
        'change' => Icons.edit_calendar_outlined,
        'blocked' => Icons.error_outline,
        _ => Icons.language,
      };

  /// "la.everdoors.com · 5", or "· blocked", as drawn.
  String _tabLabel(Map<String, dynamic> place) {
    final host = _placeName(place);
    if (place['status'] == 'blocked') return '$host · blocked';
    final days = ((place['days'] as List?) ?? const [])
        .whereType<Map>()
        .where((d) => d['changed'] == true)
        .length;
    return days == 0 ? host : '$host · $days';
  }

  Widget _place(
    BuildContext context,
    Map<String, dynamic> place, {
    required bool wide,
  }) {
    final theme = Theme.of(context);
    final (label, tone) = _reviewPill(place['status'] as String?);
    final days = ((place['days'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final reasons = ((place['reasons'] as List?) ?? const [])
        .map((r) => '$r')
        .toList();

    return Container(
      key: ValueKey('hours-bulk-place-${place['site_id']}'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        // Edged in its status's colour all round: a website that changes
        // green, one that cannot be written amber.
        border: Border.all(
          color: tone == Tone.neutral
              ? theme.colorScheme.outlineVariant
              : ToneColours.of(context, tone).ink.withValues(alpha: 0.35),
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _placeName(place),
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      place['timezone'] as String? ?? 'No time zone set',
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              StatusPill(label, tone: tone),
            ],
          ),
          if (reasons.isNotEmpty) ...[
            const SizedBox(height: 8),
            if (place['status'] == 'blocked')
              Text(
                'Nothing is written to this website until these are fixed:',
                style: theme.textTheme.bodySmall,
              ),
            for (final reason in reasons)
              Text(
                reason,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: ToneColours.of(context, Tone.bad).ink,
                ),
              ),
          ],
          if (days.isNotEmpty) ...[
            const SizedBox(height: 10),
            wide ? _dayTable(context, days) : _dayLines(context, days),
          ],
        ],
      ),
    );
  }

  String _span(Map? h) {
    if (h == null) return '—';
    if (h['closed'] == true) return 'Closed';
    return '${_wire(h['open'] as String?)} – ${_wire(h['close'] as String?)}';
  }

  String _asked(Map<String, dynamic> day) =>
      day['action'] == 'closed' ? 'Closed all day' : _pair(day['requested']);

  /// A requested or converted pair, where either edge may be Keep (null).
  String _pair(Object? edges) {
    final open = (edges as Map?)?['open'] as String?;
    final close = edges?['close'] as String?;
    return switch ((open, close)) {
      (null, null) => '—',
      (final o?, null) => 'opens ${_wire(o)}',
      (null, final c?) => 'closes ${_wire(c)}',
      (final o?, final c?) => '${_wire(o)} – ${_wire(c)}',
    };
  }

  /// The end of a day's row: why, in red, when it cannot be written; a pill
  /// when there is something else to know; quiet words when there is not.
  Widget _note(BuildContext context, Map<String, dynamic> day) {
    if (day['error'] != null) {
      return Text(
        '${day['error']}',
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          color: ToneColours.of(context, Tone.bad).ink,
        ),
      );
    }
    if (day['adjusted'] == true) {
      return StatusPill(
        _notBefore == null
            ? 'Opening moved'
            : 'Not before ${_clock(_notBefore!)}',
        tone: Tone.caution,
      );
    }
    if (day['changed'] == true) {
      return const StatusPill('Changes', tone: Tone.ok);
    }
    final theme = Theme.of(context);
    return Text(
      'no change',
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
  }

  Widget _dayTable(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    final ok = ToneColours.of(context, Tone.ok);
    final bad = ToneColours.of(context, Tone.bad);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    // One size for every row: an untouched day is greyed, not shrunk.
    final quiet = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final loud = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurface,
    );

    TableRow row(Map<String, dynamic> day) {
      final changed = day['changed'] == true;
      final failed = day['error'] != null;
      final style = changed || failed ? loud : quiet;

      Widget cell(Widget child) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 6),
        child: child,
      );

      Widget text(String value, [TextStyle? s]) =>
          cell(Text(value, style: s ?? style));

      return TableRow(
        // Green behind a day that changes, red behind one that cannot be
        // written: the week reads at a glance.
        decoration: changed || failed
            ? BoxDecoration(
                color: (failed ? bad : ok).fill,
                borderRadius: BorderRadius.circular(8),
              )
            : null,
        children: [
          text(
            '${day['day']}',
            changed || failed
                ? style?.copyWith(fontWeight: FontWeight.w600)
                : null,
          ),
          // What goes, struck through in red, and what replaces it, in green.
          text(
            _span(day['existing'] as Map?),
            changed
                ? style?.copyWith(
                    color: bad.ink,
                    decoration: TextDecoration.lineThrough,
                    decorationColor: bad.ink,
                  )
                : null,
          ),
          text(_asked(day)),
          text(_pair(day['converted'])),
          text(
            _span(day['final'] as Map?),
            changed
                ? style?.copyWith(color: ok.ink, fontWeight: FontWeight.w700)
                : null,
          ),
          cell(
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: _note(context, day),
            ),
          ),
        ],
      );
    }

    return Table(
      columnWidths: const {0: FixedColumnWidth(52), 5: FlexColumnWidth(1.4)},
      defaultVerticalAlignment: TableCellVerticalAlignment.middle,
      children: [
        TableRow(
          children: [
            for (final h in [
              'Day',
              'Now',
              'Requested',
              'Converted',
              'Final',
              '',
            ])
              Padding(
                padding: const EdgeInsets.all(6),
                child: Text(h.toUpperCase(), style: head),
              ),
          ],
        ),
        for (final day in days) row(day),
      ],
    );
  }

  /// On a phone: the days that change, one line each; the rest named once.
  Widget _dayLines(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    final ok = ToneColours.of(context, Tone.ok);
    final bad = ToneColours.of(context, Tone.bad);
    final same = [
      for (final d in days)
        if (d['changed'] != true && d['error'] == null) '${d['day']}',
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final d in days)
          if (d['changed'] == true || d['error'] != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${d['day']}  ',
                          style: theme.textTheme.titleSmall,
                        ),
                        TextSpan(
                          text: _span(d['existing'] as Map?),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: bad.ink,
                            decoration: TextDecoration.lineThrough,
                            decorationColor: bad.ink,
                          ),
                        ),
                        const TextSpan(text: '  →  '),
                        TextSpan(
                          text: d['error'] != null
                              ? 'not written'
                              : _span(d['final'] as Map?),
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: d['error'] != null ? bad.ink : ok.ink,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                  // Every line here is a change, so only what is more than
                  // that gets a pill.
                  if (d['error'] != null || d['adjusted'] == true)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: _note(context, d),
                    ),
                ],
              ),
            ),
        if (same.isNotEmpty)
          Text(
            '${same.join(', ')} unchanged',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  // ── the result ───────────────────────────────────────────────────

  static (String, Tone) _resultPill(String? status) => switch (status) {
    'changed' => ('Changed', Tone.ok),
    'unchanged' => ('Unchanged', Tone.neutral),
    'stale' => ('Changed since the review', Tone.caution),
    'unconfirmed' => ('Not confirmed', Tone.caution),
    _ => ('Failed', Tone.bad),
  };

  Widget _done(BuildContext context) {
    final theme = Theme.of(context);
    final results = ((_result?['results'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final applied = {for (final r in results) r['site_id']};

    // The review's other websites, which the apply never touched: say what
    // happened to them too, so the list is every website that was ticked.
    final untouched = [
      for (final p in _places)
        if (!applied.contains(p['site_id'])) p,
    ];

    Widget line(String host, (String, Tone) pill, String? reason) => Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(host, style: theme.textTheme.titleSmall),
                if (reason != null && reason.isNotEmpty)
                  Text(reason, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          StatusPill(pill.$1, tone: pill.$2),
        ],
      ),
    );

    return _page(
      children: [
        Text(
          'Each website was read back after writing.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 8),
        for (final r in results)
          line(
            r['host'] as String? ?? '',
            _resultPill(r['status'] as String?),
            r['reason'] as String?,
          ),
        for (final p in untouched)
          line(
            _placeName(p),
            _reviewPill(p['status'] as String?),
            ((p['reasons'] as List?) ?? const []).firstOrNull?.toString(),
          ),
      ],
    );
  }
}
