import 'package:flutter/material.dart';
import '../core/numbers.dart';
import 'error_note.dart';

/// The foot of a paginated list: how much is not on screen, and the way to ask
/// for it.
///
/// ── Why this is not left to each list ────────────────────────────
///
/// The customers screen learned the whole lesson the hard way and wrote it
/// down: a header claiming a total the list does not render, with no next-page
/// control and nothing saying rows are hidden, reads as data loss rather than
/// as pagination. Its second lesson is here too — a failed LATER page is not
/// the same event as a failed FIRST page, and collapsing them blanks every row
/// already on screen and drops somebody back to the top of a list they were
/// halfway down. So a page error is reported HERE, beside the rows, and the
/// rows stay.
///
/// Two lists in Human Resources page the same way. A third copy of the same
/// footer is how one of them quietly starts saying something different about
/// the same situation.
class LoadMore extends StatelessWidget {
  const LoadMore({
    super.key,
    required this.shown,
    required this.total,
    required this.busy,
    required this.onMore,
    this.error,
    this.groupDigits = false,
  });

  final int shown;
  final int total;

  /// A request is in flight. The control goes dead rather than disappearing: a
  /// second tap appends the same page twice and the list grows duplicates.
  final bool busy;

  /// Why the last attempt at a further page failed, if it did.
  final String? error;

  final VoidCallback onMore;

  /// "1,234 left" rather than "1234 left" (Jobs, Customers, Estimates).
  final bool groupDigits;

  @override
  Widget build(BuildContext context) {
    final left = total - shown;

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (error != null) ...[ErrorNote(error!), const SizedBox(height: 8)],
          TextButton(
            onPressed: busy ? null : onMore,
            child: Text(
              busy
                  ? 'Loading…'
                  : error != null
                  ? 'Try again'
                  : 'Load more (${groupDigits ? formatCount(left) : '$left'} left)',
            ),
          ),
        ],
      ),
    );
  }
}
