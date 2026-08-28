import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Numbers › Buy a new line.
///
/// ── Why this is a search and not a text box ──────────────────────
///
/// Buying was an area code and nothing else. The server took `available[0]` —
/// whatever Twilio happened to list first — so the business got a random
/// number in roughly the right place and found out what it was afterwards.
/// That is fine for a test and wrong for somebody putting a number on a van.
///
/// Twilio exposes every filter below on its AvailablePhoneNumber resources,
/// and they are all here rather than a chosen few: a chimney company wants a
/// number in its own town, and a company with a jingle wants one ending in
/// 2468. Neither is served by "first result in the 561 area code".
///
/// ── Why the capabilities and the address warning are on the row ──
///
/// Twilio will happily list a number it then refuses to sell, because that
/// number requires a registered Address on the account first. It will also
/// list voice-only numbers to somebody who came here to send texts. Both
/// facts are known before the purchase and both are on the row, so the
/// refusal happens before the money rather than after it.
class BuyNumberPanel extends StatefulWidget {
  const BuyNumberPanel({
    super.key,
    required this.api,
    required this.scopeType,
    required this.scopeId,
    required this.onBought,
  });

  final ApiClient api;

  /// The place the line will hang on, and whose Twilio connection pays.
  final String scopeType;
  final String scopeId;

  /// Reload the pane — the new line belongs in the list below.
  final Future<void> Function() onBought;

  @override
  State<BuyNumberPanel> createState() => _BuyNumberPanelState();
}

class _BuyNumberPanelState extends State<BuyNumberPanel> {
  static const _types = <String, String>{
    'local': 'Local',
    'toll_free': 'Toll-free',
    'mobile': 'Mobile',
  };

  bool _open = false;
  bool _advanced = false;

  String _type = 'local';
  String _country = 'US';
  List<_Country> _countries = const [];

  final _areaCode = TextEditingController();
  final _contains = TextEditingController();
  final _locality = TextEditingController();
  final _region = TextEditingController();
  final _postalCode = TextEditingController();
  final _nearNumber = TextEditingController();
  final _nearLatLong = TextEditingController();
  final _distance = TextEditingController();
  final _rateCenter = TextEditingController();
  final _lata = TextEditingController();

  /// The name typed into the row being confirmed.
  final _label = TextEditingController();

  bool _voice = true;
  bool _sms = true;
  bool _mms = false;
  bool _excludeAddressRequired = false;
  bool _beta = true;

  bool _searching = false;
  bool _searched = false;
  String? _error;
  String? _note;
  List<_Available> _results = const [];

  /// The number currently being bought, so only its own row shows a spinner.
  String? _buying;

  /// The row showing its confirmation, if any.
  ///
  /// Buying is money and is not undoable in one click, so it is confirmed —
  /// but the house rule is no modals, ever (docs/HOW-I-WORK.md). The row
  /// itself opens into the confirmation, which also keeps the number being
  /// bought on screen next to the answer rather than behind a scrim.
  String? _confirming;

