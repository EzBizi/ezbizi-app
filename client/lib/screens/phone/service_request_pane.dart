import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › asking EzBizi for phone service (§4 gate 2).
///
/// ── Why the tab is not simply hidden ─────────────────────────────
///
/// Phone is a paid extra, so most organizations arrive here with nothing
/// switched on. Hiding the tab would mean the only way to discover the product
/// is to be told about it; refusing at the door would be the dead end §6
/// forbids. So the tab explains what Phone is and offers the single thing that
/// moves: asking.
///
/// The customer never meets Twilio on this screen. They pick companies, say how
/// busy the lines will be, whether they need texting, and what for. That is
/// what a person at EzBizi needs in order to answer.
class PhoneServiceRequestPane extends StatefulWidget {
  const PhoneServiceRequestPane({
    super.key,
    required this.api,
    required this.session,
    this.onApproved,
  });

  final ApiClient api;
  final Session session;

  /// The companies EzBizi has said yes to, reported up every time this pane
  /// reads them.
  ///
  /// The Connection section needs the same answer to decide whether to draw an
  /// Activate control at all — §4: a control that is offered must be a control
  /// that works. Passing it up rather than letting that section fetch the same
  /// endpoint keeps one request and one source of the answer.
  final void Function(List<String> approvedCompanyIds)? onApproved;

  @override
  State<PhoneServiceRequestPane> createState() =>
      _PhoneServiceRequestPaneState();
}

class _PhoneServiceRequestPaneState extends State<PhoneServiceRequestPane> {
  bool _loading = true;
  String? _error;

  Map<String, dynamic>? _latest;
  bool _mayRequest = false;

  /// Asking is an organization-level act.
  ///
  /// The request names WHICH companies want phone service, and a company view
  /// can only see itself — so from inside Everdoors the form could only ever
  /// ask for Everdoors, while the button said "ask for another company". The
  /// control promised something the view could not deliver.
  bool get _mayAskHere => _me.scope.type == 'organization';
  String? _cooldownEndsAt;
  Map<String, String> _volumes = const {};
  List<_CompanyOption> _companies = const [];

  bool _formOpen = false;
  bool _saving = false;
  String? _formError;

  final Set<String> _picked = <String>{};
  String? _volume;
  bool _wantsSms = false;
  final _purpose = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _purpose.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  /// The companies this view is looking at, in the order the server sent them.
  List<_CompanyOption> get _companiesInView {
    final inView = {for (final c in _me.companiesInView) c.id};

    return _companies.where((c) => inView.contains(c.id)).toList();
  }

  bool get _canAsk => _me.can('phone.edit');

  String? get _askRefusal => _me.refusal('phone.edit', 'ask for phone service');

  Future<void> _load() async {
    setState(() {
      _loading = _latest == null && _companies.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/service-request'),
      widget.api.get('/phone/service-request/companies'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!results[0].ok) {
        _error = results[0].message;
        return;
      }

      final data = results[0].body['data'];
      if (data is Map<String, dynamic>) {
        _latest = data['latest'] as Map<String, dynamic>?;
        _mayRequest = data['may_request'] == true;
        _cooldownEndsAt = data['cooldown_ends_at'] as String?;
        final volumes = data['volumes'];
        _volumes = volumes is Map
            ? volumes.map((k, v) => MapEntry(k.toString(), v.toString()))
            : const {};
      }

      widget.onApproved?.call(
        ((data is Map<String, dynamic> ? data['approved_company_ids'] : null)
                    as List? ??
                const [])
            .map((id) => id.toString())
            .toList(growable: false),
      );

      if (results[1].ok) {
        final list = results[1].body['data'];
        _companies = list is List
            ? list
                  .whereType<Map<String, dynamic>>()
                  .map(_CompanyOption.fromJson)
                  .toList(growable: false)
            : const [];
      }
    });
  }

  Future<void> _submit() async {
    if (_saving) return;

    if (_picked.isEmpty) {
      setState(() => _formError = 'Pick at least one company.');
      return;
    }
    if (_volume == null) {
      setState(() => _formError = 'Say roughly how many calls you expect.');
      return;
    }
    if (_purpose.text.trim().length < 20) {
      setState(
        () => _formError =
            'Tell us in a sentence or two what the numbers are for.',
      );
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/service-request', {
      'company_ids': _picked.toList()..sort(),
      'expected_volume': _volume,
      'wants_sms': _wantsSms,
      'purpose': _purpose.text.trim(),
    });

    if (!mounted) return;

    setState(() => _saving = false);

    if (!response.ok) {
      setState(() => _formError = response.message);
      return;
    }

