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
              Expanded(
                flex: 5,
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
                flex: 4,
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

          Padding(
            padding: const EdgeInsets.all(48),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Container(
                    width: 38,
                    height: 38,
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

                if (pill != null) ...[
                  const SizedBox(height: 28),
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
                ],

                const Spacer(),

                Text(
                  eyebrow.toUpperCase(),
                  style: TextStyle(
                    color: EzBiziTheme.accent,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.4,
                  ),
                ),
                const SizedBox(height: 14),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 460),
                  child: Text(
                    headline,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 38,
                      height: 1.12,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.8,
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 440),
                  child: Text(
                    blurb,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.72),
                      fontSize: 14,
                      height: 1.55,
                    ),
                  ),
                ),

                const SizedBox(height: 34),
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
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < steps.length; i++) ...[
            if (i > 0)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 18),
                child: Icon(Icons.arrow_forward,
                    size: 16, color: EzBiziTheme.accent.withValues(alpha: 0.85)),
              ),
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
          padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 48),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
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
