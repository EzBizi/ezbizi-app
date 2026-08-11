import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/dashboard_panels.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';

/// Human Resources at a glance (HR plan, "Dashboard").
///
/// ── The empty state is the main state ────────────────────────────
///
/// Most organizations open this tab with one person on the books, no
/// onboardings and no invitations sent, and that is not a degenerate case to
/// fall back to — it is what the owner sees on day one and probably day thirty.
/// So every panel below is written empty-first: it says what the number means,
/// what will make it move, and where to go to make that happen. "0" on its own
/// is indistinguishable from a screen that failed to load.
///
/// That is also why the counts are drawn even when they are zero rather than
/// the panel being hidden. A tab that shows three cards today and five next week
/// re-teaches itself every time somebody looks at it.
///
/// ── Why the figures are not computed here ────────────────────────
///
/// They are widgets in the server's dashboard registry, the same ones the main
/// dashboard offers (§18). This tab asks for a fixed set in the plan's order;
/// somebody who wants headcount beside their revenue turns the same widget on
/// over there. One computation, two surfaces — so a correction to how people are
/// counted lands in both without either being remembered.
class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  bool _loading = true;
  String? _error;

  /// The panels the server sent, keyed by widget key.
  ///
  /// A map rather than a list because this tab draws known panels in a known
  /// order; a panel the caller has no permission for is simply absent, and
  /// looking it up by key is how that stays a missing card rather than an
  /// off-by-one against a positional list.
  Map<String, Map<String, dynamic>> _panels = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // A spinner only when there is nothing to keep — see AsyncBody. A refresh
    // holds what is on screen and swaps it when the answer lands.
    setState(() {
      _loading = _panels.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/hr/dashboard');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;

      if (response.ok) {
        _panels = {
          for (final panel in (response.body['panels'] as List<dynamic>)
              .cast<Map<String, dynamic>>())
            panel['key'] as String: panel,
        };
      }
    });
  }

  Map<String, dynamic>? _data(String key) =>
      _panels[key]?['data'] as Map<String, dynamic>?;

  /// The part of the business these figures cover, named the way the rest of
  /// the app names it. Falls back to the bare label only if there is no
  /// ancestry to show, which is the organization sitting at the top.
  String get _viewedPlace {
    final scope = widget.session.me!.scope;

    return scope.path.isEmpty ? scope.label : scope.fullPath;
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          // Two columns when there is room, one when there is not — the shape
          // the main dashboard uses. Panels are self-contained, so this needs no
          // breakpoint table and works the same on a phone.
          final wide = constraints.maxWidth >= 900;
          final width = wide
              ? (constraints.maxWidth - 64) / 2
              : constraints.maxWidth - 48;

          return ListView(
            padding: const EdgeInsets.all(24),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'At a glance',
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
              const SizedBox(height: 4),
              Text(
                // Says whose numbers these are. Everything on this tab is
                // filtered to what the reader may see and to the part of the
                // business they have switched to, and a headcount that silently
                // meant "your branch" would be read as "the company".
                //
                // The whole path, not the bare name: two companies can each
                // operate a "Connecticut", so the name alone names one of two
                // different places with different people behind each.
                'Counted across $_viewedPlace.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 16),
              Wrap(
                spacing: 16,
                runSpacing: 16,
                children: [
                  SizedBox(width: width, child: _headcount(context)),
                  SizedBox(width: width, child: _onboardings(context)),
                  SizedBox(width: width, child: _invitations(context)),
                  SizedBox(width: width, child: _startersAndLeavers(context)),
                  SizedBox(width: width, child: const _CertificationsPanel()),
                ],
              ),
            ],
          );
        },
      ),
    );
  }

  // ── the panels ───────────────────────────────────────────────

  Widget _headcount(BuildContext context) {
    final data = _data('hr.headcount');

    if (data == null) return const SizedBox.shrink();

    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;

    // How many places this view covers, which is what the sentence below is
    // about. Read rather than counted off `rows`: `rows` is the list to DRAW,
    // not a list of places — "Not assigned to a place" is one of its rows and
    // is not a place — so the server is the side that can answer this.
    final places = data['places'] as int;

    return _Panel(
      title: 'Headcount by place',
      icon: Icons.groups_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _BigNumber(total, total == 1 ? 'person' : 'people'),
          const SizedBox(height: 12),
          if (places == 0)
            const _Explains(
              'Nowhere to count yet. Add a company and a branch under '
              'Organization, and each one will appear here with its own '
              'headcount.',
            )
          else ...[
            for (final row in rows)
              _CountRow(label: row['title'] as String, value: row['total'] as int),

            // Said out loud, because the rows genuinely will not add up to the
            // total for anybody working in more than one place — and a reader
            // who spots that without an explanation concludes the page is
            // broken.
            if (total < _sum(rows)) ...[
              const SizedBox(height: 8),
              const _Explains(
                'Somebody working in more than one place is counted in each of '
                'them, so the list adds up to more than the total.',
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _onboardings(BuildContext context) {
    final data = _data('hr.onboardings_in_flight');

    if (data == null) return const SizedBox.shrink();

    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;

    return _Panel(
      title: 'Onboardings in flight',
      icon: Icons.how_to_reg_outlined,
      child: total == 0
          ? const _Explains(
              'No paperwork is under way. When you invite somebody and choose '
              'the contract they are joining on, their form appears here until '
              'both sides have signed it.',
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _BigNumber(total, 'unfinished'),
                const SizedBox(height: 12),
                for (final row in rows)
                  _CountRow(
                    label: row['title'] as String,
                    value: row['total'] as int,
                  ),
                if (data['oldest_started_at'] != null) ...[
                  const SizedBox(height: 8),
                  _Explains(
                    'The longest has been open since '
                    '${formatStamp(data['oldest_started_at'], 'an unknown date', formats: widget.session.me!.display)}.',
                  ),
                ],
              ],
            ),
    );
  }

  Widget _invitations(BuildContext context) {
    final data = _data('hr.invitations_stalled');

    if (data == null) return const SizedBox.shrink();

    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;
    final sent = data['sent_total'] as int;

    return _Panel(
      title: 'Invitations that have stalled',
      icon: Icons.mail_outline,
      child: total > 0
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _BigNumber(total, total == 1 ? 'needs chasing' : 'need chasing'),
                const SizedBox(height: 12),
                for (final row in rows)
                  _CountRow(
                    label: row['title'] as String,
                    value: row['total'] as int,
                  ),
                const SizedBox(height: 8),
                const _Explains(
                  'An expired invitation needs sending again — the link in the '
                  'first one no longer works. Open Invitations to resend it.',
                ),
              ],
            )
          // Two different kinds of nothing, and they need different sentences:
          // one is good news, the other means this organization has not started.
          : _Explains(
              sent == 0
                  ? 'You have not invited anybody yet. Invitations are how '
                        'somebody gets an account — start one from the '
                        'Invitations tab and its progress shows up here.'
                  : 'Nothing has stalled. Every invitation sent is either still '
                        'live or has been accepted.',
            ),
    );
  }

  Widget _startersAndLeavers(BuildContext context) {
    final data = _data('hr.starters_and_leavers');

    if (data == null) return const SizedBox.shrink();

    final people = (data['people'] as List<dynamic>).cast<Map<String, dynamic>>();
    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final days = data['days'] as int;
    final more = data['more_people'] as int;

    return _Panel(
      title: 'Starting and leaving',
      icon: Icons.trending_up_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final row in rows)
            _CountRow(
              // Which rows the window covers is the server's answer, not this
              // tab's — and it is composed by the same function the main
              // dashboard uses, so the identical figure cannot be labelled
              // "in the last 30 days" here and left bare over there.
              label: dashboardRowLabel(data, row),
              value: row['total'] as int,
            ),

          if (people.isNotEmpty) ...[
            const Divider(height: 24),
            for (final person in people)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        person['name'] as String,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Text(
                      formatStamp(
                        person['joined_at'],
                        'Date unknown',
                        formats: widget.session.me!.display,
                      ),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            if (more > 0)
              Text(
                'and $more more',
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ] else ...[
            const SizedBox(height: 8),
            _Explains(
              'Nobody has joined in the last $days days. Somebody appears here '
              'on the day they accept their invitation.',
            ),
          ],

          const SizedBox(height: 8),
          // The asymmetry, stated rather than hidden. "No longer active" has no
          // period because nothing records the DAY somebody was deactivated —
          // saying so is better than implying the count covers the window above.
          const _Explains(
            'People who have left are counted in total rather than over a '
            'period: the app records that an account was switched off, not the '
            'day it happened.',
          ),
        ],
      ),
    );
  }

  static int _sum(List<Map<String, dynamic>> rows) =>
      rows.fold(0, (running, row) => running + (row['total'] as int));
}

