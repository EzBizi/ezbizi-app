import 'package:flutter/material.dart';
import 'error_note.dart';
import 'loading_skeleton.dart';

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
    this.skeleton = false,
  });

  final bool loading;
  final String? error;
  final VoidCallback onRetry;
  final Widget child;

  /// Grey rows instead of the spinner, for a load that takes seconds.
  final bool skeleton;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      if (skeleton) return const LoadingSkeleton();
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
              OutlinedButton(
                onPressed: onRetry,
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    return child;
  }
}

/// A list screen's header over its list. On a phone the header scrolls away
/// with the list; on anything larger it stays put above it.
///
/// Measured on a 411px Android phone: Customers' note, buttons, search and
/// filters stood over the list and took 470 of the 800 pixels under the app
/// bar, and Fleet's nine chips took 370. Only what was left scrolled.
///
/// [list] must not bring its own ScrollController: NestedScrollView reaches it
/// through the PrimaryScrollController, which is re-offered below for every
/// platform because Flutter only hands it down automatically on mobile ones,
/// and a narrow desktop window takes this path too.
class HeaderAboveList extends StatelessWidget {
  const HeaderAboveList({super.key, required this.header, required this.list});

  final Widget header;
  final Widget list;

  @override
  Widget build(BuildContext context) {
    // The window's width, not this widget's: a desktop screen beside the
    // navigation rail is narrower than 600 and is not short of height.
    if (MediaQuery.sizeOf(context).width >= 600) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          header,
          Expanded(child: list),
        ],
      );
    }

    // Floating: any scroll back up brings the header back. Scrolled with the
    // body alone, a short section (HR's Pay, one card) left an empty screen
    // with the name, Back and the section tabs all gone (phone audit
    // 2026-11-04, Section 3 #5).
    return NestedScrollView(
      floatHeaderSlivers: true,
      headerSliverBuilder: (context, _) => [SliverFloatingHeader(child: header)],
      body: Builder(
        builder: (context) => PrimaryScrollController(
          controller: PrimaryScrollController.of(context),
          automaticallyInheritForPlatforms: TargetPlatform.values.toSet(),
          child: list,
        ),
      ),
    );
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
  const PanelHeader({super.key, this.title, required this.note, this.action});

  /// The heading, or null when something above has already said it.
  ///
  /// Optional since the shell began drawing every module's name at the top of
  /// the page. Fifteen call sites were repeating what was already on screen —
  /// four the module's own name ("Customers" under "Customers"), eleven their
  /// own tab's label ("Records" under a highlighted Records chip). The NOTE is
  /// what those headers were really carrying: it holds the live count and the
  /// sentence explaining the list, and it is never redundant.
  final String? title;
  final String note;
  final Widget? action;

  /// Below this the action goes under the heading rather than beside it.
  static const double _besideAbove = 620;

  @override
  Widget build(BuildContext context) {
    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null) ...[
          Text(title!, style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 4),
        ],
        Text(note, style: Theme.of(context).textTheme.bodyMedium),
      ],
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 8, 24, 12),
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
                constraints: BoxConstraints(
                  maxWidth: constraints.maxWidth * 0.55,
                ),
                child: action!,
              ),
            ],
          );
        },
      ),
    );
  }
}
