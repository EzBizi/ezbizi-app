import 'package:flutter/material.dart';

/// A colour box: type `#rrggbb`, or tap the swatch and pick (Jose,
/// 2026-10-01: "for all the fields that require colors, we should have a
/// color picker instead, with the option to input manually as well").
///
/// The picker opens IN PLACE under the box, never in a dialog (§6): a row of
/// ready colours, then hue, colour strength and brightness sliders. Empty
/// means "use the default", so Clear stays one tap away.
class ColourField extends StatefulWidget {
  const ColourField({
    super.key,
    required this.label,
    required this.value,
    required this.onChanged,
    this.enabled = true,
    this.hint = '#rrggbb, empty uses the default',
  });

  final String label;
  final String value;
  final ValueChanged<String> onChanged;
  final bool enabled;
  final String hint;

  /// `#rgb`, `#rrggbb` or `#rrggbbaa` as a colour, or null.
  static Color? parse(String raw) {
    var hex = raw.trim().replaceFirst('#', '');
    if (hex.length == 3 || hex.length == 4) {
      hex = hex.split('').map((c) => '$c$c').join();
    }
    if (!RegExp(r'^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$').hasMatch(hex)) return null;
    final rgb = int.parse(hex.substring(0, 6), radix: 16);
    final alpha = hex.length == 8 ? int.parse(hex.substring(6), radix: 16) : 255;
    return Color((alpha << 24) | rgb);
  }

  static String hex(Color c) =>
      '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';

  @override
  State<ColourField> createState() => _ColourFieldState();
}

class _ColourFieldState extends State<ColourField> {
  late final _box = TextEditingController(text: widget.value);
  bool _open = false;

  /// The common ones: brand-like oranges, blues, greens, and the neutrals.
  /// No purple (house rule; UI pass 11-16): brown took its place.
  static const _ready = [
    Color(0xFFE55300), Color(0xFFD62828), Color(0xFFF2B705), Color(0xFF2E9D4F),
    Color(0xFF0B5CAD), Color(0xFF1877F2), Color(0xFF7F5347), Color(0xFF00897B),
    Color(0xFF1B1B1B), Color(0xFF555555), Color(0xFFF1F3F5), Color(0xFFFFFFFF),
  ];

  @override
  void didUpdateWidget(ColourField old) {
    super.didUpdateWidget(old);
    // A different site or preset was chosen: show its value, not ours.
    if (old.value != widget.value && widget.value != _box.text) {
      _box.text = widget.value;
    }
  }

  @override
  void dispose() {
    _box.dispose();
    super.dispose();
  }

  void _pick(Color c) {
    final hex = ColourField.hex(c);
    setState(() => _box.text = hex);
    widget.onChanged(hex);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colour = ColourField.parse(_box.text);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _box,
            enabled: widget.enabled,
            maxLength: 9,
            style: theme.textTheme.bodyMedium,
            decoration: InputDecoration(
              labelText: widget.label,
              hintText: widget.hint,
              isDense: true,
              counterText: '',
              prefixIcon: Padding(
                padding: const EdgeInsets.all(6),
                child: Tooltip(
                  message: _open ? 'Close the colour picker' : 'Pick a colour',
                  child: InkWell(
                    key: ValueKey('colour-swatch-${widget.label}'),
                    borderRadius: BorderRadius.circular(6),
                    onTap: widget.enabled ? () => setState(() => _open = !_open) : null,
                    child: _Chip(colour),
                  ),
                ),
              ),
              prefixIconConstraints: const BoxConstraints(minWidth: 40, minHeight: 36),
              suffixIcon: _box.text.isEmpty || !widget.enabled
                  ? null
                  : IconButton(
                      tooltip: 'Clear, use the default',
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () {
                        setState(() => _box.clear());
                        widget.onChanged('');
                      },
                    ),
            ),
            onChanged: (v) {
              setState(() {});
              widget.onChanged(v.trim());
            },
          ),
          if (_open && widget.enabled) _picker(theme, colour ?? const Color(0xFFE55300)),
        ],
      ),
    );
  }

  Widget _picker(ThemeData theme, Color current) {
    final hsv = HSVColor.fromColor(current);

    Widget slider(String label, double value, double max, HSVColor Function(double) at) => Row(
          children: [
            SizedBox(width: 84, child: Text(label, style: theme.textTheme.bodySmall)),
            Expanded(
              child: Slider(
                value: value.clamp(0, max),
                max: max,
                onChanged: (v) => _pick(at(v).toColor()),
              ),
            ),
          ],
        );

    return Container(
      key: ValueKey('colour-picker-${widget.label}'),
      margin: const EdgeInsets.only(top: 6),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final c in _ready)
                Tooltip(
                  message: ColourField.hex(c),
                  child: InkWell(
                    key: ValueKey('colour-ready-${ColourField.hex(c)}'),
                    borderRadius: BorderRadius.circular(6),
                    onTap: () => _pick(c),
                    child: _Chip(c, size: 28, selected: c.toARGB32() == current.toARGB32()),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          slider('Hue', hsv.hue, 360, hsv.withHue),
          slider('Strength', hsv.saturation, 1, hsv.withSaturation),
          slider('Brightness', hsv.value, 1, hsv.withValue),
        ],
      ),
    );
  }
}

/// One square of colour, chequered when there is none.
class _Chip extends StatelessWidget {
  const _Chip(this.colour, {this.size = 24, this.selected = false});

  final Color? colour;
  final double size;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: colour ?? scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(
          color: selected ? scheme.primary : scheme.outline,
          width: selected ? 2.5 : 1,
        ),
      ),
      child: colour == null
          ? Icon(Icons.format_color_reset_outlined, size: size * 0.6, color: scheme.onSurfaceVariant)
          : null,
    );
  }
}