  @override
  void dispose() {
    for (final controller in [
      _areaCode,
      _contains,
      _locality,
      _region,
      _postalCode,
      _nearNumber,
      _nearLatLong,
      _distance,
      _rateCenter,
      _lata,
      _label,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  @override
  void didUpdateWidget(BuyNumberPanel old) {
    super.didUpdateWidget(old);

    // A different place is a different Twilio account: its countries and its
    // search results are not this one's.
    if (old.scopeId != widget.scopeId) {
      setState(() {
        _results = const [];
        _searched = false;
        _note = null;
        _countries = const [];
      });
      if (_open) _loadCountries();
    }
  }

  Future<void> _loadCountries() async {
    final response = await widget.api.get(
      '/phone/numbers/countries'
      '?scope_type=${widget.scopeType}&scope_id=${widget.scopeId}',
    );

    if (!mounted || !response.ok) return;

    setState(() {
      _countries = _Country.listFrom(response.body);
      if (_countries.isNotEmpty && !_countries.any((c) => c.code == _country)) {
        _country = _countries.first.code;
      }
    });
  }

  Future<void> _search() async {
    setState(() {
      _searching = true;
      _error = null;
      _note = null;
    });

    final query = <String, String>{
      'scope_type': widget.scopeType,
      'scope_id': widget.scopeId,
      'type': _type,
      'country': _country,
      'voice': _voice ? '1' : '0',
      'sms': _sms ? '1' : '0',
      if (_mms) 'mms': '1',
      if (!_beta) 'beta': '0',
      if (_excludeAddressRequired) 'exclude_all_address_required': '1',
    };

    void put(String key, TextEditingController field) {
      final value = field.text.trim();
      if (value.isNotEmpty) query[key] = value;
    }

    put('area_code', _areaCode);
    put('contains', _contains);
    put('in_locality', _locality);
    put('in_region', _region);
    put('in_postal_code', _postalCode);
    put('near_number', _nearNumber);
    put('near_lat_long', _nearLatLong);
    put('distance', _distance);
    put('in_rate_center', _rateCenter);
    put('in_lata', _lata);

    final response = await widget.api.get(
      '/phone/numbers/available?${Uri(queryParameters: query).query}',
    );

    if (!mounted) return;

    setState(() {
      _searching = false;
      _searched = true;
      _results = response.ok ? _Available.listFrom(response.body) : const [];
      _error = response.ok ? null : response.reason;
      _note = response.ok ? response.body['message'] as String? : null;
    });
  }

  /// Open the inline confirmation on one row, with the number pre-named.
  void _confirm(_Available number) {
    _label.text = number.pretty;
    setState(() {
      _confirming = number.e164;
      _error = null;
    });
  }

  Future<void> _buy(_Available number) async {
    final label = _label.text.trim();

    setState(() {
      _buying = number.e164;
      _error = null;
    });

    final response = await widget.api.post('/phone/numbers', {
      'scope_type': widget.scopeType,
      'scope_id': widget.scopeId,
      'e164': number.e164,
      if (label.isNotEmpty) 'label': label,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _buying = null;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _buying = null;
      _confirming = null;
      // The number is ours now, so it is no longer for sale.
      _results = _results.where((r) => r.e164 != number.e164).toList();
    });

    await widget.onBought();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!_open) {
      return Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          icon: const Icon(Icons.add_call, size: 18),
          label: const Text('Buy a new line'),
          onPressed: () {
            setState(() => _open = true);
            if (_countries.isEmpty) _loadCountries();
          },
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Buy a new line', style: theme.textTheme.labelLarge),
            ),
            TextButton(
              onPressed: () => setState(() => _open = false),
              child: const Text('Close'),
            ),
          ],
        ),
        Text(
          'What Twilio has for sale. Nothing is bought until you pick one.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),

        _filters(theme),

        const SizedBox(height: 12),
        Row(
          children: [
            FilledButton(
              onPressed: _searching ? null : _search,
              child: Text(_searching ? 'Searching…' : 'Search'),
            ),
            const SizedBox(width: 12),
            TextButton(
              onPressed: () => setState(() => _advanced = !_advanced),
              child: Text(_advanced ? 'Fewer options' : 'More options'),
            ),
          ],
        ),

        if (_error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_error!),
        ],

        if (_note != null) ...[
          const SizedBox(height: 12),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        ..._resultList(theme),
      ],
    );
  }

  Widget _filters(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            SizedBox(
              width: 170,
              child: DropdownButtonFormField<String>(
                initialValue: _type,
                // Without this the menu sizes to its widest label and spills
                // out of the box — "United States Minor Outlying Islands"
                // defeats any width worth typing.
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Kind',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                items: [
                  for (final entry in _types.entries)
                    DropdownMenuItem(
                      value: entry.key,
                      child: Text(entry.value),
                    ),
                ],
                onChanged: (value) => setState(() => _type = value ?? 'local'),
              ),
            ),
            SizedBox(
              width: 220,
              child: DropdownButtonFormField<String>(
                initialValue:
                    _countries.any((c) => c.code == _country) ? _country : null,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Country',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                items: [
                  for (final c in _countries)
                    DropdownMenuItem(
                      value: c.code,
                      child: Text(c.name, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (value) =>
                    setState(() => _country = value ?? _country),
              ),
            ),
            _field(_areaCode, 'Area code', width: 120, hint: '561'),
            _field(
              _contains,
              'Contains',
              width: 180,
              hint: '2468 or CHIM',
              helper: '* matches one, % matches many',
            ),
            _field(_locality, 'City', width: 180),
            _field(_region, 'State', width: 110, hint: 'FL'),
            _field(_postalCode, 'ZIP', width: 120),
          ],
        ),

        const SizedBox(height: 8),
        Wrap(
          spacing: 4,
          children: [
            FilterChip(
              label: const Text('Calls'),
              selected: _voice,
              onSelected: (on) => setState(() => _voice = on),
            ),
            FilterChip(
              label: const Text('Texts'),
              selected: _sms,
              onSelected: (on) => setState(() => _sms = on),
            ),
            FilterChip(
              label: const Text('Picture messages'),
              selected: _mms,
              onSelected: (on) => setState(() => _mms = on),
            ),
            FilterChip(
              label: const Text('Hide ones needing an address'),
              selected: _excludeAddressRequired,
              onSelected: (on) => setState(() => _excludeAddressRequired = on),
            ),
          ],
        ),

        if (_advanced) ...[
          const SizedBox(height: 12),
          Text(
            'Near a place, or inside a carrier boundary. Twilio applies these '
            'to US and Canadian numbers only.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _field(
                _nearNumber,
                'Near number',
                width: 180,
                hint: '+15615551234',
              ),
              _field(
                _nearLatLong,
                'Near lat,long',
                width: 200,
                hint: '26.93,-80.09',
              ),
              _field(
                _distance,
                'Within (miles)',
                width: 140,
                hint: '25',
                helper: 'Up to 500',
              ),
              _field(_rateCenter, 'Rate center', width: 180),
              _field(
                _lata,
                'LATA',
                width: 140,
                helper: 'Needed with a rate center',
              ),
            ],
          ),
          const SizedBox(height: 4),
          CheckboxListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _beta,
            onChanged: (on) => setState(() => _beta = on ?? true),
            title: const Text('Include numbers new to Twilio'),
            subtitle: const Text(
              'Newly added to the platform. Twilio includes these by default.',
            ),
          ),
        ],
      ],
    );
  }

  List<Widget> _resultList(ThemeData theme) {
    if (!_searched || _searching) return const [];

    if (_results.isEmpty) {
      return [
        const SizedBox(height: 16),
        Text(
          'Twilio has nothing matching that. A wider search — no city, or a '
          'different area code — usually finds something.',
          style: theme.textTheme.bodySmall,
        ),
      ];
    }

    return [
      const SizedBox(height: 16),
      Text('${_results.length} for sale', style: theme.textTheme.labelLarge),
      const SizedBox(height: 8),
      for (final number in _results) _resultRow(number, theme),
    ];
  }

  Widget _resultRow(_Available number, ThemeData theme) {
    final buying = _buying == number.e164;
    final confirming = _confirming == number.e164;

    return Card(
      key: ValueKey('available:${number.e164}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(number.pretty, style: theme.textTheme.titleSmall),
                      if (number.where.isNotEmpty)
                        Text(number.where, style: theme.textTheme.bodySmall),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          for (final can in number.canDo)
                            Chip(
                              label: Text(can),
                              padding: EdgeInsets.zero,
                              visualDensity: VisualDensity.compact,
                              materialTapTargetSize:
                                  MaterialTapTargetSize.shrinkWrap,
                            ),
                          if (number.needsAddress)
                            Chip(
                              avatar: const Icon(Icons.info_outline, size: 14),
                              label: const Text('Needs a registered address'),
                              padding: EdgeInsets.zero,
                              visualDensity: VisualDensity.compact,
                              materialTapTargetSize:
                                  MaterialTapTargetSize.shrinkWrap,
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                if (!confirming)
                  FilledButton.tonal(
                    onPressed: _buying == null ? () => _confirm(number) : null,
                    child: const Text('Buy'),
                  ),
              ],
            ),

            if (confirming) ...[
              const SizedBox(height: 12),
              Text(
                'This buys the line from Twilio and starts its monthly charge.',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _label,
                autofocus: true,
                decoration: const InputDecoration(
                  labelText: 'Call it',
                  helperText: 'What this line is for — Office, Dispatch, Sales.',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  FilledButton(
                    onPressed: buying ? null : () => _buy(number),
                    child: Text(buying ? 'Buying…' : 'Buy this number'),
                  ),
                  const SizedBox(width: 12),
                  TextButton(
                    onPressed: buying
                        ? null
                        : () => setState(() => _confirming = null),
                    child: const Text('Cancel'),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    required double width,
    String? hint,
    String? helper,
  }) {
    return SizedBox(
      width: width,
      child: TextField(
        controller: controller,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          helperText: helper,
          helperMaxLines: 2,
          border: const OutlineInputBorder(),
          isDense: true,
        ),
      ),
    );
  }
}

class _Country {
  const _Country(this.code, this.name);

  final String code;
  final String name;

  static List<_Country> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Country(
          (row['country_code'] ?? '') as String,
          (row['country'] ?? row['country_code'] ?? '') as String,
        ),
    ];
  }
}

