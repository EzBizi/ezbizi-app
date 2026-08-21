import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import '../email/consent_window.dart';

/// Marketing › Social Media Management — pages, compose, calendar.
///
/// Layout locked 21 Aug. Connect uses the server catalogue: OAuth popup for
/// networks with an app, handle + app password for Bluesky.
class SocialMediaTab extends StatefulWidget {
  const SocialMediaTab({
    super.key,
    required this.api,
    required this.session,
    this.openConsent = openConsentWindow,
  });

  final ApiClient api;
  final Session session;
  final OpenConsentWindow openConsent;

  @override
  State<SocialMediaTab> createState() => _SocialMediaTabState();
}

class _SocialMediaTabState extends State<SocialMediaTab> {
  int _tab = 0;
  List<_SocialNetwork> _providers = const [];
  List<_SocialConnection> _connections = const [];
  String? _loadError;

  String get _viewName =>
      widget.session.me?.scope.label ??
      widget.session.me?.organization.name ??
      'this view';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final providers = await widget.api.get('/social/providers');
    final connections = await widget.api.get('/social/connections');
    if (!mounted) return;

    setState(() {
      if (!providers.ok) {
        _loadError = providers.message;
        return;
      }
      if (!connections.ok) {
        _loadError = connections.message;
        return;
      }
      _loadError = null;
      _providers = ((providers.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SocialNetwork.fromJson)
          .toList();
      _connections = ((connections.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SocialConnection.fromJson)
          .toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          labels: const [
            ('Posts', Icons.edit_outlined),
            ('Accounts', Icons.link_outlined),
          ],
          selected: _tab,
          onSelected: (tab) => setState(() => _tab = tab),
        ),
        const Divider(height: 1),
        Expanded(
          child: _tab == 0
              ? _PostsPane(
                  viewName: _viewName,
                  connections: _connections,
                  onConnect: () => _openConnect(context),
                )
              : _AccountsPane(
                  viewName: _viewName,
                  providers: _providers,
                  connections: _connections,
                  error: _loadError,
                  onConnect: () => _openConnect(context),
                ),
        ),
      ],
    );
  }

  Future<void> _openConnect(BuildContext context) {
    return showSidePanel<void>(
      context,
      builder: (context) => _ConnectPanel(
        api: widget.api,
        viewName: _viewName,
        providers: _providers,
        openConsent: widget.openConsent,
        onConnected: _load,
      ),
    );
  }
}

class _PostsPane extends StatelessWidget {
  const _PostsPane({
    required this.viewName,
    required this.connections,
    required this.onConnect,
  });

  final String viewName;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;

  static const double _stackBelow = 900;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final stack = constraints.maxWidth < _stackBelow;
        final pages = _PagesColumn(
          viewName: viewName,
          connections: connections,
          onConnect: onConnect,
        );
        final compose = _ComposeColumn(viewName: viewName);

        if (stack) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              pages,
              const SizedBox(height: 12),
              compose,
              const SizedBox(height: 12),
              const _CalendarsColumn(),
            ],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 220, child: pages),
              const SizedBox(width: 10),
              Expanded(flex: 4, child: compose),
              const SizedBox(width: 10),
              const Expanded(
                flex: 7,
                child: _CalendarsColumn(fill: true),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _PagesColumn extends StatelessWidget {
  const _PagesColumn({
    required this.viewName,
    required this.connections,
    required this.onConnect,
  });

  final String viewName;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;

  @override
  Widget build(BuildContext context) {
    return DashboardCard(
      title: 'Pages',
      icon: Icons.web_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (connections.isEmpty)
            DashboardNote(
              'Only pages this view may use. None are connected in $viewName yet.',
            )
          else
            for (final page in connections)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text(page.displayName),
                subtitle: Text(page.providerLabel),
              ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton(
              onPressed: onConnect,
              child: const Text('Connect'),
            ),
          ),
        ],
      ),
    );
  }
}

class _ComposeColumn extends StatelessWidget {
  const _ComposeColumn({required this.viewName});

  final String viewName;

