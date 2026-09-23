import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';

/// A1Leads GBP — a read-only window onto Rodrigo's A1 Leads, and only that.
///
/// ── What this is NOT ─────────────────────────────────────────────
///
/// It is not a container for EzBizi's Reviews and Business Profile. Those are
/// our own modules, they read our own tables, they live inside Marketing, and
/// they are what a Google reviewer is shown when our own Business Profile API
/// access arrives. This screen must never draw them.
///
/// Jose, 2026-09-23, after an earlier version of this file did exactly that:
/// *"we still need our system in place for when we get OUR GBP Credentials and
/// we'll need the google reviewers to see it. SO PUT IT BACK WHERE IT WAS, THE
/// A1LEADS GBP TAB IS A NEW ENTIRE THING."*
///
/// ── What it is ───────────────────────────────────────────────────
///
/// A-1 Chimney's Business Profile still lives in A1 Leads, because EzBizi's own
/// API application was refused on 2026-09-22 under Google's 60-day rule and
/// cannot be re-filed before 26 October. Until then this tab reads that system
/// through its partner API — reviews and listing health — and writes nothing,
/// to Google or to us.
///
/// ── Why it says the date out loud ────────────────────────────────
///
/// The source is a daily snapshot, not a live system, and a snapshot that fails
/// to refresh looks exactly like a quiet week. So the as-of date is part of the
/// screen rather than a detail behind a tooltip, and when the source is not
/// connected the screen says so instead of drawing an empty list.
class A1LeadsGbpScreen extends StatefulWidget {
  const A1LeadsGbpScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<A1LeadsGbpScreen> createState() => _A1LeadsGbpScreenState();
}

class _A1LeadsGbpScreenState extends State<A1LeadsGbpScreen> {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    /*
     * THE SOURCE IS NOT CONNECTED YET, and this says so rather than showing
     * nothing.
     *
     * The route grant and partner token are installed on the A1 Leads host by
     * Jose as root; until that lands every call is refused by the consumer
     * policy. An empty frame here would read as the tab being broken, and an
     * empty LIST would read as a business with no reviews — which is the one
     * wrong impression this screen must never give, since A-1 Chimney has
     * thirteen thousand of them.
     */
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.link_off,
                size: 40,
                color: theme.colorScheme.outline,
              ),
              const SizedBox(height: 12),
              Text(
                'A1 Leads is not connected yet',
                style: theme.textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                'This tab reads the Google Business Profile data that still '
                'lives in A1 Leads. It is waiting on the read-only access grant '
                'for that system. Nothing is missing and nothing is broken — '
                'there is simply nothing to show until the connection is in '
                'place.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 16),
              // Says where the real thing lives, so nobody reads this tab as
              // "EzBizi has no reviews". Ours are in Marketing and always were.
              Text(
                'EzBizi\'s own Reviews and Business Profile are in Marketing, '
                'unchanged.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
