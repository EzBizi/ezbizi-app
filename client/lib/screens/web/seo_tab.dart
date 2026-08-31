import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'hours.dart';

/// The absolute base a copied snippet must carry, and why it cannot be blank.
///
/// `ApiClient.baseUrl` is deliberately EMPTY in the same-origin web build — see
/// api_client.dart, which argues at length that a hostname must never be baked
/// in. That is right for the app talking to itself and exactly wrong for a
/// string somebody pastes into a DIFFERENT website: interpolating an empty base
/// produced `src="/api/v1/web/ezbizi.js"`, which resolves against whatever host
/// the snippet was pasted into. On www.ezbizi.com that is a 404; it only ever
/// worked on app.ezbizi.com, the one host that will never need it.
///
/// So the snippet takes the ORIGIN the app is being served from when no base is
/// configured. Pure and parameterised because the interesting cases — a blank
/// base, a native build with a real base, a test process whose `Uri.base` is a
/// `file:` URL with no origin at all — are worth asserting rather than eyeballing.
String snippetApiBase({required String configured, required Uri pageUrl}) {
  if (configured.isNotEmpty) return configured;

  // `Uri.origin` throws on anything that is not http/https, and a widget test
  // runs with a `file:` base. Falling back to the canonical host is better than
  // handing somebody a relative URL that silently does nothing.
  if (pageUrl.scheme == 'http' || pageUrl.scheme == 'https') {
    return pageUrl.origin;
  }

  return 'https://app.ezbizi.com';
}

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
/// ── List and detail, and what that cost ──────────────────────────
///
/// This was one column of expanding cards, and the comment here argued for it:
/// a company has three or four sites, and a split needs a wide layout, a narrow
/// layout, and a selection two panes can disagree about. Jose asked for the
/// split on 2026-08-28. The argument was about COST rather than correctness, so
/// it is recorded here rather than defended — and the cost was real, so the
/// three things it predicted are each answered rather than hoped away:
///
///   · the wide layout is `_split`, two panes that each scroll inside
///     themselves — the first attempt let the row size to its tallest child
///     and overflowed the viewport by however far the form ran past it;
///   · the narrow layout is `_stacked`, where the rail IS the screen and
///     choosing a site pushes the detail (§6 allows a screen, not a modal);
///   · the panes cannot disagree because there is ONE `_selectedId`, and at
///     narrow width only one of them is on screen at a time.
///
/// The move also broke something the old shape hid: fetching a site's values
/// was tied to EXPANDING its card, so a pane with no expand fetched nothing and
/// every site rendered empty. See `_SiteDetailState.initState`.
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

  /// Which site the detail pane is showing. Null until the first load, and
  /// falls back to the first site rather than an empty pane.
  String? _selectedId;

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

  /// Below this, two panes do not fit and the rail becomes the screen.
  ///
  /// 900 rather than a tidier number because the detail pane needs about 560
  /// for a field row plus its shortcode chip without the chip wrapping, and the
  /// rail needs 280 to show a subdomain without truncating it.
  static const double _splitFrom = 900;

  Map<String, dynamic>? get _selected {
    if (_sites.isEmpty) return null;

    for (final site in _sites) {
      if (site['id'] == _selectedId) return site;
    }

    return _sites.first;
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) => constraints.maxWidth >= _splitFrom
            ? _split()
            : _stacked(context),
      ),
    );
  }

  /// Wide: list left, detail right, one selection shared by both.
  Widget _split() {
    final selected = _selected;

    /*
     * `stretch` rather than `start`: each pane must take the full height and
     * scroll INSIDE itself. With `start` the row was as tall as its tallest
     * child, so a site with eight fields overflowed the viewport by however
     * much the form exceeded it — three pixels in the test fixture, and a lot
     * more with real content.
     */
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          width: 288,
          child: _rail(
            onOpen: (site) async =>
                setState(() => _selectedId = site['id'] as String),
          ),
        ),
        const VerticalDivider(width: 1),
        Expanded(
          child: selected == null
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Text('Add a website to set what it publishes.'),
                  ),
                )
              : SingleChildScrollView(child: _detailFor(selected)),
        ),
      ],
    );
  }

  /// Narrow: the rail IS the screen, and choosing a site pushes the detail.
  ///
  /// §6 allows a new screen and forbids a modal, so this is a push rather than
  /// a sheet. It is also why the two panes never disagree about the selection
  /// at this width — there is only ever one of them on screen.
  Widget _stacked(BuildContext context) {
    return _rail(
      onOpen: (site) async {
        setState(() => _selectedId = site['id'] as String);

        await Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => Scaffold(
              appBar: AppBar(title: Text(site['host'] as String)),
              body: SafeArea(
                child: SingleChildScrollView(
                  child: _detailFor(site),
                ),
              ),
            ),
          ),
        );

        // The detail can rename, remove or reconnect a site, so the list it
        // came back to has to be re-read rather than trusted.
        await _load();
      },
    );
  }

  Widget _rail({required Future<void> Function(Map<String, dynamic>) onOpen}) {
    return ListView(
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
                _selectedId = site['id'] as String;
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
          _SiteTile(
            key: ValueKey('tile-${site['id']}'),
            site: site,
            selected: site['id'] == _selected?['id'],
            onTap: () => onOpen(site),
          ),
      ],
    );
  }

  Widget _detailFor(Map<String, dynamic> site) => _SiteDetail(
        key: ValueKey('detail-${site['id']}'),
        api: widget.api,
        site: site,
        canEdit: _canEdit,
        freshToken: _freshTokens[site['id'] as String],
        onForgetToken: () =>
            setState(() => _freshTokens.remove(site['id'] as String)),
        onToken: (token) =>
            setState(() => _freshTokens[site['id'] as String] = token),
        onChanged: _load,
      );
}

