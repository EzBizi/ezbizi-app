import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import 'memberships_members.dart';

/// Customers › Memberships › Settings (UI queue 52, desktop 1 and phone 4).
///
/// The plans a member joins: what each costs and how often it is billed, how
/// many are on it this year, and whether new members may still join it. A
/// plan is changed in an editor drawn above the list, in place; adding one is
/// the same editor, empty. Changing plans takes `memberships.edit` (the
/// Membership session's spec); without it the list is read only.
class MembershipsSettings extends StatefulWidget {
  const MembershipsSettings({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsSettings> createState() => _MembershipsSettingsState();
}

class _MembershipsSettingsState extends State<MembershipsSettings> {
  bool _loading = true;
  String? _error;
  List<MembershipPlan> _plans = const [];

  /// Members on each plan this year, by plan id. Null when the members could
  /// not be read: the column is then not drawn rather than drawn as zeros.
  Map<String, int>? _members;

  /// The plan being edited: an id, `new`, or null.
  String? _editing;
  bool _saving = false;
  String? _formError;
  final _name = TextEditingController();
  final _price = TextEditingController();
  final _description = TextEditingController();
  final _workizType = TextEditingController();
  String _billing = 'yearly';

  bool get _canEdit => widget.session.me?.can('memberships.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
    // A plan added or changed by another admin, or members moving: the
    // plans and their counts re-read; a plan being edited keeps its boxes.
    _live = LiveChanges.of(widget.api).subscribe(
      {'membership_plans', 'memberships'},
      (_) {
        if (!_loading && !_saving) return _load(quiet: true);
      },
      active: LiveChanges.visible(this),
    );
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _name.dispose();
    _description.dispose();
    _workizType.dispose();
    _price.dispose();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the plans on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _plans.isEmpty;
        _error = null;
      });
    }

    final results = await Future.wait([
      widget.api.get('/memberships/plans'),
      widget.api.get('/memberships'),
    ]);
    if (!mounted || (quiet && !results[0].ok)) return;

