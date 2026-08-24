import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';

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
  final _search = TextEditingController();
  Timer? _debounce;
  int _lookup = 0;
  List<Map<String, dynamic>> _suggestions = [];
  bool _looking = false;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _onSearch(String query) {
    _debounce?.cancel();
    final trimmed = query.trim();
    if (trimmed.length < 3) {
      setState(() {
        _suggestions = [];
        _looking = false;
      });
      return;
    }

    _debounce = Timer(const Duration(milliseconds: 280), () => _lookupAddress(trimmed));
  }

  Future<void> _lookupAddress(String query) async {
    final token = ++_lookup;
    setState(() => _looking = true);

    final response = await widget.api.get('/address-lookup', {'q': query});
    if (!mounted || token != _lookup) return;

    setState(() {
      _looking = false;
      final data = response.ok ? response.data : null;
      _suggestions = data is List
          ? data.whereType<Map<String, dynamic>>().toList()
          : const [];
    });
  }

  void _apply(Map<String, dynamic> suggestion) {
    widget.line1.text = '${suggestion['address_line1'] ?? ''}';
    widget.city.text = '${suggestion['city'] ?? ''}';
    widget.state.text = '${suggestion['state'] ?? ''}';
    widget.postal.text = '${suggestion['postal_code'] ?? ''}';
    _search.clear();
    setState(() => _suggestions = []);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _search,
          enabled: widget.enabled,
          decoration: InputDecoration(
            border: const OutlineInputBorder(),
            isDense: true,
            labelText: 'Find an address',
            hintText: 'Start typing a street or place',
            prefixIcon: const Icon(Icons.search, size: 20),
            suffixIcon: _looking
                ? const Padding(
                    padding: EdgeInsets.all(12),
                    child: SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : null,
          ),
          onChanged: _onSearch,
        ),
        if (_suggestions.isNotEmpty) ...[
          const SizedBox(height: 4),
          Material(
            elevation: 1,
            borderRadius: BorderRadius.circular(8),
            child: Column(
              children: [
                for (final suggestion in _suggestions)
                  ListTile(
                    dense: true,
                    title: Text('${suggestion['label'] ?? ''}'),
                    onTap: () => _apply(suggestion),
                  ),
              ],
            ),
          ),
        ],
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