/// One row in the rail: host, whether a plugin has ever read it, and nothing
/// else. The detail pane is where everything about a site lives, so a tile that
/// summarised more would be a second place to keep in step.
class _SiteTile extends StatelessWidget {
  const _SiteTile({
    super.key,
    required this.site,
    required this.selected,
    required this.onTap,
  });

  final Map<String, dynamic> site;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final connected = site['connected_at'] != null;

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Material(
        color: selected
            ? theme.colorScheme.primaryContainer.withValues(alpha: 0.45)
            : Colors.transparent,
        borderRadius: BorderRadius.circular(8),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 6, right: 10),
                  child: Icon(
                    connected ? Icons.circle : Icons.circle_outlined,
                    size: 9,
                    color: connected
                        ? theme.colorScheme.primary
                        : theme.colorScheme.outline,
                  ),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        site['host'] as String,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      Text(
                        connected
                            ? 'Connected'
                            : 'Waiting for the plugin',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.outline,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
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
                  // The form sits in the 288px rail now. Without this a company
                  // name wider than the rail overflows the row instead of
                  // ellipsizing.
                  isExpanded: true,
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

class _SiteDetail extends StatefulWidget {
  const _SiteDetail({
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
  State<_SiteDetail> createState() => _SiteDetailState();
}

class _SiteDetailState extends State<_SiteDetail> {
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

    /*
     * Unconditionally, which it did not used to be: fetching was tied to
     * EXPANDING the card, and when the card became a detail pane that has no
     * expand, the fetch went with it. The pane rendered its heading and no
     * fields at all — every site looked empty. The pane is keyed by site id, so
     * choosing another site remounts this state and fetches for that one.
     */
    _loadValues();
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

    /*
     * `_loading` is cleared in a finally, and that is the important half.
     *
     * It used to be set inside the success path, so ANY throw between the await
     * and that line left the spinner turning for as long as somebody was
     * willing to watch it. The first version of this method threw on exactly
     * that path — see the map decoding below — and the failure a person saw was
     * not an error message but a panel that never opened. An exception here
     * should show as a broken panel, never as a busy one.
     */
    try {
      final data = response.body['data'] as Map<String, dynamic>;
      final values = _asMap(data['values']);

      _fields = (data['fields'] as List<dynamic>? ?? const [])
          .cast<Map<String, dynamic>>();

      for (final field in _fields) {
        final key = field['key'] as String;
        _controllers.putIfAbsent(key, TextEditingController.new).text =
            (values[key] as String?) ?? '';
      }
    } catch (_) {
      _error = 'This site\'s details could not be read.';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// A JSON map that might arrive as a list, because an empty one used to.
  ///
  /// PHP encodes an empty associative array as `[]` rather than `{}`, so this
  /// field was an object on a site with details and an array on a site without.
  /// The server now always sends an object; this stays because a client that
  /// hard-casts a wire type it does not control is one deploy away from the
  /// same deadlock, and an older client will meet the old shape forever.
  Map<String, dynamic> _asMap(Object? raw) =>
      raw is Map<String, dynamic> ? raw : <String, dynamic>{};

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

  /// The browser key is public, so this is not a leak response — it is the
  /// answer to somebody polling it, or to a key that ended up somewhere the
  /// customer would rather it had not.
  ///
  /// Unlike the token there is no show-once ceremony: the new key comes back
  /// in the site payload like any other field, because it was never a secret.
  Future<void> _rotatePublicKey() async {
    final response = await widget.api.post(
      '/web/sites/$_id/public-key',
      {},
    );

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (response.ok) {
        _saved = 'New browser key issued. Paste the snippet again.';
      } else {
        _error = response.message;
      }
    });

    if (response.ok) await widget.onChanged();
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

    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(_host, style: theme.textTheme.titleLarge),
                    Text(
                      _connected
                          ? 'Connected · ${widget.site['company']?['name'] ?? ''}'
                          : 'Waiting for the plugin · ${widget.site['company']?['name'] ?? ''}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.outline,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          if (_loading)
            const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: CircularProgressIndicator(),
              ),
            )
          else ...[
            if (widget.freshToken != null) _tokenBlock(theme),

            /*
             * An unconnected site cannot publish anything, so the instructions
             * for connecting it come FIRST. Once it is connected they drop
             * below the fields: setting up happens once, and editing the
             * details is what somebody opens this screen for every time after.
             */
            if (!_connected) _installGuide(theme),

            for (final field in _fields) _fieldRow(theme, field),

            if (_connected) ...[
              const SizedBox(height: 8),
              _installGuide(theme),
            ],
            _snippetBlock(theme),

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
        ],
      ),
    );
  }

