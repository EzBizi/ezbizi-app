import 'dart:math' as math;

import 'package:flutter/rendering.dart';
import 'package:flutter/widgets.dart';

/// The table's sideways scroll with its Task column pinned, Monday's way
/// (hands-on QA 11-16, #26: scrolled right, no row could be told apart).
///
/// [SidewaysScroll] is a horizontal [Scrollable] that does not move its
/// child: the one vertical list keeps the screen's width, so its rows stay
/// lazy and its scrollbar on screen. Each [PinnedRow] reads the offset and
/// paints its first [PinnedRow.pinned] children in place and the rest
/// shifted left under them. Scrolling sideways repaints the rows on screen;
/// it builds nothing.
class SidewaysScroll extends StatelessWidget {
  const SidewaysScroll({
    super.key,
    required this.controller,
    required this.contentWidth,
    required this.child,
  });

  final ScrollController controller;

  /// The width the rows would need to show every column at once.
  final double contentWidth;
  final Widget child;

  @override
  Widget build(BuildContext context) => Scrollable(
    controller: controller,
    axisDirection: AxisDirection.right,
    viewportBuilder: (context, position) =>
        _Sideways(position: position, contentWidth: contentWidth, child: child),
  );
}

class _Sideways extends SingleChildRenderObjectWidget {
  const _Sideways({
    required this.position,
    required this.contentWidth,
    super.child,
  });

  final ViewportOffset position;
  final double contentWidth;

  @override
  RenderObject createRenderObject(BuildContext context) =>
      _RenderSideways(position, contentWidth);

  @override
  void updateRenderObject(BuildContext context, _RenderSideways renderObject) =>
      renderObject
        ..position = position
        ..contentWidth = contentWidth;
}

/// Lays its child out at its own width and tells the scroll position how
/// far the rows can go; the child is never moved.
class _RenderSideways extends RenderProxyBox {
  _RenderSideways(this._position, this._contentWidth);

  ViewportOffset _position;
  set position(ViewportOffset v) {
    if (identical(v, _position)) return;
    _position = v;
    markNeedsLayout();
  }

  double _contentWidth;
  set contentWidth(double v) {
    if (v == _contentWidth) return;
    _contentWidth = v;
    markNeedsLayout();
  }

  @override
  void performLayout() {
    super.performLayout();
    _position.applyViewportDimension(size.width);
    _position.applyContentDimensions(
      0,
      math.max(0, _contentWidth - size.width),
    );
  }
}

/// A table line: children side by side at their own widths, the first
/// [pinned] of them fixed and the rest scrolled by [sideways].
class PinnedRow extends MultiChildRenderObjectWidget {
  const PinnedRow({
    super.key,
    required this.sideways,
    this.pinned = 2,
    this.stretch = true,
    this.edge,
    super.children,
  });

  final ScrollController sideways;

  /// How many children, from the left, stay put.
  final int pinned;

  /// Children take the line's height (a Row's
  /// [CrossAxisAlignment.stretch]); otherwise they are centred.
  final bool stretch;

  /// The line drawn down the pinned part's edge once something is under it.
  final Color? edge;

  @override
  RenderPinnedRow createRenderObject(BuildContext context) => RenderPinnedRow(
    sideways: sideways,
    pinned: pinned,
    stretch: stretch,
    edge: edge,
  );

  @override
  void updateRenderObject(BuildContext context, RenderPinnedRow renderObject) =>
      renderObject
        ..sideways = sideways
        ..pinned = pinned
        ..stretch = stretch
        ..edge = edge;
}

class _PinnedData extends ContainerBoxParentData<RenderBox> {
  bool pinned = false;
}

