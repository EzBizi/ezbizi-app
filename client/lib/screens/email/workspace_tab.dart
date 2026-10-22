import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// Email › Google Workspace (UI queue 134, options 1 and 4).
///
/// Jose, 2026-10-22: *"a new tab in email, called Google Workspace … they
/// would appear as cards which you can click on to set their settings
/// individually like for example being able to set Signature, either per
/// person or in bulk, using information from EzBizi if needed, by being able
/// to link an email to a user as well."*
///
/// One card per connected Workspace; with only one, it opens straight away.
/// Inside: the server's reason when signatures cannot be set yet, the
/// signature template with placeholder chips and a live preview, the people
/// with who each is in EzBizi, a bulk bar, and a person pane beside the list.
/// On a phone the people are cards (long-press selects) and the template and
/// a person open full screen in place, with ← back.
///
/// Every edit control needs `email.edit`; without it they are not drawn.
class WorkspaceTab extends StatefulWidget {
  const WorkspaceTab({
    super.key,
    required this.api,
    required this.session,
    this.openUrl = openExternally,
  });

  /// How Google Admin is opened: a new tab on the web, the browser elsewhere.
  /// A seam so a test can see the address without a browser.
  final OpenUrl openUrl;

  final ApiClient api;
  final Session session;

  @override
  State<WorkspaceTab> createState() => _WorkspaceTabState();
}

