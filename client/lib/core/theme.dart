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
  // A deeper cyan for `secondary` — and, on light, for `primary` too, because
  // it is the only one of the three that reads as text on white (5.7:1). So
  // nothing has to reach outside the brand to be legible.
  static const _cyanDeep = Color(0xFF00708F);
  static const _ink = Color(0xFF060808);
  static const _muted = Color(0xFF5B6672);
  static const _line = Color(0x1A060808);
  static const _surfaceAlt = Color(0xFFF5F8FA);

  /// Field map status colours (Jose, 2026-09-30: "literally use colors that
  /// stand out: green, red, yellow, blue, white/dark"). Map pins sit on busy
  /// tiles in both modes, so these are the loud, saturated versions and read
  /// the same on light and dark tiles. As TEXT on a light panel they do not:
  /// on white, yellow is 1.5:1, green 2.3:1, blue and red under 4:1. The
  /// fifth, white/dark, is `colorScheme.onSurface`, which already flips with
  /// the mode.
  static const fieldGreen = Color(0xFF22C55E);
  static const fieldRed = Color(0xFFEF4444);
  static const fieldYellow = Color(0xFFFACC15);
  static const fieldBlue = Color(0xFF3B82F6);

  /// Ink on a coloured pin: near-black on yellow and on the light "opened"
  /// marker, white on the others.
  static const fieldInkDark = Color(0xFF111111);
  static const fieldInkLight = Color(0xFFFFFFFF);

  /// Paper and the ink on it, the same in both modes: a signature pad and a
  /// PDF's pages are drawn on white whatever the app's theme is. The contract
  /// document's own values (contract_document_page.dart).
  static const paper = Color(0xFFFFFFFF);
  static const paperInk = Color(0xFF111111);
  static const paperMuted = Color(0xFF555555);

  /// The corner of every button, chip and field. Cards are rounder (14).
  static const double controlRadius = 10;

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

  /// Punch-in green. Named so the top-bar button never picks a hex, and so
  /// the pair cannot drift into purple when a theme is mixed.
  static Color clockIn(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark
      ? const Color(0xFF3DDC97)
      : const Color(0xFF0B8F5B);

  /// The middle step of a load meter — "getting full", between [clockIn] and
  /// the scheme's own error red.
  ///
  /// Exists because a two-colour meter has to choose between calling 64% full
  /// fine and calling it broken, and neither is true. Amber rather than
  /// orange-red so it reads as a different word from the error colour rather
  /// than a lighter shade of it.
  static Color meterWarning(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark
      ? const Color(0xFFE0A93B)
      : const Color(0xFFB37400);

  /// Punch-out coral for the outlined top-bar chip, same constraint as [clockIn].
  static Color clockOut(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark
      ? const Color(0xFFFF8A80)
      : const Color(0xFFE07070);

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
  static ThemeData? navigationOverride(String preference) =>
      switch (preference) {
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
      // `primary` has to work in BOTH directions — as a fill with `onPrimary`
      // text on it, and as text/icons/focus rings on `surface` — so it must
      // clear 4.5:1 against its own foreground *and* against the surface. The
      // brand cyan only manages that on a dark surface.
      //
      //   #00AEEF on white    2.5:1   fails AA and even AA-large
      //   #00AEEF on #10161B  7.2:1   passes
      //   #00708F on white    5.7:1   passes
      //   white   on #00AEEF  2.5:1   fails
      //   #060808 on #00AEEF  7.9:1   passes
      //
      // Hence the flip, and hence `onPrimary` flipping with it: on light we
      // darken the fill and keep white on it; on dark we keep the brand cyan
      // and put ink on it. The bright cyan is still the brand — it is reachable
      // as [accent] for large fills and marks that carry no text.
      primary: isDark ? _cyan : _cyanDeep,
      onPrimary: isDark ? _ink : Colors.white,
      surface: isDark ? const Color(0xFF10161B) : Colors.white,
    );

    final scheme = generated.copyWith(
      secondary: isDark ? _cyanDark : _cyanDeep,
      onSecondary: Colors.white,
      tertiary: isDark ? const Color(0xFF6E8CA0) : const Color(0xFF41627A),
      onTertiary: Colors.white,
    );

    final control = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(controlRadius),
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
          // No colours here on purpose. FilledButton already defaults to
          // `primary`/`onPrimary`, so naming them again pinned the button to the
          // brand cyan with white on it — 2.5:1, on the app's main action, in
          // both brightnesses. Inheriting is the fix and the shorter code.
          //
          // No padding either, for the same reason one shape is set below: it
          // made this button taller than every other kind, so "Write another"
          // beside "Done" stood 6 points short on a desktop and 14 on a phone.
          // Material's own minimum height now sizes every kind alike.
          shape: control,
          textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
        ),
      ),

      // ONE SHAPE FOR EVERY CONTROL. Only the filled button was given one, so
      // the rest kept Material's pill: "Export" a pill beside "Add job" a
      // rounded box, and Scheduling's three modes a box and two ovals. Jose,
      // 2026-09-28: "i also do not like the fact that different shapes are
      // being used, app needs to be cohesive." The same 10 as the fields.
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(shape: control),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(shape: control),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(shape: control),
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: SegmentedButton.styleFrom(shape: control),
      ),
      chipTheme: ChipThemeData(shape: control),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? const Color(0xFF161E25) : Colors.white,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 16,
        ),

        /*
         * AN EMPTY FIELD MUST NOT READ LIKE A FILLED ONE.
         *
         * Jose, 2026-09-24, on a column of Web Management footer fields:
         * *"there's practically no difference on the field text for completed
         * stuff vs placeholders, u can't tell them apart"*.
         *
         * Measured before changing anything, which is what makes this small:
         *
         *   resting label (EMPTY)   16.0  w400  #414849
         *   entered value           16.0  w400  #060808
         *
         * Same size, same weight, and two greys close enough that a column of
         * mixed fields reads as one block of text. Nothing was styled here at
         * all — no labelStyle, no floatingLabelStyle, no hintStyle — so
         * Material's defaults decided, and its default resting label is dark
         * enough to pass for content.
         *
         * So the prompt goes properly muted and the floated caption goes
         * SMALL AND HEAVY. After that an empty field is mid-grey body text
         * and a filled one is a little bold caption over near-black — a
         * difference in colour, size AND weight rather than one shade of grey.
         *
         * `_muted` rather than something lighter: a placeholder is still text
         * somebody has to read, and #5B6672 on white is about 4.9:1. Going
         * paler would have separated them by failing contrast, which is a
         * worse screen for the people who need it most.
         */
        labelStyle: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w400,
          // Dark gets an OPAQUE grey rather than white-at-60%. Translucent
          // white over a dark fill separates by 2.48:1, under the 3:1 the
          // light theme clears — and it reads as pure white to anything that
          // measures colour without compositing, which is how the first
          // version of the test for this reported 1.00:1 and meant nothing.
          color: isDark ? const Color(0xFF8B93A0) : _muted,
        ),
        floatingLabelStyle: TextStyle(
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white70 : _muted,
        ),
        hintStyle: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w400,
          color: isDark ? Colors.white38 : _muted.withValues(alpha: 0.75),
        ),
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
          // A focus ring is a non-text UI component: WCAG wants 3:1 against
          // what is next to it. Bright cyan on a white field is 2.5:1, so the
          // one indicator a keyboard user relies on was the hardest to see.
          borderSide: BorderSide(color: scheme.primary, width: 2),
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
        // The one surface that wants a number read from across a room: the
        // Clock In lock screen, where the time IS the content. It asked for
        // `displayMedium`, found it empty, and correctly fell back to
        // `headlineMedium` -- so a full-screen clock was rendering at 28, the
        // same size as a section heading. Filled here rather than fixed there.
        //
        // 40 continues the ramp (20 -> 28 -> 40) instead of taking Material's
        // 45, which was drawn for a scale this app does not use. w700 not w800:
        // at this size the heavier weight stops reading as emphasis and starts
        // closing up the counters in 8 and 0.
        displayMedium: TextStyle(
          fontSize: 40,
          fontWeight: FontWeight.w700,
          letterSpacing: -1,
          color: isDark ? Colors.white : _ink,
        ),
        headlineMedium: TextStyle(
          fontSize: 28,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.8,
          color: isDark ? Colors.white : _ink,
        ),
        // The three that were MISSING, and what a missing one actually did.
        //
        // ThemeData does not merge Material's typography in behind this map.
        // An unlisted slot comes through as a TextStyle carrying a colour and
        // NOTHING ELSE -- `fontSize: null, fontWeight: null` -- so every call
        // site asking for it rendered at the ambient body size and weight.
        // 109 of them: `titleSmall` alone was asked for 93 times and had no
        // size of its own, so ninety-three places that meant "a small heading"
        // drew ordinary body text in a slightly darker ink.
        //
        // That is why the headings looked unrelated to each other. Not drift,
        // and not a styling preference -- three of the six slots this app uses
        // were never filled in.
        //
        // Sized to leave a real step on each rung: 28 page title, 20 section,
        // 18 sub-section, 16 the next one down, 14 body. `titleSmall` keeps
        // 14 deliberately -- it is used 93 times and changing its SIZE would
        // move ninety-three layouts. Only the weight moves, which is the half
        // that was making it indistinguishable from the body text beside it.
        headlineSmall: TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.3,
          color: isDark ? Colors.white : _ink,
        ),
        titleLarge: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: isDark ? Colors.white : _ink,
        ),
        titleMedium: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white : _ink,
        ),
        titleSmall: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white : _ink,
        ),
        bodyMedium: TextStyle(
          fontSize: 14,
          color: isDark ? Colors.white70 : _muted,
          height: 1.5,
        ),
        // The MOST-USED slot in the app, and until now the emptiest.
        //
        // Measured 2026-09-10, after Reports asked rather than guessing: 469
        // call sites across 113 files, and `bodySmall` resolved to
        // `fontSize: null, fontWeight: null`. An unlisted slot does not fall
        // back to Material's default -- it arrives carrying a colour and
        // nothing else -- so every one of them rendered at the ambient size,
        // which is 14, which is `bodyMedium`. **The app drew a distinction 469
        // times that nobody could see.**
        //
        // 12 because it has to sit between `labelSmall`'s 11 and `bodyMedium`'s
        // 14 and be visibly neither; it is also Material's own value, so the
        // 469 authors who wrote "small" got what they were asking for. Colour
        // and height follow `bodyMedium` so only the SIZE changes at those call
        // sites, not the palette.
        bodySmall: TextStyle(
          fontSize: 12,
          color: isDark ? Colors.white70 : _muted,
          height: 1.45,
        ),
        // Three call sites, same fault, fixed while the file is open.
        bodyLarge: TextStyle(
          fontSize: 16,
          color: isDark ? Colors.white : _ink,
          height: 1.5,
        ),
        // The rest of the label family, found by the widened guard on its first
        // run -- 51 uses of `labelLarge` and 11 of `labelMedium`, all unfilled.
        //
        // They are FIELD labels ("When it is asked", "Who fills it in"), not
        // button text, so they belong with `labelSmall` below rather than with
        // body copy: somebody who reaches for a label slot is asking for
        // emphasis. Weights sit under `labelSmall`'s w700, which stays the most
        // emphatic because it is also the uppercase one.
        labelLarge: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white : _ink,
        ),
        labelMedium: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: isDark ? Colors.white70 : _muted,
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
  /// Reaches the wordmark in a test without matching its text: the name is
  /// split across two spans, so `find.text('EzBizi')` finds nothing and reads
  /// as absence rather than as the wrong finder.
  static const wordmarkKey = Key('ezbizi-wordmark');

  static Widget wordmark(BuildContext context, {double size = 28}) {
    final onSurface = Theme.of(context).brightness == Brightness.dark
        ? Colors.white
        : _ink;

    return RichText(
      key: wordmarkKey,
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

  /// The fixed dark surface outside the app proper: the sign-in page's
  /// identity panel (auth_shell.dart draws the same three stops) and the Clock
  /// In lock screen. It looks the same in light and dark by design, so what
  /// sits on it is drawn with [dark]'s colours.
  static const LinearGradient brandGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF071018), Color(0xFF0A2233), Color(0xFF06384E)],
  );

  /// A card on [brandGradient]: white at 6%, edged at 12%.
  static const Color brandCard = Color(0x0FFFFFFF);
  static const Color brandEdge = Color(0x1FFFFFFF);

  /// The review-faces widget's two faces, as the WordPress plugin paints
  /// them (`ezbizi_review_faces_shortcode`), so the preview matches the page.
  static const Color reviewHappy = Color(0xFF22B24C);
  static const Color reviewSad = Color(0xFFE23D4B);

  /// The stars on [ezbizi_reviews] until a site picks its own colour.
  static const Color reviewStar = Color(0xFFF5B301);

  /// Eight colours for standing in where a person has no picture.
  ///
  /// Picked by a stable hash of the sender, so the same person keeps the same
  /// colour — which is the whole point of them, and the reason this is a fixed
  /// list rather than anything generated. Reordering it repaints every avatar
  /// in the app, so add to the end rather than inserting.
  ///
  /// It lives here rather than beside the one screen that first needed it for
  /// two reasons. A second surface wanting sender avatars would otherwise
  /// hardcode its own eight and the same person would be two colours in two
  /// places. And these carry white glyphs, so the day dark mode makes one of
  /// them unreadable there is exactly one place to fix it.
  static const List<Color> avatarPalette = [
    Color(0xFF1A73E8),
    Color(0xFFD93025),
    Color(0xFF188038),
    Color(0xFFF9AB00),
    Color(0xFF9334E6),
    Color(0xFF1967D2),
    Color(0xFFE37400),
    Color(0xFF12B5CB),
  ];
}