  /// One field: its box, its hint, and its shortcode as a button that copies.
  ///
  /// The shortcode used to sit in the helper text, where it was readable and
  /// not usable — somebody had to select `[ezbizi_phone]` out of a sentence by
  /// hand, and selecting text inside a helper line is fiddly on a desktop and
  /// near impossible on a phone. Making it the control means you click the
  /// thing you just read.
  ///
  /// Hours is the one field that is not a text box; it gets the grid below.
  Widget _fieldRow(ThemeData theme, Map<String, dynamic> field) {
    final key = field['key'] as String;
    final controller = _controllers[key]!;

    final input = key == 'hours'
        ? HoursField(
            controller: controller,
            enabled: widget.canEdit,
            label: field['label'] as String,
            hint: field['hint'] as String,
          )
        : TextField(
            controller: controller,
            enabled: widget.canEdit,
            decoration: InputDecoration(
              labelText: field['label'] as String,
              helperText: field['hint'] as String,
            ),
          );

    final shortcode = _ShortcodeButton(code: field['shortcode'] as String);

    /*
     * Beside the field when there is room, underneath it when there is not.
     * `[ezbizi_company_name]` in a monospace chip is about 190 logical pixels,
     * so on a phone it would either overflow the row or squeeze the input it
     * belongs to down to nothing.
     */
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: LayoutBuilder(
        builder: (context, constraints) => constraints.maxWidth >= 520
            ? Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: input),
                  const SizedBox(width: 12),
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: shortcode,
                  ),
                ],
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  input,
                  const SizedBox(height: 6),
                  shortcode,
                ],
              ),
      ),
    );
  }

  Widget _confirmRow(ThemeData theme) {
    final removing = _confirming == 'remove';
    final rekeying = _confirming == 'rotate-key';

    if (rekeying) {
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
              'Issue a new browser key? Any page still carrying the old one '
              'stops updating — it keeps whatever text is written between the '
              'tags, so nothing goes blank, but it stops following $_host '
              'until you paste the new snippet in.',
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
                  onPressed: _rotatePublicKey,
                  child: const Text('Issue a new browser key'),
                ),
              ],
            ),
          ],
        ),
      );
    }

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
  /// The other way in, for a site that is not WordPress.
  ///
  /// ── Why this block exists at all ──
  ///
  /// The public key shipped in the API and had nowhere to appear, so the only
  /// credential this screen showed was the SECRET token. Asked for the browser
  /// key, the honest answer from anybody looking at this panel was to hand over
  /// the `ezb_` one, because it was the only key on screen. That is not a user
  /// error; a screen that shows one credential and needs another is the error.
  ///
  /// So the two are shown TOGETHER and named for what they are: `ezb_` is the
  /// plugin's, never public; `pk_` is the browser's, printed in page source on
  /// purpose. Seeing them side by side is what makes the difference legible.
  Widget _snippetBlock(ThemeData theme) {
    final key = widget.site['public_key'] as String? ?? '';

    if (key.isEmpty) return const SizedBox.shrink();

    final base = snippetApiBase(
      configured: ApiClient.baseUrl,
      pageUrl: Uri.base,
    );

    final snippet =
        '<script src="$base/api/v1/web/ezbizi.js" '
        'data-key="$key"></script>';

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
          Text('Not WordPress?', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          const Text(
            'Put this one line in the page, then mark any element with '
            'data-ezbizi to fill it:',
          ),
          const SizedBox(height: 8),
          SelectableText(snippet, style: theme.textTheme.bodySmall),
          const SizedBox(height: 8),
          const SelectableText(
            '<span data-ezbizi="phone">555-0100</span>',
            style: TextStyle(fontFamily: 'monospace'),
          ),
          const SizedBox(height: 8),
          const Text(
            'Whatever is already between the tags stays if the value is '
            'blank, so the page never shows an empty gap — and a search '
            'engine still reads a real value.',
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: SelectableText(
                  key,
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontFamily: 'monospace',
                  ),
                ),
              ),
              IconButton(
                tooltip: 'Copy the snippet',
                icon: const Icon(Icons.copy_outlined, size: 18),
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: snippet));

                  if (mounted) setState(() => _saved = 'Snippet copied.');
                },
              ),
              if (widget.canEdit)
                TextButton(
                  onPressed: () =>
                      setState(() => _confirming = 'rotate-key'),
                  child: const Text('New key'),
                ),
            ],
          ),
          Text(
            'This key is safe in public page source — it reads only what this '
            'site already publishes. The plugin token above is not: it starts '
            'ezb_ and belongs on your server only.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

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

/// A shortcode you click rather than select.
///
/// It shows the code itself rather than a copy icon beside a label, because the
/// thing being copied is short, meaningful, and the only reason the control
/// exists. Confirming in place — the code becomes "Copied" for a moment — keeps
/// §6: a snackbar for this would be a notification about the thing you are
/// looking at.
class _ShortcodeButton extends StatefulWidget {
  const _ShortcodeButton({required this.code});

  final String code;

  @override
  State<_ShortcodeButton> createState() => _ShortcodeButtonState();
}

class _ShortcodeButtonState extends State<_ShortcodeButton> {
  bool _copied = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return OutlinedButton.icon(
      onPressed: () async {
        await Clipboard.setData(ClipboardData(text: widget.code));

        if (!mounted) return;

        setState(() => _copied = true);
        await Future<void>.delayed(const Duration(milliseconds: 1200));

        if (mounted) setState(() => _copied = false);
      },
      icon: Icon(_copied ? Icons.check : Icons.copy_outlined, size: 15),
      label: Text(
        _copied ? 'Copied' : widget.code,
        style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
      ),
      style: OutlinedButton.styleFrom(
        foregroundColor:
            _copied ? theme.colorScheme.primary : theme.colorScheme.outline,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
      ),
    );
  }
}