class _WorkspaceTabState extends State<WorkspaceTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _cards = const [];
  String? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _cards.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/email/workspaces');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _cards = _maps(response.data);
      // One Workspace: there is nothing to choose, so it opens.
      if (_cards.length == 1) _open = '${_cards.single['id']}';
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _body(context),
    );
  }

  Widget _body(BuildContext context) {
    final open = _open;
    if (open != null) {
      final card = _cards.firstWhere(
        (c) => '${c['id']}' == open,
        orElse: () => {'id': open},
      );
      return WorkspaceView(
        key: ValueKey('workspace-view-$open'),
        api: widget.api,
        session: widget.session,
        openUrl: widget.openUrl,
        card: card,
        onBack: _cards.length > 1
            ? () {
                setState(() => _open = null);
                _load();
              }
            : null,
      );
    }

    final theme = Theme.of(context);
    if (_cards.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No Google Workspace is connected yet. Connect one on the '
            'Settings tab.',
            key: const ValueKey('workspace-none'),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Wrap(
              key: const ValueKey('tour-email-workspace-cards'),
              spacing: 12,
              runSpacing: 12,
              children: [
                for (final card in _cards)
                  SizedBox(
                    width: phone ? constraints.maxWidth - 32 : 300,
                    child: _card(context, card),
                  ),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> card) {
    final theme = Theme.of(context);
    final count = card['people_count'];
    return Card(
      key: ValueKey('workspace-card-${card['id']}'),
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => setState(() => _open = '${card['id']}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${card['domain'] ?? ''}',
                style: theme.textTheme.titleMedium,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 2),
              Text(
                '${card['scope_label'] ?? ''}',
                style: theme.textTheme.bodySmall,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 10),
              statusChip(card),
              if (updatePermissions(card, widget.openUrl) case final button?)
                Padding(padding: const EdgeInsets.only(top: 6), child: button),
              const SizedBox(height: 6),
              Text(
                count is int
                    ? '$count ${count == 1 ? 'person' : 'people'}'
                    : 'People not read',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

typedef OpenUrl = Future<void> Function(Uri url);

Future<void> openExternally(Uri url) async {
  await launchUrl(
    url,
    mode: LaunchMode.externalApplication,
    webOnlyWindowName: '_blank',
  );
}

/// "Update permissions": Google Admin's delegation page with every scope
/// filled in, so the admin only clicks Authorize. Drawn whenever signatures
/// cannot be set and the server sent the page.
Widget? updatePermissions(Map<String, dynamic> card, OpenUrl openUrl) {
  final url = '${card['authorize_url'] ?? ''}';
  if (card['signatures_ready'] != false || url.isEmpty) return null;
  final uri = Uri.tryParse(url);
  if (uri == null) return null;
  return OutlinedButton.icon(
    key: ValueKey('workspace-update-permissions-${card['id']}'),
    onPressed: () => openUrl(uri),
    icon: const Icon(Icons.open_in_new, size: 18),
    label: const Text('Update permissions'),
  );
}

/// The one status a Workspace card carries.
Widget statusChip(Map<String, dynamic> card) {
  final Widget tag;
  if (card['signatures_ready'] != true) {
    tag = const Tag('Needs a Google Admin step', warn: true);
  } else if (card['has_template'] != true) {
    tag = const Tag('No template yet');
  } else {
    tag = const Tag('Signatures ready', good: true);
  }
  // Tag pads its own left edge for sitting after a title; here it leads.
  return Transform.translate(offset: const Offset(-8, 0), child: tag);
}

/// One Workspace: its template, its people, and one person beside them.
class WorkspaceView extends StatefulWidget {
  const WorkspaceView({
    super.key,
    required this.api,
    required this.session,
    required this.card,
    this.onBack,
    this.openUrl = openExternally,
  });

  final OpenUrl openUrl;

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> card;
  final VoidCallback? onBack;

  @override
  State<WorkspaceView> createState() => _WorkspaceViewState();
}

class _WorkspaceViewState extends State<WorkspaceView> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _data = const {};

  final _template = TextEditingController();
  String _savedTemplate = '';
  bool _savingTemplate = false;
  String? _templateNote;

  String? _previewFor;
  String? _previewHtml;
  bool _previewLoaded = false;
  Timer? _previewTimer;

  final _search = TextEditingController();
  final Set<String> _selected = {};
  bool _applying = false;
  Map<String, dynamic>? _applied;
  String? _applyError;

  String? _person;
  bool _phoneTemplate = false;

  String get _id => '${widget.card['id']}';
  String get _base => '/email/workspaces/$_id';

  /// Both must say yes: the role's `email.edit`, and the card's own
  /// `may_edit`, which is the server's answer for this Workspace.
  bool get _mayEdit =>
      (widget.session.me?.can('email.edit') ?? false) &&
      ({...widget.card, ..._data}['may_edit'] != false);

  List<Map<String, dynamic>> get _people => _maps(_data['people']);

  List<String> get _placeholders => [
    for (final p in _data['placeholders'] as List? ?? const []) '$p',
  ];

  @override
  void initState() {
    super.initState();
    _template.addListener(_templateChanged);
    _search.addListener(() => setState(() {}));
    _load();
  }

  @override
  void dispose() {
    _previewTimer?.cancel();
    _template.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('$_base/people');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        // 422: the directory could not be read, in the server's sentence.
        _error = response.message;
        return;
      }
      _error = null;
      final first = _data.isEmpty;
      _data = Map<String, dynamic>.from(response.data as Map);
      if (first || _template.text == _savedTemplate) {
        _savedTemplate = '${_data['template'] ?? ''}';
        _template.text = _savedTemplate;
      }
      _previewFor ??= _people
          .where((p) => p['suspended'] != true)
          .map((p) => '${p['address']}')
          .firstOrNull;
    });
    _schedulePreview();
  }

  void _templateChanged() {
    if (_template.text != _savedTemplate) setState(() => _templateNote = null);
    _schedulePreview();
  }

  void _schedulePreview() {
    _previewTimer?.cancel();
    _previewTimer = Timer(const Duration(milliseconds: 400), _runPreview);
  }

  Future<void> _runPreview() async {
    final address = _previewFor;
    final html = _template.text;
    if (address == null || html.trim().isEmpty) {
      if (mounted) {
        setState(() {
          _previewHtml = null;
          _previewLoaded = true;
        });
      }
      return;
    }
    final response = await widget.api.post('$_base/signatures/preview', {
      'address': address,
      'html': html,
    });
    if (!mounted) return;
    setState(() {
      _previewLoaded = true;
      final data = response.ok ? response.data : null;
      _previewHtml = data is Map ? data['html'] as String? : null;
    });
  }

  Future<void> _saveTemplate() async {
    setState(() => _savingTemplate = true);
    final text = _template.text;
    final response = await widget.api.put('$_base/signature-template', {
      'html': text.trim().isEmpty ? null : text,
    });
    if (!mounted) return;
    setState(() {
      _savingTemplate = false;
      if (response.ok) {
        _savedTemplate = text;
        _templateNote = 'Template saved.';
      } else {
        _templateNote = response.reason;
      }
    });
    if (response.ok) _load();
  }

  Future<void> _link(String address, String? userId) async {
    final response = await widget.api.put(
      '$_base/people/${Uri.encodeComponent(address)}/link',
      {'user_id': userId},
    );
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    await _load();
  }

  Future<void> _apply() async {
    setState(() {
      _applying = true;
      _applied = null;
      _applyError = null;
    });
    final addresses = _selected.toList();
    var set = 0;
    final results = <Map<String, dynamic>>[];
    String? error;
    // The server takes 200 at a time.
    for (var i = 0; i < addresses.length; i += 200) {
      final chunk = addresses.sublist(
        i,
        i + 200 > addresses.length ? addresses.length : i + 200,
      );
      final response = await widget.api.post('$_base/signatures/apply', {
        'addresses': chunk,
      });
      if (!response.ok) {
        error = response.reason;
        break;
      }
      final data = response.data as Map? ?? const {};
      set += (data['set'] as num?)?.toInt() ?? 0;
      results.addAll(_maps(data['results']));
    }
    if (!mounted) return;
    setState(() {
      _applying = false;
      _applyError = error;
      if (error == null) {
        _applied = {'set': set, 'results': results};
        _selected.clear();
      }
    });
    if (error == null) _load();
  }

  // ── Layout ────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        final person = _openPerson;

        if (phone && _phoneTemplate) {
          return SidePanel(
            key: const ValueKey('workspace-phone-template'),
            docked: true,
            title: 'Signature template',
            subtitle: '${widget.card['domain'] ?? ''}',
            onBack: () => setState(() => _phoneTemplate = false),
            child: _templateSection(context, narrow: true),
          );
        }

        // Beside the list when there is room for both; in place of it when
        // there is not.
        final inPlace = constraints.maxWidth < 1000;
        if (person != null && (phone || inPlace)) {
          return _personPanel(person, inPlace: true);
        }

        final list = phone ? _phoneBody(context) : _wideBody(context);
        if (person == null) return list;

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: list),
            const VerticalDivider(width: 1),
            SizedBox(width: 420, child: _personPanel(person, inPlace: false)),
          ],
        );
      },
    );
  }

  Map<String, dynamic>? get _openPerson {
    final open = _person;
    if (open == null) return null;
    for (final p in _people) {
      if (p['address'] == open) return p;
    }
    return null;
  }

  Widget _personPanel(Map<String, dynamic> person, {required bool inPlace}) {
    final address = '${person['address']}';
    void close() => setState(() => _person = null);
    return SidePanel(
      key: const ValueKey('workspace-person-pane'),
      docked: true,
      title: '${person['name'] ?? address}',
      subtitle: address,
      onClose: close,
      onBack: inPlace ? close : null,
      child: PersonPane(
        key: ValueKey('workspace-person-$address'),
        api: widget.api,
        base: _base,
        person: person,
        placeholders: _placeholders,
        mayEdit: _mayEdit,
        link: _linkControl(context, person),
        onChanged: _load,
      ),
    );
  }

  Widget _header(BuildContext context) {
    final theme = Theme.of(context);
    final card = {...widget.card, ..._data};
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.onBack != null)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('workspace-back'),
              onPressed: widget.onBack,
              icon: const Icon(Icons.arrow_back),
              label: const Text('All Workspaces'),
            ),
          ),
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 8,
          runSpacing: 4,
          children: [
            Text('${card['domain'] ?? ''}', style: theme.textTheme.titleLarge),
            if (card.containsKey('signatures_ready')) statusChip(card),
          ],
        ),
        if ('${card['scope_label'] ?? ''}'.isNotEmpty)
          Text('${card['scope_label']}', style: theme.textTheme.bodySmall),
        if (card['signatures_ready'] == false &&
            card['signatures_reason'] != null) ...[
          const SizedBox(height: 10),
          _banner(
            context,
            '${card['signatures_reason']}',
            updatePermissions(card, widget.openUrl),
          ),
        ],
      ],
    );
  }

  Widget _banner(BuildContext context, String sentence, Widget? action) {
    final caution = StateColour.of(context).caution;
    return Container(
      key: const ValueKey('workspace-not-ready'),
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: StateColour.of(context).cautionGlow.withValues(alpha: 0.14),
        border: Border.all(color: caution.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.info_outline, color: caution, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(sentence),
                if (action != null) ...[const SizedBox(height: 8), action],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _errorBody(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (widget.onBack != null)
            TextButton.icon(
              key: const ValueKey('workspace-back'),
              onPressed: widget.onBack,
              icon: const Icon(Icons.arrow_back),
              label: const Text('All Workspaces'),
            ),
          Text(
            _error!,
            key: const ValueKey('workspace-people-error'),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 12),
          OutlinedButton(onPressed: _load, child: const Text('Try again')),
        ],
      ),
    ),
  );

  // ── wide (option 1) ───────────────────────────────────────────

  Widget _wideBody(BuildContext context) {
    if (_error != null) return _errorBody(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _header(context),
        const SizedBox(height: 16),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: _templateSection(context, narrow: false),
          ),
        ),
        const SizedBox(height: 16),
        _peopleTools(context),
        const SizedBox(height: 8),
        _table(context),
      ],
    );
  }

  Widget _templateSection(BuildContext context, {required bool narrow}) {
    final theme = Theme.of(context);
    final editor = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Signature template', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Everyone without their own signature gets this. The chips put in '
          'a value filled for each person.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('workspace-template-field'),
          controller: _template,
          readOnly: !_mayEdit,
          minLines: 5,
          maxLines: 12,
          maxLength: _mayEdit ? 10000 : null,
          style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
          decoration: const InputDecoration(
            hintText: '<b>{{name}}</b><br>{{title}} · {{company}}',
            border: OutlineInputBorder(),
          ),
        ),
        if (_mayEdit) ...[
          PlaceholderChips(
            placeholders: _placeholders,
            controller: _template,
            keyPrefix: 'workspace-chip',
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              FilledButton(
                key: const ValueKey('workspace-save-template'),
                onPressed: _savingTemplate || _template.text == _savedTemplate
                    ? null
                    : _saveTemplate,
                child: const Text('Save template'),
              ),
              if (_templateNote != null) ...[
                const SizedBox(width: 12),
                Flexible(
                  child: Text(
                    _templateNote!,
                    key: const ValueKey('workspace-template-note'),
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ],
          ),
        ],
      ],
    );

    final candidates = [
      for (final p in _people)
        if (p['suspended'] != true)
          SearchOption<String>(
            value: '${p['address']}',
            label: '${p['name'] ?? p['address']}',
            detail: '${p['address']}',
          ),
    ];
    final preview = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Preview', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('workspace-preview-person'),
          value: _previewFor,
          options: candidates,
          label: 'Preview for',
          isDense: true,
          onChanged: (address) {
            setState(() => _previewFor = address);
            _schedulePreview();
          },
        ),
        const SizedBox(height: 10),
        Container(
          key: const ValueKey('workspace-template-preview'),
          width: double.infinity,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest.withValues(
              alpha: 0.4,
            ),
            borderRadius: BorderRadius.circular(10),
          ),
          child: _previewHtml == null
              ? Text(
                  !_previewLoaded
                      ? 'Getting the preview…'
                      : _template.text.trim().isEmpty
                      ? 'Write the template to see it here.'
                      : 'Nothing to show for this person.',
                  style: theme.textTheme.bodySmall,
                )
              : SignatureText(_previewHtml!),
        ),
      ],
    );

    return Column(
      key: const ValueKey('tour-email-workspace-template'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (narrow) ...[
          editor,
          const SizedBox(height: 16),
          preview,
        ] else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(flex: 3, child: editor),
              const SizedBox(width: 20),
              Expanded(flex: 2, child: preview),
            ],
          ),
      ],
    );
  }

  List<Map<String, dynamic>> get _shown {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return _people;
    return [
      for (final p in _people)
        if ([
          p['name'],
          p['address'],
          p['title'],
          (p['user'] as Map?)?['name'],
        ].any((v) => '${v ?? ''}'.toLowerCase().contains(q)))
          p,
    ];
  }

  Widget _peopleTools(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_mayEdit && _selected.isNotEmpty)
          Container(
            key: const ValueKey('workspace-bulk-bar'),
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: theme.colorScheme.primaryContainer,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Text(
                    '${_selected.length} selected',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onPrimaryContainer,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                TextButton(
                  onPressed: _applying ? null : () => setState(_selected.clear),
                  child: const Text('Clear'),
                ),
                FilledButton(
                  key: const ValueKey('workspace-bulk-apply'),
                  onPressed: _applying ? null : _apply,
                  child: Text(_applying ? 'Setting…' : 'Set signature'),
                ),
              ],
            ),
          ),
        if (_applyError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _applyError!,
              key: const ValueKey('workspace-apply-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (_applied case final applied?) _appliedBox(context, applied),
        TextField(
          key: const ValueKey('tour-email-workspace-search'),
          controller: _search,
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            hintText: 'Search people',
            isDense: true,
            border: OutlineInputBorder(),
          ),
        ),
      ],
    );
  }

  Widget _appliedBox(BuildContext context, Map<String, dynamic> applied) {
    final theme = Theme.of(context);
    final failed = [
      for (final r in _maps(applied['results']))
        if (r['ok'] != true) r,
    ];
    return Container(
      key: const ValueKey('workspace-apply-result'),
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${applied['set']} set, ${failed.length} failed',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              IconButton(
                tooltip: 'Dismiss',
                onPressed: () => setState(() => _applied = null),
                icon: const Icon(Icons.close, size: 18),
              ),
            ],
          ),
          for (final r in failed)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                '${r['address']}: ${r['message'] ?? 'Not set.'}',
                style: theme.textTheme.bodySmall,
              ),
            ),
        ],
      ),
    );
  }

  static const _flex = [3, 2, 3, 1, 1];

  Widget _table(BuildContext context) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.outline,
      fontWeight: FontWeight.w700,
    );
    final rows = _shown;
    return Card(
      key: const ValueKey('tour-email-workspace-people'),
      margin: EdgeInsets.zero,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
            child: Row(
              children: [
                if (_mayEdit) const SizedBox(width: 40),
                for (final (i, label) in const [
                  'PERSON',
                  'TITLE',
                  'EZBIZI PERSON',
                  'SIGNATURE',
                  'LAST SET',
                ].indexed)
                  Expanded(
                    flex: _flex[i],
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      child: Text(label, style: head),
                    ),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          if (rows.isEmpty)
            Padding(
              padding: const EdgeInsets.all(20),
              child: Text(
                _people.isEmpty
                    ? 'Nobody is on this Workspace.'
                    : 'Nobody matches that search.',
              ),
            ),
          for (final p in rows) _row(context, p),
        ],
      ),
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> p) {
    final theme = Theme.of(context);
    final address = '${p['address']}';
    final suspended = p['suspended'] == true;
    final small = theme.textTheme.bodySmall;

    Widget cell(int i, Widget child) => Expanded(
      flex: _flex[i],
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6),
        child: child,
      ),
    );

    final row = InkWell(
      key: ValueKey('workspace-row-$address'),
      onTap: () => setState(() => _person = address),
      child: Container(
        color: _person == address
            ? theme.colorScheme.primaryContainer.withValues(alpha: 0.4)
            : null,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(
          children: [
            if (_mayEdit)
              SizedBox(
                width: 40,
                child: Checkbox(
                  key: ValueKey('workspace-tick-$address'),
                  value: _selected.contains(address),
                  onChanged: suspended ? null : (_) => _toggle(address),
                ),
              ),
            cell(
              0,
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${p['name'] ?? address}',
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    suspended ? '$address · suspended' : address,
                    overflow: TextOverflow.ellipsis,
                    style: small,
                  ),
                ],
              ),
            ),
            cell(
              1,
              Text(
                '${p['title'] ?? ''}'.isEmpty ? '—' : '${p['title']}',
                overflow: TextOverflow.ellipsis,
                style: small,
              ),
            ),
            cell(
              2,
              suspended
                  ? Text('—', style: small)
                  : _linkControl(context, p, dense: true),
            ),
            cell(3, Text(_signatureWord(p), style: small)),
            cell(
              4,
              Text(
                formatListStamp(
                  p['signature_applied_at'],
                  '—',
                  formats: widget.session.me!.display,
                ),
                style: small,
              ),
            ),
          ],
        ),
      ),
    );

    return Column(
      children: [
        suspended ? Opacity(opacity: 0.5, child: row) : row,
        const Divider(height: 1),
      ],
    );
  }

  void _toggle(String address) => setState(() {
    if (!_selected.remove(address)) _selected.add(address);
  });

  String _signatureWord(Map<String, dynamic> p) {
    if (p['has_own_signature'] == true) return 'Own';
    if (_data['has_template'] == true) return 'Template';
    return 'Not set';
  }

  /// Who this address is in EzBizi, and the way to change it.
  Widget _linkControl(
    BuildContext context,
    Map<String, dynamic> p, {
    bool dense = false,
  }) {
    final address = '${p['address']}';
    final user = p['user'] as Map?;
    final small = Theme.of(context).textTheme.bodySmall;

    if (p['link'] == 'linked' && user != null) {
      return Row(
        children: [
          Flexible(
            child: Text(
              '${user['name']} ✓',
              key: ValueKey('workspace-linked-$address'),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (_mayEdit)
            TextButton(
              key: ValueKey('workspace-unlink-$address'),
              onPressed: () => _link(address, null),
              child: const Text('Unlink'),
            ),
        ],
      );
    }

    if (p['link'] == 'same_email' && user != null) {
      if (!_mayEdit) {
        return Text(
          '${user['name']} (same email)',
          overflow: TextOverflow.ellipsis,
          style: small,
        );
      }
      return Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton(
          key: ValueKey('workspace-same-email-$address'),
          onPressed: () => _link(address, '${user['id']}'),
          child: const Text(
            'Same email · Link',
            overflow: TextOverflow.ellipsis,
          ),
        ),
      );
    }

    if (!_mayEdit) return Text('Not linked', style: small);

    return SearchPickerField<String>(
      key: ValueKey('workspace-link-$address'),
      value: null,
      hint: 'Link a person',
      isDense: true,
      options: [
        for (final c in _maps(_data['candidates']))
          SearchOption<String>(
            value: '${c['id']}',
            label: '${c['name']}',
            detail: c['email'] == null ? null : '${c['email']}',
          ),
      ],
      onChanged: (id) {
        if (id != null) _link(address, id);
      },
    );
  }

  // ── phone (option 4) ──────────────────────────────────────────

  Widget _phoneBody(BuildContext context) {
    if (_error != null) return _errorBody(context);
    final theme = Theme.of(context);
    final rows = _shown;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _header(context),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: ListTile(
            key: const ValueKey('workspace-phone-template-open'),
            title: const Text('Signature template'),
            subtitle: Text(
              _savedTemplate.trim().isEmpty
                  ? 'Not written yet'
                  : 'Everyone without their own gets it',
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => setState(() => _phoneTemplate = true),
          ),
        ),
        const SizedBox(height: 12),
        _peopleTools(context),
        if (_mayEdit && _selected.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'Press and hold a person to select them.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        const SizedBox(height: 8),
        Column(
          key: const ValueKey('tour-email-workspace-people-cards'),
          children: [
            if (rows.isEmpty)
              Padding(
                padding: const EdgeInsets.all(20),
                child: Text(
                  _people.isEmpty
                      ? 'Nobody is on this Workspace.'
                      : 'Nobody matches that search.',
                ),
              ),
            for (final p in rows) _personCard(context, p),
          ],
        ),
      ],
    );
  }

  Widget _personCard(BuildContext context, Map<String, dynamic> p) {
    final theme = Theme.of(context);
    final address = '${p['address']}';
    final suspended = p['suspended'] == true;
    final selected = _selected.contains(address);
    final user = p['user'] as Map?;
    final who = switch (p['link']) {
      'linked' when user != null => 'linked to ${user['name']}',
      'same_email' => 'Same email, Link',
      _ => 'not linked',
    };

    final card = Card(
      key: ValueKey('workspace-card-person-$address'),
      margin: const EdgeInsets.only(bottom: 8),
      color: selected ? theme.colorScheme.primaryContainer : null,
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () {
          if (_selected.isNotEmpty && _mayEdit && !suspended) {
            _toggle(address);
          } else {
            setState(() => _person = address);
          }
        },
        onLongPress: _mayEdit && !suspended ? () => _toggle(address) : null,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              if (selected) ...[
                Icon(Icons.check_circle, color: theme.colorScheme.primary),
                const SizedBox(width: 10),
              ],
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${p['name'] ?? address}',
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    Text(
                      suspended ? '$address · suspended' : address,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${_signatureWord(p)} · $who',
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    );
    return suspended ? Opacity(opacity: 0.5, child: card) : card;
  }
}