  @override
  Widget build(BuildContext context) {
    final nowhere =
        'No page is connected in $viewName, so there is nowhere to publish.';

    return DashboardCard(
      title: 'Compose',
      icon: Icons.edit_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const DashboardNote(
            'One post, every checked page. Schedule uses this view’s wall clock.',
          ),
          const SizedBox(height: 12),
          const TextField(
            enabled: false,
            minLines: 6,
            maxLines: 10,
            decoration: InputDecoration(
              hintText: 'What should go out?',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              Refused(
                reason: nowhere,
                child: const FilledButton(
                  onPressed: null,
                  child: Text('Publish now'),
                ),
              ),
              const OutlinedButton(
                onPressed: null,
                child: Text('Schedule'),
              ),
            ],
          ),
          const SizedBox(height: 10),
          DashboardNote(nowhere),
        ],
      ),
    );
  }
}

class _CalendarsColumn extends StatefulWidget {
  const _CalendarsColumn({this.fill = false});

  /// When true the column fills the pane and month squares shrink to fit,
  /// so the whole month is on screen. Off in the stacked (phone) layout,
  /// which already scrolls the page as a whole.
  final bool fill;

  static const _days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  static const _monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
  ];

  static const _shortMonths = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];

  static DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);

  static DateTime mondayOf(DateTime d) {
    final day = _dateOnly(d);
    return day.subtract(Duration(days: day.weekday - DateTime.monday));
  }

  static String monthTitle(DateTime d) =>
      '${_monthNames[d.month - 1]} ${d.year}';

  static String weekRange(DateTime monday) {
    final sunday = monday.add(const Duration(days: 6));
    if (monday.month == sunday.month) {
      return '${monday.day}–${sunday.day} ${_shortMonths[monday.month - 1]}';
    }
    return '${monday.day} ${_shortMonths[monday.month - 1]} – '
        '${sunday.day} ${_shortMonths[sunday.month - 1]}';
  }

  @override
  State<_CalendarsColumn> createState() => _CalendarsColumnState();
}

class _CalendarsColumnState extends State<_CalendarsColumn> {
  late DateTime _month;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _month = DateTime(now.year, now.month, 1);
  }

  void _shiftMonth(int months) {
    setState(() {
      _month = DateTime(_month.year, _month.month + months, 1);
    });
  }

  @override
  Widget build(BuildContext context) {
    final month = _MonthCard(
      month: _month,
      onPrevious: () => _shiftMonth(-1),
      onNext: () => _shiftMonth(1),
      fill: widget.fill,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const _WeekCard(),
        const SizedBox(height: 12),
        if (widget.fill) Expanded(child: month) else month,
      ],
    );
  }
}

class _WeekCard extends StatelessWidget {
  const _WeekCard();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _CalendarsColumn._dateOnly(DateTime.now());
    final monday = _CalendarsColumn.mondayOf(today);

