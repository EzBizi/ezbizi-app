import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';

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

    _snack(response.ok ? 'Now inherited' : response.message, isError: !response.ok);
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

  Widget _scopePicker(BuildContext context, Me me) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text('Configuring', style: Theme.of(context).textTheme.labelSmall),
          ChoiceChip(
            label: Text(me.organization.name),
            selected: _scopeType == 'organization',
            onSelected: (_) => setState(() {
              _scopeType = 'organization';
              _scopeId = me.organization.id;
              _scopeLabel = me.organization.name;
              _load();
            }),
          ),
          // Only franchises this user can reach (§4) — the server sends reach,
          // so the UI never offers one that would fail on save.
          ...me.franchises.map((f) => ChoiceChip(
                label: Text(f.name),
                selected: _scopeType == 'franchise' && _scopeId == f.id,
                onSelected: (_) => setState(() {
                  _scopeType = 'franchise';
                  _scopeId = f.id;
                  _scopeLabel = f.name;
                  _load();
                }),
              )),
        ],
      ),
    );
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
    required this.onChanged,
    required this.onReset,
  });

  final Map<String, dynamic> setting;
  final String scopeLabel;
  final void Function(String key, dynamic value) onChanged;
  final void Function(String key) onReset;

  @override
  Widget build(BuildContext context) {
    final key = setting['key'] as String;
    final type = setting['type'] as String;
    final editable = setting['editable_here'] as bool? ?? false;
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
                  !editable
                      ? 'Set at organization level only'
                      : inherited
                          ? 'Inherited from ${source!['name']}'
                          : 'Set here',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(fontSize: 12),
                ),
              ],
            ),
          ),
          const SizedBox(width: 16),
          _control(context, key, type, editable),
          if (editable && !inherited && source != null)
            IconButton(
              icon: const Icon(Icons.settings_backup_restore, size: 18),
              tooltip: 'Reset to inherited',
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

    // int / decimal / string — a compact inline field.
    return SizedBox(
      width: 140,
      child: TextFormField(
        initialValue: value?.toString() ?? '',
        enabled: editable,
        textAlign: TextAlign.right,
        decoration: const InputDecoration(
          isDense: true,
          contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        ),
        keyboardType: (type == 'int' || type == 'decimal')
            ? const TextInputType.numberWithOptions(decimal: true)
            : TextInputType.text,
        onFieldSubmitted: (v) => onChanged(key, v),
      ),
    );
  }
}
