import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/dashboard_panels.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/dashboard_chart.dart';

/// Human Resources at a glance — the same one box the main dashboard draws.
///
/// Not five widget panels. The HR plan's five figures are the *text* of this
/// chip; the chart is in-flight vs stalled. Period chips write the same
/// `dashboard.period` the main board uses, so the window is reachable without
/// leaving the module. One card cannot be rearranged.
class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  bool _loading = true;
  bool _loaded = false;
  String? _error;
  int _request = 0;
  String _period = 'month';
  Map<String, dynamic>? _glance;

  static const _periods = <(String, String)>[
    ('week', 'Week'),
    ('month', 'Month'),
    ('quarter', 'Quarter'),
    ('year', 'Year'),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = !_loaded;
      _error = null;
    });

    final response = await widget.api.get('/hr/dashboard');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _loaded = true;
      _error = null;
      _period = response.body['period'] as String? ?? 'month';
      final glance = response.body['glance'];
      _glance = glance is Map<String, dynamic> ? glance : null;
    });
  }

  Future<void> _setPeriod(String period) async {
    if (period == _period) return;

    setState(() => _period = period);

    final glances = await _glancesForSave();
    if (!mounted) return;

    final body = <String, dynamic>{'period': period};
    if (glances != null) body['glances'] = glances;

    final response = await widget.api.put('/dashboard/layout', body);

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    } else {
      _rememberLayout(response.body, fallback: glances);
    }

    _load();
  }

  Future<void> _setPresentation(String presentation) async {
    final current = _glance;
    if (current == null) return;
    if ((current['presentation'] ?? 'text') == presentation) return;

    setState(() {
      _glance = {...current, 'presentation': presentation};
    });

    final glances = await _glancesForSave(presentation: presentation);
    if (!mounted) return;

    if (glances == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not save how this box is drawn.')),
      );
      _load();
      return;
    }

    final response = await widget.api.put('/dashboard/layout', {
      'glances': glances,
    });

    if (!mounted) return;

    if (response.ok) {
      _rememberLayout(response.body, fallback: glances);
      return;
    }

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(response.message)));

    _load();
  }

  /// Keep the union the server stored. PUT echoes `glances` after appending
  /// reachable modules this chip omitted. Without writing that into the
  /// session, the next tap sends `{hr}` again and saveLayout rebuilds the
  /// rest at defaults — wiping Jobs options and any order already saved.
  void _rememberLayout(
    Map<String, dynamic> body, {
    List<Map<String, dynamic>>? fallback,
  }) {
    final glances = body['glances'];
    final source = glances is List && glances.isNotEmpty
        ? glances
        : fallback;
    if (source == null || source.isEmpty) return;

    widget.session.rememberPreference('dashboard.glances', [
      for (final item in source)
        if (item is Map)
          {
            'key': item['key'],
            'presentation': item['presentation'] ?? 'text',
            'options': item['options'] ?? <String, dynamic>{},
          },
    ]);
  }

  /// Session layout if /me already has it; otherwise GET /dashboard so the
  /// first save names every reachable box, not only `{hr}`. Null means omit
  /// `glances` and write the period only.
  Future<List<Map<String, dynamic>>?> _glancesForSave({
    String? presentation,
  }) async {
    final fromSession = _layoutFromSession(presentation: presentation);
    if (fromSession != null) return fromSession;

    final response = await widget.api.get('/dashboard');
    if (!mounted || !response.ok) return null;

    final glances = response.body['glances'];
    if (glances is! List || glances.isEmpty) return null;

    final layout = [
      for (final item in glances)
        if (item is Map) _entryFor(item, presentation: presentation),
    ];
    widget.session.rememberPreference('dashboard.glances', layout);
    return layout;
  }

  List<Map<String, dynamic>>? _layoutFromSession({String? presentation}) {
    final saved = widget.session.me?.preferences['dashboard.glances'];
    if (saved is! List || saved.isEmpty) return null;

    return [
      for (final item in saved)
        if (item is Map) _entryFor(item, presentation: presentation),
    ];
  }

  Map<String, dynamic> _entryFor(Map item, {String? presentation}) {
    final chosen =
        presentation ?? _glance?['presentation'] as String? ?? 'text';

    return {
      'key': item['key'],
      'presentation': item['key'] == 'hr'
          ? chosen
          : (item['presentation'] ?? 'text'),
      'options': item['options'] ?? <String, dynamic>{},
    };
  }

  Widget? _presentationMenu(Map<String, dynamic> glance) {
    final options = _presentationsOf(glance);
    if (options.isEmpty) return null;

    final selected = glance['presentation'] as String? ?? 'text';
    final label = glance['label'] as String? ?? 'this box';

    return PopupMenuButton<String>(
      tooltip: 'How to draw “$label”',
      padding: EdgeInsets.zero,
      iconSize: 18,
      initialValue: options.contains(selected) ? selected : null,
      icon: Icon(_iconFor(selected)),
      onSelected: _setPresentation,
      itemBuilder: (context) => [
        for (final option in options)
          PopupMenuItem(
            value: option,
            child: Text(_labelFor(option)),
          ),
      ],
    );
  }

  static List<String> _presentationsOf(Map<String, dynamic> glance) {
    final raw = glance['allowed_presentations'];
    if (raw is! List || raw.isEmpty) return const [];

    final options = [for (final item in raw) '$item'];
    if (!options.contains('text')) options.add('text');
    return options;
  }

  static String _labelFor(String presentation) => switch (presentation) {
        'pie' => 'Pie',
        'bar' => 'Bar',
        'line' => 'Line',
        'timeline' => 'Timeline',
        'text' => 'Text',
        _ => presentation,
      };

  static IconData _iconFor(String presentation) => switch (presentation) {
        'pie' => Icons.pie_chart,
        'bar' => Icons.bar_chart,
        'line' => Icons.show_chart,
        'timeline' => Icons.timeline,
        _ => Icons.notes,
      };

  @override
  Widget build(BuildContext context) {
    final glance = _glance;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          // "At a glance" was here, directly beneath a lit-up "Dashboard" tab
          // chip — the same fact stated twice. The module's name is in the
          // shell header above; the chip says which pane. Refresh stays.
          Row(
            children: [
              const Spacer(),
              IconButton(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                tooltip: 'Refresh',
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final (key, label) in _periods)
                ChoiceChip(
                  label: Text(label),
                  selected: _period == key,
                  onSelected: (on) {
                    if (on) _setPeriod(key);
                  },
                ),
            ],
          ),
          const SizedBox(height: 16),
          if (glance != null)
            DashboardCard(
              title: glance['label'] as String? ?? 'Human Resources',
              icon: dashboardPanelIcon(glance['key'] as String? ?? 'hr'),
              controls: _presentationMenu(glance),
              child: _GlanceBody(glance: glance),
            ),
        ],
      ),
    );
  }
}

