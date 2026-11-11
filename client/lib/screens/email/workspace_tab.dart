import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'signature_builder.dart';
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
        onPeople: (people) => _remember(open, people),
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
            Column(
              key: const ValueKey('tour-email-workspace-cards'),
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final card in _cards)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _row(context, card, phone: phone),
                  ),
              ],
            ),
          ],
        );
      },
    );
  }

  /// Signature counts per Workspace, from the people read when it was opened.
  /// The list endpoint sends only `people_count`; when it sends
  /// `signatures_set` / `signatures_total` those win.
  final Map<String, (int, int)> _signatureCounts = {};

  (int, int)? _signaturesOf(Map<String, dynamic> card) {
    final set = card['signatures_set'];
    final total = card['signatures_total'];
    if (set is int && total is int) return (set, total);
    return _signatureCounts['${card['id']}'];
  }

  void _remember(String id, List<Map<String, dynamic>> people) {
    final active = people.where((p) => p['suspended'] != true).toList();
    final set = active.where((p) => p['signature_applied_at'] != null).length;
    _signatureCounts[id] = (set, active.length);
  }

  void _openCard(Map<String, dynamic> card) =>
      setState(() => _open = '${card['id']}');

  /// The one next step a Workspace row offers.
  Widget _nextStep(Map<String, dynamic> card) {
    if (card['signatures_ready'] != true) {
      return updatePermissions(card, widget.openUrl) ?? const SizedBox.shrink();
    }
    if (card['has_template'] != true) {
      return OutlinedButton(
        key: ValueKey('workspace-next-${card['id']}'),
        onPressed: () => _openCard(card),
        child: const Text('Write a template'),
      );
    }
    return TextButton(
      key: ValueKey('workspace-next-${card['id']}'),
      onPressed: () => _openCard(card),
      child: const Text('Set signatures ›'),
    );
  }

  Widget _row(
    BuildContext context,
    Map<String, dynamic> card, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final count = card['people_count'];
    final people = count is int
        ? '$count ${count == 1 ? 'person' : 'people'}'
        : 'People not read';
    final counts = _signaturesOf(card);
    final progress = counts == null
        ? null
        : SignatureProgress(
            key: ValueKey('workspace-progress-${card['id']}'),
            set: counts.$1,
            total: counts.$2,
          );
    final name = Row(
      children: [
        Container(
          width: 28,
          height: 28,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: theme.colorScheme.primaryContainer,
          ),
          child: Text(
            'G',
            style: TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 14,
              color: theme.colorScheme.onPrimaryContainer,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${card['domain'] ?? ''}',
                style: theme.textTheme.titleSmall,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              Text(
                phone
                    ? '${card['scope_label'] ?? ''} · $people'
                    : '${card['scope_label'] ?? ''}',
                style: theme.textTheme.bodySmall,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ],
    );

    final Widget body;
    if (phone) {
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          name,
          if (progress != null) ...[const SizedBox(height: 10), progress],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [statusChip(card), _nextStep(card)],
          ),
        ],
      );
    } else {
      body = Row(
        children: [
          Expanded(flex: 4, child: name),
          SizedBox(
            width: 96,
            child: Text(people, style: theme.textTheme.bodySmall),
          ),
          SizedBox(width: 170, child: progress ?? const SizedBox.shrink()),
          const SizedBox(width: 16),
          Expanded(
            flex: 3,
            child: Align(
              alignment: Alignment.centerLeft,
              child: statusChip(card),
            ),
          ),
          Expanded(
            flex: 3,
            child: Align(
              alignment: Alignment.centerRight,
              child: _nextStep(card),
            ),
          ),
        ],
      );
    }

    return Card(
      key: ValueKey('workspace-card-${card['id']}'),
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () => _openCard(card),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: body,
        ),
      ),
    );
  }
}

/// "5 of 7 set" over a bar.
class SignatureProgress extends StatelessWidget {
  const SignatureProgress({super.key, required this.set, required this.total});

