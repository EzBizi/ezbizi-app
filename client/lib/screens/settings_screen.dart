import 'dart:convert';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/error_note.dart';

/// Settings, at whichever scope is selected (plan §6).
///
/// The interesting part is showing INHERITANCE. A value that came from the
/// organization looks different from one set here, because "what is this set
/// to" and "where did it come from" are different questions — and without the
/// second, people change the wrong level and wonder why nothing happened.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  bool _loading = true;
  String? _error;
  List<dynamic> _settings = [];

  String _scopeType = 'organization';
  String? _scopeId;
  String _scopeLabel = '';

  @override
  void initState() {
    super.initState();
    final me = widget.session.me!;
    _scopeId = me.organization.id;
    _scopeLabel = me.organization.name;
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/settings', {
      'scope_type': _scopeType,
      'scope_id': _scopeId!,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _settings = response.data as List<dynamic>;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _update(String key, dynamic value) async {
    final response = await widget.api.patch(
      '/settings/$key',
      {'value': value},
      {'scope_type': _scopeType, 'scope_id': _scopeId!},
    );

    if (!mounted) return;

    if (response.paymentRequired) {
      // §13 — a lapsed subscription, not a permissions problem. Saying "access
      // denied" when someone simply needs to pay is a support call.
      widget.session.noteReadOnly(response.message);
      _snack(response.message, isError: true);
      return;
    }

    if (!response.ok) {
      _snack(response.message, isError: true);
      return;
    }

    _snack('Saved');
    _load();
  }

  Future<void> _reset(String key) async {
    final response = await widget.api.delete(
      '/settings/$key',
      {'scope_type': _scopeType, 'scope_id': _scopeId!},
    );

    if (!mounted) return;

    _snack(
      response.ok
          ? (_scopeType == 'organization' ? 'Back to the default' : 'Now inherited')
          : response.message,
      isError: !response.ok,
    );
    if (response.ok) _load();
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
      behavior: SnackBarBehavior.floating,
      width: 320,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _scopePicker(context, me),
        const Divider(height: 1),
        Expanded(child: _body(context)),
      ],
    );
  }

  /// Where in the cascade you are configuring (§6).
  ///
  /// ── Why this is not one row of chips ─────────────────────────
  ///
  /// It was, and it flattened three different kinds of thing into one
  /// undifferentiated list: the organization, its companies, and their
  /// franchises, all as equal siblings. That is a lie about the model — a
  /// franchise BELONGS to a company, and a value set on the company is what the
  /// franchise inherits.
  ///
  /// The flattening also made the screen ambiguous the moment a second company
  /// appeared. Two companies each operating Connecticut produced two chips
  /// reading "Connecticut", side by side, with nothing to tell them apart and
  /// different settings behind each. Prefixing the labels would have hidden
  /// that; showing the structure removes it, because the two now sit on
  /// different rows under the company that owns them.
  Widget _scopePicker(BuildContext context, Me me) {
    // Grouped by the company each one belongs to. Every reachable franchise has
    // a reachable company — the server derives one list from the other — so
    // nothing can fall outside a group.
    final byCompany = <String, List<FranchiseSummary>>{};

    for (final f in me.franchises) {
      byCompany.putIfAbsent(f.companyId ?? '', () => []).add(f);
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            SizedBox(
              width: 84,
              child: Text('Configuring',
                  style: Theme.of(context).textTheme.labelSmall),
            ),
            _chip(
              label: me.organization.name,
              // Every tier carries its own glyph, so the rows read as three
              // kinds of thing rather than one list with some of it decorated.
              // A hub for the organization: it is what the companies hang off,
              // and its silhouette stays distinct from the two buildings below
              // it at 15px.
              icon: Icons.hub_outlined,
              selected: _scopeType == 'organization',
              onTap: () => _select('organization', me.organization.id, me.organization.name),
            ),
          ]),

          for (final company in me.companies) ...[
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // The indent is the hierarchy. Nothing else in this row says
                // that the chips to the right sit under the company on the
                // left, and a label would only repeat what the position shows.
                const SizedBox(width: 84),
                _chip(
                  label: company.name,
                  icon: Icons.apartment_outlined,
                  selected: _scopeType == 'company' && _scopeId == company.id,
                  onTap: () => _select('company', company.id, company.name),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final f in byCompany[company.id] ?? const [])
                        _chip(
                          label: f.name,
                          icon: Icons.storefront_outlined,
                          selected: _scopeType == 'franchise' && _scopeId == f.id,
                          onTap: () => _select('franchise', f.id, f.name),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _chip({
    required String label,
    required IconData icon,
    required bool selected,
    required VoidCallback onTap,
  }) {
    return ChoiceChip(
      label: Text(label),
      avatar: Icon(icon, size: 15),
      selected: selected,
      onSelected: (_) => onTap(),
    );
  }

  /// One place that changes scope, so the three fields cannot drift apart.
  ///
  /// They were set inline at each of the three call sites, which is how a
  /// scope's type, id and label end up describing different things after an
  /// edit to one of them.
  void _select(String type, String id, String label) {
    setState(() {
      _scopeType = type;
      _scopeId = id;
      _scopeLabel = label;
    });

    _load();
  }

  Widget _body(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    // Grouped by key prefix, matching the server's own grouping.
    final groups = <String, List<dynamic>>{};
    for (final s in _settings) {
      final key = s['key'] as String;
      final group = key.contains('.') ? key.split('.').first : 'general';
      groups.putIfAbsent(group, () => []).add(s);
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        // Everything on this screen belongs to the ORGANIZATION and is set by
        // an administrator. Your own theme, password and second factor moved to
        // "Your account" — they were here, directly above the organization's
        // job statuses, which implied whoever could change one could change the
        // other. §2.1 is explicit that the two categories are not to be merged.
        for (final entry in groups.entries) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 20, 4, 8),
            child: Text(entry.key.toUpperCase(),
                style: Theme.of(context).textTheme.labelSmall),
          ),
          Card(
            child: Column(
              children: [
                for (var i = 0; i < entry.value.length; i++) ...[
                  if (i > 0) const Divider(height: 1),
                  _SettingRow(
                    setting: entry.value[i] as Map<String, dynamic>,
                    scopeLabel: _scopeLabel,
                    scopeType: _scopeType,
                    onChanged: _update,
                    onReset: _reset,
                  ),
                ],
              ],
            ),
          ),
        ],
      ],
    );
  }
}