class RenderPinnedRow extends RenderBox
    with
        ContainerRenderObjectMixin<RenderBox, _PinnedData>,
        RenderBoxContainerDefaultsMixin<RenderBox, _PinnedData> {
  RenderPinnedRow({
    required ScrollController sideways,
    required int pinned,
    required bool stretch,
    Color? edge,
  }) : _sideways = sideways,
       _pinned = pinned,
       _stretch = stretch,
       _edge = edge;

  ScrollController _sideways;
  set sideways(ScrollController v) {
    if (identical(v, _sideways)) return;
    if (attached) _sideways.removeListener(markNeedsPaint);
    _sideways = v;
    if (attached) _sideways.addListener(markNeedsPaint);
    markNeedsPaint();
  }

  int _pinned;
  set pinned(int v) {
    if (v == _pinned) return;
    _pinned = v;
    markNeedsLayout();
  }

  bool _stretch;
  set stretch(bool v) {
    if (v == _stretch) return;
    _stretch = v;
    markNeedsLayout();
  }

  Color? _edge;
  set edge(Color? v) {
    if (v == _edge) return;
    _edge = v;
    markNeedsPaint();
  }

  /// Where the pinned part ends and the scrolling part is clipped from.
  double _pinnedWidth = 0;

  /// How far the scrolling part is moved left now.
  double get _dx => _sideways.hasClients && _sideways.positions.length == 1
      ? math.max(0, _sideways.offset)
      : 0;

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _PinnedData) child.parentData = _PinnedData();
  }

  @override
  void attach(PipelineOwner owner) {
    super.attach(owner);
    _sideways.addListener(markNeedsPaint);
  }

  @override
  void detach() {
    _sideways.removeListener(markNeedsPaint);
    super.detach();
  }

  final _clip = LayerHandle<ClipRectLayer>();

  @override
  void dispose() {
    _clip.layer = null;
    super.dispose();
  }

  BoxConstraints _childConstraints(BoxConstraints c) =>
      _stretch && c.hasBoundedHeight
      ? BoxConstraints.tightFor(height: c.maxHeight)
      : BoxConstraints(maxHeight: c.maxHeight);

  @override
  void performLayout() {
    final inner = _childConstraints(constraints);
    var x = 0.0;
    var tallest = 0.0;
    var i = 0;
    _pinnedWidth = 0;
    for (var child = firstChild; child != null; i++) {
      child.layout(inner, parentUsesSize: true);
      final data = child.parentData! as _PinnedData;
      data.pinned = i < _pinned;
      data.offset = Offset(x, 0);
      x += child.size.width;
      if (data.pinned) _pinnedWidth = x;
      tallest = math.max(tallest, child.size.height);
      child = data.nextSibling;
    }
    size = constraints.constrain(
      Size(
        constraints.hasBoundedWidth ? constraints.maxWidth : x,
        _stretch && constraints.hasBoundedHeight
            ? constraints.maxHeight
            : tallest,
      ),
    );
    if (_stretch) return;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      final data = child.parentData! as _PinnedData;
      data.offset = Offset(
        data.offset.dx,
        (size.height - child.size.height) / 2,
      );
    }
  }

  @override
  Size computeDryLayout(BoxConstraints constraints) {
    final inner = _childConstraints(constraints);
    var x = 0.0;
    var tallest = 0.0;
    for (var child = firstChild; child != null; child = childAfter(child)) {
      final s = child.getDryLayout(inner);
      x += s.width;
      tallest = math.max(tallest, s.height);
    }
    return constraints.constrain(
      Size(
        constraints.hasBoundedWidth ? constraints.maxWidth : x,
        _stretch && constraints.hasBoundedHeight
            ? constraints.maxHeight
            : tallest,
      ),
    );
  }

  double _sum(double Function(RenderBox) f) {
    var total = 0.0;
    for (var c = firstChild; c != null; c = childAfter(c)) {
      total += f(c);
    }
    return total;
  }

  double _max(double Function(RenderBox) f) {
    var most = 0.0;
    for (var c = firstChild; c != null; c = childAfter(c)) {
      most = math.max(most, f(c));
    }
    return most;
  }

  @override
  double computeMinIntrinsicWidth(double height) =>
      _sum((c) => c.getMinIntrinsicWidth(height));

  @override
  double computeMaxIntrinsicWidth(double height) =>
      _sum((c) => c.getMaxIntrinsicWidth(height));

  @override
  double computeMinIntrinsicHeight(double width) =>
      _max((c) => c.getMinIntrinsicHeight(double.infinity));

  @override
  double computeMaxIntrinsicHeight(double width) =>
      _max((c) => c.getMaxIntrinsicHeight(double.infinity));

  Offset _at(RenderBox child, double dx) {
    final data = child.parentData! as _PinnedData;
    return data.pinned ? data.offset : data.offset.translate(-dx, 0);
  }

  @override
  void paint(PaintingContext context, Offset offset) {
    final dx = _dx;
    _clip.layer = context.pushClipRect(
      needsCompositing,
      offset,
      Rect.fromLTRB(_pinnedWidth, 0, size.width, size.height),
      (context, offset) {
        for (var c = firstChild; c != null; c = childAfter(c)) {
          if (!(c.parentData! as _PinnedData).pinned) {
            context.paintChild(c, offset + _at(c, dx));
          }
        }
      },
      oldLayer: _clip.layer,
    );
    for (var c = firstChild; c != null; c = childAfter(c)) {
      if ((c.parentData! as _PinnedData).pinned) {
        context.paintChild(c, offset + _at(c, dx));
      }
    }
    if (_edge case final edge? when dx > 0) {
      context.canvas.drawRect(
        Rect.fromLTWH(offset.dx + _pinnedWidth - 1, offset.dy, 1, size.height),
        Paint()..color = edge,
      );
    }
  }

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) {
    final dx = _dx;
    // The pinned part lies over the scrolling one: what is under it cannot
    // be clicked through it.
    final underPinned = position.dx < _pinnedWidth;
    for (var c = lastChild; c != null; c = childBefore(c)) {
      final pinned = (c.parentData! as _PinnedData).pinned;
      if (underPinned != pinned) continue;
      final hit = result.addWithPaintOffset(
        offset: _at(c, dx),
        position: position,
        hitTest: (result, p) => c!.hitTest(result, position: p),
      );
      if (hit) return true;
    }
    return false;
  }

  @override
  void applyPaintTransform(RenderBox child, Matrix4 transform) {
    final at = _at(child, _dx);
    transform.translateByDouble(at.dx, at.dy, 0, 1);
  }

  @override
  Rect? describeApproximatePaintClip(RenderObject child) =>
      (child.parentData! as _PinnedData).pinned
      ? null
      : Rect.fromLTRB(_pinnedWidth, 0, size.width, size.height);
}