/// One person: what Gmail has, what it will be, and where it comes from.
class PersonPane extends StatefulWidget {
  const PersonPane({
    super.key,
    required this.api,
    required this.base,
    required this.person,
    required this.placeholders,
    required this.mayEdit,
    required this.link,
    required this.onChanged,
  });

  final ApiClient api;

  /// `/email/workspaces/{id}`.
  final String base;
  final Map<String, dynamic> person;
  final List<String> placeholders;
  final bool mayEdit;

  /// The EzBizi person control, the list's own.
  final Widget link;
  final Future<void> Function() onChanged;

  @override
  State<PersonPane> createState() => _PersonPaneState();
}

class _PersonPaneState extends State<PersonPane> {
  bool _loading = true;
  String? _current;
  String? _own;
  String? _preview;
  bool _ownChosen = false;
  final _editor = TextEditingController();
  bool _busy = false;
  String? _note;

  String get _address => '${widget.person['address']}';
  String get _path =>
      '${widget.base}/people/${Uri.encodeComponent(_address)}/signature';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _editor.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get(_path);
    if (!mounted) return;
    final data = response.ok ? response.data as Map? ?? const {} : const {};
    setState(() {
      _loading = false;
      _current = data['current'] as String?;
      _own = data['own'] as String?;
      _preview = data['preview'] as String?;
      _ownChosen = _own != null || widget.person['has_own_signature'] == true;
      if (_own != null) _editor.text = _own!;
      if (!response.ok) _note = response.reason;
    });
  }

  Future<void> _useTemplate() async {
    setState(() {
      _ownChosen = false;
      _busy = true;
    });
    final response = await widget.api.put(_path, {'html': null});
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = response.ok ? 'They get the template.' : response.reason;
    });
    if (response.ok) {
      _editor.clear();
      await _load();
      await widget.onChanged();
    }
  }

  Future<void> _saveOwn() async {
    setState(() => _busy = true);
    final text = _editor.text;
    final response = await widget.api.put(_path, {
      'html': text.trim().isEmpty ? null : text,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = response.ok ? 'Their own signature is saved.' : response.reason;
    });
    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
  }

  Future<void> _setNow() async {
    setState(() => _busy = true);
    final response = await widget.api.post('${widget.base}/signatures/apply', {
      'addresses': [_address],
    });
    if (!mounted) return;
    String note;
    if (!response.ok) {
      note = response.reason;
    } else {
      final results = _maps((response.data as Map?)?['results']);
      final mine = results.firstOrNull;
      note = mine == null
          ? 'Nothing was set.'
          : mine['ok'] == true
          ? 'Set in Gmail.'
          : '${mine['message'] ?? 'Not set.'}';
    }
    setState(() {
      _busy = false;
      _note = note;
    });
    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final suspended = widget.person['suspended'] == true;
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    Widget box(String label, String? html, String empty, Key key) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelMedium),
        const SizedBox(height: 4),
        Container(
          key: key,
          width: double.infinity,
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest.withValues(
              alpha: 0.4,
            ),
            borderRadius: BorderRadius.circular(10),
          ),
          child: html == null || html.trim().isEmpty
              ? Text(empty, style: theme.textTheme.bodySmall)
              : SignatureText(html),
        ),
      ],
    );

    final fills = (widget.person['fills'] as Map?)?.cast<String, dynamic>();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('EzBizi person', style: theme.textTheme.labelMedium),
        const SizedBox(height: 4),
        if (suspended)
          Text(
            'This account is suspended in Google.',
            style: theme.textTheme.bodySmall,
          )
        else
          widget.link,
        const SizedBox(height: 16),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: box(
                'In Gmail now',
                _current,
                'Nothing set, or Google could not be read.',
                const ValueKey('workspace-person-current'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: box(
                'Will be',
                _preview,
                'Nothing yet: write the template first.',
                const ValueKey('workspace-person-preview'),
              ),
            ),
          ],
        ),
        if (widget.mayEdit && !suspended) ...[
          const SizedBox(height: 16),
          Wrap(
            key: const ValueKey('workspace-person-choice'),
            spacing: 8,
            runSpacing: 6,
            children: [
              ChoiceChip(
                key: const ValueKey('workspace-use-template'),
                label: const Text('Use the template'),
                selected: !_ownChosen,
                onSelected: _busy
                    ? null
                    : (_) {
                        if (_ownChosen) _useTemplate();
                      },
              ),
              ChoiceChip(
                key: const ValueKey('workspace-give-own'),
                label: const Text('Give them their own'),
                selected: _ownChosen,
                onSelected: _busy
                    ? null
                    : (_) => setState(() => _ownChosen = true),
              ),
            ],
          ),
          if (_ownChosen) ...[
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('workspace-own-field'),
              controller: _editor,
              minLines: 4,
              maxLines: 10,
              maxLength: 10000,
              style: theme.textTheme.bodySmall?.copyWith(
                fontFamily: 'monospace',
              ),
              decoration: const InputDecoration(
                labelText: 'Their own signature',
                border: OutlineInputBorder(),
              ),
            ),
            PlaceholderChips(
              placeholders: widget.placeholders,
              controller: _editor,
              keyPrefix: 'workspace-own-chip',
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton(
                key: const ValueKey('workspace-own-save'),
                onPressed: _busy ? null : _saveOwn,
                child: const Text('Save their own'),
              ),
            ),
          ],
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              key: const ValueKey('workspace-person-set-now'),
              onPressed: _busy ? null : _setNow,
              child: const Text('Set it now'),
            ),
          ),
        ],
        if (_note != null) ...[
          const SizedBox(height: 8),
          Text(
            _note!,
            key: const ValueKey('workspace-person-note'),
            style: theme.textTheme.bodySmall,
          ),
        ],
        if (fills != null && fills.isNotEmpty) ...[
          const SizedBox(height: 18),
          Text('Filled with', style: theme.textTheme.labelMedium),
          const SizedBox(height: 4),
          for (final MapEntry(key: field, value: fill) in fills.entries)
            if (fill is Map)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 96,
                      child: Text(
                        placeholderLabel(field),
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                    Expanded(
                      child: Text(
                        '${fill['value'] ?? ''}'.isEmpty
                            ? '—'
                            : '${fill['value']}',
                      ),
                    ),
                    if (fill['from'] != null)
                      Text(
                        fill['from'] == 'ezbizi'
                            ? 'from EzBizi'
                            : 'from Google',
                        key: ValueKey('workspace-fill-$field'),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.outline,
                        ),
                      ),
                  ],
                ),
              ),
        ],
      ],
    );
  }
}