class _SettingRow extends StatelessWidget {
  const _SettingRow({
    required this.setting,
    required this.scopeLabel,
    required this.scopeType,
    required this.onChanged,
    required this.onReset,
  });

  final Map<String, dynamic> setting;
  final String scopeLabel;

  /// Organization is the top of the cascade, so it inherits from nothing. The
  /// row has to know, or it offers to "reset to inherited" at a level with
  /// nothing above it.
  final String scopeType;
  final void Function(String key, dynamic value) onChanged;
  final void Function(String key) onReset;

  @override
  Widget build(BuildContext context) {
    final key = setting['key'] as String;
    final type = setting['type'] as String;
    // Two separate questions, and the screen used to ask only the first.
    //
    //   editable_here — may this SCOPE hold a value for it (inheritance)
    //   may_edit      — may THIS PERSON set it here (permission)
    //
    // Rendering on the first alone meant somebody without `organizations.edit`
    // saw every control live, flicked a switch, watched it animate, and got a
    // 403 from a control that had already moved.
    final scopeAllows = setting['editable_here'] as bool? ?? false;
    final editable = setting['may_edit'] as bool? ?? scopeAllows;
    final source = setting['source'] as Map<String, dynamic>?;
    final inherited = source?['inherited'] as bool? ?? false;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(setting['label'] as String,
                    style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 2),
                // Where the value came from. The whole reason the API returns
                // `source` — an admin who can't see this changes the wrong level.
                Text(
                  source == null
                      // Nothing set anywhere in the cascade — the value on
                      // screen is the platform's, not this organization's.
                      ? 'EzBizi default'
                      : !scopeAllows
                      ? 'Set at organization level only'
                      : !editable
                          // Names the permission. "You cannot do that" sends
                          // somebody to ask an administrator for something
                          // neither of them can name.
                          ? 'Read-only — needs ${setting['permission'] ?? 'permission'}'
                          : inherited
                              ? 'Inherited from ${source['name']}'
                              : 'Set here',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(fontSize: 12),
                ),

                // The server sends `help` for every setting and nothing showed
                // it, so the reasoning behind each one lived only in the code.
                if ((setting['help'] as String?)?.isNotEmpty ?? false) ...[
                  const SizedBox(height: 4),
                  Text(
                    setting['help'] as String,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 16),
          _control(context, key, type, editable),
          if (editable && !inherited && source != null)
            IconButton(
              icon: const Icon(Icons.settings_backup_restore, size: 18),
              // At organization scope there is nothing above to inherit FROM —
              // it is the top of the cascade. Clearing the value there still
              // does something useful, it just falls back to the platform's
              // default rather than to a parent, and the label should say which.
              tooltip: scopeType == 'organization'
                  ? 'Reset to the EzBizi default'
                  : 'Reset to inherited',
              onPressed: () => onReset(key),
            ),
        ],
      ),
    );
  }

  Widget _control(BuildContext context, String key, String type, bool editable) {
    final value = setting['value'];

    if (type == 'bool') {
      return Switch(
        value: value == true,
        onChanged: editable ? (v) => onChanged(key, v) : null,
      );
    }

    if (type == 'enum') {
      final options = (setting['options'] as List<dynamic>? ?? []).cast<String>();
      return DropdownButton<String>(
        value: options.contains(value) ? value as String : null,
        underline: const SizedBox.shrink(),
        onChanged: editable ? (v) => v == null ? null : onChanged(key, v) : null,
        items: options.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
      );
    }

    if (type == 'json') {
      // Structured values used to render in the same 140px box as a tax rate,
      // showing `{monday: {open: 08:00...` — a Dart Map's toString, which is not
      // JSON and which the server rejects the moment it is sent back. So the
      // control offered you a value it would refuse to accept.
      return _JsonButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        onChanged: (v) => onChanged(key, v),
      );
    }

    // int / decimal / string.
    return SizedBox(
      width: 150,
      child: _InlineField(
        // Keyed by value, so a save elsewhere or a scope switch rebuilds the
        // field instead of leaving the old text sitting in it.
        key: ValueKey('$key:${value?.toString()}'),
        initial: value?.toString() ?? '',
        enabled: editable,
        numeric: type == 'int' || type == 'decimal',
        onCommitted: (v) => onChanged(key, v),
      ),
    );
  }
}

