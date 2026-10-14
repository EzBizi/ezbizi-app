import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import 'memberships_members.dart';

/// Customers › Memberships › Dashboard (UI queue 52, desktop 1 and phone 4).
///
/// Counted here from GET /memberships, the same rows Members draws, so the two
/// tabs cannot disagree: who is a member this year, who renews in the next 30
/// days, who lapsed, then the renewals coming up by name and the members per
/// plan. Money only for a role with `jobs.financials`, as everywhere in the
/// program.
class MembershipsDashboard extends StatefulWidget {
  const MembershipsDashboard({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsDashboard> createState() => _MembershipsDashboardState();
}

class _MembershipsDashboardState extends State<MembershipsDashboard> {
  bool _loading = true;
  String? _error;
  List<Membership> _rows = const [];

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/memberships');
    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      if (response.ok) _rows = Membership.listFrom(response.body);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final active = _rows.where((r) => r.status == 'active').toList();
    final soon = _rows.where((r) => r.renewsSoon).toList()
      ..sort((a, b) => a.endsOn!.compareTo(b.endsOn!));
    final lapsed = _rows.where((r) => r.status == 'expired').length;
    final renewals = active.where((r) => r.enrollment == 'renewal').length;
    final currency = _rows.firstOrNull?.currency ?? 'USD';
    int sum(Iterable<int?> values) =>
        values.fold<int>(0, (total, v) => total + (v ?? 0));

    final byPlan = <String, int>{};
    for (final row in active) {
      byPlan[row.planName] = (byPlan[row.planName] ?? 0) + 1;
    }
    final plans = byPlan.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    final most = plans.isEmpty ? 1 : plans.first.value;

    final narrow = MediaQuery.sizeOf(context).width < 600;

    if (_rows.isEmpty) {
      return Padding(
        key: const ValueKey('tour-memberships-dashboard'),
        padding: const EdgeInsets.all(24),
        child: Text(
          'No members yet, so there is nothing to count. Members are added on '
          'the Members tab.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListView(
      key: const ValueKey('tour-memberships-dashboard'),
      padding: EdgeInsets.all(narrow ? 16 : 24),
      children: [
        _Figures(
          figures: [
            (
              'Active',
              '${active.length}',
              '${renewals == 1 ? '1 renewal' : '$renewals renewals'} · ${active.length - renewals} new',
              null,
            ),
            (
              'Renewing soon',
              '${soon.length}',
              'in the next 30 days',
              Tone.caution,
            ),
            ('Lapsed', '$lapsed', 'year ended, not renewed', Tone.bad),
            if (_money)
              (
                'Paid this year',
                memberMoney(sum(active.map((r) => r.paidMinor)), currency),
                'of ${memberMoney(sum(active.map((r) => (r.paidMinor ?? 0) + (r.dueMinor ?? 0))), currency)} so far',
                null,
              ),
            if (_money)
              (
                'Est. gross',
                memberMoney(sum(active.map((r) => r.grossMinor)), currency),
                'plans and their average job',
                null,
              ),
          ],
        ),
        const SizedBox(height: 16),
        LayoutBuilder(
          builder: (context, box) {
            // Two across where both fit, one across otherwise.
            final width = box.maxWidth >= 760
                ? (box.maxWidth - 16) / 2
                : box.maxWidth;

            return Wrap(
              spacing: 16,
              runSpacing: 16,
              children: [
                for (final panel in [
                  _Panel(
                    title: 'Renewing in the next 30 days',
                    child: soon.isEmpty
                        ? Text(
                            'Nobody renews in the next 30 days.',
                            key: const ValueKey('tour-memberships-renewing'),
                            style: theme.textTheme.bodySmall,
                          )
                        : Column(
                            key: const ValueKey('tour-memberships-renewing'),
                            children: [
                              for (final row in soon)
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 6,
                                  ),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              row.customerName,
                                              style: theme.textTheme.bodyMedium
                                                  ?.copyWith(
                                                    fontWeight: FontWeight.w700,
                                                  ),
                                            ),
                                            Text(
                                              '${row.planName} · ${_formats.formatDate(row.endsOn!.add(const Duration(days: 1)))} · '
                                              '${row.visitLine(_formats) ?? (row.remindedAt == null ? 'reminder not sent yet' : 'reminder sent')}',
                                              style: theme.textTheme.bodySmall,
                                            ),
                                          ],
                                        ),
                                      ),
                                      if (_money && (row.dueMinor ?? 0) > 0)
                                        Text(
                                          '${memberMoney(row.dueMinor, row.currency)} due',
                                          style: theme.textTheme.bodySmall,
                                        ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                  ),
                  _Panel(
                    title: 'Members by plan',
                    child: plans.isEmpty
                        ? Text(
                            'Nobody is a member this year.',
                            key: const ValueKey('tour-memberships-by-plan'),
                            style: theme.textTheme.bodySmall,
                          )
                        : Column(
                            key: const ValueKey('tour-memberships-by-plan'),
                            children: [
                              for (final plan in plans)
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 4,
                                  ),
                                  child: Row(
                                    children: [
                                      SizedBox(
                                        width: 120,
                                        child: Text(
                                          plan.key,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      Expanded(
                                        child: ClipRRect(
                                          borderRadius: BorderRadius.circular(
                                            5,
                                          ),
                                          child: LinearProgressIndicator(
                                            value: plan.value / most,
                                            minHeight: 10,
                                            backgroundColor: theme
                                                .colorScheme
                                                .surfaceContainerHighest,
                                          ),
                                        ),
                                      ),
                                      SizedBox(
                                        width: 44,
                                        child: Text(
                                          '${plan.value}',
                                          textAlign: TextAlign.end,
                                          style: theme.textTheme.bodyMedium
                                              ?.copyWith(
                                                fontWeight: FontWeight.w700,
                                              ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                  ),
                ])
                  SizedBox(width: width, child: panel),
              ],
            );
          },
        ),
      ],
    );
  }
}

/// Figures in equal tiles, as many across as fit.
class _Figures extends StatelessWidget {
  const _Figures({required this.figures});

  final List<(String, String, String, Tone?)> figures;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        final across = (box.maxWidth / 170).floor().clamp(2, figures.length);
        final width = (box.maxWidth - gap * (across - 1)) / across;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final (label, value, note, tone) in figures)
              SizedBox(
                width: width,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: theme.colorScheme.surface,
                    borderRadius: BorderRadius.circular(
                      EzBiziTheme.controlRadius,
                    ),
                    border: Border.all(color: EzBiziTheme.edge(context)),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          label.toUpperCase(),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelSmall?.copyWith(
                            letterSpacing: 0.8,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          value,
                          style: theme.textTheme.headlineSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: tone == null || value == '0'
                                ? null
                                : ToneColours.of(context, tone).ink,
                          ),
                        ),
                        Text(
                          note,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

/// A titled box.
class _Panel extends StatelessWidget {
  const _Panel({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                letterSpacing: 0.8,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }
}