/// The call-flow canvas palette.
///
/// It WAS one fixed dark set, deliberately: a graph canvas is a drawing rather
/// than a page, and n8n — the reference Jose sent — is dark in both. He looked
/// at it running and ruled the other way: *"should be white on white mode,
/// dark on dark mode"* (2026-09-15). So there are two, and the canvas follows
/// the app like everything else.
///
/// Still declared here rather than in the screen. `theme_conventions_test`
/// caught ten literals accumulating in that one file and was right to; a
/// palette that is not theme-DERIVED still has to be one place.
///
/// Nothing outside the call-flow chart may use these. A second screen wanting
/// "the canvas look" is the moment this stops being a canvas and starts being
/// an unmanaged second theme.
class FlowCanvas {
  const FlowCanvas({
    required this.ink,
    required this.tile,
    required this.tileLine,
    required this.text,
    required this.muted,
    required this.accent,
    required this.wire,
    required this.amber,
    required this.grid,
  });

  /// The ground the graph is drawn on.
  final Color ink;

  /// A node tile, and its outline when the node is ordinary.
  final Color tile;
  final Color tileLine;

  final Color text;
  final Color muted;

  /// The entry node, and the energy travelling a live wire.
  final Color accent;

  /// A wire at rest.
  final Color wire;

  /// A return — the queue going back to the group it came from — and a step
  /// that does nothing. Amber rather than red on purpose: neither is a call
  /// failing.
  final Color amber;

