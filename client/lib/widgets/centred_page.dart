import 'package:flutter/material.dart';

/// A lone form in the middle of an empty page (Jose 11-25: Review QR "should
/// be centered just like the new inspection stuff"). One widget so the two
/// cannot drift.
///
/// On a desk the content sits mid-page; with [anchor] the point that many
/// pixels from its top is what sits mid-page, so a search bar stays put while
/// suggestions grow beneath it. On a phone (< 600 wide) it starts at the top,
/// where the keyboard cannot cover it (phone audit 2026-11-04, Section 3 #11).
class CentredPage extends StatelessWidget {
  const CentredPage({
    super.key,
    required this.child,
    this.maxWidth = 560,
    this.anchor,
  });

  final Widget child;
  final double maxWidth;
  final double? anchor;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        final body = Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: child,
          ),
        );

        if (phone || anchor != null) {
          final top = phone
              ? 16.0
              : (constraints.maxHeight / 2 - anchor!).clamp(
                  16.0,
                  double.infinity,
                );
          return SingleChildScrollView(
            padding: EdgeInsets.fromLTRB(16, top, 16, 24),
            child: body,
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
          child: ConstrainedBox(
            constraints: BoxConstraints(
              minHeight: (constraints.maxHeight - 48).clamp(
                0.0,
                double.infinity,
              ),
            ),
            child: body,
          ),
        );
      },
    );
  }
}