/// Expiring certifications — named by the plan, and not built.
///
/// Shown rather than omitted, which is this codebase's standing answer for work
/// that is planned and absent: an automation action the engine cannot run is
/// listed and badged, and a module the platform has switched off is drawn greyed
/// with the reason beside it. Something simply missing invites the reader to
/// conclude the app is broken or that they have lost a permission, and both
/// guesses are worse than the truth.
///
/// The truth here is specific. A contract template's fields carry a key, a
/// label, a type, who fills them, whether they are required, whether they are
/// sensitive and whether they are retired — and nothing that says a date IS an
/// expiry. A template can already ask for "First aid certificate" as a date, but
/// nothing marks that date as the one that lapses, so there is no honest way to
/// answer "what expires next month". Building the panel first would mean
/// inventing that shape here and making onboarding match it afterwards.
class _CertificationsPanel extends StatelessWidget {
  const _CertificationsPanel();

  @override
  Widget build(BuildContext context) {
    return _Panel(
      title: 'Expiring certifications',
      icon: Icons.verified_outlined,
      badge: const Tag('Not built yet', warn: true),
      child: const _Explains(
        'A contract can already ask for a date, but nothing yet marks one as an '
        'expiry — so there is no way to tell a licence that runs out from a date '
        'of birth. When contract fields can carry an expiry, the ones lapsing '
        'soonest will be listed here.',
      ),
    );
  }
}

