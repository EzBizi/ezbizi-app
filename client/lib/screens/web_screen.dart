import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'web/seo_tab.dart';

/// Web Management — the company's websites, and what they publish.
///
/// ── One chip today, and a strip anyway ───────────────────────────
///
/// SEO is the first sub-tab and there will be more, so the strip is here from
/// the start rather than retrofitted around a screen that grew into it. It is
/// the shape Marketing and Human Resources already use, and a person told "that
/// lives under Web Management" needs the second word to know they arrived.
///
/// Everything inside is behind this module's own permission — there are no
/// child modules drawn here the way Leads and Reviews are drawn inside
/// Marketing, so the strip is a fixed list rather than one built from `/me`.
class WebScreen extends StatefulWidget {
  const WebScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WebScreen> createState() => _WebScreenState();
}

class _WebScreenState extends State<WebScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final tabs = <(String, IconData, Widget Function())>[
      (
        'SEO',
        Icons.travel_explore_outlined,
        () => SeoTab(api: widget.api, session: widget.session),
      ),
    ];

    final selected = _tab.clamp(0, tabs.length - 1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$1, tab.$2)],
            selected: selected,
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(child: tabs[selected].$3()),
      ],
    );
  }
}
