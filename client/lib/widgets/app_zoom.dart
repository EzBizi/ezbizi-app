import 'dart:async';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/client_platform.dart';
import '../core/session.dart';

/// Zoom for the desktop apps (Kimberly 01a213cf, approved by Jose
/// 2026-11-25): Ctrl/Cmd + and -, Ctrl/Cmd 0 to reset, Ctrl and the mouse
/// wheel. The whole window scales, text and layout together, the way a
/// browser zooms: the app is laid out at the window's size divided by the
/// zoom and drawn back up to fill it. The level is the person's preference
/// (`appearance.zoom`), so it follows them to another machine.
///
/// The web keeps the browser's zoom and phones the system text size: there
/// this draws [child] untouched.
class AppZoom extends StatefulWidget {
  const AppZoom({
    super.key,
    required this.session,
    required this.child,
    this.enabled,
  });

  final Session session;
  final Widget child;

  /// Null: on for the desktop apps only. Tests set it.
  final bool? enabled;

  static bool get desktop =>
      const {'windows', 'macos', 'linux'}.contains(ClientPlatform.name);

  /// The levels a key press steps through, as in a browser.
  static const steps = [50, 67, 75, 80, 90, 100, 110, 125, 150, 175, 200];

  @override
  State<AppZoom> createState() => _AppZoomState();
}

class _AppZoomState extends State<AppZoom> {
  bool _toast = false;
  Timer? _hide;

  bool get _on => widget.enabled ?? AppZoom.desktop;

  int get _percent => (widget.session.me?.zoom ?? 1.0) * 100 ~/ 1;

  @override
  void initState() {
    super.initState();
    HardwareKeyboard.instance.addHandler(_key);
    widget.session.addListener(_changed);
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_key);
    widget.session.removeListener(_changed);
    _hide?.cancel();
    super.dispose();
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  static bool get _modifier =>
      HardwareKeyboard.instance.isControlPressed ||
      HardwareKeyboard.instance.isMetaPressed;

  void _set(int percent) {
    if (widget.session.me == null) return;
    final to = percent.clamp(50, 200);
    if (to != _percent) {
      unawaited(widget.session.setPreference('appearance.zoom', to));
    }
    setState(() => _toast = true);
    _hide?.cancel();
    _hide = Timer(const Duration(seconds: 2), () {
      if (mounted) setState(() => _toast = false);
    });
  }

  void _step(int by) {
    final now = _percent;
    final next = by > 0
        ? AppZoom.steps.firstWhere((s) => s > now, orElse: () => 200)
        : AppZoom.steps.lastWhere((s) => s < now, orElse: () => 50);
    _set(next);
  }

  bool _key(KeyEvent event) {
    if (!_on || event is KeyUpEvent || !_modifier) return false;
    final key = event.logicalKey;
    if (key == LogicalKeyboardKey.equal ||
        key == LogicalKeyboardKey.add ||
        key == LogicalKeyboardKey.numpadAdd) {
      _step(1);
      return true;
    }
    if (key == LogicalKeyboardKey.minus ||
        key == LogicalKeyboardKey.numpadSubtract) {
      _step(-1);
      return true;
    }
    if (key == LogicalKeyboardKey.digit0 || key == LogicalKeyboardKey.numpad0) {
      _set(100);
      return true;
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    if (!_on) return widget.child;
    final z = _percent / 100;
    final theme = Theme.of(context);
    final base = ScrollConfiguration.of(context);
    // The same tree at every level, 100% included, so zooming never
    // rebuilds the app from scratch (and loses what is open).
    return Stack(
      children: [
        Listener(
          onPointerSignal: (event) {
            if (event is PointerScrollEvent && _modifier) {
              GestureBinding.instance.pointerSignalResolver.register(
                event,
                (e) => _step(
                  (e as PointerScrollEvent).scrollDelta.dy < 0 ? 1 : -1,
                ),
              );
            }
          },
          // With Ctrl held, the wheel is the zoom's: lists stand still
          // rather than scroll under it.
          child: ScrollConfiguration(
            behavior: base.copyWith(
              physics: _StillWhileZooming(
                parent: base.getScrollPhysics(context),
              ),
            ),
            child: LayoutBuilder(
              builder: (context, box) {
                final size = Size(box.maxWidth / z, box.maxHeight / z);
                final mq = MediaQuery.of(context);
                return ClipRect(
                  child: Transform.scale(
                    key: const ValueKey('app-zoom'),
                    scale: z,
                    alignment: Alignment.topLeft,
                    child: OverflowBox(
                      alignment: Alignment.topLeft,
                      minWidth: size.width,
                      maxWidth: size.width,
                      minHeight: size.height,
                      maxHeight: size.height,
                      child: MediaQuery(
                        data: mq.copyWith(
                          size: size,
                          padding: mq.padding / z,
                          viewPadding: mq.viewPadding / z,
                          viewInsets: mq.viewInsets / z,
                        ),
                        child: widget.child,
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
        // "Zoom 110% · Reset", outside the zoom so it is the same size at
        // every level.
        if (_toast)
          Positioned(
            left: 0,
            right: 0,
            bottom: 24,
            child: Center(
              child: Material(
                key: const ValueKey('app-zoom-toast'),
                color: theme.colorScheme.inverseSurface,
                borderRadius: BorderRadius.circular(20),
                elevation: 3,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 4, 4),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Zoom $_percent%',
                        style: TextStyle(
                          color: theme.colorScheme.onInverseSurface,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(width: 4),
                      TextButton(
                        key: const ValueKey('app-zoom-reset'),
                        onPressed: _percent == 100 ? null : () => _set(100),
                        style: TextButton.styleFrom(
                          foregroundColor: theme.colorScheme.inversePrimary,
                        ),
                        child: const Text('Reset'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// A list refuses the wheel while Ctrl or Cmd is held, so Ctrl and the wheel
/// zoom instead of scrolling the page under the pointer.
class _StillWhileZooming extends ScrollPhysics {
  const _StillWhileZooming({super.parent});

  @override
  _StillWhileZooming applyTo(ScrollPhysics? ancestor) =>
      _StillWhileZooming(parent: buildParent(ancestor));

  @override
  bool shouldAcceptUserOffset(ScrollMetrics position) =>
      !_AppZoomState._modifier && super.shouldAcceptUserOffset(position);
}
