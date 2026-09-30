import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import 'address_lookup.dart';
import 'not_filled_in.dart';

/// Street, suite, city, state, postal — and a search box that fills them.
///
/// The same five columns a company and a customer have. Search talks to
/// `/address-lookup`; picking a suggestion writes the parts, and the parts
/// are what we save.
///
/// A person's home address adds [country] (Jose, 2026-09-30: "Main Address -
/// Unit - City - State - Country - Zip"), and marks its empty boxes amber
/// with [flagEmpty], as the rest of their profile does.
class AddressFields extends StatefulWidget {
  const AddressFields({
    super.key,
    required this.api,
    required this.line1,
    required this.line2,
    required this.city,
    required this.state,
    required this.postal,
    this.country,
    this.enabled = true,
    this.flagEmpty = false,
  });

  final ApiClient api;
  final TextEditingController line1;
  final TextEditingController line2;
  final TextEditingController city;
  final TextEditingController state;
  final TextEditingController postal;

  /// Null leaves the country out, as a company and a customer have none.
  final TextEditingController? country;

  final bool enabled;

  /// Amber while empty: every box but the suite, which most addresses lack.
  final bool flagEmpty;

  @override
  State<AddressFields> createState() => _AddressFieldsState();
}

class _AddressFieldsState extends State<AddressFields> {
  void _apply(Map<String, dynamic> suggestion) {
    widget.line1.text = '${suggestion['address_line1'] ?? ''}';
    widget.city.text = '${suggestion['city'] ?? ''}';
    widget.state.text = '${suggestion['state'] ?? ''}';
    widget.postal.text = '${suggestion['postal_code'] ?? ''}';
    widget.country?.text = '${suggestion['country'] ?? ''}';
  }

  @override
  void initState() {
    super.initState();
    // The amber follows what is typed, not only what was loaded.
    if (widget.flagEmpty) {
      for (final c in _flagged) {
        c.addListener(_changed);
      }
    }
  }

  @override
  void dispose() {
    for (final c in _flagged) {
      c.removeListener(_changed);
    }
    super.dispose();
  }

  List<TextEditingController> get _flagged => [
    widget.line1,
    widget.city,
    widget.state,
    widget.postal,
    ?widget.country,
  ];

  void _changed() => setState(() {});

  /// A box's decoration, amber and "Not filled in" when [flagEmpty] and it
  /// is empty.
  InputDecoration _box(
    TextEditingController controller,
    InputDecoration decoration,
  ) => notFilledIn(
    context,
    decoration,
    empty: widget.flagEmpty && controller.text.trim().isEmpty,
  );

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AddressLookup(
          api: widget.api,
          enabled: widget.enabled,
          onPicked: _apply,
        ),
        const SizedBox(height: 12),
        TextField(
          controller: widget.line1,
          enabled: widget.enabled,
          decoration: _box(
            widget.line1,
            const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              labelText: 'Street',
            ),
          ),
        ),
        const SizedBox(height: 10),
        TextField(
          controller: widget.line2,
          enabled: widget.enabled,
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            labelText: 'Suite, unit, floor',
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(flex: 3, child: _city()),
            const SizedBox(width: 10),
            // A fixed width, not a share of the row: on a phone the share was
            // narrower than the box's own padding and the ST label vanished.
            SizedBox(width: 80, child: _state()),
            if (widget.country == null) ...[
              const SizedBox(width: 10),
              Expanded(flex: 2, child: _postal()),
            ],
          ],
        ),
        if (widget.country case final country?) ...[
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                flex: 3,
                child: TextField(
                  controller: country,
                  enabled: widget.enabled,
                  decoration: _box(
                    country,
                    const InputDecoration(
                      border: OutlineInputBorder(),
                      isDense: true,
                      labelText: 'Country',
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(flex: 2, child: _postal()),
            ],
          ),
        ],
      ],
    );
  }

  Widget _city() => TextField(
    controller: widget.city,
    enabled: widget.enabled,
    decoration: _box(
      widget.city,
      const InputDecoration(
        border: OutlineInputBorder(),
        isDense: true,
        labelText: 'City',
      ),
    ),
  );

  Widget _state() => TextField(
    controller: widget.state,
    enabled: widget.enabled,
    maxLength: 2,
    textCapitalization: TextCapitalization.characters,
    inputFormatters: [
      FilteringTextInputFormatter.allow(RegExp('[A-Za-z]')),
      LengthLimitingTextInputFormatter(2),
      _UpperCaseFormatter(),
    ],
    decoration: _box(
      widget.state,
      const InputDecoration(
        border: OutlineInputBorder(),
        isDense: true,
        labelText: 'ST',
        counterText: '',
      ),
    ).copyWith(hintText: widget.flagEmpty ? '' : null),
  );

  Widget _postal() => TextField(
    controller: widget.postal,
    enabled: widget.enabled,
    decoration: _box(
      widget.postal,
      const InputDecoration(
        border: OutlineInputBorder(),
        isDense: true,
        labelText: 'ZIP',
      ),
    ),
  );
}

class _UpperCaseFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    return newValue.copyWith(text: newValue.text.toUpperCase());
  }
}
