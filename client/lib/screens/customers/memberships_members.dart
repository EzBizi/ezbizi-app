import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/money.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// Customers › Memberships › Members (UI queue 52, desktop 1 and phone 4).
///
/// Jose, 2026-10-05: *"customer list, enrollment type, plan type, amount due,
/// state, zip, estimate gross for client, amount paid so far"* — those are the
/// columns, in his order, with when the year renews after them. Tiles above
/// are also the filters. A row opens the member in place with a ← back; Add a
/// member is a side panel.
///
/// Money (due, gross, paid) only for a role with `jobs.financials`, which is
/// what the server answers to as well: without it the amounts come back empty
/// and here their columns and tile are not drawn at all, rather than drawn as
/// dashes nobody can read.
class MembershipsMembers extends StatefulWidget {
  const MembershipsMembers({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsMembers> createState() => _MembershipsMembersState();
}

class _MembershipsMembersState extends State<MembershipsMembers> {
  bool _loading = true;
  String? _error;
  List<Membership> _rows = const [];
  List<MembershipPlan> _plans = const [];

  /// The tile picked: `active`, `soon`, `lapsed` or `due`. Null is everybody.
  String? _tile;
  String _search = '';

  /// The member open in place of the list.
  String? _open;

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;
  bool get _canAdd => widget.session.me?.can('memberships.create') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/memberships'),
      widget.api.get('/memberships/plans'),
    ]);
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!results[0].ok) {
        _error = results[0].reason;
        return;
      }
      _rows = Membership.listFrom(results[0].body);
      _plans = results[1].ok
          ? MembershipPlan.listFrom(results[1].body)
          : const [];
    });
  }

  List<Membership> get _shown {
    final term = _search.trim().toLowerCase();
    // A phone is found by its digits whatever way it was typed or stored.
    final digits = term.replaceAll(RegExp(r'\D'), '');

    return [
      for (final row in _rows)
        if (_inTile(row) &&
            (term.isEmpty ||
                row.customerName.toLowerCase().contains(term) ||
                row.zip.toLowerCase().contains(term) ||
                row.state.toLowerCase().contains(term) ||
                row.planName.toLowerCase().contains(term) ||
                (row.customerEmail?.toLowerCase().contains(term) ?? false) ||
                (digits.length >= 3 &&
                    (row.customerPhone ?? '')
                        .replaceAll(RegExp(r'\D'), '')
                        .contains(digits))))
          row,
    ];
  }

  bool _inTile(Membership row) => switch (_tile) {
    'active' => row.status == 'active',
    'soon' => row.renewsSoon,
    'lapsed' => row.status == 'expired',
    'due' => (row.dueMinor ?? 0) > 0,
    _ => true,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final open = _rows.where((r) => r.id == _open).firstOrNull;
    if (open != null) {
      return MembershipDetail(
        api: widget.api,
        session: widget.session,
        membership: open,
        plans: _plans,
        onBack: () {
          setState(() => _open = null);
          _load();
        },
      );
    }

    final narrow = MediaQuery.sizeOf(context).width < 600;
    final rows = _shown;
    final due = _rows.where((r) => (r.dueMinor ?? 0) > 0).toList();
    final currency = _rows.firstOrNull?.currency ?? 'USD';

    return ListView(
      padding: EdgeInsets.all(narrow ? 16 : 24),
      children: [
        FilterTiles(
          key: const ValueKey('tour-memberships-members-tiles'),
          prefix: 'members',
          picked: _tile,
          onPick: (tile) => setState(() => _tile = tile),
          tiles: [
            FilterTile(
              key: 'active',
              label: 'Active',
              count: _rows.where((r) => r.status == 'active').length,
              note: 'members this year',
            ),
            FilterTile(
              key: 'soon',
              label: 'Renewing soon',
              count: _rows.where((r) => r.renewsSoon).length,
              note: 'in the next 30 days',
              tone: Tone.caution,
            ),
            FilterTile(
              key: 'lapsed',
              label: 'Lapsed',
              count: _rows.where((r) => r.status == 'expired').length,
              note: 'year ended, not renewed',
              tone: Tone.bad,
            ),
            if (_money)
              FilterTile(
                key: 'due',
                label: 'Amount due',
                count: due.length,
                note:
                    '${memberMoney(due.fold<int>(0, (sum, r) => sum + (r.dueMinor ?? 0)), currency)} due',
              ),
          ],
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            Expanded(
              child: TextField(
                key: const Key('members-search'),
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search members',
                  isDense: true,
                  border: OutlineInputBorder(),
                ),
                onChanged: (value) => setState(() => _search = value),
              ),
            ),
            if (_canAdd) ...[
              const SizedBox(width: 12),
              FilledButton.icon(
                key: const Key('members-add'),
                onPressed: _plans.any((p) => p.active) ? _add : null,
                icon: const Icon(Icons.add),
                label: Text(narrow ? 'Add' : 'Add a member'),
              ),
            ],
          ],
        ),
        if (_canAdd && !_plans.any((p) => p.active)) ...[
          const SizedBox(height: 8),
          Text(
            'Add a plan under Settings first; a member joins a plan.',
            style: theme.textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 12),
        if (_rows.isEmpty)
          Text(
            'No members yet. Add one here, and the people who join from the '
            'offer after a good review will be listed too.',
            style: theme.textTheme.bodyMedium,
          )
        else if (rows.isEmpty)
          Text('No members match.', style: theme.textTheme.bodyMedium)
        else
          LayoutBuilder(
            // Narrower than the table's own stacking point, a member is a row
            // that says what each value is: the table's stacked form would be
            // "$0 FL 33901 $489 $199" with nothing naming any of it.
            builder: (context, box) => box.maxWidth < 720
                ? _rowsList(theme, rows)
                : _table(theme, rows),
          ),
      ],
    );
  }

  Widget _rowsList(ThemeData theme, List<Membership> rows) {
    return Card(
      key: const ValueKey('tour-memberships-members-list'),
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (final (i, row) in rows.indexed) ...[
            if (i > 0) const Divider(height: 1),
            InkWell(
              key: ValueKey('member:${row.id}'),
              onTap: () => setState(() => _open = row.id),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 10,
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            row.customerName,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          Text(
                            '${row.planName} · ${row.enrollment == 'renewal' ? 'renewal' : 'new'} · '
                            '${[row.state, row.zip].where((v) => v.isNotEmpty).join(' ')}',
                            style: theme.textTheme.bodySmall,
                          ),
                          if (row.contacts.isNotEmpty)
                            Text(
                              row.contacts.join(' · '),
                              style: theme.textTheme.bodySmall,
                            ),
                          _Renews(row: row, formats: _formats, prefixed: true),
                        ],
                      ),
                    ),
                    if (_money)
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            '${memberMoney(row.dueMinor, row.currency)} due',
                          ),
                          Text(
                            'paid ${memberMoney(row.paidMinor, row.currency)}',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// Jose's columns in his order. Amounts are left-aligned like the rest: a
  /// right-aligned amount ran into the left-aligned column after it ("$0FL").
  Widget _table(ThemeData theme, List<Membership> rows) {
    return ListTable(
      key: const ValueKey('tour-memberships-members-list'),
      rowKey: (i) => ValueKey('member:${rows[i].id}'),
      onTap: (i) => setState(() => _open = rows[i].id),
      columns: [
        const ListColumn('Customer', flex: 4),
        const ListColumn('Enrollment', flex: 2),
        const ListColumn('Plan', flex: 4),
        if (_money) const ListColumn('Amount due', flex: 2),
        const ListColumn('State', flex: 1),
        const ListColumn('Zip', flex: 2),
        if (_money) const ListColumn('Est. gross', flex: 2),
        if (_money) const ListColumn('Paid so far', flex: 2),
        const ListColumn('Renews', flex: 3),
      ],
      rows: [
        for (final row in rows)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  row.customerName,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                for (final contact in row.contacts)
                  Text(contact, style: theme.textTheme.bodySmall),
              ],
            ),
            _EnrollmentTag(row.enrollment),
            Text(row.planLabel, style: theme.textTheme.bodySmall),
            if (_money) Text(memberMoney(row.dueMinor, row.currency)),
            Text(row.state),
            Text(row.zip),
            if (_money) Text(memberMoney(row.grossMinor, row.currency)),
            if (_money) Text(memberMoney(row.paidMinor, row.currency)),
            _Renews(row: row, formats: _formats),
          ],
      ],
    );
  }

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  Future<void> _add() async {
    final added = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Add a member',
        child: _AddMember(
          api: widget.api,
          plans: [
            for (final p in _plans)
              if (p.active) p,
          ],
          money: _money,
          formats: _formats,
        ),
      ),
    );

    if (added == true && mounted) await _load();
  }
}