/// Same drawing rule as the main dashboard: chart above sentences, empty and
/// coming soon as a sentence, no client-side totals.
class _GlanceBody extends StatelessWidget {
  const _GlanceBody({required this.glance});

  final Map<String, dynamic> glance;

  @override
  Widget build(BuildContext context) {
    final data =
        glance['data'] as Map<String, dynamic>? ?? const <String, dynamic>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: _body(
        data,
        glance['coming_soon'] == true,
        glance['presentation'] as String? ?? 'text',
      ),
    );
  }

  List<Widget> _body(
    Map<String, dynamic> data,
    bool comingSoon,
    String presentation,
  ) {
    if (comingSoon || data['empty'] == true) {
      return [
        DashboardNote(_sentenceOf(data) ?? 'Nothing to show yet.'),
      ];
    }

    return [
      // No guard here: DashboardChart decides what it can draw and draws
      // nothing when it cannot. A screen-side copy of that rule was a second
      // definition that had already drifted (UI/UX, 2026-09-09) — and its
      // "yes" over an all-zero pie still rendered nothing, so removing it
      // changes what a person sees in zero cases.
      DashboardChart(
        presentation: presentation,
        slices: data['slices'] is List ? data['slices'] as List : null,
        series: data['series'] is List ? data['series'] as List : null,
      ),
      ..._sentences(data),
    ];
  }

  static List<Widget> _sentences(Map<String, dynamic> data) {
    final sentences = data['sentences'];

    if (sentences is List && sentences.isNotEmpty) {
      return [
        for (final sentence in sentences)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text('$sentence'),
          ),
      ];
    }

    final sentence = _sentenceOf(data);

    if (sentence != null) return [Text(sentence)];

    return [const DashboardNote('Nothing to show yet.')];
  }

  static String? _sentenceOf(Map<String, dynamic> data) {
    final sentence = data['sentence'];

    if (sentence is String && sentence.isNotEmpty) return sentence;

    final sentences = data['sentences'];

    if (sentences is List && sentences.isNotEmpty) {
      return sentences.first.toString();
    }

    return null;
  }
}
