import 'package:flutter/material.dart';

import 'machine_detail.dart';

import '../../core/theme.dart';

/// One machine on the PC Specs grid: what it is called, who sits at it, and
/// how hard it is working.
///
/// ── Why a meter and not a number ──────────────────────────────────
///
/// The roster's question is "is anything wrong", asked across twenty machines
/// at once. "63%" answers that only after you have read it and compared it to
/// a threshold you are holding in your head; a bar that is two thirds full and
/// amber answers it at a glance and in peripheral vision. The number stays,
/// on the right, for when the answer is "yes, and by how much".
///
/// ── Why the colour is never the only signal ───────────────────────
///
/// Same rule the status bolt follows: the percentage is always written out
/// beside the bar, so nothing here depends on telling amber from red. The
/// colour is the fast path, not the channel.
class MachineCard extends StatelessWidget {
  const MachineCard({
    super.key,
    required this.title,
    required this.person,
    required this.online,
    required this.seenLabel,
    this.reserveActivityLine = false,
    this.module,
    this.sessions,
    this.cpuPercent,
    this.ramPercent,
    this.gpuPercent,
    this.needsAttention = false,
    this.diskPercent,
    this.diskFreeLabel,
    this.activeWindow,
    this.onTap,
    this.selected = false,
  });

  /// The machine's own name where it reports one, else the device's label.
  final String title;

  /// Who is signed in on it.
  final String person;

  /// The tab they are on right now - "Leads", "Scheduling" - or null when
  /// they are offline or have not opened one. The old roster showed this in
  /// its header and it is the second question a card gets asked after "is
  /// this person on", so it stays on the card rather than only in the panel.
  ///
  /// Rendered as its own Text with the roster's own "on X" wording, NOT
  /// glued into the person's name: one person is one string somebody may be
  /// looking for, and "Marco Reyes - Jobs" is not that string.
  final String? module;

  /// "1 active - 20 earlier", the roster's own words for how many sessions
  /// this person has. Carried over from the tile subtitle the grid replaced:
  /// somebody signed in from four machines over a week is a fact a card that
  /// shows only the newest one would otherwise hide completely.
  final String? sessions;

  final bool online;

  /// "just now" / "3m ago" — already formatted by the roster's [seenAgo].
  final String seenLabel;

  /// Whether to keep a line's height at the foot of the card for
  /// [activeWindow], even on a card that has none. Set by the grid for every
  /// card at once — see the comment at its use.
  final bool reserveActivityLine;

  /// Null where the agent has not reported one — rendered as "—", never as
  /// zero. A machine that has not said how busy it is has not said it is idle.
  final int? cpuPercent;
  final int? ramPercent;

  /// Whether Windows says this machine's defences want looking at — see
  /// [securityNeedsAttention] for which states count and, more importantly,
  /// which deliberately do not.
  ///
  /// It earns a place on the CARD rather than only in the panel because the
  /// question it answers is "is anything wrong across the room", and finding
  /// one unhealthy machine among forty-two by opening forty-two panels is not
  /// answering it. The badge appears ONLY when something is wrong: a badge
  /// confirming that nothing is wrong would sit on forty-one cards saying
  /// nothing, which is how people learn to stop reading badges.
  final bool needsAttention;

  /// Graphics load. Absent on a machine whose counters name no 3D engine, and
  /// on the agent's first read after launch — rendered "—" either way, for the
  /// same reason as [cpuPercent].
  final int? gpuPercent;
  final int? diskPercent;

  /// "858 GB free", shown beside the disk meter where it is known.
  final String? diskFreeLabel;

  /// What is in front on that machine, where the viewer may see it at all.
  final String? activeWindow;