/// One member, opened in place of the list: this year, what has been paid,
/// every year before it, and the way to end it.
class MembershipDetail extends StatefulWidget {
  const MembershipDetail({
    super.key,
    required this.api,
    required this.session,
    required this.membership,
    required this.plans,
    required this.onBack,
  });

  final ApiClient api;
  final Session session;
  final Membership membership;
  final List<MembershipPlan> plans;
  final VoidCallback onBack;

  @override
  State<MembershipDetail> createState() => _MembershipDetailState();
}

class _MembershipDetailState extends State<MembershipDetail> {
  late Membership _row = widget.membership;
  List<Membership> _years = const [];
  bool _saving = false;
  bool _confirmCancel = false;
  bool _editingPaid = false;
  final _paid = TextEditingController();

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;
  bool get _canEdit => widget.session.me?.can('memberships.edit') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _loadYears();
  }

  @override
  void dispose() {
    _paid.dispose();
    super.dispose();
  }

  /// Every year this customer has been a member. Drawn only when the server
  /// answers with them: a list of one is this year, which is already above.
  Future<void> _loadYears() async {
    final response = await widget.api.get('/memberships', {
      'customer_id': _row.customerId,
    });
    if (!mounted || !response.ok) return;

    final rows = Membership.listFrom(
      response.body,
    ).where((r) => r.customerId == _row.customerId).toList();
    setState(() => _years = rows);
  }

  /// "2025–26".
  static String _yearOf(Membership year) => year.startsOn == null
      ? '—'
      : '${year.startsOn!.year}–${((year.startsOn!.year + 1) % 100).toString().padLeft(2, '0')}';

  /// What was typed, in minor units; null and said out loud when it is not
  /// an amount. Empty is nothing to save.
  int? _typed(String text) {
    try {
      return parseAmountMinor(text);
    } on FormatException catch (error) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error.message)));
      return null;
    }
  }

  Future<void> _patch(Map<String, Object?> body) async {
    setState(() => _saving = true);

    final response = await widget.api.patch('/memberships/${_row.id}', body);
    if (!mounted) return;

    setState(() {
      _saving = false;
      _editingPaid = false;
      _confirmCancel = false;
      if (response.ok) {
        final data = response.body['data'];
        if (data is Map<String, dynamic>) _row = Membership.fromJson(data);
      }
    });

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final row = _row;
    final live = row.status == 'active' || row.status == 'upcoming';

    Widget fact(String label, Widget value) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 130,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(child: value),
        ],
      ),
    );

    final strong = theme.textTheme.bodyMedium?.copyWith(
      fontWeight: FontWeight.w700,
    );

    return ListView(
      key: const ValueKey('tour-memberships-member'),
      padding: const EdgeInsets.fromLTRB(8, 12, 24, 24),
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back to members',
              icon: const Icon(Icons.arrow_back),
              onPressed: widget.onBack,
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(row.customerName, style: theme.textTheme.titleMedium),
                  Text(
                    '${row.planLabel} · ${row.enrollment == 'renewal' ? 'renewal' : 'new'}',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (_canEdit && live)
              _confirmCancel
                  ? Wrap(
                      spacing: 8,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          'End this membership?',
                          style: theme.textTheme.bodySmall,
                        ),
                        FilledButton(
                          key: const Key('member-cancel-yes'),
                          onPressed: _saving
                              ? null
                              : () => _patch({'cancelled': true}),
                          child: const Text('Yes, cancel it'),
                        ),
                        TextButton(
                          onPressed: () =>
                              setState(() => _confirmCancel = false),
                          child: const Text('Keep it'),
                        ),
                      ],
                    )
                  : OutlinedButton(
                      key: const Key('member-cancel'),
                      onPressed: () => setState(() => _confirmCancel = true),
                      child: const Text('Cancel membership'),
                    ),
          ],
        ),
        const SizedBox(height: 12),
        Card(
          margin: const EdgeInsets.only(left: 16),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                fact(
                  'This year',
                  Text(
                    row.startsOn == null || row.endsOn == null
                        ? '—'
                        : '${_formats.formatDate(row.startsOn!)} – ${_formats.formatDate(row.endsOn!)}',
                    style: strong,
                  ),
                ),
                fact('Status', _Status(row: row)),
                if (row.customerPhone case final phone?)
                  fact(
                    'Phone',
                    SelectableText(formatPhoneNumber(phone), style: strong),
                  ),
                if (row.customerEmail case final email?)
                  fact('Email', SelectableText(email, style: strong)),
                fact(
                  'Plan',
                  _canEdit && live && widget.plans.length > 1
                      ? Align(
                          alignment: Alignment.centerLeft,
                          child: SizedBox(
                            width: 320,
                            child: SearchPickerField<String>(
                              value: row.planId,
                              label: 'Plan',
                              border: const OutlineInputBorder(),
                              options: [
                                for (final plan in widget.plans)
                                  SearchOption(
                                    value: plan.id,
                                    label: plan.label,
                                  ),
                              ],
                              onChanged: (id) {
                                if (id != null && id != row.planId) {
                                  _patch({'membership_plan_id': id});
                                }
                              },
                            ),
                          ),
                        )
                      : Text(row.planLabel, style: strong),
                ),
                if (_money) ...[
                  fact(
                    'Paid so far',
                    _editingPaid
                        ? Row(
                            children: [
                              SizedBox(
                                width: 160,
                                child: TextField(
                                  key: const Key('member-paid'),
                                  controller: _paid,
                                  autofocus: true,
                                  keyboardType:
                                      const TextInputType.numberWithOptions(
                                        decimal: true,
                                      ),
                                  decoration: const InputDecoration(
                                    isDense: true,
                                    prefixText: r'$ ',
                                    border: OutlineInputBorder(),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                              FilledButton(
                                key: const Key('member-paid-save'),
                                onPressed: _saving
                                    ? null
                                    : () {
                                        final minor = _typed(_paid.text);
                                        if (minor != null) {
                                          _patch({'paid_minor': minor});
                                        }
                                      },
                                child: const Text('Save'),
                              ),
                              TextButton(
                                onPressed: () =>
                                    setState(() => _editingPaid = false),
                                child: const Text('Cancel'),
                              ),
                            ],
                          )
                        : Wrap(
                            spacing: 8,
                            crossAxisAlignment: WrapCrossAlignment.center,
                            children: [
                              Text(
                                memberMoney(row.paidMinor, row.currency),
                                style: strong,
                              ),
                              if (_canEdit)
                                TextButton(
                                  key: const Key('member-paid-change'),
                                  onPressed: () => setState(() {
                                    _paid.text = ((row.paidMinor ?? 0) / 100)
                                        .toStringAsFixed(2);
                                    _editingPaid = true;
                                  }),
                                  child: const Text('Change'),
                                ),
                            ],
                          ),
                  ),
                  fact(
                    'Amount due',
                    Text(
                      memberMoney(row.dueMinor, row.currency),
                      style: strong,
                    ),
                  ),
                  fact(
                    'Est. gross',
                    Text(
                      '${memberMoney(row.grossMinor, row.currency)}  ·  the plan and their average job',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                ],
                fact(
                  'Reminder',
                  Text(
                    row.remindedAt == null
                        ? 'Not sent yet. It goes a month before the year ends.'
                        : 'Sent ${_formats.formatDate(row.remindedAt!)}',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
                if (row.visitLine(_formats) case final visit?)
                  fact(
                    'Next visit',
                    Text(
                      visit,
                      key: const Key('member-visit'),
                      // Reminded and not answered is the one that needs a
                      // call (Jose: no answer leaves staff a follow-up).
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: row.visitOn == null
                            ? ToneColours.of(context, Tone.caution).ink
                            : null,
                        fontWeight: row.visitOn == null
                            ? FontWeight.w700
                            : null,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
        if (_years.length > 1) ...[
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.only(left: 16),
            child: Text('Every year', style: theme.textTheme.labelLarge),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.only(left: 16),
            // The same reason as the members list: stacked, a table row is
            // values with nothing naming them, so a narrow pane gets lines.
            child: LayoutBuilder(
              builder: (context, box) => box.maxWidth < 720
                  ? Card(
                      margin: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (final (i, year) in _years.indexed) ...[
                            if (i > 0) const Divider(height: 1),
                            ListTile(
                              title: Text(
                                '${_yearOf(year)} · ${year.planName}',
                                style: strong,
                              ),
                              subtitle: Text(
                                [
                                  year.enrollment == 'renewal'
                                      ? 'Renewal'
                                      : 'New',
                                  if (_money)
                                    'paid ${memberMoney(year.paidMinor, year.currency)}',
                                ].join(' · '),
                              ),
                              trailing: _Status(row: year),
                            ),
                          ],
                        ],
                      ),
                    )
                  : ListTable(
                      columns: [
                        const ListColumn('Year', flex: 3),
                        const ListColumn('Plan', flex: 3),
                        const ListColumn('Enrollment', flex: 2),
                        if (_money) const ListColumn('Paid', flex: 2),
                        const ListColumn('Status', flex: 2),
                      ],
                      rows: [
                        for (final year in _years)
                          [
                            Text(_yearOf(year), style: strong),
                            Text(year.planName),
                            Text(
                              year.enrollment == 'renewal' ? 'Renewal' : 'New',
                            ),
                            if (_money)
                              Text(memberMoney(year.paidMinor, year.currency)),
                            _Status(row: year),
                          ],
                      ],
                    ),
            ),
          ),
        ],
      ],
    );
  }
}

/// The side panel that adds a member: who, which plan, from when.
class _AddMember extends StatefulWidget {
  const _AddMember({
    required this.api,
    required this.plans,
    required this.money,
    required this.formats,
  });

  final ApiClient api;
  final List<MembershipPlan> plans;
  final bool money;
  final DisplayFormats formats;

  @override
  State<_AddMember> createState() => _AddMemberState();
}

class _AddMemberState extends State<_AddMember> {
  Map<String, dynamic>? _customer;
  late String? _planId = widget.plans.firstOrNull?.id;
  final _starts = TextEditingController();
  final _paid = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _starts.dispose();
    _paid.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final customer = _customer;
    if (customer == null || _planId == null) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    final int? paid;
    try {
      paid = parseAmountMinor(_paid.text);
    } on FormatException catch (error) {
      setState(() {
        _saving = false;
        _error = error.message;
      });
      return;
    }

    final response = await widget.api.post('/memberships', {
      'customer_id': customer['id'],
      'membership_plan_id': _planId,
      if (_starts.text.trim().isNotEmpty) 'starts_on': _starts.text.trim(),
      if (widget.money && paid != null) 'paid_minor': paid,
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Autocomplete<Map<String, dynamic>>(
          displayStringForOption: (c) => '${c['name'] ?? ''}',
          optionsBuilder: (value) async {
            final term = value.text.trim();
            if (term.length < 2) return const <Map<String, dynamic>>[];
            final response = await widget.api.get('/customers', {
              'search': term,
              'per_page': '20',
            });
            if (!response.ok || response.data is! List) {
              return const <Map<String, dynamic>>[];
            }
            return List<Map<String, dynamic>>.from(response.data as List);
          },
          onSelected: (c) => setState(() => _customer = c),
          fieldViewBuilder: (context, controller, focusNode, onSubmit) =>
              TextField(
                key: const Key('member-add-customer'),
                controller: controller,
                focusNode: focusNode,
                decoration: InputDecoration(
                  labelText: 'Customer',
                  helperText: _customer == null
                      ? 'Type a name or a phone number.'
                      : null,
                  suffixIcon: Icon(
                    _customer == null ? Icons.search : Icons.check_circle,
                    size: 20,
                  ),
                  border: const OutlineInputBorder(),
                ),
                // Typing again un-chooses: what is in the box is who it is for.
                onChanged: (_) {
                  if (_customer != null) setState(() => _customer = null);
                },
              ),
        ),
        const SizedBox(height: 16),
        SearchPickerField<String>(
          value: _planId,
          label: 'Plan',
          border: const OutlineInputBorder(),
          options: [
            for (final plan in widget.plans)
              SearchOption(
                value: plan.id,
                label: plan.label,
                detail: plan.description,
              ),
          ],
          onChanged: (id) => setState(() => _planId = id),
        ),
        const SizedBox(height: 16),
        CalendarDayField(
          controller: _starts,
          formats: widget.formats,
          label: 'Starts',
          helper: 'Left empty, the year starts today.',
        ),
        if (widget.money) ...[
          const SizedBox(height: 16),
          TextField(
            key: const Key('member-add-paid'),
            controller: _paid,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Paid so far',
              prefixText: r'$ ',
              border: OutlineInputBorder(),
            ),
          ),
        ],
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
        const SizedBox(height: 20),
        FilledButton(
          key: const Key('member-add-save'),
          onPressed: _customer == null || _planId == null || _saving
              ? null
              : _save,
          child: Text(_saving ? 'Adding…' : 'Add member'),
        ),
      ],
    );
  }
}

class _EnrollmentTag extends StatelessWidget {
  const _EnrollmentTag(this.enrollment);

  final String enrollment;

  @override
  Widget build(BuildContext context) {
    final renewal = enrollment == 'renewal';
    final colours = ToneColours.of(context, renewal ? Tone.neutral : Tone.info);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Text(
        renewal ? 'Renewal' : 'New',
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: colours.ink,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// When the year renews, in the tone of how soon; or how it ended.
class _Renews extends StatelessWidget {
  const _Renews({
    required this.row,
    required this.formats,
    this.prefixed = false,
  });

  final Membership row;
  final DisplayFormats formats;

  /// "renews 3/14/2027" where no column heading says what the date is.
  final bool prefixed;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.bodySmall;
    final ends = row.endsOn;

    if (row.status == 'cancelled') return Text('Cancelled', style: style);
    if (ends == null) return Text('—', style: style);
    if (row.status == 'expired') {
      return Text(
        'Ended ${formats.formatDate(ends)}',
        style: style?.copyWith(
          color: ToneColours.of(context, Tone.bad).ink,
          fontWeight: FontWeight.w700,
        ),
      );
    }

    final renews = formats.formatDate(ends.add(const Duration(days: 1)));

    return Text(
      prefixed ? 'renews $renews' : renews,
      style: row.renewsSoon
          ? style?.copyWith(
              color: ToneColours.of(context, Tone.caution).ink,
              fontWeight: FontWeight.w700,
            )
          : style,
    );
  }
}

class _Status extends StatelessWidget {
  const _Status({required this.row});

  final Membership row;

  @override
  Widget build(BuildContext context) {
    final (label, tone) = switch (row.status) {
      'active' => ('Active', Tone.ok),
      'upcoming' => ('Starts later', Tone.info),
      'expired' => ('Lapsed', Tone.bad),
      'cancelled' => ('Cancelled', Tone.neutral),
      _ => (row.status, Tone.neutral),
    };

    return Text(
      label,
      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
        color: ToneColours.of(context, tone).ink,
        fontWeight: FontWeight.w700,
      ),
    );
  }
}

/// Minor units as money, or a dash when the server did not send one (no
/// financials, or nothing to say). Whole units unless there are cents.
String memberMoney(int? minor, String currency) => minor == null
    ? '—'
    : formatMoney(minor, currency: currency, cents: minor % 100 != 0);

/// One year of one customer's membership, as GET /memberships sends it.
class Membership {
  const Membership({
    required this.id,
    required this.customerId,
    required this.customerName,
    this.customerPhone,
    this.customerEmail,
    required this.state,
    required this.zip,
    required this.enrollment,
    required this.planId,
    required this.planName,
    required this.billing,
    required this.status,
    required this.currency,
    this.startsOn,
    this.endsOn,
    this.remindedAt,
    this.priceMinor,
    this.paidMinor,
    this.dueMinor,
    this.grossMinor,
    this.knowsVisit = false,
    this.visitOn,
    this.visitJobNumber,
  });

  final String id;
  final String customerId;
  final String customerName;

  /// How to reach the customer (Jose: "show the phone number as well on
  /// members, and email if available ofc"). Null when the customer has none,
  /// and from a server from before they were sent.
  final String? customerPhone;
  final String? customerEmail;

  /// Phone then email, whichever there are, the phone as the app writes it.
  List<String> get contacts => [
    if (customerPhone != null) formatPhoneNumber(customerPhone),
    ?customerEmail,
  ];
  final String state;
  final String zip;
  final String enrollment;
  final String planId;
  final String planName;
  final String billing;
  final String status;
  final String currency;
  final DateTime? startsOn;
  final DateTime? endsOn;
  final DateTime? remindedAt;
  final int? priceMinor;
  final int? paidMinor;
  final int? dueMinor;
  final int? grossMinor;

  /// Whether the server says anything about the next visit at all. A server
  /// from before it sends neither key, and then "no date picked yet" would be
  /// a guess, so nothing is said.
  final bool knowsVisit;

  /// The day the customer picked for their next visit, as stored (no zone
  /// shift), and the job it booked; both null while no day is picked.
  final DateTime? visitOn;
  final String? visitJobNumber;

  /// "Visit booked for 10/26/2026 · job 1234", "No date picked yet" once the
  /// reminder has gone, or null when there is nothing to say.
  String? visitLine(DisplayFormats formats) {
    if (!knowsVisit) return null;
    final on = visitOn;
    if (on != null) {
      final job = visitJobNumber;
      return 'Visit booked for ${formats.formatDate(on)}'
          '${job == null || job.isEmpty ? '' : ' · job $job'}';
    }

    return remindedAt == null ? null : 'No date picked yet';
  }

  String get planLabel =>
      '$planName · ${billing == 'monthly' ? 'monthly' : 'yearly'}';

  /// A live year that ends within 30 days of today.
  bool get renewsSoon {
    final ends = endsOn;
    if (status != 'active' || ends == null) return false;
    final today = DateUtils.dateOnly(DateTime.now());

    return !ends.isBefore(today) && ends.difference(today).inDays <= 30;
  }

  static DateTime? _day(Object? value) =>
      value is String && value.isNotEmpty ? DateTime.tryParse(value) : null;

  static int? _int(Object? value) => value is int ? value : null;

  static String? _text(Object? value) =>
      value is String && value.trim().isNotEmpty ? value.trim() : null;

  factory Membership.fromJson(Map<String, dynamic> row) => Membership(
    id: '${row['id']}',
    customerId: '${row['customer_id']}',
    customerName: '${row['customer_name'] ?? ''}',
    customerPhone: _text(row['customer_phone']),
    customerEmail: _text(row['customer_email']),
    state: '${row['state'] ?? ''}',
    zip: '${row['zip'] ?? ''}',
    enrollment: '${row['enrollment'] ?? 'new'}',
    planId: '${row['plan_id'] ?? ''}',
    planName: '${row['plan_name'] ?? ''}',
    billing: '${row['billing'] ?? 'yearly'}',
    status: '${row['status'] ?? ''}',
    currency: '${row['currency'] ?? 'USD'}',
    startsOn: _day(row['starts_on']),
    endsOn: _day(row['ends_on']),
    remindedAt: _day(row['reminded_at']),
    priceMinor: _int(row['price_minor']),
    paidMinor: _int(row['paid_minor']),
    dueMinor: _int(row['due_minor']),
    grossMinor: _int(row['estimated_gross_minor']),
    knowsVisit: row.containsKey('visit_on'),
    visitOn: _day(row['visit_on']),
    visitJobNumber: row['visit_job_number']?.toString(),
  );

  static List<Membership> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        Membership.fromJson(row),
    ];
  }
}