  /// The dot grid. Barely there by design; it says "canvas" and nothing else.
  final Color grid;

  static const FlowCanvas light = FlowCanvas(
    ink: Color(0xFFFBFDFD),
    tile: Color(0xFFFFFFFF),
    tileLine: Color(0xFFD2DCDF),
    text: Color(0xFF0D1117),
    muted: Color(0xFF5B6B73),
    accent: Color(0xFF0F766E),
    wire: Color(0xFFAEBFC4),
    amber: Color(0xFF8A5A00),
    grid: Color(0x14000000),
  );

  static const FlowCanvas dark = FlowCanvas(
    ink: Color(0xFF1D2025),
    tile: Color(0xFF2B2F36),
    tileLine: Color(0xFF3A414A),
    text: Color(0xFFE8EDF2),
    muted: Color(0xFF9AA6B2),
    accent: Color(0xFF5EEAD4),
    wire: Color(0xFF4D5661),
    amber: Color(0xFFD9A441),
    grid: Color(0x14FFFFFF),
  );

  static FlowCanvas of(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark ? dark : light;
}

/// Two states the colour scheme has no word for.
///
/// `ColorScheme` gives us `error` and nothing for its opposite, so every screen
/// that draws "this is working" or "this needs a look" has reached for a
/// literal — three modules had the same green and the same amber written out by
/// hand, which is what `theme_conventions_test` is counting.
///
/// Not added speculatively: these are the two values already in the tree, moved
/// rather than invented. A third state gets added here when a third screen
/// needs one, not before.
class StateColour {
  const StateColour({
    required this.on,
    required this.caution,
    required this.cautionGlow,
  });

