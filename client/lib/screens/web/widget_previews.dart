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

import 'package:ezbizi_client/core/theme.dart';
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
  /*
   * The part colours the Colours section sets (Jose, 2026-10-05: "she
   * changes color from orange to blue, but the live preview remains
   * orange"). The previews were drawn from the site's accent only, so a
   * colour chosen for the icons or the button never reached them. Each part
   * colour now stands in for the default it replaces; the box's background
   * and border wrap the whole preview.
   */
  Color? first(List<String> keys) {
    for (final k in keys) {
      final c = _hex('${options[k] ?? ''}');
      if (c != null) return c;
    }
    return null;
  }

  accent = first(['icon_color', 'button_bg', 'badge_bg', 'submit_bg', 'icon_bg', 'link_color', 'arrow_color']) ?? accent;
  onAccent = first(['button_color', 'badge_color', 'submit_color']) ?? onAccent;
  text = first(['title_color', 'label_color', 'value_color', 'name_color', 'text_color', 'heading_color',
          'question_color', 'number_color', 'day_color', 'address_color', 'link_color']) ??
      text;
  final boxBg = first(['box_bg', 'card_bg', 'item_bg']);
  final boxBorder = first(['box_border_color', 'card_border_color', 'item_border_color']);

  final drawn = _drawn(key, theme, options, accent, onAccent, text, surface, radius);
  if (boxBg == null && boxBorder == null) return drawn;

  return Container(
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(
      color: boxBg,
      border: boxBorder == null ? null : Border.all(color: boxBorder, width: 2),
      borderRadius: BorderRadius.circular(radius),
    ),
    child: drawn,
  );
}

Widget _drawn(
  String key,
  ThemeData theme,
  Map<String, dynamic> options,
  Color accent,
  Color onAccent,
  Color text,
  Color surface,
  double radius,
) {
  switch (key) {
    case 'social_links':
      return _socialLinks(options, accent, onAccent, text, radius);
    case 'city_list':
      return _cityList(options, accent, text);
    case 'hours_table':
      return _hoursTable(options, accent, onAccent, text);
    case 'google_map':
      return _map(options, theme, accent, text, radius);
    case 'contact_info':
      return _contactInfo(options, accent, text);
    case 'cta':
      return _cta(options, theme, accent, onAccent, text, radius);
    case 'phone_box':
      return _phoneBox(options, accent, text);
    case 'review_faces':
      return _reviewFaces(options, theme, text);
    case 'team':
      return _team(options, accent, text, radius);
    case 'branches':
      return _branches(options, accent, onAccent, text);
    case 'store_locator':
      return _storeLocator(options, accent, text);
    case 'services':
      return _services(options, accent, text);
    case 'reviews':
      return _reviews(options, text);
    case 'before_after':
      return _beforeAfter(options, text);
    case 'faq':
      return _faq(options, text);
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

/// The stand-in details.
///
/// Obvious stand-ins on purpose, like the towns above: the real ones come from
/// the site's own values at render time, and a preview that fetched them would
/// make this screen depend on a live site to show an arrangement.
const List<(String, String, IconData)> _details = [
  ('phone', 'Phone', Icons.phone_outlined),
  ('email', 'Email', Icons.mail_outline),
  ('address', 'Location', Icons.location_on_outlined),
];

const Map<String, String> _detailValues = {
  'phone': '(888) 984-4344',
  'email': 'office@example.com',
  'address': '1 Elm Street, Springfield',
};

/// Phone, email and address as one block.
///
/// All three arrangements are drawn rather than described, for the same reason
/// the towns are: "compact" means nothing until you see that the labels go.
Widget _contactInfo(Map<String, dynamic> options, Color accent, Color text) {
  final layout = _pick(options['layout'], 'stack');
  final icons = _flag(options['icons'], true);
  final labels = _flag(options['labels'], true);

  final shown = [
    for (final detail in _details)
      if (_flag(options[detail.$1], true)) detail,
  ];

  if (shown.isEmpty) {
    // The state somebody reaches by switching all three off, which is
    // reachable and renders nothing on the real page either.
    return Text(
      'Nothing selected, so this publishes nothing.',
      style: TextStyle(color: text.withValues(alpha: 0.7), fontSize: 12),
    );
  }

  Widget one((String, String, IconData) detail) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      if (icons) ...[
        Container(
          width: layout == 'compact' ? 24 : 34,
          height: layout == 'compact' ? 24 : 34,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(8),
            border: layout == 'compact'
                ? null
                : Border.all(color: accent, width: 1.2),
          ),
          child: Icon(detail.$3, size: 16, color: accent),
        ),
        const SizedBox(width: 8),
      ],
      /*
       * Flexible, and the value is allowed TWO lines.
       *
       * An address is the longest thing this block ever holds and the preview
       * pane is about 360 wide, so a Row of intrinsic-width children runs off
       * the edge — 44 pixels, measured, the first time this was written. On a
       * real footer it would wrap; here it had nothing to wrap into.
       */
      Flexible(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            if (labels)
              Text(
                detail.$2,
                style: TextStyle(
                  color: text,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                ),
              ),
            Text(
              _detailValues[detail.$1]!,
              style: TextStyle(color: text, fontSize: 12),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    ],
  );

  if (layout == 'stack') {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final detail in shown)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: one(detail),
          ),
      ],
    );
  }

  return Wrap(
    spacing: layout == 'compact' ? 12 : 18,
    runSpacing: 10,
    children: [for (final detail in shown) one(detail)],
  );
}

