import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
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
  ContractVocabulary _vocabulary = ContractVocabulary.none;

  /// Why the designer cannot be opened for writing, when it cannot. Kept apart
  /// from [_error]: a missing catalogue does not make the list of templates
  /// unreadable, and blanking the page over it hides what somebody came to read.
  String? _cannotDesign;

  /// The template being designed, as `GET /hr/contracts/{contract}` returned
  /// it, or [_newTemplate] for one that does not exist yet.
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
          title: 'Contracts',
          note:
              'A contract decides what onboarding asks, who is asked it, and '
              'what merges into the document. Editing one publishes a new '
              'version; the contracts already signed keep the version that '
              'produced them.',
          action: FilledButton.icon(
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
        Expanded(
          child: AsyncBody(
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
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _templates.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _templates[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> template) {
    final id = template['id'] as String?;
    final version = template['version'];

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.fromLTRB(20, 10, 12, 10),
        title: Row(
          children: [
            Flexible(
              child: Text(
                template['name'] as String? ?? '',
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (version != null) Tag('Version $version'),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              if (template['description'] is String &&
                  (template['description'] as String).isNotEmpty)
                template['description'] as String,
              _questionCount(template['field_count']),
            ].where((part) => part.isNotEmpty).join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        trailing: _opening == id
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : TextButton(
                onPressed: id == null ? null : () => _open(id),
                child: Text(_mayEdit ? 'Design' : 'Read'),
              ),
        onTap: id == null ? null : () => _open(id),
      ),
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

    return '$count ${count == 1 ? 'question' : 'questions'} asked today';
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
