import 'package:flutter/material.dart';

/// Which one-time tips this person has dismissed, and how to record another.
///
/// Set by the shell from the `callouts.seen` preference once /me loads; until
/// then [ready] is false and no callout draws, so a tip never flashes up for
/// someone who already dismissed it (and tests that never sign in see none).
class Callouts {
  Callouts._();

  static final ValueNotifier<Set<String>> seen = ValueNotifier(const {});
  static bool ready = false;
  static void Function(String id)? onDismiss;

  static void dismiss(String id) {
    seen.value = {...seen.value, id};
    onDismiss?.call(id);
  }
}

/// A small "New: …" tip anchored under [child], gone for good after "Got it"
/// (monday port, desk 2026-11-09).
class Callout extends StatefulWidget {
  const Callout({
    super.key,
    required this.id,
    required this.text,
    required this.child,
  });

  /// Stable slug stored in `callouts.seen`, e.g. `list_toolbar`.
  final String id;
  final String text;
  final Widget child;

  @override
  State<Callout> createState() => _CalloutState();
}

class _CalloutState extends State<Callout> {
  final _link = LayerLink();
  final _portal = OverlayPortalController();

  @override
  void initState() {
    super.initState();
    Callouts.seen.addListener(_sync);
    WidgetsBinding.instance.addPostFrameCallback((_) => _sync());
  }

  @override
  void dispose() {
    Callouts.seen.removeListener(_sync);
    super.dispose();
  }

  void _sync() {
    if (!mounted) return;
    final show = Callouts.ready && !Callouts.seen.value.contains(widget.id);
    show ? _portal.show() : _portal.hide();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return CompositedTransformTarget(
      link: _link,
      child: OverlayPortal(
        controller: _portal,
        overlayChildBuilder: (context) => CompositedTransformFollower(
          link: _link,
          targetAnchor: Alignment.bottomLeft,
          offset: const Offset(0, 6),
          child: Align(
            alignment: Alignment.topLeft,
            child: Material(
              key: Key('callout-${widget.id}'),
              elevation: 3,
              color: scheme.inverseSurface,
              borderRadius: BorderRadius.circular(8),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 6, 4, 6),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 260),
                      child: Text(
                        widget.text,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onInverseSurface,
                        ),
                      ),
                    ),
                    TextButton(
                      key: Key('callout-${widget.id}-got-it'),
                      onPressed: () => Callouts.dismiss(widget.id),
                      style: TextButton.styleFrom(
                        foregroundColor: scheme.inversePrimary,
                      ),
                      child: const Text('Got it'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
        child: widget.child,
      ),
    );
  }
}