/// The call to action, in whichever of its three placements is set.
///
/// The bar and the popup are drawn as they SIT — one along the bottom of the
/// mock page, one floating over it — because the placement is the whole
/// decision and a button previewed in place three times would not show it.
Widget _cta(
  Map<String, dynamic> options,
  ThemeData theme,
  Color accent,
  Color onAccent,
  Color text,
  double radius,
) {
  final placement = _pick(options['placement'], 'inline');
  final action = _pick(options['action'], 'call');
  final icon = _flag(options['icon'], true);
  final wide = _flag(options['full_width'], false);
  final typed = '${options['text'] ?? ''}'.trim();

  final words = typed.isNotEmpty
      ? typed
      : switch (action) {
          'book' => 'Book online',
          'form' => 'Book a visit',
          _ => 'Call us today',
        };

  final button = Container(
    padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
    decoration: BoxDecoration(
      color: accent,
      borderRadius: BorderRadius.circular(radius),
    ),
    child: Row(
      mainAxisSize: wide ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (icon) ...[
          Icon(
            action == 'call' ? Icons.phone_outlined : Icons.mail_outline,
            size: 16,
            color: onAccent,
          ),
          const SizedBox(width: 8),
        ],
        Text(
          words,
          style: TextStyle(
            color: onAccent,
            fontSize: 13,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );

  if (placement == 'inline') return button;

  // A mock page with the thing on it, so the placement is visible rather than
  // described. 150 tall is enough to read as a page and short enough to leave
  // room for the settings beside it.
  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      SizedBox(
        height: 150,
        child: Stack(
          children: [
            Positioned.fill(
              child: Container(
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: theme.colorScheme.outlineVariant),
                ),
                alignment: Alignment.topLeft,
                padding: const EdgeInsets.all(10),
                child: Text(
                  'your page',
                  style: TextStyle(
                    color: text.withValues(alpha: 0.5),
                    fontSize: 11,
                  ),
                ),
              ),
            ),
            if (placement == 'bar')
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                child: Container(
                  color: accent,
                  padding: const EdgeInsets.all(6),
                  alignment: Alignment.center,
                  child: button,
                ),
              )
            else
              Positioned(right: 10, bottom: 14, child: button),
          ],
        ),
      ),
      const SizedBox(height: 6),
      Text(
        placement == 'bar'
            ? 'Fixed along the bottom of every page it is placed on.'
            : 'Opens once a visit, over the page.',
        style: theme.textTheme.bodySmall,
      ),
    ],
  );
}

