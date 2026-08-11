import 'package:flutter/material.dart';

/// The EzBizi look — cyan on white, from the brand mark (ezbizi.com).
///
/// Everything here is a DEFAULT, not a constant. Organizations define their own
/// themes (§12), so nothing may assume these colours: read them from the theme,
/// never import the hex. That is why the palette is private below and only
/// reachable through [EzBiziTheme.light] / [EzBiziTheme.dark].
class EzBiziTheme {
  static const _cyan = Color(0xFF00AEEF);
  static const _cyanDark = Color(0xFF0093CA);
  // A deeper cyan for `secondary`, so nothing has to reach outside the brand.
  static const _cyanDeep = Color(0xFF00708F);
  static const _ink = Color(0xFF060808);
  static const _muted = Color(0xFF5B6672);
  static const _line = Color(0x1A060808);
  static const _surfaceAlt = Color(0xFFF5F8FA);

  static ThemeData light() => _build(Brightness.light);
  static ThemeData dark() => _build(Brightness.dark);

  /// The line between the chrome's regions — top bar to page, sidebar to page.
  ///
  /// Deliberately NOT `dividerColor`. That one separates rows inside a list,
  /// where the eye is already scanning line by line and a crisp rule helps. A
  /// structural edge is doing the opposite job: it says two regions are
  /// different without asking to be looked at, and at the same weight it reads
  /// as a cut across the page.
  ///
  /// A tinted grey rather than transparent black, so it sits in the same family
  /// as the surfaces either side instead of darkening whatever is behind it.
  static Color edge(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark
          ? const Color(0xFF1E262E)
          : const Color(0xFFE9EDF1);

  /// Which theme each half of the shell gets.
  ///
  /// `mixed_dark_nav` and `mixed_light_nav` are one idea in two directions: the
  /// sidebar and the page in opposite themes. Everything else puts both halves
  /// on the same one.
  static ThemeMode modeFor(String preference) => switch (preference) {
        'light' || 'mixed_dark_nav' => ThemeMode.light,
        'dark' || 'mixed_light_nav' => ThemeMode.dark,
        _ => ThemeMode.system,
      };

  /// The sidebar's own theme, when it differs from the page's.
  ///
  /// Null when the two match, so the caller can skip wrapping entirely rather
  /// than rebuilding an identical theme and pushing a redundant inherited
  /// widget down the tree.
  static ThemeData? navigationOverride(String preference) => switch (preference) {
        'mixed_dark_nav' => dark(),
        'mixed_light_nav' => light(),
        _ => null,
      };

  static ThemeData _build(Brightness brightness) {
    final isDark = brightness == Brightness.dark;

    /*
     * NO PURPLE. Ever. It is not a taste preference.
     *
     * `ColorScheme.fromSeed` derives `secondary` and `tertiary` by rotating the
     * seed's hue, and from a cyan seed that lands on violet. Nobody chose it and
     * nobody noticed, and it turned up as a violet stripe down the side of every
     * card in the contract designer — which the owner recognised instantly as
     * the mark of a generated interface. Their words: "they are the mark of AI
     * did this and it's a mockery all over internet".
     *
     * The palette is pinned so the app cannot reach for a colour it never chose.
     * `secondary` and `tertiary` stay inside the brand's own range — a deeper
     * and a lighter cyan — so a widget that grabs one gets something that
     * belongs here rather than something Material invented.
     */
    final generated = ColorScheme.fromSeed(
      seedColor: _cyan,
      brightness: brightness,
      primary: _cyan,
      onPrimary: Colors.white,
      surface: isDark ? const Color(0xFF10161B) : Colors.white,
    );

    final scheme = generated.copyWith(
      secondary: isDark ? _cyanDark : _cyanDeep,
      onSecondary: Colors.white,
      tertiary: isDark ? const Color(0xFF6E8CA0) : const Color(0xFF41627A),
      onTertiary: Colors.white,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: isDark ? const Color(0xFF0B0F14) : _surfaceAlt,
      fontFamily: 'Inter',

      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: isDark ? Colors.white : _ink,
        elevation: 0,
        scrolledUnderElevation: 1,
        surfaceTintColor: Colors.transparent,
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: _cyan,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 18),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
        ),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? const Color(0xFF161E25) : Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: _line),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide(color: isDark ? Colors.white24 : _line),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: _cyan, width: 2),
        ),
      ),

      cardTheme: CardThemeData(
        elevation: 0,
        color: scheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: isDark ? Colors.white12 : _line),
        ),
      ),

      dividerTheme: DividerThemeData(
        color: isDark ? Colors.white12 : _line,
        thickness: 1,
        space: 1,
      ),

      textTheme: TextTheme(
        headlineMedium: TextStyle(
          fontSize: 28,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.8,
          color: isDark ? Colors.white : _ink,
        ),
        titleMedium: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white : _ink,
        ),
        bodyMedium: TextStyle(
          fontSize: 14,
          color: isDark ? Colors.white70 : _muted,
          height: 1.5,
        ),
        labelSmall: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          letterSpacing: 1.2,
          color: isDark ? Colors.white54 : _muted,
        ),
      ),
    );
  }

  /// The wordmark.
  ///
  /// "Bizi" follows the theme rather than being fixed to the brand's ink: the
  /// logo is drawn on white, but on a dark background black-on-near-black is
  /// simply invisible. The cyan "Ez" is constant — it carries the brand and has
  /// enough contrast either way.
  ///
  /// Takes a BuildContext for exactly that reason. An earlier version hardcoded
  /// the ink colour and the second half of the name vanished in dark mode.
  static Widget wordmark(BuildContext context, {double size = 28}) {
    final onSurface = Theme.of(context).brightness == Brightness.dark
        ? Colors.white
        : _ink;

    return RichText(
      text: TextSpan(
        children: [
          TextSpan(
            text: 'Ez',
            style: TextStyle(
              fontSize: size,
              fontWeight: FontWeight.w800,
              letterSpacing: -1,
              color: _cyan,
            ),
          ),
          TextSpan(
            text: 'Bizi',
            style: TextStyle(
              fontSize: size,
              fontWeight: FontWeight.w800,
              letterSpacing: -1,
              color: onSurface,
            ),
          ),
        ],
      ),
    );
  }

  static const Color accent = _cyan;
  static const Color accentDark = _cyanDark;
}
