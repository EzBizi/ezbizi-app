import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

/// Whether report tables keep their heading row on screen while their rows
/// scroll (Jose, 2026-11-16: "make basically the first row sticky or not,
/// maybe add a pin icon so the user decides").
///
/// Provided once, by the Reports screen, from the `reports.pin_headings`
/// preference, so every tab answers to the one choice. With no scope above a
/// table nothing changes: the pin is not drawn and the table keeps the
/// behaviour it had before.
class HeadingPin extends InheritedWidget {
  const HeadingPin({
    super.key,
    required this.pinned,
    required this.onChanged,
    required super.child,
  });

  final bool pinned;
  final ValueChanged<bool> onChanged;

  static HeadingPin? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<HeadingPin>();

  /// A pinned sliver while pinned, a plain one when not. Without a scope it
  /// stays pinned, as the slivers that use this were before the pin.
  static Widget sliver(BuildContext context, {required Widget child}) =>
      maybeOf(context)?.pinned ?? true
      ? PinnedHeaderSliver(child: child)
      : SliverToBoxAdapter(child: child);

  @override
  bool updateShouldNotify(HeadingPin old) => old.pinned != pinned;
}

/// The pin at the start of a heading row. Nothing without a [HeadingPin].
class HeadingPinButton extends StatelessWidget {
  const HeadingPinButton({super.key});

  @override
  Widget build(BuildContext context) {
    final scope = HeadingPin.maybeOf(context);
    if (scope == null) return const SizedBox.shrink();
    final pinned = scope.pinned;

    return Padding(
      padding: const EdgeInsets.only(right: 4),
      child: IconButton(
        key: const ValueKey('heading-pin'),
        tooltip: pinned
            ? 'Headings stay on screen. Click to let them scroll away.'
            : 'Keep the headings on screen while you scroll',
        visualDensity: VisualDensity.compact,
        padding: EdgeInsets.zero,
        constraints: const BoxConstraints.tightFor(width: 24, height: 24),
        iconSize: 16,
        isSelected: pinned,
        icon: const Icon(Icons.push_pin_outlined),
        selectedIcon: Icon(
          Icons.push_pin,
          color: Theme.of(context).colorScheme.primary,
        ),
        onPressed: () => scope.onChanged(!pinned),
      ),
    );
  }
}

/// A heading over a table's rows. Pinned (see [HeadingPin]), the heading
/// stays at the top of the page's scroll view while the rows pass under it,
/// until the last row; otherwise it scrolls away with them.
///
/// For a table that scrolls with its page, as a ListView child. One that is
/// a sliver list uses [HeadingPin.sliver]; one with a height of its own
/// already keeps its heading.
///
/// The heading is moved when painted, not laid out again, so scrolling costs
/// a repaint of the table and nothing else.
class PinnableHeading extends MultiChildRenderObjectWidget {
  PinnableHeading({super.key, required Widget heading, required Widget body})
    : super(children: [body, heading]);

  bool _pinned(BuildContext context) =>
      HeadingPin.maybeOf(context)?.pinned ?? false;

  @override
  RenderObject createRenderObject(BuildContext context) =>
      RenderPinnableHeading(
        pinned: _pinned(context),
        scrollable: Scrollable.maybeOf(context, axis: Axis.vertical),
      );

  @override
  void updateRenderObject(
    BuildContext context,
    RenderPinnableHeading renderObject,
  ) {
    renderObject
      ..pinned = _pinned(context)
      ..scrollable = Scrollable.maybeOf(context, axis: Axis.vertical);
  }
}

class _PinnableData extends ContainerBoxParentData<RenderBox> {}

class RenderPinnableHeading extends RenderBox
    with
        ContainerRenderObjectMixin<RenderBox, _PinnableData>,
        RenderBoxContainerDefaultsMixin<RenderBox, _PinnableData> {
  RenderPinnableHeading({required bool pinned, ScrollableState? scrollable})
    : _pinned = pinned,
      _scrollable = scrollable;

  bool _pinned;
  set pinned(bool value) {
    if (value == _pinned) return;
    _unlisten();
    _pinned = value;
    _listen();
    markNeedsPaint();
  }

  ScrollableState? _scrollable;
  ScrollPosition? _position;
  set scrollable(ScrollableState? value) {
    if (value == _scrollable && value?.position == _position) return;
    _unlisten();
    _scrollable = value;
    _listen();
    markNeedsPaint();
  }

  /// How far down the heading was last painted.
  double _lift = 0;

  RenderBox get _body => firstChild!;
  RenderBox get _heading => lastChild!;

  void _listen() {
    if (!attached || !_pinned) return;
    _position = _scrollable?.position;
    _position?.addListener(markNeedsPaint);
  }

  void _unlisten() {
    _position?.removeListener(markNeedsPaint);
    _position = null;
  }

  @override
  void attach(PipelineOwner owner) {
    super.attach(owner);
    _listen();
  }

  @override
  void detach() {
    _unlisten();
    super.detach();
  }

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _PinnableData) child.parentData = _PinnableData();
  }

  BoxConstraints _inner(BoxConstraints c) =>
      BoxConstraints(minWidth: c.minWidth, maxWidth: c.maxWidth);

  @override
  Size computeDryLayout(covariant BoxConstraints constraints) {
    final heading = _heading.getDryLayout(_inner(constraints));
    final body = _body.getDryLayout(_inner(constraints));
    return constraints.constrain(
      Size(math.max(heading.width, body.width), heading.height + body.height),
    );
  }

  @override
  void performLayout() {
    _heading.layout(_inner(constraints), parentUsesSize: true);
    _body.layout(_inner(constraints), parentUsesSize: true);
    (_heading.parentData! as _PinnableData).offset = Offset.zero;
    (_body.parentData! as _PinnableData).offset = Offset(
      0,
      _heading.size.height,
    );
    size = constraints.constrain(
      Size(
        math.max(_heading.size.width, _body.size.width),
        _heading.size.height + _body.size.height,
      ),
    );
  }

  /// How far the heading must come down to sit at the top of the page's
  /// scroll view: none while the table's top is on screen, and never past the
  /// last row.
  double _liftNow() {
    if (!_pinned) return 0;
    final viewport = _scrollable?.context.findRenderObject();
    if (viewport is! RenderBox || !viewport.attached) return 0;
    final top = localToGlobal(Offset.zero, ancestor: viewport).dy;
    final most = math.max(0.0, _body.size.height - _heading.size.height);
    return (-top).clamp(0.0, most);
  }

  @override
  void paint(PaintingContext context, Offset offset) {
    _lift = _liftNow();
    // The rows first, so the heading paints over the ones under it.
    context.paintChild(_body, offset + Offset(0, _heading.size.height));
    context.paintChild(_heading, offset + Offset(0, _lift));
  }

  @override
  void applyPaintTransform(RenderBox child, Matrix4 transform) {
    final dy = child == _heading ? _lift : _heading.size.height;
    transform.translateByDouble(0, dy, 0, 1);
  }

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) {
    final onHeading = result.addWithPaintOffset(
      offset: Offset(0, _lift),
      position: position,
      hitTest: (result, p) => _heading.hitTest(result, position: p),
    );
    if (onHeading) return true;
    return result.addWithPaintOffset(
      offset: Offset(0, _heading.size.height),
      position: position,
      hitTest: (result, p) => _body.hitTest(result, position: p),
    );
  }
}
