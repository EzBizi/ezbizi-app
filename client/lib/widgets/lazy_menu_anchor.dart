import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// A [MenuAnchor] that costs nothing until it is opened.
///
/// A [MenuAnchor] builds its overlay portal, shortcuts, actions, tap region
/// and scopes up front; a Task Board row holds one per label cell, and a
/// screen of rows was thousands of widgets (Jose 11-16: "lagging out or
/// crashing every second"). Until the first open this builds only what
/// [builder] draws; the controller [builder] gets wakes the real anchor and
/// opens it the frame after.
class LazyMenuAnchor extends StatefulWidget {
  const LazyMenuAnchor({
    super.key,
    required this.menuChildren,
    required this.builder,
    this.child,
    this.onOpen,
    this.onClose,
    this.style,
    this.alignmentOffset,
  });

  final List<Widget> menuChildren;
  final MenuAnchorChildBuilder builder;
  final Widget? child;
  final VoidCallback? onOpen;
  final VoidCallback? onClose;
  final MenuStyle? style;
  final Offset? alignmentOffset;

  @override
  State<LazyMenuAnchor> createState() => _LazyMenuAnchorState();

  /// How many are open now: a hover card listens while it shows, and steps
  /// aside for the menu its own click opened (hands-on QA 11-16).
  static final ValueNotifier<int> openCount = ValueNotifier(0);
}

class _LazyMenuAnchorState extends State<LazyMenuAnchor> {
  bool _live = false;
  final _real = MenuController();
  late final _proxy = _WakingController(this);

  /// Esc closes it. Opened from a cell with no focus, the menu never had
  /// the focus its own Esc shortcut needs (hands-on QA 11-16: a status menu
  /// stayed open, the board dimmed, under Esc).
  bool _esc(KeyEvent e) {
    if (e is! KeyDownEvent || e.logicalKey != LogicalKeyboardKey.escape) {
      return false;
    }
    if (!_real.isOpen) return false;
    _real.close();
    return true;
  }

  bool _open = false;

  void _opened() {
    if (!_open) LazyMenuAnchor.openCount.value++;
    _open = true;
    HardwareKeyboard.instance.addHandler(_esc);
    widget.onOpen?.call();
  }

  void _closed() {
    if (_open) LazyMenuAnchor.openCount.value--;
    _open = false;
    HardwareKeyboard.instance.removeHandler(_esc);
    widget.onClose?.call();
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_esc);
    // Gone while open (its row scrolled away or reloaded): still say it
    // closed, or whatever onOpen began (the board's dim) never ends.
    if (_open) {
      LazyMenuAnchor.openCount.value--;
      widget.onClose?.call();
    }
    super.dispose();
  }

  void _wake(Offset? at) {
    if (_live) return _real.open(position: at);
    setState(() => _live = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _real.open(position: at);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_live) return widget.builder(context, _proxy, widget.child);
    return MenuAnchor(
      controller: _real,
      menuChildren: widget.menuChildren,
      builder: widget.builder,
      onOpen: _opened,
      onClose: _closed,
      style: widget.style,
      alignmentOffset: widget.alignmentOffset,
      child: widget.child,
    );
  }
}

class _WakingController extends MenuController {
  _WakingController(this._anchor);

  final _LazyMenuAnchorState _anchor;

  @override
  bool get isOpen => false;

  @override
  void open({Offset? position}) => _anchor._wake(position);

  @override
  void close() {}
}
