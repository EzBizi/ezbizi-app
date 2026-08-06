import 'package:flutter/material.dart';
import '../core/theme.dart';
import 'auth_preview.dart';

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
    required this.preview,
    required this.footnote,
    required this.form,
    this.pill,
  });

  /// Small uppercase line above the headline.
  final String eyebrow;

  final String headline;
  final String blurb;

  /// A glimpse of the product itself — see auth_preview.dart.
  ///
  /// It was a row of nouns with arrows between them, which is the shape every
  /// SaaS sign-in page has and which says nothing a person could not guess from
  /// the word "CRM". Each face supplies its own, because the two answer
  /// different questions: one business's week, or every business at once.
  final Widget preview;

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
                  preview: preview,
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
    required this.preview,
    required this.footnote,
    required this.pill,
  });

  final String eyebrow;
  final String headline;
  final String blurb;
  final Widget preview;
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
          // A blueprint grid rather than two blurred circles — see
          // GridBackdrop for why. Positioned.fill so it covers the panel at any
          // size without a second layout pass.
          const Positioned.fill(child: GridBackdrop()),

          // One column, held to a readable width and centred in the panel.
          //
          // It was `EdgeInsets.all(48)` on a full-width column, so on a 1100px
          // panel the text hugged the left edge with half the panel empty
          // beside it. Constraining the column and centring it keeps the same
          // left-aligned reading line at any panel width, and puts the whole
          // block where the eye expects it.
          // Scrollable, but only when it has to be.
          //
          // The column uses Spacers to centre itself, which needs a bounded
          // height — and a short window (a laptop with the dock and a bookmarks
          // bar) would otherwise overflow it. minHeight + IntrinsicHeight keeps
          // the Spacers working at normal heights and lets the whole thing
          // scroll when the panel is genuinely too short.
          LayoutBuilder(
            builder: (context, box) => SingleChildScrollView(
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: box.maxHeight),
                child: IntrinsicHeight(
                  child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 620),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(56, 44, 56, 40),
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

                // The preview is the first thing to go on a short window.
                //
                // It is the most valuable part of the panel and also the
                // tallest, so on a 900px-high laptop the column wanted 935px
                // and overflowed. Dropping it keeps the headline, the blurb and
                // the footnote — the parts that still say something without it.
                if (box.maxHeight >= 800) ...[
                  const SizedBox(height: 34),
                  // Flexible, so the preview takes whatever height is left over
                  // and its own FittedBox scales it down to fit. Laid out at its
                  // natural size it wants ~420px, which is more than a
                  // 1440x900 laptop has left after the headline — the most
                  // common laptop size there is.
                  Flexible(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: preview,
                    ),
                  ),
                ],

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
                ),
              ),
            ),
          ),
        ],
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
          // 40 on a 360px phone leaves 280px of usable width, which is
          // narrower than the sign-in row needs. Tighten it when the screen is.
          padding: EdgeInsets.symmetric(
            horizontal: MediaQuery.sizeOf(context).width < 480 ? 20 : 40,
            vertical: 48,
          ),
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
