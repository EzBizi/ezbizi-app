import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// A search box that finds a street address and hands back its parts.
///
/// ── Why this is separate from [AddressFields] ──
///
/// Settings wants the search AND the five boxes it fills, and that pairing is
/// what `AddressFields` is. Web Management wants the search and its own boxes:
/// every field there carries a shortcode chip beside it, which is the whole
/// point of that screen, and the boxes in `AddressFields` have none. Copying
/// the search into the second screen would have been a second debounce, a
/// second stale-response guard and a second suggestion list to keep in step.
///
/// So the search lives here and both screens call it. It writes nothing itself
/// — it reports the parts and lets the caller decide what they belong to.
class AddressLookup extends StatefulWidget {
  const AddressLookup({
    super.key,
    required this.api,
    required this.onPicked,
    this.enabled = true,
    this.label = 'Find an address',
    this.dense = true,
  });

  final ApiClient api;

  /// The chosen suggestion, as the server's parts: `address_line1`, `city`,
  /// `state`, `postal_code`. Never a suite — no lookup knows one.
  final void Function(Map<String, dynamic> parts) onPicked;

  final bool enabled;
  final String label;

  /// Dense and outlined, as the settings forms it was made for. False takes
  /// the theme's box, for a form whose other fields do (booking a lead, a
  /// customer): one form, one height of box.
  final bool dense;

  @override
  State<AddressLookup> createState() => _AddressLookupState();
}

class _AddressLookupState extends State<AddressLookup> {
  final _search = TextEditingController();
  Timer? _debounce;

  /// Bumped per request so a slow answer to an old query cannot overwrite the
  /// suggestions for the one being typed now.
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

    _debounce =
        Timer(const Duration(milliseconds: 280), () => _lookupAddress(trimmed));
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

  void _pick(Map<String, dynamic> suggestion) {
    widget.onPicked(suggestion);
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
            border: widget.dense ? const OutlineInputBorder() : null,
            isDense: widget.dense,
            labelText: widget.label,
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
                    onTap: () => _pick(suggestion),
                  ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
