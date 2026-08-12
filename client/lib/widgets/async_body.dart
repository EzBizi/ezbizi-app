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
///
/// ── Why the action drops below on a narrow screen ────────────────
///
/// R7: this has to work on a phone, and as a plain Row it did not. A button
/// keeps its intrinsic width in a Row and `Expanded` takes what is left, so at
/// 375px the paragraph beside it was laid out into a column a few characters
/// wide. Measured, on the Contracts tab: a header 1270px tall inside a 900px
/// window, overflowing by 370 and taking the list with it. Every screen using
/// this had the same header, so every one of them was broken at that width.
///
/// Below the threshold the action goes under the text and gets the full width
/// instead. That is a real breakpoint rather than a guess: it is roughly where
/// the note stops having room for a readable line beside a button.
class PanelHeader extends StatelessWidget {
  const PanelHeader({super.key, required this.title, required this.note, this.action});

  final String title;
  final String note;
  final Widget? action;

  /// Below this the action goes under the heading rather than beside it.
  static const double _besideAbove = 620;

  @override
  Widget build(BuildContext context) {
    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.headlineMedium),
        const SizedBox(height: 4),
        Text(note, style: Theme.of(context).textTheme.bodyMedium),
      ],
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 12),
      child: LayoutBuilder(
        builder: (context, constraints) {
          if (action == null) return text;

          if (constraints.maxWidth < _besideAbove) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                text,
                const SizedBox(height: 12),
                // Aligned to the start, so it reads as the next thing to do
                // rather than as a control floating off the right edge.
                Align(alignment: Alignment.centerLeft, child: action!),
              ],
            );
          }

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: text),
              const SizedBox(width: 16),
              // ── The action has to be BOUNDED, not merely placed ──
              //
              // A non-flex child of a Row is handed unbounded width. For a
              // single button that is harmless — it takes its intrinsic width
              // and `Expanded` gets the rest. For an action that can WRAP it is
              // the whole bug: a Wrap given infinite width never wraps, so the
              // Customers screen's three controls laid out at their full 571px
              // in one line and squeezed the note to 64px, 787px tall inside a
              // 600px window. The stack-below-620 rule did not catch it because
              // 700px is above 620.
              //
              // A cap rather than `Flexible`: two flex children split the free
              // space by their factors, so `Flexible` would hold the action to
              // half the header even on a wide screen where the note wants the
              // room and one button needs 150px. `maxWidth` only ever binds
              // when the action is genuinely wide, and then it is what lets the
              // Wrap do its job.
              //
              // 55% rather than half. The action is the denser content — three
              // controls against a paragraph — and a note reading two words a
              // line is the failure being fixed, so the tie goes to the text
              // only after the action has what it needs.
              ConstrainedBox(
                constraints: BoxConstraints(maxWidth: constraints.maxWidth * 0.55),
                child: action!,
              ),
            ],
          );
        },
      ),
    );
  }
}
