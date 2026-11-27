import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'error_note.dart';

/// The organization's brand colour, and what it makes on the customer's site.
///
/// Sits beside [LogoBadge] on the organization screen because they are one
/// idea: how this business looks wherever we render it for them.
///
/// ── One colour, never a pair ──
///
/// The text ON the colour is derived by the server (`Branding::readableOn`),
/// so there is nothing here for a person to get wrong and nothing to warn
/// about. That is why this card does NOT reuse the contrast warning from
/// Web Management → Widgets. That one reports on a pair a human chose; this
/// one shows a pair we chose. Two jobs.
///
/// The derived value is READ FROM THE SERVER rather than recomputed here, for
/// the same reason: two implementations of "readable" can drift, and the
/// person who finds out is a customer looking at their own website.
///
/// ── The state that matters most is "not set" ──
///
/// It is every organization until somebody uses this card, so it is not an
/// empty slot to be styled later. It says the widgets are legible but not
/// theirs — because the business getting OUR colour on THEIR website is the
/// one least likely to be on this screen.
class BrandColourCard extends StatefulWidget {
  const BrandColourCard({super.key, required this.api, required this.mayEdit});

  final ApiClient api;
  final bool mayEdit;

  /// The swatch row, for tests that need to reach it without colour matching.
  static const swatchesKey = Key('brand-colour-swatches');
  static const previewKey = Key('brand-colour-preview');

  @override
  State<BrandColourCard> createState() => _BrandColourCardState();
}

class _BrandColourCardState extends State<BrandColourCard> {
  /// Measured, not chosen by eye: each clears 4.5:1 against its own derived
  /// text, tightest #00838f at 4.64:1. So a customer who picks rather than
  /// types cannot land on an unreadable button.
  ///
  /// **Keep #00838f whatever else changes here.** It is the colour that
  /// exposed the near-black bug in `Branding::readableOn`: on the old #111111
  /// it read 4.18:1 and would have had our own default trip our own contrast
  /// warning, on a pair no customer chose. This list is incidentally a
  /// regression case for that constant, and that one is the case.
  static const _presets = [
    '#00838f',
    '#0b5cad',
    '#b3261e',
    '#2e7d32',
    '#6a1b9a',
    '#e65100',
    '#37474f',
  ];

  String? _own; // what this organization set, or null
  // What is actually on the page, and its derived text. These ALWAYS answer:
  // for a business that has told us nothing they are the fallback and its
  // text, which is why the not-set state still shows a button. `effective`
  // would have been null there — it answers "have they told us a colour",
  // a different question, and the one `_own` already answers here.
  String? _rendered;
  String? _onRendered;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/branding/colour');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _take(response.body['data']);
    });
  }

  // One save at a time (audit 2026-11-27): quick swatch taps raced.
  bool _saving = false;

  Future<void> _save(String? colour) async {
    if (_saving) return;
    _saving = true;
    setState(() => _error = null);
    final response = await widget.api.put('/branding/colour', {
      'colour': colour,
    });
    _saving = false;
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _take(response.body['data']);
    });
  }

  void _take(Object? data) {
    final map = data is Map ? data : const {};
    _own = map['organization'] as String?;
    _rendered = map['rendered'] as String?;
    _onRendered = map['on_rendered'] as String?;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: LayoutBuilder(
            builder: (context, constraints) => Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Icon(Icons.palette_outlined, size: 18),
                    const SizedBox(width: 8),
                    Text('Brand colour', style: theme.textTheme.titleSmall),
                  ],
                ),
                const SizedBox(height: 10),
                if (_own == null) _notSet(theme),
                if (_error != null) ...[
                  ErrorNote(_error!),
                  const SizedBox(height: 10),
                ],
                // Layout 1 on a desktop: controls beside the button.
                // Layout 6 on a phone: swatches FIRST, so the common act does
                // not open a keyboard, and the hex field under it for somebody
                // who has their brand written down.
                if (constraints.maxWidth < 600) ...[
                  _swatches(theme),
                  const SizedBox(height: 12),
                  _hex(theme),
                  const SizedBox(height: 12),
                  _preview(theme),
                ] else
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            _hex(theme),
                            const SizedBox(height: 12),
                            _swatches(theme),
                          ],
                        ),
                      ),
                      const SizedBox(width: 16),
                      Expanded(child: _preview(theme)),
                    ],
                  ),
                _derived(theme),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _notSet(ThemeData theme) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(
      'You have not set a colour. Your website widgets are using a neutral '
      'blue until you do — it is legible, but it is not yours.',
      style: theme.textTheme.bodySmall,
    ),
  );

  Widget _hex(ThemeData theme) => TextFormField(
    key: ValueKey('brand-hex:${_own ?? ''}'),
    initialValue: _own ?? '',
    enabled: widget.mayEdit,
    style: theme.textTheme.bodyMedium,
    decoration: const InputDecoration(
      labelText: 'Colour (#rrggbb)',
      isDense: true,
    ),
    // Sent unchecked on purpose: the server takes #rrggbb only and refuses
    // anything else with a sentence worth reading. Validating here too would
    // be a second rule to keep in step with that one.
    onFieldSubmitted: (value) {
      final typed = value.trim();
      _save(typed.isEmpty ? null : typed);
    },
  );

  Widget _swatches(ThemeData theme) => Wrap(
    key: BrandColourCard.swatchesKey,
    spacing: 8,
    runSpacing: 8,
    children: [
      for (final preset in _presets)
        InkWell(
          onTap: widget.mayEdit ? () => _save(preset) : null,
          borderRadius: BorderRadius.circular(8),
          child: Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: _parse(preset),
              borderRadius: BorderRadius.circular(8),
              border: Border.all(
                color: _own == preset
                    ? theme.colorScheme.primary
                    : theme.dividerColor,
                width: _own == preset ? 2 : 1,
              ),
            ),
          ),
        ),
    ],
  );

  Widget _preview(ThemeData theme) {
    final background = _parse(_rendered);
    final foreground = _parse(_onRendered);

    // Nothing invented: with no derived colour from the server there is no
    // honest button to draw, so the card shows the controls and stops.
    if (background == null || foreground == null) return const SizedBox.shrink();

    return Container(
      key: BrandColourCard.previewKey,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'ON YOUR WEBSITE',
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: FontWeight.w700,
              letterSpacing: 0.4,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
            decoration: BoxDecoration(
              color: background,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'Send',
              style: TextStyle(color: foreground, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }

  /// Names the text colour the SERVER chose, never one worked out here.
  Widget _derived(ThemeData theme) {
    final lines = <String>[
      if (_onRendered != null)
        'The text on it is chosen for you — '
            '${_onRendered == '#000000' ? 'black' : 'white'} on this colour. '
            'You pick one colour, not a pair.',
      'Companies follow this unless they set their own.',
    ];

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Divider(height: 1, color: theme.dividerColor),
          const SizedBox(height: 10),
          for (final line in lines)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(line, style: theme.textTheme.bodySmall),
            ),
        ],
      ),
    );
  }

  static Color? _parse(String? value) {
    final match = RegExp(
      r'^#([0-9a-f]{6})$',
    ).firstMatch((value ?? '').toLowerCase());
    return match == null
        ? null
        : Color(int.parse('ff${match.group(1)}', radix: 16));
  }
}