/// A plan a member joins: what it costs, and how often it is billed.
class MembershipPlan {
  const MembershipPlan({
    required this.id,
    required this.name,
    required this.priceMinor,
    required this.currency,
    required this.billing,
    required this.active,
    this.description,
    this.workizJobType,
  });

  final String id;
  final String name;
  final int priceMinor;
  final String currency;
  final String billing;
  final bool active;

  /// What a visit includes, in a line; null on a plan without one, or from a
  /// server from before plans had one.
  final String? description;

  /// The Workiz job type a confirmed visit on this plan is created with.
  final String? workizJobType;

  /// The price is the YEAR's, however it is billed: the server charges a
  /// twelfth of it for every month begun on a monthly plan (Membership's
  /// dueMinor). Shown as both on a monthly plan, so "$18 a month" is never
  /// mistaken for the price that is stored.
  String get price => billing == 'monthly'
      ? '${memberMoney((priceMinor / 12).round(), currency)} a month '
            '(${memberMoney(priceMinor, currency)} a year)'
      : '${memberMoney(priceMinor, currency)} a year';

  String get label => '$name · $price';

  static List<MembershipPlan> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        MembershipPlan(
          id: '${row['id']}',
          name: '${row['name'] ?? ''}',
          priceMinor: row['price_minor'] is int ? row['price_minor'] as int : 0,
          currency: '${row['currency'] ?? 'USD'}',
          billing: '${row['billing'] ?? 'yearly'}',
          active: row['is_active'] != false,
          description: row['description'] is String
              ? row['description'] as String
              : null,
          workizJobType: row['workiz_job_type'] is String
              ? row['workiz_job_type'] as String
              : null,
        ),
    ];
  }
}
