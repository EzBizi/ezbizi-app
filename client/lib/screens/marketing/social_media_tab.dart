import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';

/// Marketing › Social Media Management — compose once, publish to many.
///
/// The first screen, not the finished poster. Accounts are not stored yet
/// and no network API is called from here. What is here is the shape the
/// rest of the work fills: connected pages at every level of the business,
/// a post written once, and a publish that has nowhere to go until a
/// page is connected.
class SocialMediaTab extends StatelessWidget {
  const SocialMediaTab({
    super.key,
    required this.session,
  });

  final Session session;

  static const double _readable = 760;

  static const _networks = [
    'Facebook',
    'Instagram',
    'LinkedIn',
    'TikTok',
    'Threads',
    'Pinterest',
    'YouTube',
    'X',
    'Bluesky',
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final org = session.me?.organization.name ?? 'this organization';

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: _readable),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Social Media Management',
                  style: theme.textTheme.headlineSmall,
                ),
                const SizedBox(height: 8),
                Text(
                  'Write a post once and send it to every page or profile the '
                  'business has connected — now, or on a schedule. Connections '
                  'belong to the organization, a company, or a franchise, so a '
                  'branch posts as itself rather than as the whole company.',
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 20),
                DashboardCard(
                  title: 'Connected accounts',
                  icon: Icons.link_outlined,
                  child: DashboardNote(
                    'No page or profile is connected in $org yet. A Facebook '
                    'Page, Instagram professional account, LinkedIn Page, '
                    'TikTok, and the rest of the catalogue will be added here '
                    'the same way a shared inbox is added under Email — the '
                    'company signs in as itself, not as EzBizi.',
                  ),
                ),
                const SizedBox(height: 16),
                DashboardCard(
                  title: 'Compose',
                  icon: Icons.edit_outlined,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const DashboardNote(
                        'One post, many targets. Publish and schedule both '
                        'wait until at least one account is connected.',
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        enabled: false,
                        minLines: 4,
                        maxLines: 8,
                        decoration: const InputDecoration(
                          labelText: 'Post',
                          hintText: 'What should go out?',
                          alignLabelWithHint: true,
                        ),
                      ),
                      const SizedBox(height: 12),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: Refused(
                          reason:
                              'No page or profile is connected yet, so there '
                              'is nowhere to publish.',
                          child: FilledButton(
                            onPressed: null,
                            child: const Text('Publish now'),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                DashboardCard(
                  title: 'Networks',
                  icon: Icons.public_outlined,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const DashboardNote(
                        'The catalogue a company will be able to connect. '
                        'Each one is added when its developer app is ready; '
                        'none of them publish from this screen yet.',
                      ),
                      const SizedBox(height: 12),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final name in _networks)
                            Chip(label: Text(name)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
