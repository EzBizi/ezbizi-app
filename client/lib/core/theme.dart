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
  static const _ink = Color(0xFF060808);
  static const _muted = Color(0xFF5B6672);
  static const _line = Color(0x1A060808);
  static const _surfaceAlt = Color(0xFFF5F8FA);

  static ThemeData light() => _build(Brightness.light);
  static ThemeData dark() => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final isDark = brightness == Brightness.dark;

    final scheme = ColorScheme.fromSeed(
      seedColor: _cyan,
      brightness: brightness,
      primary: _cyan,
      onPrimary: Colors.white,
      surface: isDark ? const Color(0xFF10161B) : Colors.white,
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

  /// The wordmark, in the brand's two weights.
  static Widget wordmark({double size = 28}) => RichText(
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
                color: _ink,
              ),
            ),
          ],
        ),
      );

  static const Color accent = _cyan;
  static const Color accentDark = _cyanDark;
}
