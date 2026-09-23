/// What the four RENDERED widgets look like, drawn from the preset being edited.
///
/// Jose, 2026-09-23: *"each widget should have a preview, right now only live
/// chat and contact form do"*.
///
/// ── What these are honest about ───────────────────────────────────
///
/// Every one of these is markup the WordPress plugin writes onto a page we do
/// not serve, inside a theme we do not know. So none of this is a screenshot
/// and none of it pretends to be: the typeface, the page background and the
/// space around the widget come from the customer's own site.
///
/// What they ARE exact about is the thing somebody is changing while they look
/// — the icon size in pixels, the number of columns, whether today's row is
/// marked, the corner radius, the accent. Those are the values on the screen,
/// and a preview that got them wrong would be worse than none.
///
/// ── Why the content is invented and the SHAPE is not ──────────────
///
/// The towns, the networks and the week below are stand-ins. The real ones
/// live on the customer's site and arrive at render time; fetching them to
/// dress a preview would make this screen depend on a live site to show a
/// colour. They are named obviously enough that nobody reads them as data —
/// "Springfield" is not a town anybody will think EzBizi knows about.
library;

import 'package:flutter/material.dart';

/// One preview, by widget key. An unknown key draws nothing rather than
/// throwing: the catalogue is a hand copy of the server's, and the day
/// somebody adds a fifth widget there this should be an absent preview and not
/// a crash on a screen that was working.
Widget renderedPreview({
  required String key,
  required ThemeData theme,
  required Map<String, dynamic> options,
  required Color accent,
  required Color onAccent,
  required Color text,
  required Color surface,
  required double radius,
}) {
  switch (key) {
    case 'social_links':
      return _socialLinks(options, accent, onAccent, text, radius);
    case 'city_list':
      return _cityList(options, accent, text);
    case 'hours_table':
      return _hoursTable(options, accent, onAccent, text);
    case 'google_map':
      return _map(options, theme, accent, text, radius);
    default:
      return const SizedBox.shrink();
  }
}

int _int(Object? v, int fallback) =>
    v is num ? v.toInt() : int.tryParse('${v ?? ''}') ?? fallback;

bool _flag(Object? v, bool fallback) => v is bool ? v : fallback;

String _pick(Object? v, String fallback) {
  final s = '${v ?? ''}';

  return s.isEmpty ? fallback : s;
}

const List<(String, IconData)> _networks = [
  ('Facebook', Icons.facebook),
  ('Instagram', Icons.camera_alt_outlined),
  ('YouTube', Icons.play_circle_outline),
  ('LinkedIn', Icons.business_center_outlined),
];

/// A row of icons, at the size and spacing set, in the shape set.
///
/// `style` is the only one worth spelling out: plain is the icon alone, solid
/// fills the shape with the accent, outline draws the shape in it. All three
/// are the plugin's own words, so the preview and the dropdown agree.
Widget _socialLinks(
  Map<String, dynamic> options,
  Color accent,
  Color onAccent,
  Color text,
  double radius,
) {
  final style = _pick(options['style'], 'plain');
  final shape = _pick(options['shape'], 'circle');
  final size = _int(options['size'], 28).toDouble();
  final gap = _int(options['gap'], 10).toDouble();
  final labels = _flag(options['labels'], false);

  final box = size + 14;

  BorderRadius corners() => switch (shape) {
    'square' => BorderRadius.zero,
    'rounded' => BorderRadius.circular(radius),
    _ => BorderRadius.circular(box),
  };

  return Wrap(
    spacing: gap,
    runSpacing: gap,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      for (final (name, icon) in _networks)
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: style == 'plain' ? size : box,
              height: style == 'plain' ? size : box,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: style == 'solid' ? accent : null,
                borderRadius: style == 'plain' ? null : corners(),
                border: style == 'outline'
                    ? Border.all(color: accent, width: 1.5)
                    : null,
              ),
              child: Icon(
                icon,
                size: size * 0.7,
                color: style == 'solid' ? onAccent : accent,
              ),
            ),
            if (labels) ...[
              const SizedBox(width: 6),
              Text(name, style: TextStyle(color: text, fontSize: 12)),
            ],
          ],
        ),
    ],
  );
}

const List<(String, List<String>)> _towns = [
  ('Hampden County', ['Springfield', 'Chicopee', 'Westfield']),
  ('Hartford County', ['Hartford', 'Enfield', 'Windsor']),
];

