import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import 'address_lookup.dart';

/// Street, suite, city, state, postal — and a search box that fills them.
///
/// The same five columns a company and a customer have. Search talks to
/// `/address-lookup`; picking a suggestion writes the parts, and the parts
/// are what we save.
class AddressFields extends StatefulWidget {
  const AddressFields({
    super.key,
    required this.api,
    required this.line1,
    required this.line2,
    required this.city,
    required this.state,
    required this.postal,
    this.enabled = true,
  });

  final ApiClient api;
  final TextEditingController line1;
  final TextEditingController line2;
  final TextEditingController city;
  final TextEditingController state;
  final TextEditingController postal;
  final bool enabled;

  @override
  State<AddressFields> createState() => _AddressFieldsState();
}

class _AddressFieldsState extends State<AddressFields> {
  void _apply(Map<String, dynamic> suggestion) {
    widget.line1.text = '${suggestion['address_line1'] ?? ''}';
    widget.city.text = '${suggestion['city'] ?? ''}';
    widget.state.text = '${suggestion['state'] ?? ''}';
    widget.postal.text = '${suggestion['postal_code'] ?? ''}';
  }

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
          decoration: const InputDecoration(
            border: OutlineInputBorder(),
            isDense: true,
            labelText: 'Street',
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
            Expanded(
              flex: 3,
              child: TextField(
                controller: widget.city,
                enabled: widget.enabled,
                decoration: const InputDecoration(
                  border: OutlineInputBorder(),
                  isDense: true,
                  labelText: 'City',
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextField(
                controller: widget.state,
                enabled: widget.enabled,
                maxLength: 2,
                textCapitalization: TextCapitalization.characters,
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp('[A-Za-z]')),
                  LengthLimitingTextInputFormatter(2),
                  _UpperCaseFormatter(),
                ],
                decoration: const InputDecoration(
                  border: OutlineInputBorder(),
                  isDense: true,
                  labelText: 'ST',
                  counterText: '',
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              flex: 2,
              child: TextField(
                controller: widget.postal,
                enabled: widget.enabled,
                decoration: const InputDecoration(
                  border: OutlineInputBorder(),
                  isDense: true,
                  labelText: 'ZIP',
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
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