  final VoidCallback? onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Card(
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: selected ? scheme.primary : EzBiziTheme.edge(context),
          width: selected ? 2 : 1,
        ),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // The flexes change ONLY when a second badge exists.
                  //
                  // With one badge the header has always fitted, and bounding
                  // the badge area unconditionally would reserve a slice of
                  // every card for a badge that forty-one of forty-two do not
                  // have. With two, the badges need a bounded width or they
                  // have nowhere to go and the row overflows — which is what
                  // it did, by 12 pixels, the first time this rendered.
                  Expanded(
                    flex: needsAttention ? 3 : 1,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // The PERSON leads, the machine sits under them.
                        // A roster is read to find somebody: "is Toula on,
                        // and what is she doing" is the question, and the
                        // machine's name is how the answer is qualified, not
                        // what is being looked up. TOULA and DESKTOP-7H4RHPQ
                        // are the same fact wearing different names, and only
                        // one of them is a name anybody scans for.
                        Text(
                          person,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall,
                        ),
                        Row(
                          children: [
                            Flexible(
                              child: Text(
                                title,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.labelSmall,
                              ),
                            ),
                            if (module != null && module!.isNotEmpty)
                              Flexible(
                                child: Text(
                                  ' · on $module',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: theme.textTheme.labelSmall,
                                ),
                              ),
                          ],
                        ),
                        if (sessions != null && sessions!.isNotEmpty)
                          Text(
                            sessions!,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Flexible(
                    // flex 0 is laid out unbounded, i.e. exactly as before.
                    flex: needsAttention ? 2 : 0,
                    child: Wrap(
                      alignment: WrapAlignment.end,
                      spacing: 4,
                      runSpacing: 4,
                      children: [
                        _Badge(
                          text: online ? 'Live' : seenLabel,
                          tone: online ? _Tone.good : _Tone.muted,
                        ),
                        if (needsAttention)
                          const _Badge(
                            text: securityNeedsAttentionLabel,
                            tone: _Tone.bad,
                          ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              MeterBar(label: 'CPU', percent: cpuPercent),
              const SizedBox(height: 6),
              MeterBar(label: 'RAM', percent: ramPercent),
              const SizedBox(height: 6),
              MeterBar(label: 'GPU', percent: gpuPercent),
              const SizedBox(height: 6),
              MeterBar(
                label: 'Disk',
                percent: diskPercent,
                trailing: diskFreeLabel,
              ),
              // Reserved rather than conditional, so every card in a run is
              // the same height. A grid whose cards differ by one line looks
              // broken in a way that reads as a rendering fault rather than
              // as "this machine reported less" — and the cards that came up
              // short were exactly the offline ones, which made being offline
              // look like a layout bug.
              //
              // [reserveActivityLine] is false when NOTHING in the grid has a
              // window to show (nobody is online, or this viewer may not see
              // screen content at all), because then the row is not carrying
              // a difference between cards — it is dead space on every one.
              // Shown whenever there IS one; the flag only decides whether a
              // card WITHOUT one still holds the space. Gating the display on
              // it too — which is what this line said first — hid the window
              // on any card whose grid had not asked for the reservation.
              if (reserveActivityLine ||
                  (activeWindow != null && activeWindow!.isNotEmpty)) ...[
                const SizedBox(height: 10),
                Text(
                  activeWindow ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// One labelled load bar. [percent] null renders an empty track and "—":
/// there is no reading, which is a different fact from a reading of zero.
class MeterBar extends StatelessWidget {
  const MeterBar({
    super.key,
    required this.label,
    required this.percent,
    this.trailing,
  });

  final String label;
  final int? percent;

  /// Extra fact for the right-hand side, e.g. "858 GB free".
  final String? trailing;

  /// Where a meter stops being green and where it starts being a problem.
  ///
  /// Two thresholds rather than one because a single one forces a bar to call
  /// 64% either fine or broken, and neither is true — see
  /// EzBiziTheme.meterWarning.
  static const int amberAt = 65;
  static const int redAt = 85;

  static Color colourFor(BuildContext context, int percent) {
    if (percent >= redAt) return Theme.of(context).colorScheme.error;
    if (percent >= amberAt) return EzBiziTheme.meterWarning(context);
    return EzBiziTheme.clockIn(context);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final value = percent;
    final known = value != null;

    final right = <String>[
      if (known) '$value%' else '—',
      if (trailing != null && trailing!.isNotEmpty) trailing!,
    ].join(' · ');

    return Row(
      children: [
        SizedBox(
          width: 34,
          child: Text(label, style: theme.textTheme.labelSmall),
        ),
        Expanded(
          child: ClipRRect(
            borderRadius: BorderRadius.circular(3),
            child: LinearProgressIndicator(
              value: known ? (value.clamp(0, 100)) / 100 : 0,
              minHeight: 6,
              backgroundColor: theme.colorScheme.surfaceContainerHighest,
              valueColor: AlwaysStoppedAnimation<Color>(
                known
                    ? colourFor(context, value)
                    : theme.colorScheme.outlineVariant,
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        // Fixed width so twenty cards line their numbers up in a column
        // instead of each bar ending somewhere different.
        SizedBox(
          width: 96,
          child: Text(
            right,
            textAlign: TextAlign.right,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall,
          ),
        ),
      ],
    );
  }
}

enum _Tone { good, muted, bad }

class _Badge extends StatelessWidget {
  const _Badge({required this.text, required this.tone});

  final String text;
  final _Tone tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colour = switch (tone) {
      _Tone.good => EzBiziTheme.clockIn(context),
      _Tone.bad => theme.colorScheme.error,
      _Tone.muted => theme.colorScheme.onSurfaceVariant,
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: colour.withValues(alpha: 0.5)),
      ),
      child: Text(
        text,
        style: theme.textTheme.labelSmall?.copyWith(color: colour),
      ),
    );
  }
}