  /// Working, live, clocked in. Jose's words for the clock pip were *"Green in,
  /// red out"* — red is `colorScheme.error` and this is the other half.
  final Color on;

  /// Worth a look and not a failure: a shift outside its schedule, a step that
  /// plays nothing. Amber rather than red on purpose — neither is a fault.
  final Color caution;

  /// The same state as LIGHT rather than ink: the glow of a profile picture
  /// whose profile lacks something (ProfileAura; Jose, 2026-09-30, from A1
  /// Tools). [caution] is a text colour, dark enough to read, and a glow in it
  /// is a brown smudge.
  final Color cautionGlow;

  static const StateColour light = StateColour(
    on: Color(0xFF1A7F37),
    caution: Color(0xFF8A5A00),
    cautionGlow: Color(0xFFFF9F1C),
  );

  /// Lifted on the dark ground for the same reason every other slot is: the
  /// light values sit at roughly 3:1 on #1D2025 and disappear at a glance.
  static const StateColour dark = StateColour(
    on: Color(0xFF4CC46A),
    caution: Color(0xFFD9A441),
    cautionGlow: Color(0xFFFFB547),
  );

  static StateColour of(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark ? dark : light;
}

/// How much attention a state asks for, for the pill or chip that shows it.
///
/// Six, because that is how many the Jobs list needed on the day it gained
/// colour (queue 24). The tones are LEVELS, not job words, so a second screen
/// can map its own states onto them — and one has: Scheduling's availability
/// chips (726cfcb8). Each mapping lives beside the thing it colours
/// (`toneForJobStatus`, the availability pane's own switch); this is only
/// where the colours come from, so nobody writes six more literals.
///
/// The uses below are the ones in the tree. A new use that does not read as
/// the same LEVEL of news as the ones listed is a sign it wants a different
/// tone, not a sign this list is out of date.
enum Tone {
  /// Nothing to act on, or nothing known — and the fallback for a value no
  /// mapping recognises, because a guessed colour is a claim.
  /// Jobs: Submitted. Availability: Full, where there is nothing to offer.
  neutral,