/// The towns, arranged the way the preset says.
///
/// All three arrangements are drawn rather than one with a note, because the
/// arrangement IS what somebody is choosing here and "accordion" means nothing
/// until you see that it collapses.
Widget _cityList(Map<String, dynamic> options, Color accent, Color text) {
  final layout = _pick(options['layout'], 'columns');
  final columns = _int(options['columns'], 3).clamp(1, 6);
  final grouped = _flag(options['group'], true);
  final linked = _flag(options['link'], true);

  final style = TextStyle(
    color: linked ? accent : text,
    fontSize: 12,
    decoration: linked ? TextDecoration.underline : null,
  );

  Widget names(List<String> list) {
    if (layout == 'list') {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [for (final town in list) Text(town, style: style)],
      );
    }

    // columns, and the accordion's body once it is open
    return Wrap(
      spacing: 16,
      runSpacing: 4,
      children: [
        for (final town in list)
          SizedBox(width: (220 / columns) - 8, child: Text(town, style: style)),
      ],
    );
  }

  final heading = TextStyle(
    color: text,
    fontSize: 12,
    fontWeight: FontWeight.w700,
  );

  if (!grouped) {
    return names([for (final (_, list) in _towns) ...list]);
  }

  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      for (final (county, list) in _towns) ...[
        Row(
          children: [
            if (layout == 'accordion') ...[
              Icon(Icons.expand_more, size: 16, color: text),
              const SizedBox(width: 4),
            ],
            Text(county, style: heading),
          ],
        ),
        const SizedBox(height: 4),
        // An accordion shows the first county open and the rest shut, which is
        // what the plugin renders and the only way the choice reads as
        // different from a plain list.
        if (layout != 'accordion' || county == _towns.first.$1) names(list),
        const SizedBox(height: 10),
      ],
    ],
  );
}

const List<(String, String)> _week = [
  ('Monday', '8:00 AM – 5:00 PM'),
  ('Tuesday', '8:00 AM – 5:00 PM'),
  ('Wednesday', '8:00 AM – 5:00 PM'),
  ('Thursday', '8:00 AM – 5:00 PM'),
  ('Friday', '8:00 AM – 5:00 PM'),
  ('Saturday', 'Closed'),
  ('Sunday', 'Closed'),
];

/// The published week as a table.
///
/// `today` is drawn against a real weekday rather than a fixed row, so the
/// person deciding whether to switch it on sees it land where it will actually
/// land. `compact` collapses days that share hours, which is the plugin's own
/// rule and the reason Mon–Fri becomes one row.
Widget _hoursTable(
  Map<String, dynamic> options,
  Color accent,
  Color onAccent,
  Color text,
) {
  final markToday = _flag(options['today'], true);
  final compact = _flag(options['compact'], false);

  // DateTime.weekday is 1 = Monday, and _week is Monday first.
  final todayIndex = DateTime.now().weekday - 1;

  final rows = <(String, String, bool)>[];

  if (!compact) {
    for (var i = 0; i < _week.length; i++) {
      rows.add((_week[i].$1, _week[i].$2, i == todayIndex));
    }
  } else {
    var start = 0;
    for (var i = 1; i <= _week.length; i++) {
      if (i < _week.length && _week[i].$2 == _week[start].$2) continue;

      final span = start == i - 1
          ? _week[start].$1
          : '${_week[start].$1} – ${_week[i - 1].$1}';

      rows.add((span, _week[start].$2, todayIndex >= start && todayIndex < i));
      start = i;
    }
  }

  return Column(
    children: [
      for (final (day, hours, isToday) in rows)
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          color: markToday && isToday ? accent : null,
          child: Row(
            children: [
              Expanded(
                child: Text(
                  day,
                  style: TextStyle(
                    color: markToday && isToday ? onAccent : text,
                    fontSize: 12,
                    fontWeight: markToday && isToday
                        ? FontWeight.w700
                        : FontWeight.w400,
                  ),
                ),
              ),
              Text(
                hours,
                style: TextStyle(
                  color: markToday && isToday ? onAccent : text,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
    ],
  );
}

/// The map, at the height set.
///
/// Deliberately NOT a real map. Loading Google's tiles here would put a
/// billable request behind every keystroke on a zoom slider, on a screen
/// somebody leaves open — and the two things being chosen are the height and
/// the zoom, which a drawn frame shows as well as a photograph would.
Widget _map(
  Map<String, dynamic> options,
  ThemeData theme,
  Color accent,
  Color text,
  double radius,
) {
  final height = _int(options['height'], 320).toDouble();
  final zoom = _int(options['zoom'], 14);

  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Container(
        // Capped so a 900px map does not push everything else off the pane;
        // the real one is the height set, and the line below says so.
        height: height.clamp(80.0, 260.0),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(radius),
          border: Border.all(color: theme.colorScheme.outlineVariant),
        ),
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.location_on, size: 28, color: accent),
              const SizedBox(height: 4),
              Text(
                'Zoom $zoom',
                style: TextStyle(color: text, fontSize: 12),
              ),
            ],
          ),
        ),
      ),
      const SizedBox(height: 6),
      Text(
        height > 260
            ? '${height.round()}px tall on the page — shown shorter here so it '
                  'fits beside the settings.'
            : '${height.round()}px tall.',
        style: theme.textTheme.bodySmall,
      ),
    ],
  );
}
