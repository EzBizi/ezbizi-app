import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/dashboard_panels.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/dashboard_card.dart';
import '../widgets/dashboard_grid.dart';
import '../widgets/tag.dart';

/// The dashboard: one box per reachable module, one period for the page.
///
/// Every glance is rendered from what the server sends. There is no picker —
/// a module you can open is a box, and a module that is not built yet still
/// has one, tagged coming soon. Charts land in a later pass; this screen
/// draws the sentences.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it.
  int _request = 0;
  bool _loaded = false;
  String? _error;
  String _period = 'month';
  List<Map<String, dynamic>> _glances = [];

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
      // A spinner only when there is nothing to keep — see AsyncBody. An
      // empty glances list is a legitimate answer (no modules in reach), so
      // emptiness is not "still loading"; the first completed fetch is.
      _loading = !_loaded;
      _error = null;
    });

    final response = await widget.api.get('/dashboard');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _loaded = true;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _error = null;
      _period = response.body['period'] as String? ?? 'month';
      _glances = (response.body['glances'] as List<dynamic>)
          .cast<Map<String, dynamic>>();
    });
  }

  /// Put the glances in the order somebody just dragged them into, and remember
  /// it. Period is left off: a reorder is not a change of window.
  ///
  /// The card moves on this frame. Waiting for a round trip would mean a card
  /// that stays put for as long as the network takes, which reads as a control
  /// that did not work — and invites a second drag. If the save fails the
  /// message is shown and the screen is reloaded, which puts the boxes back
  /// where the server still has them rather than leaving a lie on screen.
  Future<void> _reorder(List<String> keys) async {
    final byKey = {
      for (final glance in _glances) glance['key'] as String: glance,
    };

    setState(() {
      _glances = [
        for (final key in keys)
          if (byKey.containsKey(key)) byKey[key]!,
      ];
    });

    final response = await widget.api.put('/dashboard/layout', {
      'glances': [for (final glance in _glances) _entryFor(glance)],
    });

    if (!mounted || response.ok) return;

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(response.message)));

    _load();
  }

  Future<void> _setPeriod(String period) async {
    if (period == _period) return;

    setState(() => _period = period);

    final response = await widget.api.put('/dashboard/layout', {
      'period': period,
      'glances': [for (final glance in _glances) _entryFor(glance)],
    });

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }

    _load();
  }

  /// A glance as the layout stores it. Presentation and options travel with
  /// it, or saving a new ORDER would quietly reset how every box was drawn.
  Map<String, dynamic> _entryFor(Map<String, dynamic> glance) => {
    'key': glance['key'],
    'presentation': glance['presentation'] ?? 'text',
    'options': glance['options'] ?? <String, dynamic>{},
  };

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Dashboard',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
              ),
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
          DashboardGrid(
            onReorder: _reorder,
            panels: [
              for (final glance in _glances)
                DashboardPanel(
                  key: glance['key'] as String,
                  title: glance['label'] as String,
                  icon: dashboardPanelIcon(glance['key'] as String),
                  badge: glance['coming_soon'] == true
                      ? const Tag('Coming soon', warn: true)
                      : null,
                  body: _GlanceBody(glance: glance),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// What one glance says, rendered from its payload rather than its name.
///
/// Charts are a later pass. This draws sentences, and the empty / coming-soon
/// sentence in [DashboardNote]'s voice. The BOX around it is DashboardCard,
/// through DashboardGrid.
class _GlanceBody extends StatelessWidget {
  const _GlanceBody({required this.glance});

  final Map<String, dynamic> glance;

  @override
  Widget build(BuildContext context) {
    final data =
        glance['data'] as Map<String, dynamic>? ?? const <String, dynamic>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: _body(data, glance['coming_soon'] == true),
    );
  }

  List<Widget> _body(Map<String, dynamic> data, bool comingSoon) {
    if (comingSoon || data['empty'] == true) {
      return [
        DashboardNote(_sentenceOf(data) ?? 'Nothing to show yet.'),
      ];
    }

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