  /// Under way. Jobs: In progress.
  info,

  /// Needs somebody soon. Jobs: Pending. Availability: 2 spots left.
  caution,

  /// Finished, and waiting for sign-off. Jobs: Done pending approval.
  review,

  /// All is well. Jobs: Done. Availability: 3 or more left, and the first
  /// available date.
  ok,

  /// The strongest word on the screen: gone, or about to be. Red at Jose's
  /// word, 2026-09-24: *"the canceled tab should be red, not gray"*.
  /// Jobs: Canceled. Availability: 1 spot left — also his, the same week, of
  /// the A1 Tools slots his dispatchers know: *"i do like the green yellow red
  /// gray color tabs for the time slots"*. Both are rulings; neither is a
  /// misuse of the other.
  bad,
}

/// A pill's fill and its text, for each [Tone].
///
/// The text is at least 4.5:1 on its fill in both brightnesses, measured by
/// `tone_colours_test` rather than asserted here.
class ToneColours {
  const ToneColours(this.fill, this.ink);

  final Color fill;
  final Color ink;

  static const _light = {
    Tone.neutral: ToneColours(Color(0xFFE8ECF0), Color(0xFF33404A)),
    Tone.info: ToneColours(Color(0xFFD9EFF8), Color(0xFF00566E)),
    Tone.caution: ToneColours(Color(0xFFFFF0D2), Color(0xFF734600)),
    Tone.review: ToneColours(Color(0xFFEEE5FA), Color(0xFF5A3290)),
    Tone.ok: ToneColours(Color(0xFFE1F2E5), Color(0xFF16662D)),
    Tone.bad: ToneColours(Color(0xFFFDECEC), Color(0xFF9F1D1D)),
  };

  static const _dark = {
    Tone.neutral: ToneColours(Color(0xFF2C333B), Color(0xFFD3DAE0)),
    Tone.info: ToneColours(Color(0xFF0E3A48), Color(0xFF8FD6EE)),
    Tone.caution: ToneColours(Color(0xFF3D2E0C), Color(0xFFF1C56A)),
    Tone.review: ToneColours(Color(0xFF33264A), Color(0xFFCDB6F2)),
    Tone.ok: ToneColours(Color(0xFF163A22), Color(0xFF8FDBA4)),
    Tone.bad: ToneColours(Color(0xFF4A1C1C), Color(0xFFFF8A8A)),
  };

  static ToneColours of(BuildContext context, Tone tone) =>
      (Theme.of(context).brightness == Brightness.dark ? _dark : _light)[tone]!;

