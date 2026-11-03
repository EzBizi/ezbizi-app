import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/contracts.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/tag.dart';
import 'contract_designer.dart';

/// The organization's contract templates (EZBIZI-HR-PLAN.md, "Contracts").
///
/// The keystone of Human Resources: a template defines the fields, and those
/// definitions decide what onboarding asks, who it asks, and what merges into
/// the finished document. The owner's words — *"no 2 contracts require the same
/// stuff"* — are why there is a list here at all rather than one form.
///
/// ── Two faces, one destination ───────────────────────────────────
///
/// The list, and the designer. The designer replaces the list rather than
/// opening beside it, the same way automations_screen swaps in its builder and
/// for the reason that screen sets out: the list behind is not context worth
/// keeping in view while somebody is four decisions deep in a form, and 560px
/// of side panel turns the field list into the column of dropdowns this whole
/// screen exists to avoid.
///
/// ── What it asks the server, and when ────────────────────────────
///
/// The list and the catalogue together, on every load. The catalogue is the
/// vocabulary a template is written in — the kinds of question, who can answer
/// one, the common core — and without it the designer has nothing to build out
/// of, so the two arrive together rather than the second one being fetched at
/// the moment somebody presses a button and finding out then.
class ContractsTab extends StatefulWidget {
  const ContractsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ContractsTab> createState() => _ContractsTabState();
}

class _ContractsTabState extends State<ContractsTab> {
  bool _loading = true;
  String? _error;

  /// Which load owns the screen — the argument customers_screen.dart sets out.
  /// Publishing ends in a refresh and the control that triggered it is not
  /// hidden behind a spinner, so two can be in flight at once.
  int _request = 0;

  List<Map<String, dynamic>> _templates = const [];
  String _query = '';

  /// The tile picked (layout A, Mia 2026-10-02): `written`, `questions` or
  /// `no_cover`; null is every contract. Counted here - the list is every
  /// contract in one answer, not a page of them.
  String? _tile;

  bool _matches(Map<String, dynamic> t) => switch (_tile) {
    'written' => t['has_body'] == true,
    'questions' => t['has_body'] == false,
    'no_cover' => t['has_cover'] == false,
    _ => true,
  };
  ContractVocabulary _vocabulary = ContractVocabulary.none;

  /// Why the designer cannot be opened for writing, when it cannot. Kept apart
  /// from [_error]: a missing catalogue does not make the list of templates
  /// unreadable, and blanking the page over it hides what somebody came to read.
  String? _cannotDesign;

  /// Why the last draft download was refused, in the server's words.
  String? _draftError;

  /// The template being designed, as `GET /hr/contracts/{contract}` returned
  /// it. NULL for one that does not exist yet — there is nothing to fetch and
  /// no sentinel is invented for it; [_creating] is what tells "designing a new
  /// one" apart from "designing nothing".
  Map<String, dynamic>? _designing;

  /// Distinguishes "designing a new one" from "designing nothing", which a
  /// nullable map alone cannot do.
  bool _creating = false;

  /// Opening one takes a request, so the row that was tapped says so rather
  /// than the whole page going blank.
  String? _opening;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  /// §4: the tab is `hr.view`, changing anything is `hr.edit`, and starting a
  /// new one is `hr.create`. Read from the permission list `/me` sends for
  /// exactly this purpose — never inferred from a role name, which is the
  /// short-circuit core/permissions.dart exists to keep out of Dart.
  bool get _mayCreate =>
      widget.session.me?.permissions.contains('hr.create') ?? false;

