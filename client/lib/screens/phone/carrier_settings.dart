import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../widgets/address_lookup.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Phone › Settings › Numbers › Edit — the settings Twilio owns.
///
/// ── Why 911 is read live and everything else is not ──────────────
///
/// Twilio deactivates emergency calling by itself when a registered address
/// stops validating, and tells nobody. A stored copy of `emergency_status`
/// would go on saying 911 works long after it stopped, which is the one
/// staleness on this screen that could get somebody hurt. So the status comes
/// from Twilio each time the form opens, not from our row.
///
/// Capabilities are read at the same time because they arrive in the same
/// response, and because a line that cannot do SMS looks identical to one
/// that can until a text silently fails.
class CarrierSettings extends StatefulWidget {
  const CarrierSettings({
    super.key,
    required this.api,
    required this.numberId,
    required this.scopeType,
    required this.scopeId,
    required this.callerIdLookup,
    required this.voicemailTranscribe,
    required this.emergencyAddressSid,
    required this.onChanged,
  });

  final ApiClient api;
  final String numberId;
  final String scopeType;
  final String scopeId;

  final bool callerIdLookup;
  final bool voicemailTranscribe;
  final String? emergencyAddressSid;

  /// The fields to merge into the line's save body.
  final ValueChanged<Map<String, dynamic>> onChanged;

  @override
  State<CarrierSettings> createState() => _CarrierSettingsState();
}

class _CarrierSettingsState extends State<CarrierSettings> {
  late bool _callerIdLookup = widget.callerIdLookup;
  late bool _voicemailTranscribe = widget.voicemailTranscribe;
  late String? _addressSid = widget.emergencyAddressSid;

  List<_Address> _addresses = const [];
  Map<String, dynamic>? _live;
  bool _loading = true;
  String? _note;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.api.get('/phone/numbers/${widget.numberId}/carrier'),
      widget.api.get(
        '/phone/addresses'
        '?scope_type=${widget.scopeType}&scope_id=${widget.scopeId}',
      ),
    ]);

    if (!mounted) return;

    final carrier = results[0];
    final addresses = results[1];

    setState(() {
      _loading = false;
      _live = carrier.ok ? carrier.body['data'] as Map<String, dynamic>? : null;
      _note = carrier.ok ? carrier.body['message'] as String? : carrier.reason;
      _addresses = addresses.ok
          ? _Address.listFrom(addresses.body)
          : const [];

      // Twilio is the authority on which address a line actually carries.
      final live = _live?['emergency_address_sid'] as String?;
      if (live != null) _addressSid = live;
    });
  }

  void _emit() => widget.onChanged({
    'voice_caller_id_lookup': _callerIdLookup,
    'voicemail_transcribe': _voicemailTranscribe,
    'emergency_address_sid': _addressSid,
  });

  /// Registering opens in place rather than over the top: the house rule is
  /// no modals (docs/HOW-I-WORK.md), and an address is entered while looking
  /// at which line needs one.
  bool _registering = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 24),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('On the carrier', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'Settings Twilio holds for this line rather than EzBizi.',
          style: theme.textTheme.bodySmall,
        ),

        if (_note != null) ...[
          const SizedBox(height: 8),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        const SizedBox(height: 12),
        _emergency(theme),

        const SizedBox(height: 12),
        _capabilities(theme),

        const SizedBox(height: 8),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _callerIdLookup,
          onChanged: (on) {
            setState(() => _callerIdLookup = on);
            _emit();
          },
          title: const Text("Look up the caller's name"),
          subtitle: Text(
            'Twilio charges per lookup. Off unless knowing who is calling '
            'before you answer is worth it.',
            style: theme.textTheme.bodySmall,
          ),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _voicemailTranscribe,
          onChanged: (on) {
            setState(() => _voicemailTranscribe = on);
            _emit();
          },
          title: const Text('Write down voicemails'),
          subtitle: Text(
            'Separate from writing down live calls, and charged separately.',
            style: theme.textTheme.bodySmall,
          ),
        ),
      ],
    );
  }

  /// The one setting on this screen with legal weight.
  Widget _emergency(ThemeData theme) {
    final status = (_live?['emergency_status'] as String?) ?? '';
    final active = status.toLowerCase() == 'active';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: active
                ? theme.colorScheme.surfaceContainerHighest
                : theme.colorScheme.errorContainer,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Row(
            children: [
              Icon(
                active ? Icons.emergency_outlined : Icons.warning_amber_rounded,
                color: active ? null : theme.colorScheme.onErrorContainer,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  active
                      ? 'Emergency calling is on for this line.'
                      : 'This line cannot call 911. Give it a registered '
                            'address — the emergency service is sent to that '
                            'address, so it has to be where the phone is.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: active ? null : theme.colorScheme.onErrorContainer,
                  ),
                ),
              ),
            ],
          ),
        ),

        const SizedBox(height: 12),
        SearchPickerField<String?>(
          value: _addresses.any((a) => a.sid == _addressSid) ? _addressSid : null,
          label: 'Emergency address',
          border: OutlineInputBorder(),
          options: [
            const SearchOption(value: null, label: 'None — 911 will not work'),
            for (final address in _addresses)
              SearchOption(value: address.sid, label: address.label),
          ],
          onChanged: (sid) {
            setState(() => _addressSid = sid);
            _emit();
          },
        ),
        const SizedBox(height: 8),
        if (_registering)
          _AddressForm(
            api: widget.api,
            scopeType: widget.scopeType,
            scopeId: widget.scopeId,
            onCancel: () => setState(() => _registering = false),
            onRegistered: () async {
              setState(() => _registering = false);
              await _load();
            },
          )
        else
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add_location_alt_outlined, size: 18),
              label: const Text('Register a new address'),
              onPressed: () => setState(() => _registering = true),
            ),
          ),
      ],
    );
  }

  Widget _capabilities(ThemeData theme) {
    final caps = (_live?['capabilities'] as Map?) ?? const {};

    if (caps.isEmpty) return const SizedBox.shrink();

    const names = {
      'voice': 'Calls',
      'sms': 'Texts',
      'mms': 'Picture messages',
      'fax': 'Fax',
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('This line can do', style: theme.textTheme.bodySmall),
        const SizedBox(height: 6),
        Wrap(
          spacing: 6,
          runSpacing: 4,
          children: [
            for (final entry in names.entries)
              Chip(
                avatar: Icon(
                  caps[entry.key] == true ? Icons.check : Icons.close,
                  size: 14,
                ),
                label: Text(entry.value),
                visualDensity: VisualDensity.compact,
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
              ),
          ],
        ),
      ],
    );
  }
}

