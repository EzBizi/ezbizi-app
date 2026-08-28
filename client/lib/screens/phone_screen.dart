import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'phone/call_tab.dart';
import 'phone/records_tab.dart';
import 'phone/reports_tab.dart';
import 'phone/settings_tab.dart';

/// Phone — Call, Records, Reports, Settings.
///
/// One destination with three faces. The sidebar already says Phone, so there
/// is no heading above the chips repeating it. Unopened tabs do not fetch.
class PhoneScreen extends StatefulWidget {
  const PhoneScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends State<PhoneScreen> {
  static const _call = 0;

  int _tab = _call;

  /// Which tabs have been shown at least once.
  ///
  /// A tab nobody has opened must not fetch; a tab they have opened must keep
  /// its State when they glance at another one. Same Offstage stack as HR.
  final Set<int> _shown = {_call};

  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Call',
      Icons.phone_outlined,
      () => CallTab(api: widget.api, session: widget.session),
    ),
    (
      'Records',
      Icons.history,
      () => RecordsTab(api: widget.api, session: widget.session),
    ),
    (
      'Reports',
      Icons.insights_outlined,
      () => ReportsTab(api: widget.api, session: widget.session),
    ),
    (
      'Settings',
      Icons.tune_outlined,
      () => PhoneSettingsTab(api: widget.api, session: widget.session),
    ),
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < _tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? _tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
