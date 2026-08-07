import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// Global module availability — the platform-wide gate (plan §2.1, layer 3).
///
/// This decides what EXISTS for everyone. It sits above each organization's own
/// permissions, and the two are deliberately separate: this answers "does this
/// feature exist right now", theirs answers "is this person allowed to use it".
/// Merged, an org admin editing a role could affect what every other customer
/// sees.
///
/// `disabled` beats everything, including an organization's Administrator. A
/// kill switch that exempts admins is not a kill switch — during an incident the
/// admins are exactly the people still hammering the broken module.
class ModulesScreen extends StatefulWidget {
  const ModulesScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<ModulesScreen> createState() => _ModulesScreenState();
}

class _ModulesScreenState extends State<ModulesScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _modules = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/modules');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _modules = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _setState(Map<String, dynamic> module, String state) async {
    var body = <String, dynamic>{'state': state};

    if (state != 'enabled') {
      // A reason is mandatory for anything but 'enabled' — an unexplained
      // disabled module is indistinguishable from a mistake once whoever flipped
      // it has moved on, and the person who finds it is usually mid-incident.
      // Beta additionally needs the organizations, since beta for nobody is a
      // confusingly-named disable.
      final details = await _askDetails(module['label'] as String, state);
      if (details == null) return;

      body.addAll(details);
    }

    final response =
        await widget.staff.api.patch('/staff/modules/${module['key']}', body);

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.message)),
      );
      return;
    }

    _load();
  }

  Future<Map<String, dynamic>?> _askDetails(String label, String state) async {
    // Only fetched when it is actually needed — the modules screen has no reason
    // to know the customer list until someone chooses beta.
    List<Map<String, dynamic>> organizations = [];

    if (state == 'beta') {
      final response = await widget.staff.api.get('/staff/organizations');

      if (!response.ok) {
        if (mounted) {
          ScaffoldMessenger.of(context)
              .showSnackBar(SnackBar(content: Text(response.message)));
        }
        return null;
      }

      organizations = List<Map<String, dynamic>>.from(response.data as List);
    }

    if (!mounted) return null;

    final controller = TextEditingController();
    final selected = <String>{};

    return showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, setLocal) => SidePanel(
          title: 'Set $label to $state',
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop({
                'reason': controller.text.trim(),
                if (state == 'beta') 'beta_organization_ids': selected.toList(),
              }),
              child: const Text('Apply'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                controller: controller,
                autofocus: true,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Reason',
                  helperText:
                      'Read by whoever finds this later, probably mid-incident',
                ),
              ),
              if (state == 'beta') ...[
                const SizedBox(height: 16),
                Text('Available to',
                    style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(
                  'Everyone else sees the module as switched off.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 8),
                ...organizations.map((org) => CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: selected.contains(org['id']),
                      title: Text(org['name'] as String),
                      onChanged: (on) => setLocal(() {
                        on == true
                            ? selected.add(org['id'] as String)
                            : selected.remove(org['id']);
                      }),
                    )),
              ],
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Modules',
          note: 'Platform-wide availability. This is the gate above every '
              'organization\'s own permissions — disabled beats everything.',
          action: IconButton(
            tooltip: 'Refresh',
            onPressed: _load,
            icon: const Icon(Icons.refresh),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _modules.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: Text(
                        'No modules are registered yet. They appear here as they '
                        'are built — nothing is listed before it exists.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _modules.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _modules[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> module) {
    final state = module['state'] as String;
    final permissions = List<String>.from(module['permissions'] as List? ?? []);

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(module['label'] as String,
                          style: Theme.of(context).textTheme.titleMedium),
                      const SizedBox(height: 2),
                      Text(module['key'] as String,
                          style: Theme.of(context).textTheme.bodyMedium),
                    ],
                  ),
                ),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: 'enabled', label: Text('On')),
                    ButtonSegment(value: 'beta', label: Text('Beta')),
                    ButtonSegment(value: 'disabled', label: Text('Off')),
                  ],
                  selected: {state},
                  onSelectionChanged: (s) => _setState(module, s.first),
                ),
              ],
            ),
            if (module['is_default'] as bool? ?? false)
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Text(
                  'No flag set — available by default. A module exists in code, '
                  'so nobody having restricted it means it is on.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
            if ((module['reason'] as String?)?.isNotEmpty ?? false) ...[
              const SizedBox(height: 12),
              ErrorNote('${module['reason']}'
                  '${module['changed_by'] != null ? ' — ${module['changed_by']}' : ''}'),
            ],
            if (permissions.isNotEmpty) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: permissions
                    .map((p) => Chip(
                          label: Text(p, style: const TextStyle(fontSize: 11)),
                          visualDensity: VisualDensity.compact,
                          materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ))
                    .toList(),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