    setState(() {
      _formOpen = false;
      _picked.clear();
      _purpose.clear();
      _volume = null;
      _wantsSms = false;
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: ErrorNote(_error!),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_latest != null) _statusCard(theme),
        if (_formOpen) _form(theme) else _callToAction(theme),
      ],
    );
  }

  Widget _callToAction(ThemeData theme) {
    final nothingYet = _companiesInView.every((c) => !c.hasService);

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (nothingYet && _latest == null) ...[
              Text('Phone service', style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              Text(
                'Numbers, a softphone in this browser, voicemail, recordings '
                'and texting — run by EzBizi, on EzBizi’s phone account. '
                'It is a paid extra, so somebody here reads every request '
                'before it is switched on.',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
            ],

            if (!_mayAskHere)
              Text(
                // Deliberately does not name the group. A company view must
                // not print names from outside itself — the switcher at the
                // top is the only control that lists reach.
                'Phone service is asked for from the group view, because the '
                'request names which companies it is for. Switch views at the '
                'top to ask for another company.',
                style: theme.textTheme.bodySmall,
              )
            else if (_cooldownEndsAt != null)
              Text(
                'You can ask again on ${_date(_cooldownEndsAt!)}.',
                style: theme.textTheme.bodyMedium,
              )
            else
              Refused(
                reason: _canAsk ? null : _askRefusal,
                child: FilledButton(
                  onPressed: _canAsk && _mayRequest && _mayAskHere
                      ? () => setState(() {
                          _formOpen = true;
                          _formError = null;
                        })
                      : null,
                  child: Text(
                    _latest == null
                        ? 'Ask for phone service'
                        : 'Ask for another company',
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _statusCard(ThemeData theme) {
    final latest = _latest!;
    final status = latest['status'] as String? ?? '';

    // Only what this view is looking at.
    //
    // Standing in A-1 Chimney, this card listed Everdoors and EzBizi too —
    // every company the request happened to name. That is reach, not view, and
    // it is the one thing AGENTS.md says a company screen must never do: the
    // switcher is the only control that lists reach.
    //
    // An organization view still sees all of them, because that IS the view.
    final inView = {for (final c in _me.companiesInView) c.id};

    final companies = (latest['companies'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .where((row) => inView.contains(row['company_id']))
        .toList();

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(switch (status) {
              'pending' => 'We are looking at your request',
              'approved' => 'Phone service is switched on',
              'partial' => 'Some of it is switched on',
              _ => 'We could not switch it on yet',
            }, style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            Text(
              status == 'pending'
                  ? 'Asked on ${_date(latest['requested_at'])}. There is nothing '
                        'else to do — we will come back to you.'
                  : 'Asked on ${_date(latest['requested_at'])}, answered on '
                        '${_date(latest['decided_at'])}.',
              style: theme.textTheme.bodyMedium,
            ),

            // The sentence a person wrote, shown as written. A refusal that
            // does not explain itself is a dead end. An approval needs no
            // reason, and its note is staff's own record (desktop review D9:
            // it named the owner and an internal session to the customer).
            if (status != 'approved' &&
                ((latest['decision_note'] as String?)?.isNotEmpty ??
                    false)) ...[
              const SizedBox(height: 12),
              Text(
                latest['decision_note'] as String,
                style: theme.textTheme.bodyMedium,
              ),
            ],

            const SizedBox(height: 12),
            for (final row in companies)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    Icon(
                      switch (row['approved']) {
                        true => Icons.check_circle_outline,
                        false => Icons.cancel_outlined,
                        _ => Icons.schedule_outlined,
                      },
                      size: 18,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        '${row['name']} — ${switch (row['approved']) {
                          true => 'switched on',
                          false => 'not yet',
                          _ => 'waiting',
                        }}',
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _form(ThemeData theme) {
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Ask for phone service', style: theme.textTheme.titleSmall),

            const SizedBox(height: 16),
            Text('Which companies need it', style: theme.textTheme.labelLarge),

            // Same rule as the status card above: the view, not the reach.
            // Offering a sibling here would let somebody standing in A-1
            // Chimney ask for phone service on Everdoors, which is the exact
            // thing the switcher exists to prevent.
            for (final company in _companiesInView)
              CheckboxListTile(
                value: company.hasService || _picked.contains(company.id),
                // A company that already has it is ticked and locked rather
                // than missing: absent reads as "we forgot that one".
                onChanged: company.hasService || _saving
                    ? null
                    : (on) => setState(() {
                        if (on == true) {
                          _picked.add(company.id);
                        } else {
                          _picked.remove(company.id);
                        }
                      }),
                title: Text(company.name),
                subtitle: company.hasService
                    ? const Text('Already has phone service')
                    : null,
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),

            const SizedBox(height: 16),
            Text('How many calls a month', style: theme.textTheme.labelLarge),
            for (final entry in _volumes.entries)
              RadioListTile<String>(
                value: entry.key,
                // ignore: deprecated_member_use
                groupValue: _volume,
                // ignore: deprecated_member_use
                onChanged: _saving
                    ? null
                    : (value) => setState(() => _volume = value),
                title: Text(entry.value),
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),

            const SizedBox(height: 8),
            CheckboxListTile(
              value: _wantsSms,
              onChanged: _saving
                  ? null
                  : (on) => setState(() => _wantsSms = on == true),
              title: const Text('We need text messaging as well as calls'),
              subtitle: const Text(
                'Texting takes longer to switch on — carriers register the '
                'business first.',
              ),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),

            const SizedBox(height: 12),
            TextField(
              controller: _purpose,
              decoration: const InputDecoration(
                labelText: 'What will the numbers be used for',
                hintText:
                    'Booking work, calling customers back, out-of-hours '
                    'emergencies…',
              ),
              minLines: 3,
              maxLines: 6,
              enabled: !_saving,
              onChanged: (_) => setState(() {}),
            ),

            if (_formError != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_formError!),
            ],

            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                Refused(
                  reason: _canAsk ? null : _askRefusal,
                  child: FilledButton(
                    onPressed: _canAsk && !_saving ? _submit : null,
                    child: const Text('Send the request'),
                  ),
                ),
                TextButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() {
                          _formOpen = false;
                          _formError = null;
                        }),
                  child: const Text('Cancel'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String _date(Object? iso) {
    final parsed = DateTime.tryParse(iso?.toString() ?? '');
    if (parsed == null) return 'an unknown date';

    final local = parsed.toLocal();
    return '${local.day} ${longMonths[local.month - 1]} ${local.year}';
  }
}

class _CompanyOption {
  const _CompanyOption({
    required this.id,
    required this.name,
    required this.hasService,
  });

  final String id;
  final String name;
  final bool hasService;

  factory _CompanyOption.fromJson(Map<String, dynamic> json) => _CompanyOption(
    id: json['id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    hasService: json['has_service'] == true,
  );
}