  final int set;
  final int total;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final done = total > 0 && set >= total;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text('$set of $total set', style: theme.textTheme.bodySmall),
        const SizedBox(height: 4),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: total == 0 ? 0 : set / total,
            minHeight: 6,
            color: done
                ? StateColour.of(context).on
                : theme.colorScheme.primary,
            backgroundColor: theme.colorScheme.surfaceContainerHighest,
          ),
        ),
      ],
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
    this.onPeople,
    this.openUrl = openExternally,
  });

  final OpenUrl openUrl;

  /// Told the people each time they are read, so the list can show progress.
  final void Function(List<Map<String, dynamic>> people)? onPeople;

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

  /// The template open in the builder: a saved one, `{}` for a new one,
  /// null when the builder is closed.
  Map<String, dynamic>? _editing;
  Map<String, dynamic>? _seed;
  String? _deleting;
  String? _assignTo;

  /// "Given A-1 standard to 2 people": the picker keeps the choice and this
  /// says it took (Jose 11-10: it "reverts back", so it looked like nothing).
  String? _assignNote;

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

  List<Map<String, dynamic>> get _templates => _maps(_data['templates']);

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
    _load();
  }

  @override
  void dispose() {
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
      _data = Map<String, dynamic>.from(response.data as Map);
    });
    if (response.ok) widget.onPeople?.call(_people);
  }

  Future<void> _makeDefault(String? id) async {
    final response = await widget.api.put('$_base/signature-default', {
      'template_id': id,
    });
    if (!mounted) return;
    if (!response.ok) _say(response.reason);
    await _load();
  }

  Future<void> _deleteTemplate(String id) async {
    final response = await widget.api.delete('$_base/signature-templates/$id');
    if (!mounted) return;
    setState(() => _deleting = null);
    if (!response.ok) _say(response.reason);
    await _load();
  }

  /// [template] null puts the selected people back on the default.
  Future<void> _assign(String? template) async {
    final response = await widget.api.put('$_base/signatures/assign', {
      'addresses': _selected.toList(),
      'template_id': template,
    });
    if (!mounted) return;
    if (!response.ok) {
      _say(response.reason);
      return;
    }
    final count = _selected.length;
    final name = template == null
        ? 'the default'
        : '${_templates.firstWhere((t) => '${t['id']}' == template, orElse: () => const {})['name'] ?? 'the template'}';
    setState(
      () => _assignNote =
          'Given $name to $count ${count == 1 ? 'person' : 'people'}. '
          'Set in Gmail sends it.',
    );
    await _load();
  }

  void _say(String text) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));

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
        _assignTo = null;
        _assignNote = null;
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

        if (_editing case final editing?) {
          void close() => setState(() {
            _editing = null;
            _seed = null;
          });
          return SidePanel(
            key: const ValueKey('workspace-signature-builder'),
            docked: true,
            title: editing['name'] == null
                ? 'New signature template'
                : '${editing['name']}',
            subtitle: '${widget.card['domain'] ?? ''}',
            onBack: close,
            onClose: close,
            child: SignatureBuilder(
              api: widget.api,
              base: _base,
              people: _people,
              maxLength: (_data['max_length'] as num?)?.toInt() ?? 10000,
              template: editing['id'] == null ? null : editing,
              seed: _seed,
              onSaved: () async {
                await _load();
                if (mounted) close();
              },
            ),
          );
        }

        if (phone && _phoneTemplate) {
          return SidePanel(
            key: const ValueKey('workspace-phone-template'),
            docked: true,
            title: 'Signature templates',
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
        templates: _templates,
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
    final using = <String, int>{};
    for (final p in _people) {
      final id = (p['using'] as Map?)?['id'];
      if (id != null) using['$id'] = (using['$id'] ?? 0) + 1;
    }
    final templates = _templates;

    return Column(
      key: const ValueKey('tour-email-workspace-template'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Signature templates', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'The default goes to everyone who is not given another. Saving or '
          'assigning changes nothing in Gmail until you set it.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 10),
        if (templates.isEmpty)
          Text(
            'No templates yet.',
            key: const ValueKey('workspace-no-templates'),
            style: theme.textTheme.bodySmall,
          ),
        for (final t in templates)
          _templateRow(context, t, using['${t['id']}'] ?? 0, narrow: narrow),
        if (_mayEdit) ...[
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              FilledButton.icon(
                key: const ValueKey('workspace-new-template'),
                onPressed: () => setState(() => _editing = {}),
                icon: const Icon(Icons.add),
                label: const Text('New template'),
              ),
              OutlinedButton(
                key: const ValueKey('workspace-new-a1-template'),
                onPressed: () => setState(() {
                  _seed = a1StandardDesign();
                  _editing = {};
                }),
                child: const Text('Start from the A-1 standard'),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _templateRow(
    BuildContext context,
    Map<String, dynamic> t,
    int count, {
    required bool narrow,
  }) {
    final theme = Theme.of(context);
    final id = '${t['id']}';
    final isDefault = t['is_default'] == true;
    final confirming = _deleting == id;
    return Container(
      key: ValueKey('workspace-template-$id'),
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: 8,
        runSpacing: 4,
        children: [
          Text(
            '${t['name']}',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          if (isDefault)
            const Chip(
              key: ValueKey('workspace-template-default'),
              label: Text('Default'),
              visualDensity: VisualDensity.compact,
            ),
          Text(
            isDefault
                ? 'everyone else'
                : count == 1
                ? '1 person'
                : '$count people',
            style: theme.textTheme.bodySmall,
          ),
          if (_mayEdit) ...[
            TextButton(
              key: ValueKey('workspace-edit-template-$id'),
              onPressed: () => setState(() => _editing = t),
              child: const Text('Edit'),
            ),
            if (!isDefault)
              TextButton(
                key: ValueKey('workspace-default-template-$id'),
                onPressed: () => _makeDefault(id),
                child: const Text('Make default'),
              ),
            if (confirming) ...[
              TextButton(
                key: ValueKey('workspace-delete-confirm-$id'),
                onPressed: () => _deleteTemplate(id),
                child: Text(
                  'Delete it',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
              TextButton(
                onPressed: () => setState(() => _deleting = null),
                child: const Text('Keep'),
              ),
            ] else
              TextButton(
                key: ValueKey('workspace-delete-template-$id'),
                onPressed: () => setState(() => _deleting = id),
                child: const Text('Delete'),
              ),
          ],
        ],
      ),
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
                  onPressed: _applying
                      ? null
                      : () => setState(() {
                          _selected.clear();
                          _assignTo = null;
                          _assignNote = null;
                        }),
                  child: const Text('Clear'),
                ),
                if (_templates.isNotEmpty)
                  SizedBox(
                    width: 240,
                    child: SearchPickerField<String>(
                      key: const ValueKey('workspace-bulk-assign'),
                      value: _assignTo,
                      hint: 'Give them a template',
                      isDense: true,
                      options: [
                        const SearchOption<String>(
                          value: '',
                          label: 'The default',
                        ),
                        for (final t in _templates)
                          SearchOption<String>(
                            value: '${t['id']}',
                            label: '${t['name']}',
                          ),
                      ],
                      onChanged: (id) {
                        if (id == null) return;
                        setState(() => _assignTo = id);
                        _assign(id.isEmpty ? null : id);
                      },
                    ),
                  ),
                FilledButton(
                  key: const ValueKey('workspace-bulk-apply'),
                  onPressed: _applying ? null : _apply,
                  child: Text(_applying ? 'Setting…' : 'Set in Gmail'),
                ),
                if (_assignNote != null)
                  Text(
                    _assignNote!,
                    key: const ValueKey('workspace-assign-note'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onPrimaryContainer,
                    ),
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
        ListSearch(
          key: const ValueKey('tour-email-workspace-search'),
          controller: _search,
          hint: 'Search people',
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

  /// Which signature, and whether Gmail has it: "A-1 standard · in Gmail".
  String _signatureWord(Map<String, dynamic> p) {
    final using = p['using'] as Map?;
    final which = switch (using?['kind']) {
      'own' => 'Own',
      'template' || 'default' => '${using?['name']}',
      'legacy' => 'Old template',
      _ => 'None',
    };
    final pushed = switch (p['pushed']) {
      'current' => 'in Gmail',
      'changed' => 'changed since set',
      _ => 'not set yet',
    };
    return which == 'None' ? 'None' : '$which · $pushed';
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
            title: const Text('Signature templates'),
            subtitle: Text(
              _templates.isEmpty
                  ? 'None yet'
                  : '${_templates.length} · default: ${_templates.where((t) => t['is_default'] == true).map((t) => t['name']).firstOrNull ?? 'none'}',
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
    this.templates = const [],
    required this.mayEdit,
    required this.link,
    required this.onChanged,
  });

  final ApiClient api;

  /// `/email/workspaces/{id}`.
  final String base;
  final Map<String, dynamic> person;
  final List<String> placeholders;
  final List<Map<String, dynamic>> templates;
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

  /// What a template fills that no directory holds, kept for this person.
  static const _fieldLabels = {
    'title': 'Title',
    'mobile': 'Mobile',
    'office_phone': 'Office phone',
    'extension': 'Extension',
    'address': 'Address',
    'photo_url': 'Photo address',
  };
  late final Map<String, TextEditingController> _fields = {
    for (final key in _fieldLabels.keys)
      key: TextEditingController(
        // A person with nothing set arrives as [] from PHP, not {}.
        text:
            '${switch (widget.person['fields']) {
              final Map f => f[key] ?? '',
              _ => '',
            }}',
      ),
  };

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
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _saveFields() async {
    setState(() => _busy = true);
    final response = await widget.api.put(
      '${widget.base}/people/${Uri.encodeComponent(_address)}/fields',
      {
        'fields': {for (final e in _fields.entries) e.key: e.value.text},
      },
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = response.ok ? 'Their details are saved.' : response.reason;
    });
    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
  }

  Future<void> _uploadPhoto() async {
    final picked = await Files.pickImage();
    if (picked == null || !mounted) return;
    setState(() => _busy = true);
    final response = await widget.api.postFile(
      '${widget.base}/people/${Uri.encodeComponent(_address)}/photo',
      'photo',
      picked.name,
      picked.bytes,
    );
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    final url = data is Map ? data['photo_url'] : null;
    setState(() {
      _busy = false;
      if (url != null) _fields['photo_url']!.text = '$url';
      _note = response.ok ? 'Photo uploaded.' : response.reason;
    });
    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
  }

  Future<void> _giveTemplate(String? id) async {
    setState(() => _busy = true);
    final response = await widget.api.put('${widget.base}/signatures/assign', {
      'addresses': [_address],
      'template_id': id,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = response.ok ? null : response.reason;
    });
    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
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
    final stacked = MediaQuery.sizeOf(context).width < 600;
    final gmailNow = box(
      'In Gmail now',
      _current,
      'Nothing set, or Google could not be read.',
      const ValueKey('workspace-person-current'),
    );
    final willBe = box(
      'Will be',
      _preview,
      'Nothing yet: write the template first.',
      const ValueKey('workspace-person-preview'),
    );

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
        // Side by side, or stacked on a phone, where two halves broke the
        // address mid-word (phone audit 2026-11-04).
        if (stacked) ...[
          gmailNow,
          const SizedBox(height: 10),
          willBe,
        ] else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: gmailNow),
              const SizedBox(width: 10),
              Expanded(child: willBe),
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
                label: const Text('Use a template'),
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
              // Nothing to set until there is something it will be.
              onPressed:
                  _busy || (!_ownChosen && (_preview ?? '').trim().isEmpty)
                  ? null
                  : _setNow,
              child: const Text('Set it now'),
            ),
          ),
          if (widget.templates.isNotEmpty && !_ownChosen) ...[
            const SizedBox(height: 16),
            SearchPickerField<String>(
              key: const ValueKey('workspace-person-template'),
              value: '${widget.person['template_id'] ?? ''}',
              label: 'Template',
              isDense: true,
              options: [
                const SearchOption<String>(value: '', label: 'The default'),
                for (final t in widget.templates)
                  SearchOption<String>(
                    value: '${t['id']}',
                    label: '${t['name']}',
                  ),
              ],
              onChanged: _busy
                  ? null
                  : (id) => _giveTemplate(id == null || id.isEmpty ? null : id),
            ),
          ],
          const SizedBox(height: 16),
          Text('Their details', style: theme.textTheme.labelMedium),
          const SizedBox(height: 2),
          Text(
            'Filled into their signature. Left empty, the line is left out.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 6),
          for (final MapEntry(key: key, value: label) in _fieldLabels.entries)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: TextField(
                key: ValueKey('workspace-field-$key'),
                controller: _fields[key],
                decoration: InputDecoration(labelText: label, isDense: true),
              ),
            ),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              OutlinedButton.icon(
                key: const ValueKey('workspace-photo-upload'),
                onPressed: _busy ? null : _uploadPhoto,
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('Upload photo'),
              ),
              FilledButton(
                key: const ValueKey('workspace-fields-save'),
                onPressed: _busy ? null : _saveFields,
                child: const Text('Save details'),
              ),
            ],
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
