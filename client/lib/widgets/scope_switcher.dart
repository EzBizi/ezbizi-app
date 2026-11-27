import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import 'logo_badge.dart';
import 'scope_picker_panel.dart';

/// Which part of the business you are looking at (plan §3 roll-ups).
///
/// ── What choosing one actually does ──────────────────────────────
///
/// It narrows everything at once — the dashboard's numbers, the lists, what an
/// export contains — and it decides where new work is created. Somebody
/// standing in Connecticut cannot book a job in Texas, because from there Texas
/// does not exist.
///
/// A company includes its Sub Companies; a Sub Company never includes its
/// parent. Roll-ups go up.
///
/// ── Hidden when there is nothing to choose ───────────────────────
///
/// Somebody who can reach exactly one franchise does not get a switcher — the
/// app simply IS that franchise for them. A control with one option is a
/// control that only teaches people it does nothing.
class ScopeSwitcher extends StatelessWidget {
  const ScopeSwitcher({
    super.key,
    required this.api,
    required this.session,
    this.block = false,
  });

  final ApiClient api;
  final Session session;

  /// Full width, the chevron at the right end: the phone's own row.
  final bool block;

  /// The current view's logo, sized for the top bar.
  ///
  /// Separate from the switcher rather than tucked inside it. At 22px behind a
  /// chip's fill it was barely visible, which defeats the point: the mark is
  /// the fastest way to tell two Connecticuts apart, and it only works if it
  /// reads before the words do.
  ///
  /// No border and no fill — a logo is artwork, and boxing it makes it look
  /// like a button that does nothing.
  static Widget logo(ApiClient api, Session session, {double size = 30}) {
    final scope = session.me!.scope;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: LogoBadge(
        // Keyed by path, so switching company swaps the mark rather than
        // leaving the previous one until something else rebuilds.
        key: ValueKey('${scope.logoPath}?${scope.logoVersion}'),
        api: api,
        path: scope.logoPath,
        version: scope.logoVersion,
        hasOwn: false,
        mayEdit: false,
        bare: true,
        aspect: scope.logoAspect,
        size: size,
        fallbackIcon: _iconFor(scope.type),
      ),
    );
  }

  /// Is there anywhere else to stand? One franchise and no company to roll up
  /// into means there is not, and this draws nothing.
  ///
  /// Exposed because the top bar needs the same answer: on a phone it drops the
  /// wordmark and the company logo and lets this control name the business, so
  /// it has to know when this control will not be there to do it. One
  /// definition with two callers, rather than the condition copied into the
  /// shell where the two could drift apart and leave a phone bar identifying
  /// nothing at all.
  static bool hasChoices(Me me) =>
      me.switchableFranchises.length > 1 || me.switchableCompanies.length > 1;

  @override
  Widget build(BuildContext context) {
    final me = session.me!;

    if (!hasChoices(me)) return const SizedBox.shrink();

    final scheme = Theme.of(context).colorScheme;

    return InkWell(
      borderRadius: BorderRadius.circular(8),
      // A panel rather than a dropdown. The dropdown listed every company,
      // sub company and franchise in one run — already taller than the window
      // at four companies, and unusable at twenty. See ScopePickerPanel: the
      // list stays a tree and search does the scaling.
      onTap: () => showScopePicker(context, api: api, session: session),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: scheme.outlineVariant.withValues(alpha: 0.6),
          ),
        ),
        child: Row(
          mainAxisSize: block ? MainAxisSize.max : MainAxisSize.min,
          children: [
            // Flexible: a fixed 300 ran under the top bar's Clock Out chip on a
            // phone (phone review 2026-11-13, item 2). It ellipsises instead.
            Flexible(
              fit: block ? FlexFit.tight : FlexFit.loose,
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxWidth: block ? double.infinity : 300,
                ),
                child: Tooltip(
                  message: me.scope.fullPath,
                  child: Text(
                    // The company AND the place. "Connecticut" alone named one of
                    // two different places with different data behind each.
                    me.scope.shortPath,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 6),
            Icon(Icons.unfold_more, size: 15, color: scheme.onSurfaceVariant),
          ],
        ),
      ),
    );
  }

  static IconData _iconFor(String type) => switch (type) {
    'company' => Icons.apartment_outlined,
    'franchise' => Icons.storefront_outlined,
    _ => Icons.hub_outlined,
  };
}
