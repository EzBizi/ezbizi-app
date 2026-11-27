import 'dart:math' as math;

import 'package:flutter/rendering.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/widgets.dart';

/// One line of controls that never wraps: [children] go left to right in
/// priority order while they fit, and the ones that do not are left out
/// for [overflow] to offer (a ⋯ menu). [overflow] is told how many
/// children are showing and sits at the right end.
///
/// The Task Board's toolbar wrapped to three rows at 1440 and four on a
/// phone, about 180 px before the first task (hands-on QA 11-16).
class PriorityRow extends StatefulWidget {
  const PriorityRow({
    super.key,
    required this.children,
    required this.overflow,
    this.spacing = 8,
  });

  final List<Widget> children;

  /// Built with the number of [children] showing.
  final Widget Function(BuildContext context, int showing) overflow;
  final double spacing;

  @override
  State<PriorityRow> createState() => _PriorityRowState();
}

class _PriorityRowState extends State<PriorityRow> {
  late int _showing = widget.children.length;

  void _measured(int showing) {
    if (showing == _showing) return;
    // After this frame: the count comes out of layout.
    SchedulerBinding.instance.addPostFrameCallback((_) {
      if (mounted && showing != _showing) setState(() => _showing = showing);
    });
  }

  @override
  Widget build(BuildContext context) => _PriorityLine(
    spacing: widget.spacing,
    onMeasured: _measured,
    children: [...widget.children, widget.overflow(context, _showing)],
  );
}

class _PriorityLine extends MultiChildRenderObjectWidget {
  const _PriorityLine({
    required super.children,
    required this.spacing,
    required this.onMeasured,
  });

  final double spacing;
  final ValueChanged<int> onMeasured;

  @override
  RenderObject createRenderObject(BuildContext context) =>
      _RenderPriorityLine(spacing: spacing, onMeasured: onMeasured);

  @override
  void updateRenderObject(
    BuildContext context,
    _RenderPriorityLine renderObject,
  ) {
    renderObject
      ..spacing = spacing
      ..onMeasured = onMeasured;
  }
}

class _LineData extends ContainerBoxParentData<RenderBox> {
  bool shown = true;
}

class _RenderPriorityLine extends RenderBox
    with
        ContainerRenderObjectMixin<RenderBox, _LineData>,
        RenderBoxContainerDefaultsMixin<RenderBox, _LineData> {
  _RenderPriorityLine({required double spacing, required this.onMeasured})
    : _spacing = spacing;

  double _spacing;
  set spacing(double v) {
    if (v == _spacing) return;
    _spacing = v;
    markNeedsLayout();
  }

  ValueChanged<int> onMeasured;

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _LineData) child.parentData = _LineData();
  }

  @override
  void performLayout() {
    final width = constraints.maxWidth;
    final loose = BoxConstraints(
      maxWidth: width.isFinite ? width : double.infinity,
      maxHeight: constraints.maxHeight,
    );
    final items = <RenderBox>[];
    var child = firstChild;
    while (child != null) {
      items.add(child);
      child = childAfter(child);
    }
    if (items.isEmpty) {
      size = constraints.smallest;
      return;
    }
    final more = items.removeLast();
    more.layout(loose, parentUsesSize: true);
    final room = width - more.size.width - _spacing;

    var x = 0.0;
    var height = more.size.height;
    var showing = 0;
    var full = false;
    for (final c in items) {
      c.layout(loose, parentUsesSize: true);
      final data = c.parentData! as _LineData;
      final next = x + c.size.width;
      // Each in order; once one does not fit, the rest wait in the menu,
      // so the line keeps its order.
      data.shown = !full && next <= room;
      if (!data.shown) {
        full = true;
        continue;
      }
      showing++;
      height = math.max(height, c.size.height);
      x = next + _spacing;
    }
    size = constraints.constrain(
      Size(width.isFinite ? width : x + more.size.width, height),
    );
    var at = 0.0;
    for (final c in items) {
      final data = c.parentData! as _LineData;
      if (!data.shown) continue;
      data.offset = Offset(at, (size.height - c.size.height) / 2);
      at += c.size.width + _spacing;
    }
    (more.parentData! as _LineData)
      ..shown = true
      ..offset = Offset(
        size.width - more.size.width,
        (size.height - more.size.height) / 2,
      );
    onMeasured(showing);
  }

  @override
  void paint(PaintingContext context, Offset offset) {
    var child = firstChild;
    while (child != null) {
      final data = child.parentData! as _LineData;
      if (data.shown) context.paintChild(child, offset + data.offset);
      child = data.nextSibling;
    }
  }

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) {
    var child = lastChild;
    while (child != null) {
      final data = child.parentData! as _LineData;
      if (data.shown &&
          result.addWithPaintOffset(
            offset: data.offset,
            position: position,
            hitTest: (r, p) => child!.hitTest(r, position: p),
          )) {
        return true;
      }
      child = data.previousSibling;
    }
    return false;
  }

  @override
  void visitChildrenForSemantics(RenderObjectVisitor visitor) {
    var child = firstChild;
    while (child != null) {
      final data = child.parentData! as _LineData;
      if (data.shown) visitor(child);
      child = data.nextSibling;
    }
  }

  @override
  double computeMinIntrinsicHeight(double width) =>
      _maxChild((c) => c.getMinIntrinsicHeight(double.infinity));

  @override
  double computeMaxIntrinsicHeight(double width) =>
      _maxChild((c) => c.getMaxIntrinsicHeight(double.infinity));

  double _maxChild(double Function(RenderBox) f) {
    var h = 0.0;
    var child = firstChild;
    while (child != null) {
      h = math.max(h, f(child));
      child = childAfter(child);
    }
    return h;
  }
}