// ── the pieces every panel is made of ──────────────────────────

class _Panel extends StatelessWidget {
  const _Panel({
    required this.title,
    required this.icon,
    required this.child,
    this.badge,
  });

  final String title;
  final IconData icon;
  final Widget child;
  final Widget? badge;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 18, color: theme.colorScheme.onSurfaceVariant),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    title,
                    style: theme.textTheme.titleMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                ?badge,
              ],
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

/// The headline figure, with the word that says what it counts.
///
/// The unit is never left off. "1" beside "Headcount by place" could be one
/// person or one place, and the two are a very different message.
class _BigNumber extends StatelessWidget {
  const _BigNumber(this.value, this.unit);

  final int value;
  final String unit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Text('$value', style: theme.textTheme.headlineMedium),
        const SizedBox(width: 6),
        Text(unit, style: theme.textTheme.bodyMedium),
      ],
    );
  }
}

class _CountRow extends StatelessWidget {
  const _CountRow({required this.label, required this.value});

  final String label;
  final int value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Expanded(child: Text(label, overflow: TextOverflow.ellipsis)),
          const SizedBox(width: 12),
          Text(
            '$value',
            // Zero is stated in the same weight as any other number rather than
            // greyed out. A place with nobody in it is usually the row somebody
            // opened this tab to find.
            style: theme.textTheme.titleSmall,
          ),
        ],
      ),
    );
  }
}

/// A sentence that says what a number means or what will make it move.
///
/// Its own widget so every panel says it the same way, and so "the empty state
/// explains itself" is a thing the code does rather than a thing each panel
/// remembers to do.
class _Explains extends StatelessWidget {
  const _Explains(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Text(
      text,
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
  }
}
