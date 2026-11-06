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
///
/// Every address typed anywhere in the app comes through here or through
/// [AddressLookup] - Jose, 2026-09-30: "I don't want to see this issue
/// anymore anywhere in the app where address is just one field."
/// address_lookup_everywhere_test.dart holds the app to it.
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
    this.dense = true,
    this.copy,
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

  /// As [AddressLookup.dense]: false for a form whose other boxes are the
  /// theme's own.
  final bool dense;

  /// A copy button for a box, by its field key (address_line1, city…); null
  /// leaves every box as it was (HR, design 186).
  final Widget Function(String field, TextEditingController box)? copy;

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
    String label,
    String field,
  ) => notFilledIn(
    context,
    _plain(label, controller, field),
    empty: widget.flagEmpty && controller.text.trim().isEmpty,
  );

  InputDecoration _plain(
    String label,
    TextEditingController controller,
    String field,
  ) => InputDecoration(
    border: widget.dense ? const OutlineInputBorder() : null,
    isDense: widget.dense,
    labelText: label,
    suffixIcon: widget.copy?.call(field, controller),
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
          dense: widget.dense,
        ),
        const SizedBox(height: 12),
        TextField(
          controller: widget.line1,
          enabled: widget.enabled,
          decoration: _box(widget.line1, 'Street', 'address_line1'),
        ),
        const SizedBox(height: 10),
        TextField(
          controller: widget.line2,
          enabled: widget.enabled,
          decoration: _plain(
            'Suite, unit, floor',
            widget.line2,
            'address_line2',
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(flex: 3, child: _city()),
            const SizedBox(width: 10),
            // A fixed width, not a share of the row: on a phone the share was
            // narrower than the box's own padding and the ST label vanished.
            SizedBox(width: widget.copy == null ? 80 : 120, child: _state()),
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
                  decoration: _box(country, 'Country', 'country'),
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
    decoration: _box(widget.city, 'City', 'city'),
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
      'ST',
      'state',
    ).copyWith(counterText: '', hintText: widget.flagEmpty ? '' : null),
  );

  Widget _postal() => TextField(
    controller: widget.postal,
    enabled: widget.enabled,
    decoration: _box(widget.postal, 'ZIP', 'postal_code'),
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
