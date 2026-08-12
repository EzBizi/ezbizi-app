import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/dashboard_panels.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/dashboard_grid.dart';
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

  /// This tab, as a saved order names it.
  static const String _surface = 'hr';

  /// The panel this tab draws that the server has no widget for.
  ///
  /// It is still a panel — it is on screen, it takes a place in the row, and
  /// somebody can move it — so it needs a key like the rest. Prefixed the same
  /// way, because the day the field model can answer "what expires next month"
  /// this becomes a registry widget under exactly this key and every saved order
  /// that already names it keeps working.
  static const String _certificationsKey = 'hr.certifications';

  /// The panels this tab draws, in the order the HR plan names them.
  ///
  /// The user's own order is laid over this, and this is what is left when they
  /// have not set one — and what a panel they have never seen is appended
  /// after. See `panelsInSavedOrder`.
  static const List<String> _asPlanned = [
    'hr.headcount',
    'hr.onboardings_in_flight',
    'hr.invitations_stalled',
    'hr.starters_and_leavers',
    _certificationsKey,
  ];

  /// The order this user last dragged these panels into.
  ///
  /// Read off the session, which already carries their preferences — the /me
  /// payload has them so the app can paint the right theme on the first frame,
  /// and this is the same kind of fact. No second request, and nothing on this
  /// tab has to wait for one before it can be drawn in the right order.
  late List<String> _order;

  @override
  void initState() {
    super.initState();
    _order = savedPanelOrder(widget.session.me!.preferences, _surface);
    _load();
  }

  /// Remember where somebody just put a card.
  ///
  /// ── Why this writes to the dashboard's own store ─────────────────
  ///
  /// It is a user preference (§2.1 category 6), the same category the main
  /// dashboard's layout is, and it goes to the same endpoint and into the same
  /// `users.preferences` blob. Not a tenant setting and not an organization
  /// setting: nobody may arrange somebody else's dashboard, and two people
  /// sharing a role must be able to disagree about where headcount sits.
  ///
  /// It is only an ORDER, though, and not a layout: this tab's panels are fixed
  /// by the HR plan, so there is nothing to choose and only a sequence to keep.
  /// That is why it is stored per surface rather than being folded into
  /// `dashboard.widgets`, which is the main dashboard's list of chosen panels
  /// and would mean two dashboards writing over each other's choices.
  ///
  /// Moved first and saved second, for the reason the main dashboard gives. Put
  /// back if the save fails, because a card sitting somewhere the server does
  /// not agree with is worse than a card that did not move.
  Future<void> _reorder(List<String> keys) async {
    final previous = _order;

    setState(() => _order = keys);

    final response = await widget.api.put('/dashboard/layout', {
      'surface': _surface,
      'widgets': [
        for (final key in keys) {'key': key},
      ],
    });

    if (!mounted || response.ok) return;

    setState(() => _order = previous);

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(response.message)));
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
      child: ListView(
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
            // Says whose numbers these are. Everything on this tab is filtered
            // to what the reader may see and to the part of the business they
            // have switched to, and a headcount that silently meant "your
            // branch" would be read as "the company".
            //
            // The whole path, not the bare name: two companies can each operate
            // a "Connecticut", so the name alone names one of two different
            // places with different people behind each.
            'Counted across $_viewedPlace.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          // The same grid the main dashboard uses, so the cards are the same
          // size, the same shape and rearranged the same way on both screens.
          DashboardGrid(onReorder: _reorder, panels: _panelsToDraw(context)),
        ],
      ),
    );
  }

  /// The panels there are, arranged the way this user asked for.
  ///
  /// A panel the server withheld is absent rather than drawn empty — somebody
  /// whose role lost a permission gets a shorter tab, not a broken one — and a
  /// saved order naming a panel that is no longer here is simply passed over.
  List<DashboardPanel> _panelsToDraw(BuildContext context) {
    final drawn = <String, DashboardPanel>{};

    for (final key in _asPlanned) {
      final panel = _panelFor(context, key);

      if (panel != null) drawn[key] = panel;
    }

    return [
      for (final key in panelsInSavedOrder(drawn.keys.toList(), _order))
        drawn[key]!,
    ];
  }

  /// One panel, or null when there is nothing to draw for it.
  ///
  /// The title is the SERVER's label, not a copy of it kept here. The same
  /// widget is offered on the main dashboard, and a panel named two things on
  /// two screens is one rename away from looking like two different figures.
  DashboardPanel? _panelFor(BuildContext context, String key) {
    if (key == _certificationsKey) {
      return DashboardPanel(
        key: key,
        title: 'Expiring certifications',
        icon: dashboardPanelIcon(key),
        badge: const Tag('Not built yet', warn: true),
        body: const _CertificationsBody(),
      );
    }

    final panel = _panels[key];

    if (panel == null) return null;

    final data = panel['data'] as Map<String, dynamic>;

    final body = switch (key) {
      'hr.headcount' => _headcount(context, data),
      'hr.onboardings_in_flight' => _onboardings(context, data),
      'hr.invitations_stalled' => _invitations(context, data),
      'hr.starters_and_leavers' => _startersAndLeavers(context, data),
      // A widget this tab lists but has no body for. Skipped rather than drawn
      // blank, which is the same answer the server gives a widget it cannot
      // resolve.
      _ => null,
    };

    if (body == null) return null;

    return DashboardPanel(
      key: key,
      title: panel['label'] as String,
      icon: dashboardPanelIcon(key),
      body: body,
    );
  }

  // ── the panels ───────────────────────────────────────────────

  Widget _headcount(BuildContext context, Map<String, dynamic> data) {
    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;

    // How many places this view covers, which is what the sentence below is
    // about. Read rather than counted off `rows`: `rows` is the list to DRAW,
    // not a list of places — "Not assigned to a place" is one of its rows and
    // is not a place — so the server is the side that can answer this.
    final places = data['places'] as int;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DashboardBigNumber('$total', total == 1 ? 'person' : 'people'),
        const SizedBox(height: 12),
        if (places == 0)
          const DashboardNote(
            'Nowhere to count yet. Add a company and a branch under '
            'Organization, and each one will appear here with its own '
            'headcount.',
          )
        else ...[
          for (final row in rows)
            DashboardCountRow(
              label: row['title'] as String,
              value: '${row['total']}',
            ),

          // Said out loud, because the rows genuinely will not add up to the
          // total for anybody working in more than one place — and a reader who
          // spots that without an explanation concludes the page is broken.
          if (total < _sum(rows)) ...[
            const SizedBox(height: 8),
            const DashboardNote(
              'Somebody working in more than one place is counted in each of '
              'them, so the list adds up to more than the total.',
            ),
          ],
        ],
      ],
    );
  }

  Widget _onboardings(BuildContext context, Map<String, dynamic> data) {
    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;

    if (total == 0) {
      return const DashboardNote(
        'No paperwork is under way. When you invite somebody and choose the '
        'contract they are joining on, their form appears here until both '
        'sides have signed it.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DashboardBigNumber('$total', 'unfinished'),
        const SizedBox(height: 12),
        for (final row in rows)
          DashboardCountRow(
            label: row['title'] as String,
            value: '${row['total']}',
          ),
        if (data['oldest_started_at'] != null) ...[
          const SizedBox(height: 8),
          DashboardNote(
            'The longest has been open since '
            '${formatStamp(data['oldest_started_at'], 'an unknown date', formats: widget.session.me!.display)}.',
          ),
        ],
      ],
    );
  }

  Widget _invitations(BuildContext context, Map<String, dynamic> data) {
    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = data['total'] as int;
    final sent = data['sent_total'] as int;

    if (total == 0) {
      // Two different kinds of nothing, and they need different sentences: one
      // is good news, the other means this organization has not started.
      return DashboardNote(
        sent == 0
            ? 'You have not invited anybody yet. Invitations are how somebody '
                  'gets an account — start one from the Invitations tab and '
                  'its progress shows up here.'
            : 'Nothing has stalled. Every invitation sent is either still live '
                  'or has been accepted.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DashboardBigNumber(
          '$total',
          total == 1 ? 'needs chasing' : 'need chasing',
        ),
        const SizedBox(height: 12),
        for (final row in rows)
          DashboardCountRow(
            label: row['title'] as String,
            value: '${row['total']}',
          ),
        const SizedBox(height: 8),
        const DashboardNote(
          'An expired invitation needs sending again — the link in the first '
          'one no longer works. Open Invitations to resend it.',
        ),
      ],
    );
  }

  Widget _startersAndLeavers(BuildContext context, Map<String, dynamic> data) {
    final people = (data['people'] as List<dynamic>).cast<Map<String, dynamic>>();
    final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
    final days = data['days'] as int;
    final more = data['more_people'] as int;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final row in rows)
          DashboardCountRow(
            // Which rows the window covers is the server's answer, not this
            // tab's — and it is composed by the same function the main
            // dashboard uses, so the identical figure cannot be labelled "in
            // the last 30 days" here and left bare over there.
            label: dashboardRowLabel(data, row),
            value: '${row['total']}',
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
          DashboardNote(
            'Nobody has joined in the last $days days. Somebody appears here '
            'on the day they accept their invitation.',
          ),
        ],

        const SizedBox(height: 8),
        // The asymmetry, stated rather than hidden. "No longer active" has no
        // period because nothing records the DAY somebody was deactivated —
        // saying so is better than implying the count covers the window above.
        const DashboardNote(
          'People who have left are counted in total rather than over a '
          'period: the app records that an account was switched off, not the '
          'day it happened.',
        ),
      ],
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
///
/// It is the BODY only. The card around it — the badge, the header, the border,
/// the minimum height — is DashboardCard's, like every other panel on this tab
/// and on the main dashboard, because two card implementations was the other
/// half of the bug this replaced.
class _CertificationsBody extends StatelessWidget {
  const _CertificationsBody();

  @override
  Widget build(BuildContext context) {
    return const DashboardNote(
      'A contract can already ask for a date, but nothing yet marks one as an '
      'expiry — so there is no way to tell a licence that runs out from a date '
      'of birth. When contract fields can carry an expiry, the ones lapsing '
      'soonest will be listed here.',
    );
  }
}
