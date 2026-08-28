import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// SEO — the hosts a company owns, and the business facts each one publishes.
///
/// ── What this screen is actually for ─────────────────────────────
///
/// EzBizi holds the company's phone number, address, licence and hours. The
/// company's own WordPress renders them through shortcodes. So the work here is
/// small and specific: register each host, hand over its token once, and fill
/// in the boxes per host. A subdomain is a separate host with its own boxes,
/// which is how the New Jersey site answers a New Jersey number.
///
/// ── No pop-ups, which shapes every interaction on this screen ────
///
/// §6: a new screen or a right-side panel, never a modal. So adding a website
/// is a form that opens in place at the top of the list; issuing a token and
/// removing a site are confirm rows that open inside the card they act on; and
/// a freshly issued token appears in that same card rather than in a dialog
/// somebody dismisses before copying. Each of those started as a `showDialog`
/// and was wrong — worth writing down, because the dialog version is the one
/// that comes to mind first every time.
///
/// ── Why one column of expanding cards rather than list-and-detail ─
///
/// A company has three or four sites, not three hundred. A master/detail split
/// would need a wide layout, a narrow layout and a navigation stack for a list
/// that fits on screen — and each is a place for the two panes to disagree
/// about which site is selected. Expanding in place has one code path at every
/// width, including 375.
///
/// ── The token is shown once, and the screen says so ──────────────
///
/// The server keeps only a hash, so that block is the only time anybody sees
/// it. Saying "copy it now" beside it is not decoration: the alternative is a
/// support call that ends in "rotate it", which is the same work done twice.
class SeoTab extends StatefulWidget {
  const SeoTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SeoTab> createState() => _SeoTabState();
}

class _SeoTabState extends State<SeoTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sites = [];

  bool _adding = false;

  /// Tokens issued in this session, by site — shown in the card they belong to
  /// until the person dismisses them. Never fetched, because the server cannot
  /// answer for one.
  final Map<String, String> _freshTokens = {};

  bool get _canEdit => widget.session.me!.can('web_management.edit');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _sites.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/web/sites');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _sites = (response.body['data'] as List<dynamic>)
            .cast<Map<String, dynamic>>();
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          PanelHeader(
            title: 'Websites',
            note:
                'Every website and subdomain this company runs. Each one keeps '
                'its own details, so a branch site can answer its own phone '
                'number.',
            action: _canEdit && !_adding
                ? FilledButton.icon(
                    onPressed: () => setState(() => _adding = true),
                    icon: const Icon(Icons.add),
                    label: const Text('Add a website'),
                  )
                : null,
          ),
          const SizedBox(height: 12),
          if (_adding)
            _AddSiteForm(
              api: widget.api,
              session: widget.session,
              onCancel: () => setState(() => _adding = false),
              onAdded: (site, token) async {
                setState(() {
                  _adding = false;
                  _freshTokens[site['id'] as String] = token;
                });
                await _load();
              },
            ),
          if (_sites.isEmpty && !_adding)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 32),
              child: Center(
                child: Text(
                  'No websites yet. Add the first one to get its plugin token.',
                ),
              ),
            ),
          for (final site in _sites)
            _SiteCard(
              key: ValueKey(site['id']),
              api: widget.api,
              site: site,
              canEdit: _canEdit,
              freshToken: _freshTokens[site['id'] as String],
              onForgetToken: () =>
                  setState(() => _freshTokens.remove(site['id'] as String)),
              onToken: (token) =>
                  setState(() => _freshTokens[site['id'] as String] = token),
              onChanged: _load,
            ),
        ],
      ),
    );
  }
}

