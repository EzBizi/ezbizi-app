import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import 'error_note.dart';
import 'franchise_field.dart';
import 'refused.dart';
import 'side_panel.dart';

/// Getting records out of a tab, and back into it (plan §12, §9).
///
/// ── Why this is a widget and not a screen ────────────────────────
///
/// It was a screen: an "Import & export" destination listing every resource in
/// one place, with an Export button per row. The owner's call was that transfer
/// belongs in the tab that owns the records — "the import exports will go inside
/// each of the tabs, not a separate tab to do all of them, it's more intuitive
/// that way" — so the same two controls now sit in the header of Customers and
/// of Jobs, beside the button that adds one.
///
/// What did NOT move here is `exports/bundle` and `exports/inventory`. Those are
/// about the whole organization rather than about one resource, and §9 is what
/// requires them; they live in Settings, under the organization's own data
/// section. Putting "export everything this business holds" behind the Customers
/// tab would have been the same mistake in a smaller box.
///
/// ── What it asks the server, and what it decides itself ──────────
///
/// The catalogue at `/exports` lists the resources this person may READ, and
/// answers two further questions about each: whether they may take all of it
/// (`export_refusal`) and whether it can be imported at all. This widget decides
/// nothing about permissions beyond turning those answers into a control or a
/// sentence — no permission key is compiled into this file.
///
/// A resource the catalogue does not mention gets no controls, because the
/// server has said this person may not read it. A resource that cannot be
/// imported by anybody — jobs, deliberately — gets Export alone.
///
/// Neither control is ever silently dropped over a permission. A control that
/// quietly is not there teaches nobody anything: it stays, disabled, carrying
/// the server's own sentence about who can grant the missing permission (R8).
///
/// ── Export is its own grant now ──────────────────────────────────
///
/// Being listed used to mean "you may export this", because export was gated on
/// the resource's view permission and nothing else. It is `customers.export` and
/// `jobs.export` since 2026-08-12: the "Import & export" tab was gated on
/// `data.view`, and when it was removed and these controls moved into the tabs
/// that own the records, an organization lost its only way to say "this role
/// does not download the whole customer list". The server publishes the refusal
/// as a finished SENTENCE rather than as a key — unlike the import half below —
/// so the greyed control here and the 403 behind it cannot word one refusal two
/// ways.
class DataTransfer extends StatefulWidget {
  const DataTransfer({
    super.key,
    required this.api,
    required this.session,
    required this.resource,
    this.onImported,
  });

  final ApiClient api;
  final Session session;

  /// Which resource this tab is about — `customers`, `jobs`. The tab knows its
  /// own name; everything else about it comes from the server.
  final String resource;

  /// Called after an import commits, so the list behind this can refetch.
  /// Null for a resource that cannot be imported.
  final VoidCallback? onImported;

  @override
  State<DataTransfer> createState() => _DataTransferState();
}

class _DataTransferState extends State<DataTransfer> {
  /// The catalogue entry for this resource, once it arrives. Null while the
  /// request is in flight, and null afterwards when the server did not list it.
  Map<String, dynamic>? _resource;

