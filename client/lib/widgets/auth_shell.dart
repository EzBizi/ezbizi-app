import 'package:flutter/material.dart';
import '../core/theme.dart';

/// The two-panel sign-in layout: identity on the left, the form on the right.
///
/// Shared by both faces so they cannot drift. The customer app and the control
/// plane are the same product wearing different hats, and a sign-in screen that
/// looks like two different companies is how somebody ends up unsure which one
/// they are typing a password into.
///
/// The left panel is ALWAYS dark, in light theme and dark. It is brand rather
/// than chrome — the same reason a printed logo does not invert with the paper —
/// and a two-tone split only reads as deliberate when one side stays put.
///
/// Below 900px it disappears entirely rather than stacking. A hero panel above
/// a login form on a phone is two screens of scrolling before you can type, and
/// nobody signing in on a phone is there to read the pitch.
class AuthShell extends StatelessWidget {
  const AuthShell({
    super.key,
    required this.eyebrow,
    required this.headline,
    required this.blurb,
    required this.steps,
    required this.footnote,
    required this.form,
    this.pill,
  });

  /// Small uppercase line above the headline.
  final String eyebrow;

  final String headline;
  final String blurb;

  /// Three words with arrows between them — the shape of the product in one row.
  final List<({IconData icon, String label})> steps;

  /// The reassuring line at the bottom of the dark panel.
  final String footnote;

  /// An optional badge in the top-left, under the wordmark.
  final String? pill;

  final Widget form;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: LayoutBuilder(
        builder: (context, constraints) {
          final wide = constraints.maxWidth >= 900;

          if (!wide) {
            return _FormPanel(form: form, showWordmark: true);
          }

          return Row(
            children: [
              // 1:1 rather than 5:4. The form is the reason the page exists;
              // giving it visibly less room than the pitch beside it read as an
              // afterthought.
              Expanded(
                flex: 1,
                child: _IdentityPanel(
                  eyebrow: eyebrow,
                  headline: headline,
                  blurb: blurb,
                  steps: steps,
                  footnote: footnote,
                  pill: pill,
                ),
              ),
              Expanded(
                flex: 1,
                child: _FormPanel(form: form, showWordmark: false),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _IdentityPanel extends StatelessWidget {
  const _IdentityPanel({
    required this.eyebrow,
    required this.headline,
    required this.blurb,
    required this.steps,
    required this.footnote,
    required this.pill,
  });

  final String eyebrow;
  final String headline;
  final String blurb;
  final List<({IconData icon, String label})> steps;
  final String footnote;
  final String? pill;

  static const _ink = Color(0xFF071018);

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [_ink, Color(0xFF0A2233), Color(0xFF06384E)],
          stops: [0.0, 0.55, 1.0],
        ),
      ),
      child: Stack(
        children: [
          // Two soft discs, bled off opposite corners. Cheap depth: no image to
          // ship, no asset to keep in step with the brand, and it scales to any
          // panel width without a second art file.
          Positioned(
            top: -140,
            right: -120,
            child: _Glow(size: 420, color: EzBiziTheme.accent.withValues(alpha: 0.16)),
          ),
          Positioned(
            bottom: -180,
            left: -140,
            child: _Glow(size: 460, color: EzBiziTheme.accent.withValues(alpha: 0.10)),
          ),

          // One column, held to a readable width and centred in the panel.
          //
          // It was `EdgeInsets.all(48)` on a full-width column, so on a 1100px
          // panel the text hugged the left edge with half the panel empty
          // beside it. Constraining the column and centring it keeps the same
          // left-aligned reading line at any panel width, and puts the whole
          // block where the eye expects it.
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 620),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(56, 48, 56, 44),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(
                      color: EzBiziTheme.accent,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(Icons.bolt, color: Colors.white, size: 22),
                  ),
                  const SizedBox(width: 12),
                  const Text(
                    'EzBizi',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 22,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.4,
                    ),
                  ),
                ]),

                // Even flex above and below the cluster, so it sits on the
                // panel's true centre instead of drifting downwards.
                const Spacer(),

                if (pill != null) ...[
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
                    ),
                    child: Text(
                      pill!,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),
                ],

                Text(
                  eyebrow.toUpperCase(),
                  style: TextStyle(
                    color: EzBiziTheme.accent,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.4,
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  headline,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 40,
                    height: 1.14,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.9,
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  blurb,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.70),
                    fontSize: 14.5,
                    height: 1.6,
                  ),
                ),

                const SizedBox(height: 36),
                _StepStrip(steps: steps),

                const Spacer(),

                Row(children: [
                  Icon(Icons.lock_outline,
                      size: 14, color: Colors.white.withValues(alpha: 0.55)),
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      footnote,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.55),
                        fontSize: 12,
                      ),
                    ),
                  ),
                ]),
              ],
            ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StepStrip extends StatelessWidget {
  const _StepStrip({required this.steps});

  final List<({IconData icon, String label})> steps;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 18),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.05),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withValues(alpha: 0.09)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: [
          for (var i = 0; i < steps.length; i++) ...[
            if (i > 0)
              Icon(Icons.arrow_forward,
                  size: 16, color: EzBiziTheme.accent.withValues(alpha: 0.85)),
            Column(
              children: [
                Icon(steps[i].icon, color: Colors.white, size: 20),
                const SizedBox(height: 8),
                Text(
                  steps[i].label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: RadialGradient(colors: [color, color.withValues(alpha: 0)]),
      ),
    );
  }
}

class _FormPanel extends StatelessWidget {
  const _FormPanel({required this.form, required this.showWordmark});

  final Widget form;
  final bool showWordmark;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: Theme.of(context).colorScheme.surface,
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 48),
          child: ConstrainedBox(
            // Wider than the old 380: at that width the form read as a
            // small card adrift in a very large empty panel.
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Only when the dark panel is gone. Two wordmarks on one screen
                // is a logo competing with itself.
                if (showWordmark) ...[
                  Center(child: EzBiziTheme.wordmark(context, size: 30)),
                  const SizedBox(height: 36),
                ],
                form,
              ],
            ),
          ),
        ),
      ),
    );
  }
}