/// Register a host. Opens in place, above the list it will join.
class _AddSiteForm extends StatefulWidget {
  const _AddSiteForm({
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onAdded,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final Future<void> Function(Map<String, dynamic> site, String token) onAdded;

  @override
  State<_AddSiteForm> createState() => _AddSiteFormState();
}

class _AddSiteFormState extends State<_AddSiteForm> {
  final TextEditingController _host = TextEditingController();
  String? _companyId;
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    final me = widget.session.me!;

    // The view's company, never `companies.first` — reach is not the same
    // question as what is on screen, and the first reachable company is often
    // a sibling of the one being looked at.
    _companyId = me.filingCompanyId ??
        (me.companiesInView.length == 1 ? me.companiesInView.first.id : null);
  }

  @override
  void dispose() {
    _host.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final companyId = _companyId;

    if (companyId == null) {
      setState(() => _error = 'Choose which company owns this website.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.post('/web/sites', {
      'company_id': companyId,
      'host': _host.text,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = firstApiError(response);
      });
      return;
    }

    await widget.onAdded(
      response.body['data'] as Map<String, dynamic>,
      response.body['token'] as String,
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Add a website', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 12),
            if (me.offersCompanyChoice)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: DropdownButtonFormField<String>(
                  initialValue: _companyId,
                  decoration: const InputDecoration(labelText: 'Company'),
                  items: [
                    for (final company in me.companiesInView)
                      DropdownMenuItem(
                        value: company.id,
                        child: Text(company.name),
                      ),
                  ],
                  onChanged: (value) => setState(() => _companyId = value),
                ),
              ),
            TextField(
              controller: _host,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'Website address',
                hintText: 'nj.a-1chimney.com',
                helperText: 'A subdomain is its own website, with its own details.',
              ),
              onSubmitted: (_) => _saving ? null : _save(),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ErrorNote(_error!),
              ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                TextButton(
                  onPressed: _saving ? null : widget.onCancel,
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: _saving ? null : _save,
                  child: const Text('Add'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// A validation message the person can act on, rather than "HTTP 422".
String firstApiError(ApiResponse response) {
  final errors = response.body['errors'];

  if (errors is Map<String, dynamic>) {
    for (final messages in errors.values) {
      if (messages is List && messages.isNotEmpty) {
        return messages.first.toString();
      }
    }
  }

  return response.message;
}

class _SiteCard extends StatefulWidget {
  const _SiteCard({
    super.key,
    required this.api,
    required this.site,
    required this.canEdit,
    required this.freshToken,
    required this.onForgetToken,
    required this.onToken,
    required this.onChanged,
  });

  final ApiClient api;
  final Map<String, dynamic> site;
  final bool canEdit;
  final String? freshToken;
  final VoidCallback onForgetToken;
  final void Function(String token) onToken;
  final Future<void> Function() onChanged;

  @override
  State<_SiteCard> createState() => _SiteCardState();
}

class _SiteCardState extends State<_SiteCard> {
  bool _open = false;
  bool _loading = false;
  String? _error;
  String? _saved;

  /// null, 'rotate' or 'remove' — the inline confirm row standing in for the
  /// dialog §6 forbids.
  String? _confirming;

  bool _downloading = false;

  List<Map<String, dynamic>> _fields = [];
  final Map<String, TextEditingController> _controllers = {};

  String get _id => widget.site['id'] as String;
  String get _host => widget.site['host'] as String;
  bool get _connected => widget.site['connected_at'] != null;

  @override
  void initState() {
    super.initState();

    // A site that was just registered opens showing its token and its install
    // steps, because that is the only moment either is needed.
    _open = widget.freshToken != null;
    if (_open) _loadValues();
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadValues() async {
    setState(() {
      _loading = _fields.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/web/sites/$_id/values');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final data = response.body['data'] as Map<String, dynamic>;
    final values = (data['values'] as Map<String, dynamic>?) ?? {};

    setState(() {
      _loading = false;
      _fields = (data['fields'] as List<dynamic>).cast<Map<String, dynamic>>();

      for (final field in _fields) {
        final key = field['key'] as String;
        _controllers.putIfAbsent(key, TextEditingController.new).text =
            (values[key] as String?) ?? '';
      }
    });
  }

  Future<void> _save() async {
    setState(() {
      _error = null;
      _saved = null;
    });

    final response = await widget.api.put('/web/sites/$_id/values', {
      'values': {
        for (final entry in _controllers.entries) entry.key: entry.value.text,
      },
    });

    if (!mounted) return;

    setState(() {
      if (response.ok) {
        _saved = 'Saved. Websites pick this up within a few minutes.';
      } else {
        _error = firstApiError(response);
      }
    });
  }

  /// The plugin, as a zip WordPress will accept from its own uploader.
  ///
  /// Fetched rather than linked, because the endpoint is authenticated and a
  /// plain `<a href>` carries no token.
  Future<void> _downloadPlugin() async {
    setState(() {
      _downloading = true;
      _error = null;
    });

    final response = await widget.api.getBytesResponse('/web/plugin');

    if (!mounted) return;

    setState(() {
      _downloading = false;
      if (response.bytes == null) {
        _error = response.message ?? 'The plugin could not be downloaded.';
      }
    });

    if (response.bytes case final bytes?) {
      Files.download('ezbizi-wordpress-plugin.zip', bytes);
    }
  }

  Future<void> _rotate() async {
    final response = await widget.api.post('/web/sites/$_id/token', {});

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (!response.ok) _error = response.message;
    });

    if (response.ok) {
      widget.onToken(response.body['token'] as String);
      await widget.onChanged();
    }
  }

  Future<void> _remove() async {
    final response = await widget.api.delete('/web/sites/$_id');

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (!response.ok) _error = response.message;
    });

    if (response.ok) await widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: Icon(
              _connected ? Icons.check_circle_outline : Icons.link_off,
              color: _connected
                  ? theme.colorScheme.primary
                  : theme.colorScheme.outline,
            ),
            title: Text(_host),
            subtitle: Text(
              _connected
                  ? 'Connected · ${widget.site['company']?['name'] ?? ''}'
                  : 'Waiting for the plugin · ${widget.site['company']?['name'] ?? ''}',
            ),
            trailing: Icon(_open ? Icons.expand_less : Icons.expand_more),
            onTap: () {
              setState(() => _open = !_open);
              if (_open && _fields.isEmpty) _loadValues();
            },
          ),
          if (_open) ...[
            const Divider(height: 1),
            Padding(
              padding: const EdgeInsets.all(16),
              child: _loading
                  ? const Center(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: CircularProgressIndicator(),
                      ),
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (widget.freshToken != null) _tokenBlock(theme),
                        if (!_connected) _installGuide(theme),
                        for (final field in _fields)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 12),
                            child: TextField(
                              controller: _controllers[field['key'] as String],
                              enabled: widget.canEdit,
                              decoration: InputDecoration(
                                labelText: field['label'] as String,
                                helperText:
                                    '${field['hint']}  ·  ${field['shortcode']}',
                              ),
                            ),
                          ),
                        if (_error != null) ErrorNote(_error!),
                        if (_saved != null)
                          Text(_saved!, style: theme.textTheme.bodySmall),
                        const SizedBox(height: 8),
                        if (widget.canEdit)
                          if (_confirming != null)
                            _confirmRow(theme)
                          else
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              alignment: WrapAlignment.end,
                              children: [
                                TextButton(
                                  onPressed: () =>
                                      setState(() => _confirming = 'remove'),
                                  child: const Text('Remove website'),
                                ),
                                OutlinedButton(
                                  onPressed: () =>
                                      setState(() => _confirming = 'rotate'),
                                  child: const Text('Issue a new token'),
                                ),
                                FilledButton(
                                  onPressed: _save,
                                  child: const Text('Save'),
                                ),
                              ],
                            ),
                      ],
                    ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _confirmRow(ThemeData theme) {
    final removing = _confirming == 'remove';

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            removing
                ? 'Remove $_host? Its details and its token go with it. The '
                      'plugin on that website keeps showing what it last read '
                      'until somebody removes it there too.'
                : 'Issue a new token? The one installed on $_host stops working '
                      'straight away, and that site shows its last known '
                      'details until somebody pastes the new one in.',
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: () => setState(() => _confirming = null),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: removing ? _remove : _rotate,
                child: Text(removing ? 'Remove' : 'Issue a new token'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// The token, in the card it belongs to, until it is dismissed.
  Widget _tokenBlock(ThemeData theme) {
    final token = widget.freshToken!;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Token for $_host', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          const Text(
            'Copy this now. It is shown once and cannot be looked up again — '
            'if it is lost, issue a new one, which takes a moment and breaks '
            'nothing except the old copy.',
          ),
          const SizedBox(height: 8),
          SelectableText(
            token,
            style: const TextStyle(fontFamily: 'monospace'),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: () =>
                    Clipboard.setData(ClipboardData(text: token)),
                icon: const Icon(Icons.copy, size: 18),
                label: const Text('Copy'),
              ),
              TextButton(
                onPressed: widget.onForgetToken,
                child: const Text('I have copied it'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Shown until the plugin has read once, and not after.
  ///
  /// The steps are the whole of the setup, in the order somebody does them.
  /// They stay while the site is unconnected because that is exactly when they
  /// are needed, and go once it has read, because a permanent install guide
  /// above a working site is noise on every later visit.
  Widget _installGuide(ThemeData theme) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Connect $_host', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          const Text('1. Download the EzBizi plugin, below.'),
          const Text(
            '2. In WordPress, open Plugins › Add New › Upload Plugin, choose '
            'the file, and activate it.',
          ),
          const Text('3. Open Settings › EzBizi.'),
          const Text('4. Paste the token this site was given and save.'),
          const Text('5. Use the shortcodes below anywhere on the site.'),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _downloading ? null : _downloadPlugin,
            icon: const Icon(Icons.download_outlined, size: 18),
            label: Text(_downloading ? 'Preparing…' : 'Download the plugin'),
          ),
          const SizedBox(height: 8),
          Text(
            'Lost the token? Issue a new one below — it takes a moment and '
            'breaks nothing except the old copy.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
