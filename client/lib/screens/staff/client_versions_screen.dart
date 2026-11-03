import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// The oldest client build each platform may still talk to.
///
/// ── Why this screen has to exist ─────────────────────────────────
///
/// The server side of this shipped without it, and the result was a control the
/// product owned and could not reach: the only way to raise or lift a minimum
/// was to issue `PUT /api/v1/staff/client-versions/{platform}` by hand with a
/// bearer token. That is the shell-adjacent operation the house rule — the app
/// configures itself — exists to avoid, and it fails in exactly the situation
/// the endpoint was written for. StaffClientVersionsController's own reasoning
/// for being an endpoint rather than an artisan command is that "the person who
/// has to act may be on a phone"; a phone cannot issue that request either.
///
/// So it sits beside Modules, which is the same kind of control: a platform-wide
/// switch owned by EzBizi staff, invisible to customers, that changes what every
/// organization experiences at the same moment. The difference — and the reason
/// this screen is written more carefully than that one — is that this switch can
/// stop a customer's software from starting at all.
///
/// ── Six rows, always ─────────────────────────────────────────────
///
/// Including the four nobody has configured, because those are the interesting
/// half: they are the platforms where a stale build is currently sailing
/// through. A list of only the configured rows would answer "which minimums
/// exist" when the question is "what is the state of each platform".
class ClientVersionsScreen extends StatefulWidget {
  const ClientVersionsScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<ClientVersionsScreen> createState() => _ClientVersionsScreenState();
}

class _ClientVersionsScreenState extends State<ClientVersionsScreen> {
  bool _loading = true;

  /// Which load owns the screen — the same rule as ModulesScreen. A save ends in
  /// a refresh while the controls are still live, so two can be in flight at
  /// once, and whichever answered last would otherwise win regardless of which
  /// was asked for last.
  int _request = 0;

  String? _error;

  /// A refusal from a SAVE, kept on the screen rather than thrown at a snack bar.
  ///
  /// Snack bars are pop-ups by another name: they cover the bottom of a narrow
  /// window, they time out, and they announce the outcome somewhere other than
  /// where the person is looking. The server's messages here explain a rule
  /// ("that is not a version this can compare", "the download link must start
  /// with https://") and are written to be read, not glimpsed.
  String? _saveError;

  List<Map<String, dynamic>> _rows = [];

  String _query = '';

