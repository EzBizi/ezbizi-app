import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/us_states.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../core/soft_keyboard.dart';

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
  /// What somebody came here to buy, which is not the same question as
  /// Twilio's `type`.
  ///
  /// Twilio has three types — local, toll_free, mobile — and a VANITY number
  /// is not one of them: it is either of the first two, searched by what the
  /// digits spell. Jose asked for all three side by side because that is how
  /// the choice is actually made ("I want an 800 number" / "I want one that
  /// spells CHIMNEY"), so the modes are what he named and the type is derived.
  static const _modes = <String, String>{
    'local': 'Local number',
    'toll_free': 'Toll-free',
    'vanity': 'Vanity',
    'mobile': 'Mobile',
  };

  /// The toll-free prefixes in service. Twilio searches these through
  /// `area_code`, which is why the field is shared: to the API an 833 is an
  /// area code like any other.
  ///
  /// Listed rather than typed because there are eight of them, they are not
  /// guessable, and a typo returns "no numbers" rather than "no such prefix".
  static const _tollFreePrefixes = ['800', '833', '844', '855', '866', '877', '888'];

  /// US states and Canadian provinces, for the places where Twilio actually
  /// honours a region filter. Everywhere else the field stays free text,
  /// because a list of the world's subdivisions that we maintain by hand is a
  /// list that is wrong somewhere.
  static const _regions = <String, Map<String, String>>{
    'US': usStates,
    'CA': {
      'AB': 'Alberta', 'BC': 'British Columbia', 'MB': 'Manitoba',
      'NB': 'New Brunswick', 'NL': 'Newfoundland and Labrador',
      'NS': 'Nova Scotia', 'NT': 'Northwest Territories', 'NU': 'Nunavut',
      'ON': 'Ontario', 'PE': 'Prince Edward Island', 'QC': 'Quebec',
      'SK': 'Saskatchewan', 'YT': 'Yukon',
    },
  };

  bool _advanced = false;

  String _mode = 'local';
  String _country = 'US';
  String? _tollFreePrefix;
  List<_Country> _countries = const [];

  /// Twilio's `type`, which for a vanity search is whichever kind of number
  /// the letters are wanted on.
  String get _type => _mode == 'vanity' ? _vanityType : _mode;
  String _vanityType = 'local';

  /// Whether this country's subdivisions are ones we can offer as a list.
  bool get _regionsKnown => _regions.containsKey(_country);

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
  void initState() {
    super.initState();
    _loadCountries();
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
      _loadCountries();
    }
  }

  Future<void> _loadCountries() async {
    final response = await widget.api.get(
      '/phone/numbers/countries'
      '?scope_type=${widget.scopeType}&scope_id=${widget.scopeId}',
    );

    if (!mounted || !response.ok) return;

    final listed = _Country.listFrom(response.body);

    // US AND CANADA FIRST, then the rest alphabetically.
    //
    // Twilio returns them in its own order and this is a US business selling
    // to US and Canadian customers. Sorting the two it will actually buy in
    // to the top costs nothing and saves typing into the filter every time;
    // the filter is still there for the other ninety.
    const home = ['US', 'CA'];
    listed.sort((a, b) {
      final ai = home.indexOf(a.code);
      final bi = home.indexOf(b.code);
      if (ai != bi) return (ai == -1 ? 99 : ai).compareTo(bi == -1 ? 99 : bi);
      return a.name.compareTo(b.name);
    });

    setState(() {
      _countries = listed;
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

    // A toll-free prefix IS an area code to Twilio, so the two share the
    // field — and only one of them is on screen at a time, which is what
    // keeps that from being confusing rather than clever.
    if (_mode == 'toll_free') {
      if (_tollFreePrefix != null) query['area_code'] = _tollFreePrefix!;
    } else {
      put('area_code', _areaCode);
    }

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

    // NO BUTTON IN FRONT OF IT.
    //
    // This screen is called Buy a number and it held a button reading "Buy a
    // new line" that revealed the search. Jose asked for it to go: the extra
    // step asks somebody who has already navigated here to say so again, and
    // nothing behind it is destructive — searching spends a Twilio query and
    // buys nothing until a row is picked and confirmed.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Buy a new line', style: theme.textTheme.labelLarge),
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
        // WHAT KIND, first and as buttons rather than a dropdown.
        //
        // Four things, all of them short, and the choice changes which fields
        // appear underneath — a dropdown hides that relationship behind a tap
        // and makes the fields look like they moved on their own.
        SegmentedButton<String>(
          segments: [
            for (final entry in _modes.entries)
              ButtonSegment(value: entry.key, label: Text(entry.value)),
          ],
          selected: {_mode},
          showSelectedIcon: false,
          onSelectionChanged: (picked) => setState(() {
            _mode = picked.first;

            // The old mode's answer is not this one's question. Leaving an
            // area code behind when somebody switches to toll-free searches
            // for a toll-free number in the 561 area code, which is nothing.
            if (_mode != 'local') _areaCode.clear();
            if (_mode != 'vanity') _contains.clear();
            if (_mode != 'toll_free') _tollFreePrefix = null;
          }),
        ),

        const SizedBox(height: 12),

        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            _countryPicker(),

            // ── Local: where the number should sound like it is from ──
            if (_mode == 'local' || _mode == 'mobile') ...[
              _regionPicker(),
              _field(_areaCode, 'Area code', width: 130, hint: '561'),
            ],

            // ── Toll-free: which prefix ────────────────────────────
            if (_mode == 'toll_free')
              SizedBox(
                width: 200,
                child: SearchPickerField<String?>(
                  key: const Key('buy-toll-free-prefix'),
                  value: _tollFreePrefix,
                  label: 'Prefix',
                  border: const OutlineInputBorder(),
                  isDense: true,
                  options: [
                    // "Any" first, because the reason most people buy
                    // toll-free is that it is toll-free, not that it is 833.
                    const SearchOption<String?>(value: null, label: 'Any'),
                    for (final prefix in _tollFreePrefixes)
                      SearchOption<String?>(value: prefix, label: prefix),
                  ],
                  onChanged: (value) => setState(() => _tollFreePrefix = value),
                ),
              ),

            // ── Vanity: what the digits should spell ───────────────
            if (_mode == 'vanity') ...[
              SizedBox(
                width: 190,
                child: SearchPickerField<String>(
                  key: const Key('buy-vanity-type'),
                  value: _vanityType,
                  label: 'On a',
                  border: const OutlineInputBorder(),
                  isDense: true,
                  options: const [
                    SearchOption(value: 'local', label: 'Local number'),
                    SearchOption(value: 'toll_free', label: 'Toll-free'),
                  ],
                  onChanged: (value) =>
                      setState(() => _vanityType = value ?? 'local'),
                ),
              ),
              _field(
                _contains,
                'Spells or contains',
                width: 240,
                hint: 'CHIMNEY, 2468, 55*',
                helper: 'Letters use the keypad. * any one, % any run',
              ),
              if (_vanityType == 'local')
                _field(_areaCode, 'Area code', width: 130, hint: '561'),
            ],
          ],
        ),

        if (_mode == 'vanity') ...[
          const SizedBox(height: 6),
          Text(
            'Letters are matched on the phone keypad, so CHIMNEY finds '
            '244-6639. Fewer letters find far more numbers.',
            style: theme.textTheme.bodySmall,
          ),
        ],

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
              _field(_locality, 'City', width: 180, hint: 'Jupiter'),
              _field(_postalCode, 'ZIP', width: 120),
              if (!_regionsKnown)
                _field(_region, 'State or province', width: 170),
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

  /// The country, with a filter, because there are ninety of them.
  ///
  /// `DropdownMenu` rather than `DropdownButtonFormField` for exactly that:
  /// the old one was a ninety-item scroll with no way to type, and the two
  /// countries this business buys in were wherever Twilio happened to put
  /// them. They are pinned to the top now — see [_loadCountries] — and
  /// anything else is two letters of typing away.
  Widget _countryPicker() {
    final known = _countries.any((c) => c.code == _country);

    return SizedBox(
      width: 240,
      child: DropdownMenu<String>(
        key: const Key('buy-country'),

        // Rebuilt when the list arrives: DropdownMenu takes its initial
        // selection once, and the countries are fetched after the first
        // frame, so without this the box stays empty until somebody opens it.
        initialSelection: known ? _country : null,
        width: 240,
        enableFilter: true,
        requestFocusOnTap: true,
        label: const Text('Country'),
        inputDecorationTheme: const InputDecorationTheme(
          border: OutlineInputBorder(),
          isDense: true,
        ),
        dropdownMenuEntries: [
          for (final c in _countries)
            DropdownMenuEntry(value: c.code, label: c.name),
        ],
        onSelected: (value) => setState(() {
          _country = value ?? _country;

          // A region code is only meaningful inside its own country: FL in
          // Canada is nothing, and leaving it behind searches for nothing
          // while looking like a filter that is doing something.
          _region.clear();
        }),
      ),
    );
  }

  /// State or province, as a list where we can be sure of the list.
  ///
  /// Jose asked to pick the state rather than remember its code. That is
  /// answerable for the US and Canada and not for the rest of the world
  /// without a table we would have to maintain, so outside those two the
  /// field stays free text under More options — offering a wrong list is
  /// worse than offering none.
  Widget _regionPicker() {
    if (!_regionsKnown) return const SizedBox.shrink();

    final regions = _regions[_country]!;
    final chosen = _region.text.trim().toUpperCase();

    return SizedBox(
      width: 240,
      child: DropdownMenu<String?>(
        key: ValueKey('buy-region-$_country'),
        initialSelection: regions.containsKey(chosen) ? chosen : null,
        width: 240,
        enableFilter: true,
        requestFocusOnTap: true,
        label: Text(_country == 'CA' ? 'Province' : 'State'),
        inputDecorationTheme: const InputDecorationTheme(
          border: OutlineInputBorder(),
          isDense: true,
        ),
        dropdownMenuEntries: [
          // Anywhere first: a state is a narrowing, and somebody who wants a
          // 561 number does not also need to say Florida.
          const DropdownMenuEntry(value: null, label: 'Anywhere'),
          for (final entry in regions.entries)
            DropdownMenuEntry(value: entry.key, label: entry.value),
        ],
        onSelected: (value) => setState(() => _region.text = value ?? ''),
      ),
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
                autofocus: autofocusOnOpen,
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
  ///
  /// This had its own copy of the formatting and produced a THIRD shape —
  /// `(561) 555-1234`, no country code, no spaces — so a number for sale read
  /// differently from the same number once bought. One function decides it now.
  String get pretty => formatPhoneNumber(e164);

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