/// Three stand-in people. The real list arrives at render time.
Widget _team(
    Map<String, dynamic> options, Color accent, Color text, double radius) {
  final columns = _int(options['columns'], 3).clamp(1, 6);
  final round = _pick(options['photo'], 'square') == 'circle';
  final bio = _flag(options['bio'], true);

  Widget person(String name, String role) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          AspectRatio(
            aspectRatio: 1,
            child: Container(
              decoration: BoxDecoration(
                color: accent.withValues(alpha: 0.15),
                shape: round ? BoxShape.circle : BoxShape.rectangle,
                borderRadius: round ? null : BorderRadius.circular(radius / 2),
              ),
              child: Icon(Icons.person_outline, color: accent),
            ),
          ),
          const SizedBox(height: 6),
          Text(name,
              style: TextStyle(color: text, fontWeight: FontWeight.w700),
              maxLines: 1,
              overflow: TextOverflow.ellipsis),
          Text(role,
              style: TextStyle(color: text.withValues(alpha: 0.8), fontSize: 12),
              maxLines: 1,
              overflow: TextOverflow.ellipsis),
          if (bio)
            Text('A line they wrote.',
                style: TextStyle(color: text.withValues(alpha: 0.7), fontSize: 11),
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
        ],
      );

  final people = [
    person('Pat Example', 'Technician'),
    person('Sam Example', 'Dispatcher'),
    person('Kim Example', 'Owner'),
  ];

  // A Wrap of fixed widths rather than a grid with a fixed aspect: the text
  // under each photo sets the height, so a line more cannot overflow it.
  const width = 360.0;
  final each = (width - 12 * (columns - 1)) / columns;

  return SizedBox(
    width: width,
    child: Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [for (final p in people) SizedBox(width: each, child: p)],
    ),
  );
}

/// One stand-in branch card, with what the switches leave on it.
Widget _branches(Map<String, dynamic> options, Color accent, Color onAccent,
    Color text) {
  return SizedBox(
    width: 240,
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('North Springfield',
            style: TextStyle(color: text, fontWeight: FontWeight.w700, fontSize: 16)),
        const SizedBox(height: 4),
        if (_flag(options['address'], true))
          Text('1 Example St, Springfield',
              style: TextStyle(color: text.withValues(alpha: 0.8), fontSize: 12)),
        Text('(555) 010-0100', style: TextStyle(color: text)),
        if (_flag(options['map'], true)) ...[
          const SizedBox(height: 8),
          Container(
            height: 80,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Center(child: Icon(Icons.map_outlined, color: accent)),
          ),
        ],
        if (_flag(options['directions'], true)) ...[
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            decoration: BoxDecoration(
              color: accent,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text('Get Directions',
                style: TextStyle(color: onAccent, fontWeight: FontWeight.w700)),
          ),
        ],
      ],
    ),
  );
}

/// The search bar and one stand-in store.
Widget _storeLocator(Map<String, dynamic> options, Color accent, Color text) {
  return SizedBox(
    width: 300,
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_flag(options['search'], true))
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              border: Border.all(color: text.withValues(alpha: 0.3)),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text('Search by town or ZIP',
                style: TextStyle(color: text.withValues(alpha: 0.6), fontSize: 12)),
          ),
        const SizedBox(height: 8),
        if (_flag(options['images'], true))
          Container(
            height: 60,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Center(child: Icon(Icons.storefront_outlined, color: accent)),
          ),
        const SizedBox(height: 6),
        Text('Springfield store',
            style: TextStyle(color: text, fontWeight: FontWeight.w700)),
        Text('1 Example St, Springfield',
            style: TextStyle(color: text.withValues(alpha: 0.8), fontSize: 12)),
        Text('DIRECTIONS',
            style: TextStyle(color: accent, fontWeight: FontWeight.w700, fontSize: 12)),
        if (_flag(options['maps'], true)) ...[
          const SizedBox(height: 6),
          Container(
            height: 50,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Center(child: Icon(Icons.map_outlined, color: accent)),
          ),
        ],
      ],
    ),
  );
}

/// A #rgb or #rrggbb the screen was given, as a colour, or null.
Color? _hex(String raw) {
  var h = raw.replaceFirst('#', '');
  if (h.length == 3) h = h.split('').map((c) => '$c$c').join();
  final v = h.length == 6 ? int.tryParse('FF$h', radix: 16) : null;
  return v == null ? null : Color(v);
}

/// Two stand-in services.
Widget _services(Map<String, dynamic> options, Color accent, Color text) {
  Widget card(String name) => SizedBox(
        width: 150,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            if (_flag(options['image'], true))
              Container(
                height: 70,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(6),
                ),
              ),
            if (_flag(options['icon'], true))
              Icon(Icons.handyman_outlined, color: accent),
            Text(name, style: TextStyle(color: text, fontWeight: FontWeight.w700)),
            if (_flag(options['description'], true))
              Text('What it includes.',
                  style: TextStyle(color: text.withValues(alpha: 0.8), fontSize: 12)),
          ],
        ),
      );

  return Wrap(spacing: 12, runSpacing: 12, children: [card('Example service'), card('Another one')]);
}