  bool get _mayEdit =>
      widget.session.me?.permissions.contains('hr.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. The
      // refresh after publishing leaves the rows up.
      _loading = _templates.isEmpty;
      _error = null;
    });

    final answers = await Future.wait([
      widget.api.get('/hr/contracts'),
      widget.api.get('/hr/contracts/catalogue'),
    ]);

    if (!mounted || request != _request) return;

    final templates = answers[0];
    final catalogue = answers[1];

    setState(() {
      _loading = false;

      if (!templates.ok) {
        _error = templates.reason;
        return;
      }

      _templates = List<Map<String, dynamic>>.from(
        (templates.data as List? ?? const []).whereType<Map>().map(
          Map<String, dynamic>.from,
        ),
      );

      _vocabulary = catalogue.ok && catalogue.data is Map
          ? ContractVocabulary.fromJson(
              Map<String, dynamic>.from(catalogue.data as Map),
            )
          : ContractVocabulary.none;

      _cannotDesign = _whyNot(catalogue);
    });
  }

  /// Why nothing here can be designed, or null when it can.
  String? _whyNot(ApiResponse catalogue) {
    // The ordinary answer for somebody whose role opens this tab and does not
    // write to it. Not a failure, and it must not be dressed as one.
    if (!_mayCreate && !_mayEdit) {
      return 'You can read what the contracts ask, but not change them.';
    }

    if (!catalogue.ok) {
      return 'The kinds of question a contract can ask could not be loaded, so '
          'nothing can be designed right now: ${catalogue.reason}';
    }

    if (!_vocabulary.isUsable) {
      return 'The server did not say what kinds of question a contract can ask, '
          'so there is nothing to build one out of.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_designing != null || _creating) {
      return ContractDesigner(
        api: widget.api,
        vocabulary: _vocabulary,
        formats: _formats,
        canEdit: _creating ? _mayCreate : _mayEdit,
        template: _designing,
        onSaved: () {
          _close();
          _load();
        },
        onCancel: _close,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          note:
              'A contract decides what onboarding asks, who is asked it, and '
              'what merges into the document. Editing one publishes a new '
              'version; the contracts already signed keep the version that '
              'produced them.',
          action: FilledButton.icon(
            key: const ValueKey('tour-hr-contracts-new'),
            onPressed: _mayCreate && _cannotDesign == null ? _create : null,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New contract'),
          ),
        ),
        if (_cannotDesign != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_cannotDesign!),
          ),
        if (_draftError != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_draftError!),
          ),
        Expanded(
          child: AsyncBody(
            key: const ValueKey('tour-hr-contracts-list'),
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _templates.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 460),
                        child: const Text(
                          'No contracts yet.\n\n'
                          'Onboarding asks whatever the contract says, so this '
                          'is where you decide what that is — the questions, '
                          'which side answers each one, and which of the '
                          'answers are sensitive.',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  )
                : ListView(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    children: [
                      _tiles(),
                      const SizedBox(height: 12),
                      _searchField(),
                      const SizedBox(height: 12),
                      _table(context),
                    ],
                  ),
          ),
        ),
      ],
    );
  }

  Widget _tiles() {
    int count(bool Function(Map<String, dynamic>) test) =>
        _templates.where(test).length;

    return FilterTiles(
      key: const ValueKey('tour-hr-contracts-tiles'),
      prefix: 'contracts',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        FilterTile(
          key: null,
          label: 'All',
          count: _templates.length,
          note: 'contracts',
        ),
        FilterTile(
          key: 'written',
          label: 'Wording written',
          count: count((t) => t['has_body'] == true),
          note: 'produce an agreement',
        ),
        FilterTile(
          key: 'questions',
          label: 'Questions only',
          count: count((t) => t['has_body'] == false),
          note: 'no wording yet',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'no_cover',
          label: 'No cover page',
          count: count((t) => t['has_cover'] == false),
          note: 'open straight on the text',
        ),
      ],
    );
  }

  /// The list's search box (Jose, 2026-10-14: "we lack searchbars on the 3
  /// last human resources tabs"), as Records has one.
  Widget _searchField() => Align(
    alignment: Alignment.centerLeft,
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 320),
      child: ListSearch(
        key: const ValueKey('tour-hr-contracts-search'),
        hint: 'Search contracts',
        onChanged: (v) => setState(() => _query = v),
      ),
    ),
  );

  Widget _table(BuildContext context) {
    final query = _query.trim().toLowerCase();
    final shown = _templates
        .where(_matches)
        .where(
          (t) =>
              query.isEmpty ||
              '${t['name'] ?? ''} ${t['description'] ?? ''}'
                  .toLowerCase()
                  .contains(query),
        )
        .toList();
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    if (shown.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Text('No contracts like that.', textAlign: TextAlign.center),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Contract', flex: 6),
        ListColumn('Version', flex: 1),
        ListColumn('Questions', flex: 2),
        ListColumn('Wording', flex: 2),
        ListColumn('Cover', flex: 1),
        ListColumn('', flex: 4, end: true),
      ],
      rowKey: (i) => ValueKey('contract-${shown[i]['id']}'),
      onTap: (i) {
        final id = shown[i]['id'] as String?;
        if (id != null) _open(id);
      },
      rows: [
        for (final template in shown)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  template['name'] as String? ?? '',
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                if (template['description'] is String &&
                    (template['description'] as String).isNotEmpty)
                  Text(
                    template['description'] as String,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: small,
                  ),
              ],
            ),
            Text(
              template['version'] == null ? '—' : 'v${template['version']}',
            ),
            Text(_questionCount(template['field_count'])),
            // Written, or still only questions: a template with no wording
            // produces a dump of answers, not an agreement. Absent is a server
            // that does not say, so nothing is claimed either way.
            switch (template['has_body']) {
              true => const Tag('Written', good: true),
              false => const Tag('Questions only', warn: true),
              _ => const SizedBox.shrink(),
            },
            Text(switch (template['has_cover']) {
              true => 'Yes',
              false => 'No',
              _ => '—',
            }, style: small),
            _opening == template['id']
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Wrap(
                    alignment: WrapAlignment.end,
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      Tooltip(
                        message: 'A copy marked DRAFT, nothing is signed',
                        child: OutlinedButton.icon(
                          key: ValueKey('contract-draft-${template['id']}'),
                          onPressed: template['id'] == null
                              ? null
                              : () => _draft(template),
                          icon: const Icon(Icons.download_outlined, size: 18),
                          label: const Text('Download draft'),
                        ),
                      ),
                      TextButton(
                        onPressed: template['id'] == null
                            ? null
                            : () => _open(template['id'] as String),
                        child: Text(_mayEdit ? 'Design' : 'Read'),
                      ),
                    ],
                  ),
          ],
      ],
    );
  }

  /// "9 questions asked today".
  ///
  /// The server counts the LIVE fields, not every field the version carries,
  /// and the wording says so: a template that has been tidied up over two years
  /// holds retired questions nobody is asked, and a bare "12 questions" would
  /// overstate every one of them. The retired ones are visible where they can
  /// be acted on, which is inside the designer.
  String _questionCount(Object? fieldCount) {
    final count = (fieldCount as num?)?.toInt();

    if (count == null) return '';

    return '$count asked today';
  }

  /// The current version with sample values, stamped DRAFT on every page
  /// (`GET /hr/contracts/{id}/draft.pdf`). Gated like preview; a refusal shows
  /// the server's own sentence beside the list.
  Future<void> _draft(Map<String, dynamic> template) async {
    setState(() => _draftError = null);
    final response = await widget.api.getBytesResponse(
      '/hr/contracts/${template['id']}/draft.pdf',
    );
    if (!mounted) return;
    final bytes = response.bytes;
    if (!response.ok || bytes == null) {
      setState(
        () => _draftError = response.message ?? 'The draft could not be made.',
      );
      return;
    }
    Files.download(draftFilename(template['name'] as String?), bytes);
  }

  void _create() => setState(() {
    _creating = true;
    _designing = null;
  });

  void _close() => setState(() {
    _creating = false;
    _designing = null;
  });

  /// The list row carries counts; the designer needs the field list itself, and
  /// that only comes from the single-template endpoint. Fetched on the way in
  /// rather than shipped with every row, so opening the tab does not pull down
  /// every question of every contract to draw six names.
  Future<void> _open(String id) async {
    setState(() => _opening = id);

    final response = await widget.api.get('/hr/contracts/$id');

    if (!mounted) return;

    setState(() {
      _opening = null;

      if (!response.ok) {
        // Beside the list rather than over it: the rest of the page is fine,
        // and one row failing to open is not a reason to lose the others.
        _cannotDesign = 'That contract could not be opened: ${response.reason}';
        return;
      }

      _designing = Map<String, dynamic>.from(response.data as Map);
      _creating = false;
    });
  }
}

/// `<name>-draft.pdf`, the name a draft copy is saved under.
String draftFilename(String? name) {
  final base = (name ?? '').trim();
  return '${base.isEmpty ? 'Contract' : base}-draft.pdf';
}