    setState(() {
      _loading = false;
      if (!results[0].ok) {
        _error = results[0].reason;
        return;
      }
      _plans = MembershipPlan.listFrom(results[0].body);
      if (results[1].ok) {
        final counts = <String, int>{};
        for (final row in Membership.listFrom(results[1].body)) {
          if (row.status == 'active') {
            counts[row.planId] = (counts[row.planId] ?? 0) + 1;
          }
        }
        _members = counts;
      } else {
        _members = null;
      }
    });
  }

  void _edit(MembershipPlan? plan) => setState(() {
    _editing = plan?.id ?? 'new';
    _formError = null;
    _name.text = plan?.name ?? '';
    _price.text = plan == null
        ? ''
        : (plan.priceMinor / 100).toStringAsFixed(2);
    _billing = plan?.billing ?? 'yearly';
    _description.text = plan?.description ?? '';
    _workizType.text = plan?.workizJobType ?? '';
  });

  Future<void> _save() async {
    final int? price;
    try {
      price = parseAmountMinor(_price.text);
    } on FormatException catch (error) {
      setState(() => _formError = error.message);
      return;
    }
    if (_name.text.trim().isEmpty || price == null) {
      setState(() => _formError = 'A plan needs a name and a price.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    // `description` is what the join page shows under the plan's name. A
    // server from before it drops the key (planInput validates), so it is
    // safe to send to either.
    final description = _description.text.trim();
    // Jose: "Type per plan" — the Workiz job type a confirmed visit is
    // created with. Dropped by a server from before it, like description.
    final workizType = _workizType.text.trim();
    final body = {
      'name': _name.text.trim(),
      'price_minor': price,
      'billing': _billing,
      'description': description.isEmpty ? null : description,
      'workiz_job_type': workizType.isEmpty ? null : workizType,
    };
    final response = _editing == 'new'
        ? await widget.api.post('/memberships/plans', {
            ...body,
            'is_active': true,
          })
        : await widget.api.patch('/memberships/plans/$_editing', body);
    if (!mounted) return;

    setState(() {
      _saving = false;
      if (response.ok) {
        _editing = null;
      } else {
        _formError = response.message;
      }
    });
    if (response.ok) await _load();
  }

  Future<void> _setActive(MembershipPlan plan, bool active) async {
    final response = await widget.api.patch('/memberships/plans/${plan.id}', {
      'is_active': active,
    });
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return ErrorRetry(_error!, onRetry: _load);
    }

    final narrow = MediaQuery.sizeOf(context).width < 600;
    final members = _members;

    return ListView(
      padding: EdgeInsets.all(narrow ? 16 : 24),
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Plans', style: theme.textTheme.titleMedium),
                  Text(
                    'What a member joins, what it costs, and how often it is '
                    'billed.',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (_canEdit && _editing == null)
              FilledButton.icon(
                key: const Key('plans-add'),
                onPressed: () => _edit(null),
                icon: const Icon(Icons.add),
                label: const Text('Add a plan'),
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_editing != null) ...[_editor(theme), const SizedBox(height: 12)],
        if (_plans.isEmpty)
          Text(
            'No plans yet. A member joins a plan, so add one first.',
            key: const ValueKey('tour-memberships-plans'),
            style: theme.textTheme.bodyMedium,
          )
        else
          ListTable(
            key: const ValueKey('tour-memberships-plans'),
            rowKey: (i) => ValueKey('plan:${_plans[i].id}'),
            onTap: _canEdit ? (i) => _edit(_plans[i]) : null,
            columns: [
              const ListColumn('Plan', flex: 4),
              const ListColumn('Price', flex: 3),
              const ListColumn('Billed', flex: 2),
              if (members != null)
                const ListColumn('Members', flex: 2, end: true),
              const ListColumn('Open to join', flex: 2, end: true),
            ],
            rows: [
              for (final plan in _plans)
                [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        plan.name,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      if ((plan.description ?? '').isNotEmpty)
                        Text(
                          plan.description!,
                          style: theme.textTheme.bodySmall,
                        ),
                      if ((plan.workizJobType ?? '').isNotEmpty)
                        Text(
                          'Workiz job type: ${plan.workizJobType}',
                          style: theme.textTheme.bodySmall,
                        ),
                    ],
                  ),
                  Text(plan.price),
                  Text(plan.billing == 'monthly' ? 'Monthly' : 'Yearly'),
                  if (members != null) Text('${members[plan.id] ?? 0}'),
                  Switch(
                    key: ValueKey('plan-active:${plan.id}'),
                    value: plan.active,
                    onChanged: _canEdit ? (v) => _setActive(plan, v) : null,
                  ),
                ],
            ],
          ),
        const SizedBox(height: 16),
        Container(
          key: const ValueKey('tour-memberships-renewal-note'),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: ToneColours.of(context, Tone.info).fill,
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          child: Text(
            'A month before a member\'s year ends, "Renewal due in a month" '
            'fires once. What it sends, a text, a WhatsApp or an email, is set '
            'in Automations.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: ToneColours.of(context, Tone.info).ink,
            ),
          ),
        ),
        if (!_canEdit) ...[
          const SizedBox(height: 8),
          Text(
            'You can see the plans, but not change them.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  Widget _editor(ThemeData theme) {
    return Card(
      key: const Key('plan-editor'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _editing == 'new' ? 'A new plan' : 'Change the plan',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                SizedBox(
                  width: 260,
                  child: TextField(
                    key: const Key('plan-name'),
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'Name',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                SizedBox(
                  width: 160,
                  child: TextField(
                    key: const Key('plan-price'),
                    controller: _price,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    // The year's price, whatever the billing: the server
                    // charges a twelfth of it a month on a monthly plan.
                    decoration: InputDecoration(
                      labelText: 'Price for the year',
                      prefixText: r'$ ',
                      helperText: _billing == 'monthly'
                          ? 'A twelfth is billed each month.'
                          : null,
                      border: const OutlineInputBorder(),
                    ),
                  ),
                ),
                SizedBox(
                  width: 200,
                  child: SearchPickerField<String>(
                    value: _billing,
                    label: 'Billed',
                    border: const OutlineInputBorder(),
                    options: const [
                      SearchOption(value: 'yearly', label: 'Yearly'),
                      SearchOption(value: 'monthly', label: 'Monthly'),
                    ],
                    onChanged: (v) => setState(() => _billing = v ?? 'yearly'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('plan-description'),
              controller: _description,
              maxLength: 255,
              decoration: const InputDecoration(
                labelText: 'What it includes',
                hintText: 'One sweep and inspection a year',
                helperText:
                    'Shown under the plan on the page a customer joins from.',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 4),
            SizedBox(
              width: 320,
              child: TextField(
                key: const Key('plan-workiz-type'),
                controller: _workizType,
                maxLength: 120,
                decoration: const InputDecoration(
                  labelText: 'Workiz job type',
                  hintText: 'Chimney Sweep',
                  helperText: 'The job type a confirmed visit gets in Workiz.',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            if (_formError != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_formError!),
            ],
            const SizedBox(height: 12),
            Row(
              children: [
                FilledButton(
                  key: const Key('plan-save'),
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Save'),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: () => setState(() => _editing = null),
                  child: const Text('Cancel'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