  /// The rows whose visible text contains the search, case-insensitively.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return _rows.where((row) {
      final platform = row['platform'] as String? ?? '';
      final text = [
        platform,
        _label(platform),
        row['minimum_version'],
        row['download_url'],
        row['note'],
        row['changed_by'],
        (row['is_enforced'] as bool? ?? false)
            ? 'Builds older than ${row['minimum_version']} are refused.'
            : 'No minimum set — every build gets through.',
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep. Every save refetches, and
      // tearing six rows down to redraw one is the reload this rule exists to
      // stop.
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/client-versions');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _rows = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        _error = response.message;
      }
    });
  }

  /// Write one platform's whole configuration.
  ///
  /// PUT replaces rather than amends, so every field goes every time — an
  /// omitted download link is a CLEARED download link, which is what makes
  /// "remove it" expressible at all. See StaffClientVersionsController::update.
  Future<void> _save(String platform, Map<String, dynamic> body) async {
    final response = await widget.staff.api.put(
      '/staff/client-versions/$platform',
      body,
    );

    if (!mounted) return;

    if (!response.ok) {
      // `reason` and not `message`: a 422 carries the useful sentence under
      // `errors`, keyed by field, and the top-level one is "The given data was
      // invalid" — which tells somebody who has just mistyped a version nothing
      // they can act on.
      setState(() => _saveError = response.reason);
      return;
    }

    setState(() => _saveError = null);

    _load();
  }

  Future<void> _edit(Map<String, dynamic> row) async {
    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => _EditPanel(row: row),
    );

    if (result == null || !mounted) return;

    await _save(row['platform'] as String, result);
  }

  /// The undo, in one press.
  ///
  /// Deliberately not behind a confirmation. Lifting a minimum is the SAFE
  /// direction — it can only let clients through, never refuse one — and it is
  /// pressed in the one situation where seconds matter: somebody has just
  /// realised the number they typed is stopping customers from working. A
  /// confirmation step here would be a speed bump on the fire exit.
  Future<void> _lift(Map<String, dynamic> row) => _save(
    row['platform'] as String,
    {
      'minimum_version': null,
      'download_url': row['download_url'],
      'note': row['note'],
    },
  );

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Client versions',
          note:
              'The oldest build each platform may still talk to. Raising one '
              'stops every older build on that platform immediately, for every '
              'customer — they are shown a screen telling them to update, and '
              'nothing they have saved is affected.',
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Standard field height, as tall as Leads search (Jose, 2026-10-27).
              SizedBox(
                width: 240,
                child: ListSearch(
                  key: const Key('client-versions-search'),
                  hint: 'Find a platform',
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              IconButton(
                tooltip: 'Refresh',
                onPressed: _load,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
        ),
        if (_saveError != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_saveError!),
          ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _rows.isNotEmpty && _shown.isEmpty
                ? Padding(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    child: Text(
                      'No platforms match "${_query.trim()}".',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _shown.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 6),
                    itemBuilder: (context, i) => _row(context, _shown[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final platform = row['platform'] as String;
    final enforced = row['is_enforced'] as bool? ?? false;
    final minimum = row['minimum_version'] as String?;
    final url = row['download_url'] as String?;
    final note = row['note'] as String?;
    final changedBy = row['changed_by'] as String?;

    // One compact row per platform (UI queue 168).
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Wrap and not Row: at 375px a platform name, a state line and two
            // buttons do not share a line, and a Row would overflow rather than
            // stack — on the screen whose whole job is to be reachable during an
            // incident, possibly from a phone.
            Wrap(
              spacing: 16,
              runSpacing: 12,
              crossAxisAlignment: WrapCrossAlignment.center,
              alignment: WrapAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_label(platform), style: theme.textTheme.titleSmall),
                    const SizedBox(height: 2),
                    Text(
                      // The state in a sentence, not a badge. "0.0.0030" beside
                      // a green dot leaves the reader to work out whether that
                      // is the oldest allowed build or the newest refused one,
                      // and getting that backwards by one build is an outage.
                      enforced
                          ? 'Builds older than $minimum are refused.'
                          : 'No minimum set — every build gets through.',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ],
                ),
                Wrap(
                  spacing: 8,
                  children: [
                    if (enforced)
                      TextButton(
                        onPressed: () => _lift(row),
                        child: const Text('Lift it'),
                      ),
                    OutlinedButton(
                      onPressed: () => _edit(row),
                      child: Text(enforced ? 'Change' : 'Set a minimum'),
                    ),
                  ],
                ),
              ],
            ),
            if (url != null) ...[
              const SizedBox(height: 12),
              Text(
                'Newer build: $url',
                style: theme.textTheme.bodyMedium,
              ),
            ],
            if (enforced && url == null && _takesUrl(row)) ...[
              const SizedBox(height: 12),
              Text(
                'No download address. People on a refused build are told to ask '
                'whoever set EzBizi up for them, which works but is slower than '
                'a link.',
                style: theme.textTheme.bodyMedium,
              ),
            ],
            if (note != null && note.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text('Note to customers: $note', style: theme.textTheme.bodyMedium),
            ],
            if (changedBy != null) ...[
              const SizedBox(height: 12),
              Text(
                'Last changed by $changedBy, '
                '${formatStamp(row['changed_at'], 'at an unrecorded time', formats: DisplayFormats.platform)}.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  bool _takesUrl(Map<String, dynamic> row) =>
      row['takes_download_url'] as bool? ?? true;

  /// The name the platform is known by, not the key it is stored under.
  ///
  /// Read from the server's list rather than hardcoded as a set, so a platform
  /// EzBizi ships next still appears here — under its own key, which is ugly but
  /// honest — instead of being dropped by a lookup that does not know it yet.
  static String _label(String platform) => switch (platform) {
    'web' => 'Web (in a browser)',
    'windows' => 'Windows',
    'macos' => 'macOS',
    'linux' => 'Linux',
    'android' => 'Android',
    'ios' => 'iPhone and iPad',
    _ => platform,
  };
}

/// One platform's whole configuration, in a panel beside the list it changes.
///
/// A StatefulWidget so the controllers are disposed — a panel that builds them
/// in a closure leaks one per opening (the same reason StaffShell's reauth panel
/// is one).
class _EditPanel extends StatefulWidget {
  const _EditPanel({required this.row});

  final Map<String, dynamic> row;

  @override
  State<_EditPanel> createState() => _EditPanelState();
}

class _EditPanelState extends State<_EditPanel> {
  late final _minimum = TextEditingController(
    text: widget.row['minimum_version'] as String? ?? '',
  );
  late final _url = TextEditingController(
    text: widget.row['download_url'] as String? ?? '',
  );
  late final _note = TextEditingController(
    text: widget.row['note'] as String? ?? '',
  );

  @override
  void dispose() {
    _minimum.dispose();
    _url.dispose();
    _note.dispose();
    super.dispose();
  }

  bool get _takesUrl => widget.row['takes_download_url'] as bool? ?? true;

  void _submit() {
    // Every field, every time, including the empty ones — PUT replaces the whole
    // configuration and an omitted field is a cleared field. Sending only what
    // changed would make the result of a save depend on which boxes happened to
    // be touched.
    Navigator.of(context).pop(<String, dynamic>{
      'minimum_version': _blankToNull(_minimum.text),
      'download_url': _takesUrl ? _blankToNull(_url.text) : null,
      'note': _blankToNull(_note.text),
    });
  }

  static String? _blankToNull(String value) {
    final trimmed = value.trim();

    return trimmed.isEmpty ? null : trimmed;
  }

  @override
  Widget build(BuildContext context) {
    final platform = _ClientVersionsScreenState._label(
      widget.row['platform'] as String,
    );

    return SidePanel(
      title: platform,
      subtitle:
          'Anyone running an older build than this is refused and shown a '
          'screen explaining why. Everything here can be undone in one save.',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(onPressed: _submit, child: const Text('Save')),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _minimum,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'Oldest allowed version',
              // The empty case spelled out, because it is the recovery path and
              // "leave it blank" is not obvious from an empty box.
              helperText:
                  'Like 0.0.0030 — numbers separated by dots, the way '
                  'client/VERSION writes them. This build itself is allowed; '
                  'only older ones are refused. Empty means nobody is refused.',
              helperMaxLines: 4,
            ),
          ),
          const SizedBox(height: 20),
          if (_takesUrl)
            TextField(
              controller: _url,
              decoration: const InputDecoration(
                labelText: 'Where the newer build lives',
                helperText:
                    'Must start with https://. Shown to the customer as the '
                    'place to get the update; without it they are told to ask '
                    'whoever set EzBizi up for them.',
                helperMaxLines: 4,
              ),
            )
          else
            Text(
              'A browser has nothing to install — it is served fresh on every '
              'load, so anyone stale is simply told to reload the page. There '
              'is no download address for this one.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          const SizedBox(height: 20),
          TextField(
            controller: _note,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Note to customers',
              helperText:
                  'One short sentence, shown under the refusal — "this update '
                  'fixes invoice totals". Optional.',
              helperMaxLines: 3,
            ),
          ),
        ],
      ),
    );
  }
}