    return DashboardCard(
      title: 'Weekly Calendar',
      icon: Icons.calendar_today_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const DashboardNote('Only posts for pages this view can see.'),
          const SizedBox(height: 8),
          Text(
            _CalendarsColumn.weekRange(monday),
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          _WeekdayHeader(theme: theme),
          Table(
            defaultColumnWidth: const FlexColumnWidth(),
            children: [
              TableRow(
                children: [
                  for (var i = 0; i < 7; i++)
                    _DayCell(
                      height: 88,
                      theme: theme,
                      label: '${monday.add(Duration(days: i)).day}',
                      highlight: monday.add(Duration(days: i)) == today,
                    ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MonthCard extends StatelessWidget {
  const _MonthCard({
    required this.month,
    required this.onPrevious,
    required this.onNext,
    this.fill = false,
  });

  final DateTime month;
  final VoidCallback onPrevious;
  final VoidCallback onNext;
  final bool fill;

  static const _weekdayHeaderHeight = 28.0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _CalendarsColumn._dateOnly(DateTime.now());
    final first = DateTime(month.year, month.month, 1);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = first.weekday - DateTime.monday;
    final cells = leading + daysInMonth;
    final rows = (cells / 7).ceil();

    return DashboardCard(
      title: 'Monthly Calendar',
      icon: Icons.calendar_view_month_outlined,
      expand: fill,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _PeriodNav(
            label: _CalendarsColumn.monthTitle(month),
            previousTooltip: 'Previous month',
            nextTooltip: 'Next month',
            onPrevious: onPrevious,
            onNext: onNext,
          ),
          const SizedBox(height: 8),
          if (fill)
            Expanded(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  return _fittedGrid(
                    theme: theme,
                    today: today,
                    leading: leading,
                    daysInMonth: daysInMonth,
                    rows: rows,
                    maxWidth: constraints.maxWidth,
                    maxHeight: constraints.maxHeight,
                  );
                },
              ),
            )
          else ...[
            _WeekdayHeader(theme: theme),
            Table(
              defaultColumnWidth: const FlexColumnWidth(),
              children: [
                for (var r = 0; r < rows; r++)
                  TableRow(
                    children: [
                      for (var c = 0; c < 7; c++)
                        _monthCell(
                          theme,
                          today,
                          month,
                          leading,
                          daysInMonth,
                          r * 7 + c,
                        ),
                    ],
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _fittedGrid({
    required ThemeData theme,
    required DateTime today,
    required int leading,
    required int daysInMonth,
    required int rows,
    required double maxWidth,
    required double maxHeight,
  }) {
    final forDays = math.max(0.0, maxHeight - _weekdayHeaderHeight);
    final stride = math.min(
      maxWidth / 7,
      rows == 0 ? maxWidth / 7 : forDays / rows,
    );

    return Align(
      alignment: Alignment.topLeft,
      child: SizedBox(
        width: stride * 7,
        height: _weekdayHeaderHeight + stride * rows,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              height: _weekdayHeaderHeight,
              child: _WeekdayHeader(theme: theme),
            ),
            Table(
              defaultColumnWidth: const FlexColumnWidth(),
              children: [
                for (var r = 0; r < rows; r++)
                  TableRow(
                    children: [
                      for (var c = 0; c < 7; c++)
                        _monthCell(
                          theme,
                          today,
                          month,
                          leading,
                          daysInMonth,
                          r * 7 + c,
                          size: stride,
                        ),
                    ],
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  static Widget _monthCell(
    ThemeData theme,
    DateTime today,
    DateTime month,
    int leading,
    int daysInMonth,
    int index, {
    double? size,
  }) {
    final day = index - leading + 1;
    if (day < 1 || day > daysInMonth) {
      return _DayCell(theme: theme, square: true, empty: true, size: size);
    }
    final date = DateTime(month.year, month.month, day);
    return _DayCell(
      key: Key('month-day-$day'),
      theme: theme,
      square: true,
      size: size,
      label: '$day',
      highlight: date == today,
    );
  }
}

class _PeriodNav extends StatelessWidget {
  const _PeriodNav({
    required this.label,
    required this.previousTooltip,
    required this.nextTooltip,
    required this.onPrevious,
    required this.onNext,
  });

  final String label;
  final String previousTooltip;
  final String nextTooltip;
  final VoidCallback onPrevious;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        IconButton(
          tooltip: previousTooltip,
          visualDensity: VisualDensity.compact,
          onPressed: onPrevious,
          icon: const Icon(Icons.chevron_left),
        ),
        Expanded(
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall,
          ),
        ),
        IconButton(
          tooltip: nextTooltip,
          visualDensity: VisualDensity.compact,
          onPressed: onNext,
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }
}

class _WeekdayHeader extends StatelessWidget {
  const _WeekdayHeader({required this.theme});

  final ThemeData theme;

  @override
  Widget build(BuildContext context) {
    return Table(
      children: [
        TableRow(
          children: [
            for (final day in _CalendarsColumn._days)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(day, style: theme.textTheme.labelSmall),
              ),
          ],
        ),
      ],
    );
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    super.key,
    required this.theme,
    this.height,
    this.size,
    this.square = false,
    this.label,
    this.highlight = false,
    this.empty = false,
  });

  final double? height;
  final double? size;
  final bool square;
  final ThemeData theme;
  final String? label;
  final bool highlight;
  final bool empty;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      height: size != null ? null : (square ? null : height),
      decoration: BoxDecoration(
        color: empty
            ? theme.colorScheme.surface
            : highlight
                ? theme.colorScheme.primary.withValues(alpha: 0.12)
                : theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: highlight ? theme.colorScheme.primary : theme.dividerColor,
        ),
      ),
      alignment: Alignment.topLeft,
      padding: const EdgeInsets.all(6),
      child: label == null
          ? null
          : Text(
              label!,
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: highlight ? FontWeight.w700 : FontWeight.w500,
                color: highlight
                    ? theme.colorScheme.primary
                    : theme.colorScheme.onSurface,
              ),
            ),
    );

    final padded = Padding(
      padding: const EdgeInsets.only(right: 3, bottom: 3),
      child: box,
    );

    if (size != null) {
      return SizedBox(width: size, height: size, child: padded);
    }

    if (square) {
      return AspectRatio(aspectRatio: 1, child: padded);
    }

    return padded;
  }
}

class _AccountsPane extends StatelessWidget {
  const _AccountsPane({
    required this.viewName,
    required this.providers,
    required this.connections,
    required this.onConnect,
    this.error,
  });

  final String viewName;
  final List<_SocialNetwork> providers;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        DashboardCard(
          title: 'Accounts in $viewName',
          icon: Icons.link_outlined,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const DashboardNote(
                'A connection is created at the view you are in. Organization '
                'sees every company below. Siblings do not share.',
              ),
              const SizedBox(height: 8),
              if (error != null) DashboardNote(error!),
              if (connections.isEmpty && error == null)
                DashboardNote('No pages connected in $viewName.')
              else
                for (final page in connections)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(page.displayName),
                    subtitle: Text(page.providerLabel),
                  ),
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton(
                  onPressed: onConnect,
                  child: Text('Connect a page to $viewName'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        DashboardCard(
          title: 'Catalogue',
          icon: Icons.public_outlined,
          child: Column(
            children: [
              for (final network in providers)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(network.label),
                  subtitle: Text(
                    network.usable
                        ? 'Ready to connect'
                        : network.unusableBecause ?? 'Not ready',
                  ),
                  trailing: Text(
                    network.usable ? 'Ready' : 'Not ready',
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SocialNetwork {
  const _SocialNetwork({
    required this.value,
    required this.label,
    required this.usable,
    this.unusableBecause,
    this.connectsBy = 'sign_in',
    this.startPath = '',
  });

  final String value;
  final String label;
  final bool usable;
  final String? unusableBecause;
  final String connectsBy;
  final String startPath;

  factory _SocialNetwork.fromJson(Map<String, dynamic> json) {
    return _SocialNetwork(
      value: json['value'] as String? ?? '',
      label: json['label'] as String? ?? '',
      usable: json['usable'] as bool? ?? false,
      unusableBecause: json['unusable_because'] as String?,
      connectsBy: json['connects_by'] as String? ?? 'sign_in',
      startPath: json['start_path'] as String? ?? '',
    );
  }
}

class _SocialConnection {
  const _SocialConnection({
    required this.id,
    required this.displayName,
    required this.providerLabel,
  });

  final String id;
  final String displayName;
  final String providerLabel;

  factory _SocialConnection.fromJson(Map<String, dynamic> json) {
    return _SocialConnection(
      id: json['id'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      providerLabel: json['provider_label'] as String? ?? '',
    );
  }
}

class _ConnectPanel extends StatefulWidget {
  const _ConnectPanel({
    required this.api,
    required this.viewName,
    required this.providers,
    required this.openConsent,
    required this.onConnected,
  });

  final ApiClient api;
  final String viewName;
  final List<_SocialNetwork> providers;
  final OpenConsentWindow openConsent;
  final Future<void> Function() onConnected;

  @override
  State<_ConnectPanel> createState() => _ConnectPanelState();
}

class _ConnectPanelState extends State<_ConnectPanel> {
  String? _busy;
  String? _error;
  Timer? _poll;
  final _handle = TextEditingController();
  final _appPassword = TextEditingController();

  @override
  void dispose() {
    _poll?.cancel();
    _handle.dispose();
    _appPassword.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Connect to ${widget.viewName}',
      subtitle:
          'This page will belong to ${widget.viewName}. Siblings will not see it. '
          'The organization view will, because it owns this company.',
      child: Column(
        children: [
          if (_error != null) ...[
            DashboardNote(_error!),
            const SizedBox(height: 8),
          ],
          for (final network in widget.providers)
            if (network.connectsBy == 'password')
              _blueskyTile(network)
            else
              ListTile(
                title: Text(network.label),
                subtitle: Text(
                  _busy == network.value
                      ? 'Waiting for ${network.label}…'
                      : network.usable
                          ? 'Opens the network sign-in.'
                          : network.unusableBecause ?? 'Not ready',
                ),
                trailing: FilledButton(
                  onPressed: network.usable && _busy == null
                      ? () => _connectOauth(network)
                      : null,
                  child: const Text('Connect'),
                ),
              ),
        ],
      ),
    );
  }

  Widget _blueskyTile(_SocialNetwork network) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(network.label, style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          DashboardNote(
            'Bluesky has no developer console. Use an app password from Bluesky '
            'settings, not the account password.',
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _handle,
            decoration: const InputDecoration(
              labelText: 'Handle',
              hintText: 'company.bsky.social',
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _appPassword,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'App password'),
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: _busy == null ? () => _connectBluesky(network) : null,
              child: const Text('Connect'),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _connectBluesky(_SocialNetwork network) async {
    setState(() {
      _busy = network.value;
      _error = null;
    });

    final response = await widget.api.post(network.startPath, {
      'handle': _handle.text,
      'app_password': _appPassword.text,
    });

    if (!mounted) return;

    setState(() => _busy = null);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await widget.onConnected();
  }

  Future<void> _connectOauth(_SocialNetwork network) async {
    final window = widget.openConsent();
    if (window == null) {
      setState(() {
        _error =
            'EzBizi could not open the ${network.label} sign-in window. On the web that '
            'is usually the browser blocking new windows for this site — allow '
            'them and press the button again.';
      });
      return;
    }

    setState(() {
      _busy = network.value;
      _error = null;
    });

    final response = await widget.api.post(network.startPath, {});

    if (!mounted) {
      window.close();
      return;
    }

    if (!response.ok) {
      window.close();
      setState(() {
        _busy = null;
        _error = response.reason;
      });
      return;
    }

    final data = response.data;
    final url = data is Map<String, dynamic> ? data['authorization_url'] : null;
    final flow = data is Map<String, dynamic> ? data['flow'] : null;

    if (url is! String || flow is! String) {
      window.close();
      setState(() {
        _busy = null;
        _error = '${network.label} did not hand back a sign-in address.';
      });
      return;
    }

    window.goTo(url);
    _watch(window, network, flow);
  }

  void _watch(ConsentWindow window, _SocialNetwork network, String flow) {
    var asks = 150;
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 2), (timer) async {
      asks -= 1;
      if (asks <= 0 || !mounted) {
        timer.cancel();
        if (mounted) setState(() => _busy = null);
        return;
      }

      final statusPath = network.startPath.replaceFirst('/start', '/status');
      final status = await widget.api.get(statusPath, {'flow': flow});
      if (!mounted) {
        timer.cancel();
        return;
      }

      final data = status.data;
      final state = data is Map<String, dynamic> ? data['state'] : null;
      final message = data is Map<String, dynamic> ? data['message'] : null;

      if (state == 'connected') {
        timer.cancel();
        window.close();
        setState(() => _busy = null);
        await widget.onConnected();
        return;
      }

      if (state == 'refused') {
        timer.cancel();
        window.close();
        setState(() {
          _busy = null;
          _error = message is String
              ? message
              : '${network.label} sign-in did not finish.';
        });
      }
    });
  }
}