  /// Both tables, for the contrast test.
  static Map<Tone, ToneColours> table(Brightness brightness) =>
      brightness == Brightness.dark ? _dark : _light;
}

/// A person's colour, for the avatar beside what they wrote (Leads' replies,
/// Jose's A1 Tools reference): the same person keeps the same colour, on every
/// device. Eight hues and no purple (see the ruling at the top of this file);
/// the initials read at least 4.5:1 on the fill in both brightnesses, measured
/// by `theme_conventions_test`.
abstract final class PersonColours {
  static const _light = [
    ToneColours(Color(0xFFDCEAFB), Color(0xFF0B4A8F)), // blue
    ToneColours(Color(0xFFD8F1F0), Color(0xFF0B5957)), // teal
    ToneColours(Color(0xFFDFF2E3), Color(0xFF1C5E2E)), // green
    ToneColours(Color(0xFFEAF0D2), Color(0xFF4A5A12)), // olive
    ToneColours(Color(0xFFF8EDC6), Color(0xFF6A4F00)), // gold
    ToneColours(Color(0xFFFFE6D2), Color(0xFF8A3A00)), // orange
    ToneColours(Color(0xFFFDE2DE), Color(0xFF8E2418)), // coral
    ToneColours(Color(0xFFEFE3D8), Color(0xFF5E3B22)), // brown
    // Two more for tag sets, never for a person (see forSeed): rose is the
    // nearest this palette comes to Instagram's pink without being the purple
    // this file rules out, and slate is a colour that belongs to nothing.
    ToneColours(Color(0xFFFCE4EC), Color(0xFF9A1B4B)), // rose
    ToneColours(Color(0xFFE6EBF0), Color(0xFF3A4A5A)), // slate
    // Facebook's blue, brighter than LS Call's navy (Jose, 2026-10-12).
    ToneColours(Color(0xFFE0F2FE), Color(0xFF075985)), // sky
  ];

  static const _dark = [
    ToneColours(Color(0xFF16304F), Color(0xFFA9CCFF)),
    ToneColours(Color(0xFF0F3B3A), Color(0xFF8FE0DC)),
    ToneColours(Color(0xFF163A22), Color(0xFF9ADBAA)),
    ToneColours(Color(0xFF2E3710), Color(0xFFCFDE8E)),
    ToneColours(Color(0xFF3A2F08), Color(0xFFF1D27A)),
    ToneColours(Color(0xFF44250C), Color(0xFFFFBE8C)),
    ToneColours(Color(0xFF4A1A15), Color(0xFFFFAA9E)),
    ToneColours(Color(0xFF3A2819), Color(0xFFE3C2A6)),
    ToneColours(Color(0xFF4A1627), Color(0xFFFFA8C5)),
    ToneColours(Color(0xFF27313B), Color(0xFFC3CFDB)),
    ToneColours(Color(0xFF0C3A52), Color(0xFF7DD3FC)),
  ];

  /// People get the first eight, so adding a tag hue never moves anybody's
  /// avatar to a different colour.
  static const int _people = 8;

  /// [seed] is the person's id. A bot is not a person: give it Tone.neutral.
  static ToneColours of(BuildContext context, Object seed) =>
      forSeed(seed, Theme.of(context).brightness);

  /// One hue by position, for a set that must not collide (Leads' source
  /// tags, 2026-09-29): 0 blue, 1 teal, 2 green, 3 olive, 4 gold, 5 orange,
  /// 6 coral, 7 brown, 8 rose, 9 slate, 10 sky.
  static ToneColours hue(BuildContext context, int index) =>
      (Theme.of(context).brightness == Brightness.dark ? _dark : _light)[index %
          _light.length];

  static ToneColours forSeed(Object seed, Brightness brightness) {
    // Not hashCode, which promises nothing across runs; and small enough
    // arithmetic that the web's doubles and the VM's ints agree.
    var hash = 0;
    for (final unit in '$seed'.codeUnits) {
      hash = (hash * 31 + unit) & 0x7fffffff;
    }
    final table = brightness == Brightness.dark ? _dark : _light;

    return table[hash % _people];
  }

  /// Both tables, for the contrast test.
  static List<ToneColours> table(Brightness brightness) =>
      brightness == Brightness.dark ? _dark : _light;
}

/// A newsletter's own colours, copied from `BlockRenderer`. Kept here with
/// every other colour literal (theme_conventions_test).
///
/// Not app chrome, so not from the theme: these are what the recipient's mail
/// client paints, and they stay the same in dark mode because the email does.
/// A change to the renderer's palette has to be made here too.
class EmailInk {
  static const page = Color(0xFFEDF1F4);
  static const paper = Color(0xFFFFFFFF);
  static const heading = Color(0xFF060808);
  static const body = Color(0xFF3A4650);
  static const button = Color(0xFF00708F);
  static const rule = Color(0xFFE9EDF1);
  static const footer = Color(0xFFF5F8FA);
  static const footerInk = Color(0xFF5B6672);
}