/// One stand-in review.
Widget _reviews(Map<String, dynamic> options, Color text) {
  final star = _hex('${options['star_color'] ?? ''}') ?? EzBiziTheme.reviewStar;

  return SizedBox(
    width: 280,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('Pat Example', style: TextStyle(color: text, fontWeight: FontWeight.w700)),
        if (_flag(options['stars'], true))
          Text('★★★★★', style: TextStyle(color: star, letterSpacing: 2)),
        Text('Quick, tidy and on time.', style: TextStyle(color: text)),
      ],
    ),
  );
}

/// One stand-in pair, split down the middle.
Widget _beforeAfter(Map<String, dynamic> options, Color text) {
  final before = '${options['before_label'] ?? 'Before'}';
  final after = '${options['after_label'] ?? 'After'}';

  return SizedBox(
    width: 260,
    height: 150,
    child: Row(
      children: [
        Expanded(
          child: Container(
            color: text.withValues(alpha: 0.25),
            alignment: Alignment.topLeft,
            padding: const EdgeInsets.all(6),
            child: Text(before, style: TextStyle(color: text, fontSize: 11)),
          ),
        ),
        Container(width: 3, color: text),
        Expanded(
          child: Container(
            color: text.withValues(alpha: 0.08),
            alignment: Alignment.topRight,
            padding: const EdgeInsets.all(6),
            child: Text(after, style: TextStyle(color: text, fontSize: 11)),
          ),
        ),
      ],
    ),
  );
}

/// Two stand-in questions, the first open.
Widget _faq(Map<String, dynamic> options, Color text) {
  final chevron = _pick(options['icon'], 'plus') == 'chevron';

  Widget row(String q, {String? answer}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(q, style: TextStyle(color: text, fontWeight: FontWeight.w700)),
                ),
                Text(chevron ? '›' : '+', style: TextStyle(color: text)),
              ],
            ),
            if (answer != null)
              Text(answer, style: TextStyle(color: text.withValues(alpha: 0.8), fontSize: 12)),
          ],
        ),
      );

  return SizedBox(
    width: 300,
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        row('Do you work weekends?', answer: 'Saturdays, by appointment.'),
        row('Are you insured?'),
      ],
    ),
  );
}

/// The two faces, as a visitor first sees them.
Widget _reviewFaces(
    Map<String, dynamic> options, ThemeData theme, Color text) {
  String s(String k) => '${options[k] ?? ''}';
  final size = _int(options['face_size'], 56).toDouble();

  Widget face(IconData icon, Color colour, String label) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: size, color: colour),
          const SizedBox(height: 6),
          Text(label, style: TextStyle(color: text)),
        ],
      );

  return Column(
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(
        s('title'),
        textAlign: TextAlign.center,
        style: theme.textTheme.titleMedium?.copyWith(color: text),
      ),
      const SizedBox(height: 4),
      Text(
        s('text'),
        textAlign: TextAlign.center,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(color: text),
      ),
      const SizedBox(height: 12),
      Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          face(Icons.sentiment_dissatisfied_outlined, EzBiziTheme.reviewSad,
              s('sad_label')),
          const SizedBox(width: 32),
          face(Icons.sentiment_satisfied_outlined, EzBiziTheme.reviewHappy,
              s('happy_label')),
        ],
      ),
    ],
  );
}

/// The number on its own.
Widget _phoneBox(Map<String, dynamic> options, Color accent, Color text) {
  final size = _pick(options['size'], 'medium');
  final icon = _flag(options['icon'], true);
  final label = '${options['label'] ?? ''}'.trim();

  final font = switch (size) {
    'small' => 16.0,
    'large' => 30.0,
    _ => 22.0,
  };

  return Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      if (icon) ...[
        Icon(Icons.phone_outlined, size: font * 0.9, color: accent),
        const SizedBox(width: 10),
      ],
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (label.isNotEmpty)
            Text(
              label,
              style: TextStyle(
                color: text.withValues(alpha: 0.8),
                fontSize: font * 0.5,
              ),
            ),
          Text(
            '(888) 984-4344',
            style: TextStyle(
              color: text,
              fontSize: font,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    ],
  );
}