  /// Nothing is drawn until the answer lands.
  ///
  /// No spinner, deliberately: this sits in a header beside the primary action
  /// of the screen, and a control that appears a beat later is calmer than a
  /// placeholder that flickers and is replaced. Nor is a failure reported here
  /// — the list below is making its own request against the same server and
  /// will say so far more usefully than two words in a header.
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadCatalogue();
  }

  Future<void> _loadCatalogue() async {
    final response = await widget.api.get('/exports');

    if (!mounted || !response.ok) return;

    final resources = (response.body['resources'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();

    Map<String, dynamic>? mine;

    for (final candidate in resources) {
      if (candidate['key'] == widget.resource) mine = candidate;
    }

    setState(() => _resource = mine);
  }

  Future<void> _export() async {
    final entry = _resource!;
    final label = (entry['label'] as String).toLowerCase();

    setState(() => _busy = true);

    final bytes = await widget.api.getBytes('/exports/${widget.resource}');

    if (!mounted) return;

    setState(() => _busy = false);

    if (bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not export $label. Nothing was changed.')),
      );
      return;
    }

    Files.download('${widget.resource}.csv', bytes);
  }

  @override
  Widget build(BuildContext context) {
    final entry = _resource;

    // Either the answer has not arrived, or this person may not read this
    // resource in bulk. Both are "no controls", and neither is worth a sentence
    // in a header — the second is the same answer the tab itself would give.
    if (entry == null) return const SizedBox.shrink();

    final label = (entry['label'] as String).toLowerCase();
    final importable = entry['importable'] as bool? ?? false;
    final importPermission = entry['import_permission'] as String?;

    // The permission the SERVER named for this import, turned into the same
    // sentence the 403 would carry. Nothing here knows what that key is.
    final refusedImport = importable && importPermission != null
        ? widget.session.me!.refusal(importPermission, 'import $label')
        : null;

    // Already a sentence when it arrives, and null when the control works.
    // Taken as the server sent it rather than rebuilt from a key: this is the
    // text the endpoint itself would answer with, which is the whole reason it
    // is on the wire.
    final refusedExport = entry['export_refusal'] as String?;

    return Wrap(
      key: const ValueKey('data-transfer'),
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (importable)
          Refused(
            reason: refusedImport,
            child: OutlinedButton.icon(
              onPressed: (_busy || refusedImport != null) ? null : _openImport,
              icon: const Icon(Icons.upload_outlined, size: 18),
              label: const Text('Import'),
            ),
          ),
        Refused(
          reason: refusedExport,
          child: OutlinedButton.icon(
            onPressed: (_busy || refusedExport != null) ? null : _export,
            icon: const Icon(Icons.download_outlined, size: 18),
            label: Text(_busy ? 'Exporting…' : 'Export'),
          ),
        ),
      ],
    );
  }

  Future<void> _openImport() async {
    final imported = await showSidePanel<bool>(
      context,
      builder: (_) => ImportPanel(
        api: widget.api,
        session: widget.session,
        resource: _resource!,
      ),
    );

    if (imported == true) widget.onImported?.call();
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
class ImportPanel extends StatefulWidget {
  const ImportPanel({
    super.key,
    required this.api,
    required this.session,
    required this.resource,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> resource;

  @override
  State<ImportPanel> createState() => _ImportPanelState();
}

class _ImportPanelState extends State<ImportPanel> {
  final _csv = TextEditingController();

  String? _franchiseId;
  String? _filename;
  String? _error;
  bool _busy = false;
  Map<String, dynamic>? _preview;

  @override
  void initState() {
    super.initState();
    final franchises = widget.session.me!.franchisesInView;
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

    final response = await widget.api.post(
      '/imports/${widget.resource['key']}',
      {'csv': _csv.text, 'franchise_id': _franchiseId, 'dry_run': dryRun},
    );

    if (!mounted) return;

    setState(() {
      _busy = false;

      if (!response.ok) {
        // A whole-file problem — wrong columns, too big — arrives as a
        // validation error on `file`, which is more useful than the generic
        // message beside it.
        final errors = response.body['errors'] as Map<String, dynamic>?;
        _error =
            (errors?['file'] as List<dynamic>?)?.first as String? ??
            response.message;
        _preview = null;
        return;
      }

      _preview = response.body;
    });

    if (!dryRun && response.ok && mounted) {
      Navigator.of(context).pop(true);

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Imported ${response.body['imported']} '
            '${(widget.resource['label'] as String).toLowerCase()}.',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final columns = (widget.resource['import_columns'] as List<dynamic>).join(
      ', ',
    );
    final franchises = widget.session.me!.franchisesInView;

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
          child: Text(
            _busy ? 'Working…' : 'Import ${_preview?['imported'] ?? ''}'.trim(),
          ),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Columns: $columns',
            style: Theme.of(context).textTheme.bodySmall,
          ),
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
            FranchiseField(
              me: widget.session.me!,
              value: _franchiseId,
              franchises: franchises,
              label: 'Accountable franchise',
              helperText: 'Every imported record is owned by this one.',
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
                  child: Text(
                    _filename!,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
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
    final errors = (preview['errors'] as List<dynamic>)
        .cast<Map<String, dynamic>>();

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
          Text(
            '…and ${errors.length - 20} more.',
            style: Theme.of(context).textTheme.bodySmall,
          ),

        if (preview['truncated'] == true)
          Text(
            'Only the first ${errors.length} problems are listed.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
      ],
    );
  }
}
