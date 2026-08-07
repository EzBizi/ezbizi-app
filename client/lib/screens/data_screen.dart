import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/side_panel.dart';

/// Import and export (plan §12, §9).
///
/// The list of resources comes from the server, so a new one appears here
/// without a client release — the same rule the settings and workflow screens
/// follow.
class DataScreen extends StatefulWidget {
  const DataScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DataScreen> createState() => _DataScreenState();
}

class _DataScreenState extends State<DataScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _resources = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    final response = await widget.api.get('/exports');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _resources = response.ok
          ? (response.body['resources'] as List<dynamic>)
              .cast<Map<String, dynamic>>()
          : [];
    });
  }

  Future<void> _export(String key, String label) async {
    final bytes = await widget.api.getBytes('/exports/$key');

    if (!mounted) return;

    if (bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not export $label.')),
      );
      return;
    }

    Files.download('$key.csv', bytes);
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text('Import & export', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 8),
          Text(
            'Your data is yours. Exports are plain CSV and open in any '
            'spreadsheet; an export can be edited and imported back.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),
          for (final resource in _resources) _card(resource),
        ],
      ),
    );
  }

  Widget _card(Map<String, dynamic> resource) {
    final key = resource['key'] as String;
    final label = resource['label'] as String;
    final importable = resource['importable'] as bool? ?? false;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(
                    (resource['columns'] as List<dynamic>).join(', '),
                    style: Theme.of(context).textTheme.bodySmall,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            if (importable)
              TextButton.icon(
                onPressed: () => _openImport(resource),
                icon: const Icon(Icons.upload_outlined),
                label: const Text('Import'),
              ),
            const SizedBox(width: 8),
            FilledButton.tonalIcon(
              onPressed: () => _export(key, label),
              icon: const Icon(Icons.download_outlined),
              label: const Text('Export'),
            ),
          ],
        ),
      ),
    );
  }

  void _openImport(Map<String, dynamic> resource) {
    showSidePanel<bool>(
      context,
      builder: (_) => _ImportPanel(
        api: widget.api,
        session: widget.session,
        resource: resource,
      ),
    );
  }
}

/// The import flow, which is a check and then a commit.
///
/// The dry run is not optional politeness: §11.3 calls import "the first thing a
/// new client touches", and the difference between a preview and a surprise is
/// whether somebody trusts the product with the rest of their data.
///
/// Wider than a panel's default because the rows are the point — CSV in a
/// monospace box, and a preview listing problems by row number, both of which
/// wrap into noise at the width a form needs.
class _ImportPanel extends StatefulWidget {
  const _ImportPanel({
    required this.api,
    required this.session,
    required this.resource,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> resource;

  @override
  State<_ImportPanel> createState() => _ImportPanelState();
}

class _ImportPanelState extends State<_ImportPanel> {
  final _csv = TextEditingController();

  String? _franchiseId;
  String? _filename;
  String? _error;
  bool _busy = false;
  Map<String, dynamic>? _preview;

  @override
  void initState() {
    super.initState();
    final franchises = widget.session.me!.franchises;
    if (franchises.length == 1) _franchiseId = franchises.first.id;
  }

  @override
  void dispose() {
    _csv.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final file = await Files.pickText();

    if (file == null || !mounted) return;

    setState(() {
      _filename = file.name;
      _csv.text = file.text;
      _preview = null;
    });
  }

  Future<void> _run({required bool dryRun}) async {
    if (_franchiseId == null) {
      setState(() => _error = 'Choose which franchise these belong to.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/imports/${widget.resource['key']}', {
      'csv': _csv.text,
      'franchise_id': _franchiseId,
      'dry_run': dryRun,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;

      if (!response.ok) {
        // A whole-file problem — wrong columns, too big — arrives as a
        // validation error on `file`, which is more useful than the generic
        // message beside it.
        final errors = response.body['errors'] as Map<String, dynamic>?;
        _error = (errors?['file'] as List<dynamic>?)?.first as String? ??
            response.message;
        _preview = null;
        return;
      }

      _preview = response.body;
    });

    if (!dryRun && response.ok && mounted) {
      Navigator.of(context).pop(true);

      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Imported ${response.body['imported']} '
            '${(widget.resource['label'] as String).toLowerCase()}.'),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final columns = (widget.resource['import_columns'] as List<dynamic>).join(', ');
    final franchises = widget.session.me!.franchises;

    return SidePanel(
      title: 'Import ${(widget.resource['label'] as String).toLowerCase()}',
      width: 560,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        TextButton(
          onPressed: _busy || _csv.text.trim().isEmpty
              ? null
              : () => _run(dryRun: true),
          child: const Text('Check the file'),
        ),
        FilledButton(
          // Only after a check, and only if it found something to import.
          // Offering "import" on an unchecked file makes the preview a step to
          // skip rather than the point.
          onPressed: _busy || (_preview?['imported'] as int? ?? 0) == 0
              ? null
              : () => _run(dryRun: false),
          child: Text(_busy
              ? 'Working…'
              : 'Import ${_preview?['imported'] ?? ''}'.trim()),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Columns: $columns',
              style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 4),
          Text(
            'The first row must be the column names. Leave out any column '
            'you have no data for.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          if (franchises.isEmpty)
            // Permission without reach (§4). Rendering the form anyway gives
            // somebody a Choose-a-file button, a paste box, and a refusal
            // they cannot act on — the missing thing is an assignment, which
            // only an administrator can grant.
            Text(
              'You are not assigned to a franchise, so there is nowhere for '
              'these records to belong. An administrator can assign you to one.',
              style: Theme.of(context).textTheme.bodyMedium,
            )
          else if (franchises.length > 1)
            DropdownButtonFormField<String>(
              initialValue: _franchiseId,
              decoration: const InputDecoration(
                labelText: 'Accountable franchise',
                helperText: 'Every imported record is owned by this one.',
              ),
              items: [
                for (final f in franchises)
                  DropdownMenuItem(value: f.id, child: Text(f.name)),
              ],
              onChanged: (v) => setState(() => _franchiseId = v),
            ),
          const SizedBox(height: 12),
          Row(
            children: [
              OutlinedButton.icon(
                onPressed: _busy ? null : _pick,
                icon: const Icon(Icons.attach_file),
                label: const Text('Choose a CSV file'),
              ),
              const SizedBox(width: 12),
              if (_filename != null)
                Expanded(
                  child: Text(_filename!,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall),
                ),
            ],
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _csv,
            minLines: 4,
            maxLines: 8,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
            decoration: const InputDecoration(
              labelText: '…or paste the rows here',
              border: OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() => _preview = null),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
          if (_preview != null) ...[
            const SizedBox(height: 16),
            _previewPanel(_preview!),
          ],
        ],
      ),
    );
  }

  Widget _previewPanel(Map<String, dynamic> preview) {
    final imported = preview['imported'] as int;
    final failed = preview['failed'] as int;
    final errors = (preview['errors'] as List<dynamic>).cast<Map<String, dynamic>>();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          failed == 0
              ? '$imported rows are ready to import.'
              : '$imported rows are ready. $failed will be skipped:',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: 8),

        // By row number, matching the spreadsheet — the header is row 1. Without
        // it you are asking somebody to find one wrong cell in a thousand.
        for (final error in errors.take(20))
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Text(
              'Row ${error['row']}: ${(error['messages'] as List<dynamic>).join(' ')}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),

        if (errors.length > 20)
          Text('…and ${errors.length - 20} more.',
              style: Theme.of(context).textTheme.bodySmall),

        if (preview['truncated'] == true)
          Text('Only the first ${errors.length} problems are listed.',
              style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}
