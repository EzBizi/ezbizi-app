import 'dart:async';

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
/// ── Why the date is the first thing on the screen ────────────────
///
/// The data is a snapshot of another system, installed once a day. A refresh
/// that stops running leaves yesterday's file in place and every request still
/// answers — so the tab would go on drawing a perfectly plausible list that is
/// a week old, with nothing to distinguish it from a quiet week. The capture
/// date is therefore part of the page rather than a detail behind a tooltip,
/// and when it is past the window the banner says so in words.
///
/// The other half of the same rule: an unreachable source is a SENTENCE, never
/// an empty list. An empty list here would say A-1 Chimney has no reviews, and
/// it has over thirteen thousand.
class A1LeadsGbpScreen extends StatefulWidget {
  const A1LeadsGbpScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<A1LeadsGbpScreen> createState() => _A1LeadsGbpScreenState();
}

class _A1LeadsGbpScreenState extends State<A1LeadsGbpScreen> {
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response = await widget.api.get('/a1leads-gbp');
      if (!mounted) return;
      if (!response.ok) {
        setState(() {
          _error = 'A1 Leads returned ${response.statusCode}.';
          _loading = false;
        });
        return;
      }
      setState(() {
        _data = (response.body['data'] as Map?)?.cast<String, dynamic>();
        _error = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return _sentence(
        theme,
        icon: Icons.error_outline,
        title: 'A1 Leads could not be read',
        body: _error!,
      );
    }

    final source = (_data?['source'] as Map?)?.cast<String, dynamic>() ?? const {};
    final reviews = (_data?['reviews'] as List?) ?? const [];

    // The source is unusable: say which kind of unusable, and never fall
    // through to a list. `because` carries the server's own words.
    if (source['configured'] != true || source['reachable'] != true) {
      return _sentence(
        theme,
        icon: Icons.link_off,
        title: source['configured'] == true
            ? 'A1 Leads is not answering'
            : 'A1 Leads is not connected yet',
        body:
            '${source['because'] ?? 'The connection to A1 Leads is not available.'}\n\n'
            'Nothing is missing and nothing is broken here — there is simply '
            'nothing to show until the connection is in place. EzBizi\'s own '
            'Reviews and Business Profile are in Marketing, unchanged.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sourceBand(theme, source),
        const Divider(height: 1),
        Expanded(
          child: reviews.isEmpty
              ? _sentence(
                  theme,
                  icon: Icons.inbox_outlined,
                  title: 'No reviews in this snapshot',
                  body:
                      'The snapshot installed on ${_short(source['captured_at'])} '
                      'carries no reviews for this page.',
                )
              : ListView.separated(
                  itemCount: reviews.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) =>
                      _review(theme, (reviews[i] as Map).cast<String, dynamic>()),
                ),
        ),
      ],
    );
  }

  /// The band that makes the age of the data impossible to miss.
  Widget _sourceBand(ThemeData theme, Map<String, dynamic> source) {
    final stale = source['stale'] == true;
    final colour = stale ? theme.colorScheme.errorContainer : theme.colorScheme.surfaceContainerHighest;
    final onColour = stale ? theme.colorScheme.onErrorContainer : theme.colorScheme.onSurfaceVariant;

    return Material(
      color: colour,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            Icon(stale ? Icons.warning_amber : Icons.history, size: 18, color: onColour),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                stale
                    ? 'This data is from ${_short(source['captured_at'])} and has not '
                          'refreshed since — ${source['hours_old']} hours old. Treat it as '
                          'historical rather than current.'
                    : 'Read from A1 Leads. Snapshot captured ${_short(source['captured_at'])}, '
                          'installed ${_short(source['installed_at'])}.',
                style: theme.textTheme.bodySmall?.copyWith(color: onColour),
              ),
            ),
            IconButton(
              tooltip: 'Check again',
              icon: const Icon(Icons.refresh, size: 18),
              color: onColour,
              onPressed: _load,
            ),
          ],
        ),
      ),
    );
  }

  Widget _review(ThemeData theme, Map<String, dynamic> review) {
    final rating = review['rating'];

    return ListTile(
      leading: CircleAvatar(
        backgroundColor: theme.colorScheme.surfaceContainerHighest,
        child: Text('$rating', style: theme.textTheme.labelLarge),
      ),
      title: Text('${review['reviewer_name'] ?? 'Anonymous'}'),
      subtitle: Text(
        '${review['comment'] ?? ''}'.trim().isEmpty
            ? 'No comment'
            : '${review['comment']}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: Text(
        _short(review['review_created_at']),
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _sentence(
    ThemeData theme, {
    required IconData icon,
    required String title,
    required String body,
  }) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 40, color: theme.colorScheme.outline),
              const SizedBox(height: 12),
              Text(title, style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text(
                body,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// A date a person reads, from whatever the server sent.
  ///
  /// Null-tolerant on purpose: the receipt can be missing a field when a
  /// refresh has never run, and a screen that throws on that is a screen that
  /// breaks precisely when something is already wrong.
  String _short(Object? iso) {
    final parsed = DateTime.tryParse('$iso');
    if (parsed == null) return 'an unknown date';

    final local = parsed.toLocal();
    final d = local.day.toString().padLeft(2, '0');
    final m = local.month.toString().padLeft(2, '0');
    final h = local.hour.toString().padLeft(2, '0');
    final min = local.minute.toString().padLeft(2, '0');

    return '$d/$m $h:$min';
  }
}