/// Registering an address is a Twilio write that can be refused — it validates
/// against the postal record — so its answer is shown right here rather than
/// swallowed into the line form's error slot.
class _AddressForm extends StatefulWidget {
  const _AddressForm({
    required this.api,
    required this.scopeType,
    required this.scopeId,
    required this.onCancel,
    required this.onRegistered,
  });

  final ApiClient api;
  final String scopeType;
  final String scopeId;
  final VoidCallback onCancel;
  final Future<void> Function() onRegistered;

  @override
  State<_AddressForm> createState() => _AddressFormState();
}

class _AddressFormState extends State<_AddressForm> {
  final _name = TextEditingController();
  final _street = TextEditingController();
  final _city = TextEditingController();
  final _region = TextEditingController();
  final _postal = TextEditingController();
  final _country = TextEditingController(text: 'US');

  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [_name, _street, _city, _region, _postal, _country]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.post('/phone/addresses', {
      'scope_type': widget.scopeType,
      'scope_id': widget.scopeId,
      'customer_name': _name.text.trim(),
      'street': _street.text.trim(),
      'city': _city.text.trim(),
      'region': _region.text.trim(),
      'postal_code': _postal.text.trim(),
      'iso_country': _country.text.trim().toUpperCase(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.reason;
      });
      return;
    }

    await widget.onRegistered();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('New address', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'This is where the emergency service is sent, so it must be where '
            'the phone actually is. Twilio checks it against the postal record '
            'and can refuse it.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 12),

          // Found with the lookup like every other address (Jose,
          // 2026-09-30). The country stays the two-letter code Twilio takes.
          AddressLookup(
            api: widget.api,
            enabled: !_saving,
            onPicked: (parts) => setState(() {
              _street.text = '${parts['address_line1'] ?? ''}';
              _city.text = '${parts['city'] ?? ''}';
              _region.text = '${parts['state'] ?? ''}';
              _postal.text = '${parts['postal_code'] ?? ''}';
            }),
          ),
          const SizedBox(height: 12),

          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _field(_name, 'Business or person', 260),
              _field(_street, 'Street', 260),
              _field(_city, 'City', 180),
              _field(_region, 'State', 110),
              _field(_postal, 'ZIP', 120),
              _field(_country, 'Country code', 130),
            ],
          ),

          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],

          const SizedBox(height: 12),
          Row(
            children: [
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Checking…' : 'Register'),
              ),
              const SizedBox(width: 12),
              TextButton(
                onPressed: _saving ? null : widget.onCancel,
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _field(TextEditingController controller, String label, double width) =>
      SizedBox(
        width: width,
        child: TextField(
          controller: controller,
          decoration: InputDecoration(
            labelText: label,
            border: const OutlineInputBorder(),
            isDense: true,
          ),
        ),
      );
}

class _Address {
  const _Address(this.sid, this.label, this.emergencyEnabled);

  final String sid;
  final String label;
  final bool emergencyEnabled;

  static List<_Address> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Address(
          (row['sid'] ?? '') as String,
          [
            row['friendly_name'] ?? row['customer_name'],
            row['street'],
            row['city'],
            row['region'],
          ].where((p) => p != null && '$p'.isNotEmpty).join(', '),
          row['emergency_enabled'] == true,
        ),
    ];
  }
}