class _Available {
  const _Available({
    required this.e164,
    required this.locality,
    required this.region,
    required this.capabilities,
    required this.addressRequirements,
  });

  final String e164;
  final String locality;
  final String region;
  final Map<String, bool> capabilities;
  final String addressRequirements;

  /// `+15615551234` reads as a number a person could dial.
  String get pretty {
    final digits = e164.replaceAll(RegExp(r'[^0-9]'), '');

    if (e164.startsWith('+1') && digits.length == 11) {
      return '(${digits.substring(1, 4)}) '
          '${digits.substring(4, 7)}-${digits.substring(7)}';
    }

    return e164;
  }

  String get where => [locality, region].where((p) => p.isNotEmpty).join(', ');

  List<String> get canDo => [
    if (capabilities['voice'] == true) 'Calls',
    if (capabilities['sms'] == true) 'Texts',
    if (capabilities['mms'] == true) 'Picture messages',
    if (capabilities['fax'] == true) 'Fax',
  ];

  /// Twilio lists numbers it will refuse to sell without an Address on file.
  bool get needsAddress =>
      addressRequirements.isNotEmpty && addressRequirements != 'none';

  static List<_Available> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Available(
          e164: (row['phone_number'] ?? '') as String,
          locality: (row['locality'] as String?) ?? '',
          region: (row['region'] as String?) ?? '',
          capabilities: {
            for (final entry
                in ((row['capabilities'] as Map?) ?? const {}).entries)
              entry.key.toString(): entry.value == true,
          },
          addressRequirements: (row['address_requirements'] as String?) ?? '',
        ),
    ];
  }
}