/// A text field that saves when you leave it, not only when you press Enter.
///
/// The old one committed on `onFieldSubmitted` alone. Everybody who typed a
/// value and clicked away — which is most people, most of the time — watched it
/// silently revert on the next load and concluded the screen was broken.
///
/// It is a StatefulWidget rather than a callback on the parent because it needs
/// a FocusNode, and because committing on every keystroke would fire a request
/// per character.
class _InlineField extends StatefulWidget {
  const _InlineField({
    super.key,
    required this.initial,
    required this.enabled,
    required this.numeric,
    required this.onCommitted,
  });

  final String initial;
  final bool enabled;
  final bool numeric;
  final ValueChanged<String> onCommitted;

  @override
  State<_InlineField> createState() => _InlineFieldState();
}

class _InlineFieldState extends State<_InlineField> {
  late final TextEditingController _controller =
      TextEditingController(text: widget.initial);
  late final FocusNode _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus) _commit();
    });
  }

  @override
  void dispose() {
    _focus.dispose();
    _controller.dispose();
    super.dispose();
  }

  void _commit() {
    final typed = _controller.text.trim();

    // Unchanged means nothing to save. Without this, tabbing across a row of
    // settings would write every one of them and fill the audit log with
    // changes nobody made.
    if (typed == widget.initial.trim()) return;

    widget.onCommitted(typed);
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _controller,
      focusNode: _focus,
      enabled: widget.enabled,
      textAlign: TextAlign.right,
      decoration: const InputDecoration(
        isDense: true,
        contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      ),
      keyboardType: widget.numeric
          ? const TextInputType.numberWithOptions(decimal: true)
          : TextInputType.text,
      onSubmitted: (_) => _commit(),
    );
  }
}

/// Structured settings — business hours, quiet hours — edited as real JSON.
///
/// A dialog rather than an inline box: these are nested objects, and a 140px
/// field cannot show one, let alone let somebody correct it. The text is parsed
/// before it is sent, so a typo is caught here with the cursor still in the
/// field rather than as a 422 from the server.
class _JsonButton extends StatelessWidget {
  const _JsonButton({
    required this.label,
    required this.value,
    required this.editable,
    required this.onChanged,
  });

  final String label;
  final dynamic value;
  final bool editable;
  final ValueChanged<dynamic> onChanged;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      onPressed: editable ? () => _open(context) : null,
      icon: const Icon(Icons.data_object, size: 16),
      label: Text(_summary()),
    );
  }

  /// One line that says what is set, without pretending to show the whole thing.
  String _summary() {
    if (value == null) return 'Not set';
    if (value is Map) return '${(value as Map).length} entries';
    if (value is List) return '${(value as List).length} items';

    return 'Edit';
  }

  Future<void> _open(BuildContext context) async {
    final controller = TextEditingController(
      text: const JsonEncoder.withIndent('  ').convert(value ?? {}),
    );

    String? error;

    final saved = await showDialog<Object?>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => AlertDialog(
          title: Text(label),
          content: SizedBox(
            width: 460,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: controller,
                  minLines: 8,
                  maxLines: 18,
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                  decoration: const InputDecoration(border: OutlineInputBorder()),
                ),
                if (error != null) ...[
                  const SizedBox(height: 8),
                  ErrorNote(error!),
                ],
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () {
                try {
                  Navigator.of(context).pop(jsonDecode(controller.text));
                } on FormatException catch (e) {
                  // Caught here, where the cursor still is. A 422 arriving
                  // after the dialog closed loses both the message and the
                  // half-typed value.
                  repaint(() => error = 'That is not valid JSON — ${e.message}');
                }
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );

    controller.dispose();

    if (saved != null) onChanged(saved);
  }
}
