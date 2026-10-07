import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import 'error_note.dart';

/// Recommended dashboard layouts a person can apply (UI queue 53; Jose,
/// 2026-10-05: "also make some recommended layouts, like templates a user can
/// apply"; 2026-10-07: "it changes the layout of their dashboard that being
/// order and size of widgets and modes").
///
/// The server builds them for the reader's reach (`GET /dashboard/catalogue`
/// → `templates`): each is the boxes it names, in order, sized and drawn as
/// named, then every other box hidden. Applying one is a PUT of its glances
/// exactly as sent; this widget only shows them and hands back the choice.
class DashboardTemplatesPanel extends StatefulWidget {
  const DashboardTemplatesPanel({super.key, required this.api});

  final ApiClient api;

  @override
  State<DashboardTemplatesPanel> createState() =>
      _DashboardTemplatesPanelState();
}

/// What the panel hands back: a template to apply, or [standard].
class DashboardTemplateChoice {
  const DashboardTemplateChoice.template(this.template) : standard = false;
  const DashboardTemplateChoice.standard() : template = null, standard = true;

  final Map<String, dynamic>? template;
  final bool standard;
}

class _DashboardTemplatesPanelState extends State<DashboardTemplatesPanel> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _templates = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get('/dashboard/catalogue');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _templates = [
        for (final row in (response.body['templates'] as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 32),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_error != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ErrorNote(_error!),
          const SizedBox(height: 8),
          TextButton(onPressed: _load, child: const Text('Try again')),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Each one arranges your boxes for a kind of work: which show, in '
          'what order, how big, and how they are drawn. The rest are hidden, '
          'and Add a box brings any of them back.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        if (_templates.isEmpty)
          Text(
            'No layouts to offer for what you can see.',
            style: theme.textTheme.bodyMedium,
          ),
        for (final template in _templates) ...[
          _card(context, template),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 4),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('dashboard-template-standard'),
            onPressed: () => Navigator.of(
              context,
            ).pop(const DashboardTemplateChoice.standard()),
            icon: const Icon(Icons.restart_alt),
            label: const Text('Back to the standard layout'),
          ),
        ),
      ],
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> template) {
    final theme = Theme.of(context);
    final key = '${template['key']}';

    return Container(
      key: ValueKey('dashboard-template-$key'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          DashboardTemplateMiniature(glances: glancesOf(template)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${template['label'] ?? ''}',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${template['description'] ?? ''}',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 8),
                FilledButton.tonal(
                  key: ValueKey('dashboard-template-use-$key'),
                  onPressed: () => Navigator.of(
                    context,
                  ).pop(DashboardTemplateChoice.template(template)),
                  child: const Text('Use this layout'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A template's boxes as the server sent them.
List<Map<String, dynamic>> glancesOf(Map<String, dynamic> template) => [
  for (final row in (template['glances'] as List? ?? const []))
    if (row is Map) Map<String, dynamic>.from(row),
];

/// The boxes a template shows, drawn small in order: a Large box twice the
/// width and height of a Medium one, a Small one half a Medium's width. The
/// hidden ones are not drawn.
class DashboardTemplateMiniature extends StatelessWidget {
  const DashboardTemplateMiniature({super.key, required this.glances});

  final List<Map<String, dynamic>> glances;

  static const double width = 112;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      width: width,
      padding: const EdgeInsets.all(5),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Wrap(
        spacing: 3,
        runSpacing: 3,
        children: [
          for (final glance in glances)
            if (glance['hidden'] != true)
              Container(
                key: ValueKey('miniature-${glance['key']}'),
                width: switch (glance['size']) {
                  'large' => 99,
                  'small' => 23,
                  _ => 48,
                },
                height: glance['size'] == 'large' ? 22 : 12,
                decoration: BoxDecoration(
                  color: glance['size'] == 'large'
                      ? theme.colorScheme.primary
                      : theme.colorScheme.primaryContainer,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
        ],
      ),
    );
  }
}