/// A plain name for a placeholder: `first_name` reads "First name".
String placeholderLabel(String key) {
  final words = key.replaceAll('_', ' ').trim();
  if (words.isEmpty) return key;
  return words[0].toUpperCase() + words.substring(1);
}

/// Chips that put `{{placeholder}}` into [controller] where the cursor is.
class PlaceholderChips extends StatelessWidget {
  const PlaceholderChips({
    super.key,
    required this.placeholders,
    required this.controller,
    required this.keyPrefix,
  });

  final List<String> placeholders;
  final TextEditingController controller;
  final String keyPrefix;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final p in placeholders)
          ActionChip(
            key: ValueKey('$keyPrefix-$p'),
            label: Text('+ ${placeholderLabel(p)}'),
            onPressed: () => insertAtCursor(controller, '{{$p}}'),
          ),
      ],
    );
  }
}

/// Puts [token] where the cursor is, over any selection, and leaves the
/// cursor after it. With no cursor yet, at the end.
void insertAtCursor(TextEditingController controller, String token) {
  final text = controller.text;
  final selection = controller.selection;
  final start = selection.isValid ? selection.start : text.length;
  final end = selection.isValid ? selection.end : text.length;
  controller.value = TextEditingValue(
    text: text.replaceRange(start, end, token),
    selection: TextSelection.collapsed(offset: start + token.length),
  );
}

