import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';

/// Marketing › Social Media Management — pages, compose, calendar.
///
/// Layout locked 21 Aug: Posts is three panes (pages sidebar, composer,
/// calendar). Accounts is connect. A connection will bind to the view you
/// are in; this screen does not fetch any yet, so Publish is refused with a
/// sentence rather than looking broken.
class SocialMediaTab extends StatefulWidget {
  const SocialMediaTab({
    super.key,
    required this.session,
  });

  final Session session;

  @override
  State<SocialMediaTab> createState() => _SocialMediaTabState();
}

class _SocialMediaTabState extends State<SocialMediaTab> {
  int _tab = 0;

  String get _viewName =>
      widget.session.me?.scope.label ??
      widget.session.me?.organization.name ??
      'this view';

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
          child: _tab == 0 ? _PostsPane(viewName: _viewName) : _AccountsPane(viewName: _viewName),
        ),
      ],
    );
  }
}

class _PostsPane extends StatelessWidget {
  const _PostsPane({required this.viewName});

  final String viewName;

  static const double _stackBelow = 900;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final stack = constraints.maxWidth < _stackBelow;
        final pages = _PagesColumn(viewName: viewName);
        final compose = _ComposeColumn(viewName: viewName);
        final calendar = const _CalendarColumn();

        if (stack) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              pages,
              const SizedBox(height: 12),
              compose,
              const SizedBox(height: 12),
              calendar,
            ],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 240, child: pages),
              const SizedBox(width: 10),
              Expanded(flex: 5, child: compose),
              const SizedBox(width: 10),
              Expanded(flex: 6, child: calendar),
            ],
          ),
        );
      },
    );
  }
}

class _PagesColumn extends StatelessWidget {
  const _PagesColumn({required this.viewName});

  final String viewName;

  @override
  Widget build(BuildContext context) {
    return DashboardCard(
      title: 'Pages',
      icon: Icons.web_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          DashboardNote(
            'Only pages this view may use. None are connected in $viewName yet.',
          ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton(
              onPressed: () => _openConnect(context, viewName),
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

class _CalendarColumn extends StatelessWidget {
  const _CalendarColumn();

  static const _days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DashboardCard(
      title: 'This week',
      icon: Icons.calendar_today_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const DashboardNote(
            'Only posts for pages this view can see.',
          ),
          const SizedBox(height: 12),
          Table(
            children: [
              TableRow(
                children: [
                  for (final day in _days)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text(
                        day,
                        style: theme.textTheme.labelSmall,
                      ),
                    ),
                ],
              ),
              TableRow(
                children: [
                  for (final _ in _days)
                    Container(
                      height: 72,
                      margin: const EdgeInsets.only(right: 4),
                      decoration: BoxDecoration(
                        color: theme.colorScheme.surfaceContainerLowest,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: theme.dividerColor),
                      ),
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

class _AccountsPane extends StatelessWidget {
  const _AccountsPane({required this.viewName});

  final String viewName;

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
              DashboardNote(
                'A connection is created at the view you are in. Organization '
                'sees every company below. Siblings do not share.',
              ),
              const SizedBox(height: 8),
              DashboardNote(
                'No pages connected in $viewName.',
              ),
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton(
                  onPressed: () => _openConnect(context, viewName),
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
              for (final network in _catalogue)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(network.name),
                  subtitle: Text(
                    network.ready
                        ? 'Ready to connect'
                        : network.why ?? 'Not ready',
                  ),
                  trailing: Text(
                    network.ready ? 'Ready' : 'Not ready',
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
  const _SocialNetwork(this.name, {required this.ready, this.why});

  final String name;
  final bool ready;
  final String? why;
}

const _catalogue = [
  _SocialNetwork('Facebook Page', ready: true),
  _SocialNetwork('Instagram professional', ready: true),
  _SocialNetwork('Threads', ready: true),
  _SocialNetwork('LinkedIn', ready: true),
  _SocialNetwork('TikTok', ready: true),
  _SocialNetwork('Pinterest', ready: true),
  _SocialNetwork('YouTube', ready: false, why: 'Callback not built yet.'),
  _SocialNetwork('X', ready: false, why: 'API tier not chosen.'),
  _SocialNetwork('Bluesky', ready: false, why: 'Later.'),
];

Future<void> _openConnect(BuildContext context, String viewName) {
  return showSidePanel<void>(
    context,
    builder: (context) => SidePanel(
      title: 'Connect to $viewName',
      subtitle:
          'This page will belong to $viewName. Siblings will not see it. '
          'The organization view will, because it owns this company.',
      child: Column(
        children: [
          for (final network in _catalogue)
            ListTile(
              title: Text(network.name),
              subtitle: Text(
                network.ready
                    ? 'Opens the network sign-in.'
                    : network.why ?? 'Not ready',
              ),
              trailing: const FilledButton(
                onPressed: null,
                child: Text('Connect'),
              ),
            ),
        ],
      ),
    ),
  );
}
