import 'package:flutter/material.dart';
import 'error_note.dart';

/// Loading / failed / loaded, in one place.
///
/// Every control-plane screen is the same shape: fetch a list, show a spinner,
/// show the server's message if it fails, otherwise show the content. Four
/// copies of that would be four chances for one screen to swallow an error and
/// render an empty list that looks like "no results".
///
/// ── What [loading] means ─────────────────────────────────────────
///
/// It means "there is nothing on screen yet", NOT "a request is in flight".
/// Every screen used to set its flag true at the top of every fetch, so saving
/// one field tore the whole page down to this spinner and rebuilt it from a
/// fresh request — the owner's complaint that the app "reloads for every
/// change". A refresh after a save, a delete or a toggle keeps what is already
/// rendered and swaps it when the new data lands. Only a first load and a retry
/// after a failure have nothing to preserve, and those are the two cases where
/// a spinner is the honest thing to draw.
///
/// Each caller works out which case it is in by asking whether it is holding
/// anything — typically `_loading = <what it renders>.isEmpty` at the top of
/// its `_load()`. That is deliberately not a `quietly:` parameter threaded
/// through every call site: the screen already knows, and a flag somebody has
/// to remember to pass is a flag somebody will forget.
class AsyncBody extends StatelessWidget {
  const AsyncBody({
    super.key,
    required this.loading,
    required this.error,
    required this.onRetry,
    required this.child,
  });

  final bool loading;
  final String? error;
  final VoidCallback onRetry;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (error != null) {
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ErrorNote(error!),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
            ],
          ),
        ),
      );
    }

    return child;
  }
}

/// A screen heading with an optional action on the right.
class PanelHeader extends StatelessWidget {
  const PanelHeader({super.key, required this.title, required this.note, this.action});

  final String title;
  final String note;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 4),
                Text(note, style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
          ),
          if (action != null) ...[const SizedBox(width: 16), action!],
        ],
      ),
    );
  }
}