/// A signature's HTML as readable text: tags become line breaks, bold and
/// italic are kept, everything else is dropped. No web view.
class SignatureText extends StatelessWidget {
  const SignatureText(this.html, {super.key});

  final String html;

  @override
  Widget build(BuildContext context) {
    final base = DefaultTextStyle.of(context).style;
    return Text.rich(
      TextSpan(
        children: [
          for (final run in signatureRuns(html))
            TextSpan(
              text: run.text,
              style: base.copyWith(
                fontWeight: run.bold ? FontWeight.w700 : null,
                fontStyle: run.italic ? FontStyle.italic : null,
              ),
            ),
        ],
      ),
    );
  }
}

/// A piece of a signature in one style.
typedef SignatureRun = ({String text, bool bold, bool italic});

/// [html] as runs of text, for [SignatureText]. Joined, the runs are the
/// plain text (see [signatureAsText]).
List<SignatureRun> signatureRuns(String html) {
  final cleaned = html
      .replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '')
      .replaceAll(
        RegExp(
          r'<(script|style)\b[^>]*>.*?</\1\s*>',
          dotAll: true,
          caseSensitive: false,
        ),
        '',
      );
  const blocks = {
    'p', 'div', 'tr', 'li', 'ul', 'ol', 'table', 'blockquote', //
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  };
  final runs = <SignatureRun>[];
  final out = StringBuffer();
  var bold = 0;
  var italic = 0;

  bool endsWithBreak() {
    final s = out.toString();
    return s.isEmpty || s.endsWith('\n');
  }

  void add(String text) {
    if (text.isEmpty) return;
    runs.add((text: text, bold: bold > 0, italic: italic > 0));
    out.write(text);
  }

  void lineBreak() {
    // At most one empty line in a row.
    if (out.toString().endsWith('\n\n')) return;
    _trimTrailingSpaces(runs, out);
    add('\n');
  }

  for (final m in RegExp(
    r'<(/?)([a-zA-Z][a-zA-Z0-9]*)[^>]*>|([^<]+)|<',
  ).allMatches(cleaned)) {
    final text = m.group(3) ?? (m.group(2) == null ? '<' : null);
    if (text != null) {
      var t = _decodeEntities(text.replaceAll(RegExp(r'\s+'), ' '));
      if (endsWithBreak()) t = t.trimLeft();
      add(t);
      continue;
    }
    final closing = m.group(1) == '/';
    final name = m.group(2)!.toLowerCase();
    if (name == 'br') {
      _trimTrailingSpaces(runs, out);
      add('\n');
    } else if (blocks.contains(name)) {
      if (!endsWithBreak()) lineBreak();
    } else if (name == 'b' || name == 'strong') {
      bold += closing ? (bold > 0 ? -1 : 0) : 1;
    } else if (name == 'i' || name == 'em') {
      italic += closing ? (italic > 0 ? -1 : 0) : 1;
    }
  }

  // No trailing breaks or spaces.
  while (runs.isNotEmpty) {
    final last = runs.last;
    final trimmed = last.text.trimRight();
    if (trimmed.isEmpty) {
      runs.removeLast();
      continue;
    }
    runs[runs.length - 1] = (
      text: trimmed,
      bold: last.bold,
      italic: last.italic,
    );
    break;
  }
  return runs;
}

