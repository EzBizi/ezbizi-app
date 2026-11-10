import 'package:flutter/material.dart';
import '../core/session.dart';

/// The account facts, none of which this person can change: email, role,
/// organization, and the places they may act on.
///
/// Split out of [IdentityCard], where they sat directly above its Save button
/// and so read as things Save applied to. It does not — an administrator
/// changes every one of them in Settings, next door.
///
/// There is no footer, and that is the whole signal: nothing on this card can
/// be saved, so nothing offers to save it. The same rule the Phone settings
/// panes already follow.
class AccountFactsCard extends StatelessWidget {
  const AccountFactsCard({
    super.key,
    required this.session,
    this.dense = false,
  });

  final Session session;

  /// Stack each label above its value instead of setting it beside.
  ///
  /// For the narrow right-hand column, where a 130px label gutter leaves the
  /// value about eight characters of its own.
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final me = session.me;
    if (me == null) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Your account', style: theme.textTheme.titleMedium),
            const SizedBox(height: 2),
            Text(
              'Assigned to you. An administrator changes these in Settings.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 16),

            _fact(
              context,
              'Email',
              Text(me.email),
              'Your sign-in address and where a password reset goes. Changing '
                  'it needs a confirmation email, which is not wired up yet.',
            ),
            if (me.roleName != null)
              _fact(context, 'Role', Text(me.roleName!), null),
            _fact(context, 'Organization', Text(me.organization.name), null),
            if (me.switchableFranchises.isNotEmpty)
              _fact(
                context,
                'You can act on',
                _places(context, me),
                'Permissions decide WHAT you may do; this decides WHERE.',
              ),
          ],
        ),
      ),
    );
  }

  /// The places, as chips, with the company added to any name that repeats.
  ///
  /// Two companies can each operate a Connecticut — session.dart says so at
  /// [ScopeSummary] — so the name alone does not name one place. Unqualified,
  /// the list reads "Connecticut, DMV, Georgia, … Connecticut, DMV,
  /// Connecticut", which looks like the same place listed four times. It is
  /// four different places, but a list that appears to repeat itself is one
  /// nobody trusts the rest of, and this one answers "where am I allowed to
  /// work".
  ///
  /// Only the repeats are qualified. Adding the company to every chip would
  /// double the width of a list that is usually unambiguous.
  Widget _places(BuildContext context, Me me) {
    // Switched-off places are not listed (Jose, 2026-11-10).
    final places = me.switchableFranchises;
    final count = <String, int>{};
    for (final f in places) {
      count[f.name] = (count[f.name] ?? 0) + 1;
    }
    final companyName = {for (final c in me.companies) c.id: c.name};

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final f in places)
          Chip(
            label: Text(
              (count[f.name] ?? 0) > 1 && companyName[f.companyId] != null
                  ? '${f.name} · ${companyName[f.companyId]}'
                  : f.name,
              style: const TextStyle(fontSize: 12),
            ),
            visualDensity: VisualDensity.compact,
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
          ),
      ],
    );
  }

  Widget _fact(
    BuildContext context,
    String label,
    Widget value,
    String? note,
  ) {
    final theme = Theme.of(context);
    final labelText = Text(
      label,
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (dense) ...[
            labelText,
            const SizedBox(height: 3),
            value,
          ] else
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(width: 130, child: labelText),
                Expanded(child: value),
              ],
            ),
          if (note != null)
            Padding(
              padding: EdgeInsets.only(left: dense ? 0 : 130, top: 4),
              child: Text(
                note,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