/// The signature as plain text, line breaks and all.
String signatureAsText(String html) =>
    signatureRuns(html).map((r) => r.text).join();

void _trimTrailingSpaces(List<SignatureRun> runs, StringBuffer out) {
  if (runs.isEmpty) return;
  final last = runs.last;
  final trimmed = last.text.replaceAll(RegExp(r' +$'), '');
  if (trimmed == last.text) return;
  runs[runs.length - 1] = (text: trimmed, bold: last.bold, italic: last.italic);
  final all = out.toString();
  out
    ..clear()
    ..write(all.substring(0, all.length - (last.text.length - trimmed.length)));
}

String _decodeEntities(String s) =>
    s.replaceAllMapped(RegExp(r'&(#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z]+);'), (m) {
      final e = m.group(1)!;
      if (e.startsWith('#x') || e.startsWith('#X')) {
        final code = int.tryParse(e.substring(2), radix: 16);
        return code == null ? m.group(0)! : String.fromCharCode(code);
      }
      if (e.startsWith('#')) {
        final code = int.tryParse(e.substring(1));
        return code == null ? m.group(0)! : String.fromCharCode(code);
      }
      return switch (e.toLowerCase()) {
        'amp' => '&',
        'lt' => '<',
        'gt' => '>',
        'quot' => '"',
        'apos' => "'",
        'nbsp' => ' ',
        'middot' => '·',
        'copy' => '©',
        'ndash' => '–',
        'mdash' => '—',
        _ => m.group(0)!,
      };
    });

List<Map<String, dynamic>> _maps(Object? value) => [
  for (final item in value as List? ?? const [])
    if (item is Map) Map<String, dynamic>.from(item),
];
