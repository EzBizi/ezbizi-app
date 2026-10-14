import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/logo_badge.dart';
import '../../widgets/numbered_step.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import '../../widgets/tag.dart';
import 'contract_body_editor.dart';
import 'contract_cover_panel.dart';
import 'contract_diff_panel.dart';
import 'contract_form_preview.dart';

/// Designing one contract template — the screen that decides what onboarding
/// asks (EZBIZI-HR-PLAN.md, "the contract template IS the schema").
///
/// ── Why it is a page and not a panel ─────────────────────────────
///
/// The same argument automation_builder.dart makes, and it applies harder here.
/// There is no list behind this worth keeping in view, and the form is five
/// decisions deep: what the contract is called, what everybody is asked anyway,
/// what this one adds, what each side ends up seeing, and whether to publish it.
/// Squeezed into 560px the field list becomes a column of dropdowns, which is
/// exactly the shape this screen has to avoid.
///
/// So it is the whole width, and the decisions are numbered in the order
/// somebody makes them. The one thing that IS a panel is publishing, because
/// that is a confirmation about a body of work rather than part of doing it.
///
/// ── How `filled_by` is made obvious ──────────────────────────────
///
/// It is the property that decides who sees what and the one a person will get
/// wrong, so it is answered twice on this screen and neither is a dropdown.
///
///   - On the question itself, as three chips with all three answers visible
///     and the consequence of the chosen one written underneath in the server's
///     own words. A dropdown hides two thirds of a three-way decision behind a
///     tap, and hides the consequence entirely.
///   - In step 4, by DRAWING THE TWO FORMS. A question in the wrong list is
///     something you see; a wrong value in a dropdown is something you have to
///     read. That is the whole reason the preview is on this screen rather than
///     somewhere you go and look.
///
/// ── What this screen refuses to work out for itself ──────────────
///
/// The kinds of question, the words for them, who a question can be filled by,
/// and the common core all arrive from `GET /hr/contracts/catalogue`. The diff
/// shown before publishing is the SERVER previewing its own publish. Neither is
/// re-derived here: a second implementation of a rule is a rule that is right
/// until the day the two disagree, and the automation builder has the scars.
///
/// Two of the server's rules are made UNREACHABLE here rather than re-checked:
/// a published question's key and kind are drawn read-only, and the only way to
/// take one off the form is Retire. Both are still enforced server-side, and
/// this end offers no control that could break them — which is different from
/// checking them twice.
///
/// The rest are the server's alone and its refusals are shown verbatim. A
/// signature that may not be marked sensitive is the clearest example: it is a
/// legal judgement about what a signature has to remain, the server states it in
/// a paragraph, and a copy of the rule in Dart would be a second answer to a
/// question that must only have one. So this screen blocks a save when the FORM
/// is unfinished, and never when it merely disagrees with a policy.
///
/// ── The contract itself is the LAST step ─────────────────────────
///
/// The questions used to be the whole of it, and the document was generated
/// from them. The owner's words: *"we are missing something critical which is
/// the contract writing itself, you have the shortcodes but nowhere to put
/// them."* A version now carries a `body` — the actual wording, with the field
/// keys as merge tags in it — and [ContractWriting] is where it is written,
/// beside a live rendering of the page it makes ([ContractPagePane], down the
/// other half of the tab).
///
/// It is last for a layout reason that turned out to be a design reason. It was
/// fourth with a fixed-height surface, which is the wrong shape for the one
/// field on this screen that holds a five-page document; and letting it grow
/// where it sat would have pushed the steps beneath it further down with every
/// paragraph typed. Last, it can be as long as the contract is.
///
/// The order still reads as the order the decisions happen in: a tag cannot be
/// inserted before the question it names exists, what each side is ASKED is a
/// different question from what the document SAYS, and the wording is written
/// once both are settled.
class ContractDesigner extends StatefulWidget {
  const ContractDesigner({
    super.key,
    required this.api,
    required this.vocabulary,
    required this.formats,
    required this.canEdit,
    required this.template,
    required this.onSaved,
    required this.onCancel,
  });

  final ApiClient api;
  final ContractVocabulary vocabulary;
  final DisplayFormats formats;

  /// Whether this person may write. False draws the whole thing read-only
  /// rather than hiding it: somebody who can see the Contracts tab and not
  /// change it still needs to be able to read what their onboarding asks.
  final bool canEdit;

  /// `GET /hr/contracts/{contract}`, or null to design a new one.
  final Map<String, dynamic>? template;

  final VoidCallback onSaved;
  final VoidCallback onCancel;

  @override
  State<ContractDesigner> createState() => _ContractDesignerState();
}

/// One question being edited.
///
/// It owns its controllers rather than being redrawn from a map of strings, for
/// the reason automation_builder's `_ConditionRow` documents: a
/// `TextFormField(initialValue:)` inside a list keeps the FIELD's state where it
/// sits in the list, so deleting the first row leaves the second wearing the
/// first one's text while the payload carries the second one's.
class _Question {
  _Question({
    required this.vocabulary,
    required this.published,
    String key = '',
    String label = '',
    this.type,
    this.filledBy,
    List<String> options = const [],
    this.isRequired = false,
    this.sensitive = false,
    this.isExpiry = false,
    this.retired = false,
    this.shownWhenKey,
    this.shownWhenOption,
  }) : key = TextEditingController(text: key),
       label = TextEditingController(text: label),
       options = TextEditingController(text: options.join('\n')),
       // A key somebody typed is a key they own. A key derived from the label
       // keeps following it, which is what makes the box worth not showing at
       // all until somebody asks for it.
       keyIsMine = key.isNotEmpty && !published;

  final TextEditingController key;
  final TextEditingController label;
  final TextEditingController options;

  /// The server's field vocabulary, held so this row can answer whether its own
  /// kind of question takes a list of answers. Asking the catalogue rather than
  /// comparing [type] to a string is the whole point — see
  /// [ContractVocabulary.typeTakesOptions].
  final ContractVocabulary vocabulary;

  final Key rowKey = UniqueKey();

  String? type;
  String? filledBy;
  bool isRequired;
  bool sensitive;
  bool isExpiry;
  bool retired;
  String? shownWhenKey;
  String? shownWhenOption;

  /// This question exists in the version that is live, so its key and its kind
  /// are fixed — answers have been filed under both — and it can be retired but
  /// never removed.
  final bool published;

  /// Whether the key has been taken over by hand, so it stops tracking the
  /// label.
  bool keyIsMine;

  /// Whether the key box is on screen. Off by default: the key is derived from
  /// the wording, and a box nobody needed was one more thing to get wrong.
  bool keyShown = false;

  /// One answer per line, blanks dropped.
  ///
  /// Lines rather than commas — which is what the automation builder uses for
  /// its list — because a contract option is a phrase and phrases contain
  /// commas. "Full-time, permanent" is one answer, and splitting it would have
  /// silently offered two.
  List<String> get parsedOptions => [
    for (final line in options.text.split('\n'))
      if (line.trim().isNotEmpty) line.trim(),
  ];

  /// Whether this question's kind of answer carries a list, per the catalogue.
  bool get takesOptions => vocabulary.typeTakesOptions(type);

  /// What actually goes on the wire, which is not always what was typed.
  ///
  /// Type a few answers, then change the kind of question: the options box goes
  /// off screen and the text stays in its controller. It was still sent, so the
  /// server refused the save with "remove the options" — about options the
  /// person could no longer see, on a field they had already stopped treating as
  /// a list.
  ///
  /// The text is deliberately KEPT rather than cleared, because changing the
  /// kind back should not have quietly thrown away what was typed. It is simply
  /// not sent while nothing would read it.
  List<String> get sentOptions => takesOptions ? parsedOptions : const [];

  Map<String, dynamic> toJson() => {
    'key': key.text.trim(),
    'label': label.text.trim(),
    'type': type,
    'options': sentOptions,
    'filled_by': filledBy,
    'required': isRequired,
    'sensitive': sensitive,
    'is_expiry': isExpiry,
    'retired': retired,
    if (shownWhenKey != null &&
        shownWhenKey!.isNotEmpty &&
        shownWhenOption != null &&
        shownWhenOption!.isNotEmpty)
      'shown_when': {'key': shownWhenKey, 'is': shownWhenOption},
  };

  ContractField toField() => ContractField(
    key: key.text.trim(),
    label: label.text.trim(),
    type: type ?? '',
    // The preview draws what the server would be given, or it would show a
    // picker on a field that is about to be saved as a line of text.
    options: sentOptions,
    filledBy: filledBy ?? '',
    required: isRequired,
    sensitive: sensitive,
    isExpiry: isExpiry,
    retired: retired,
    shownWhen: shownWhenKey != null && shownWhenOption != null
        ? FieldShownWhen(key: shownWhenKey!, option: shownWhenOption!)
        : null,
  );

  void dispose() {
    key.dispose();
    label.dispose();
    options.dispose();
  }
}

class _ContractDesignerState extends State<ContractDesigner> {
  final _name = TextEditingController();
  final _description = TextEditingController();

  /// The wording of the contract itself.
  ///
  /// Held here rather than inside the editor widget because it is part of what
  /// gets saved: the create request carries it, the publish preview carries it,
  /// and the publish carries it. The editor draws it and inserts into it.
  final _body = TextEditingController();

  final List<_Question> _questions = [];

  /// What the template was called when this screen opened, so a rename can be
  /// named on the publish panel and sent as its own PATCH.
  String _originalName = '';
  String _originalDescription = '';

  /// `GET /hr/contracts/{contract}/versions`, fetched once when editing.
  List<Map<String, dynamic>> _history = const [];

  /// The front page of the document, as it stands on screen.
  ///
  /// Held here rather than inside the panel for the same reason the wording is:
  /// it is part of what gets saved. The create request carries it, the publish
  /// preview carries it so the server can diff it, and the publish carries it.
  ///
  /// [ContractCover.none] rather than null — a contract with no cover is the
  /// ordinary case and it is an answer somebody gave, not a missing one.
  ContractCover _cover = ContractCover.none;

  /// What the cover was when this screen opened.
  ///
  /// Kept for the same job [_originalName] does: so that a change can be named
  /// rather than guessed at. It is deliberately NOT used to decide whether
  /// there is a version to publish — that is the server's answer, previewed
  /// through the same code path that writes it, and a second opinion computed
  /// here is exactly the divergence the diff panel exists to avoid.
  ContractCover _originalCover = ContractCover.none;

  bool _busy = false;
  String? _error;

  /// The document down the right, and the state behind it.
  late final ContractPageController _page;

  /// Below this there is not room for a form and a page side by side — see
  /// [build].
  static const double _splitAbove = 1040;

  /// Which half is on screen when there is only room for one.
  bool _reading = false;

  bool get _isEdit => widget.template != null;
  String? get _id => widget.template?['id'] as String?;

  @override
  void initState() {
    super.initState();

    _name.addListener(_changed);
    _description.addListener(_changed);

    final template = widget.template;

    if (template != null) {
      _name.text = template['name'] as String? ?? '';
      _description.text = template['description'] as String? ?? '';
      _body.text = _bodyOf(template);
      _cover = ContractCover.fromJson(template['cover']);
      _originalName = _name.text;
      _originalDescription = _description.text;
      _originalCover = _cover;

      for (final field in ContractField.listFrom(template['fields'])) {
        _adopt(field);
      }

      _loadHistory();
    }

    // After the body has been filled in from the template, so the first render
    // draws the contract as it stands rather than an empty page it then has to
    // correct a frame later.
    _page = ContractPageController(
      api: widget.api,
      vocabulary: widget.vocabulary,
      body: _body,
      contractId: _id,
      payload: () => _payload,
      resolvable: template == null ? const [] : _resolvableOf(template),
    );
  }

  @override
  void dispose() {
    // Before the body it listens to.
    _page.dispose();

    _name.dispose();
    _description.dispose();
    _body.dispose();

    for (final question in _questions) {
      question.dispose();
    }

    super.dispose();
  }

  /// The wording, out of `GET /hr/contracts/{contract}`.
  ///
  /// `body`, at the top of the detail payload, beside the field list that is
  /// also the current version's. One place, because the server has one: nothing
  /// is nested under `current_version`, which carries `id`, `version`, `notes`
  /// and `created_at` and no wording at all, and `version` at the top level is
  /// an INTEGER.
  ///
  /// It read both of those as fallbacks, citing the shape-tolerance argument in
  /// `ContractVocabulary.fromJson` — which is about two reasonable spellings of
  /// a thing that exists, and was never a licence to guess at names. Neither
  /// branch could ever run. That is the whole harm of a guessed fallback: it is
  /// not a bug you can see, it is a claim in the code that the server might
  /// answer this way, and the next person maintains around it.
  ///
  /// A template with no body is the ordinary case for everything published
  /// before the wording existed, and it is an empty box rather than an error.
  static String _bodyOf(Map<String, dynamic> template) =>
      template['body'] as String? ?? '';

  /// The tags this contract's questions resolve to, out of the same response.
  ///
  /// The third place `MergeTags::resolvable()` is published, and the one that
  /// is already in hand when this screen opens — see [MergeTag]. Handing it to
  /// the page controller is what stops the chips being assembled in Dart from
  /// the questions on screen in the window before the first preview answers,
  /// and for good in a contract whose preview is refused.
  static List<MergeTag> _resolvableOf(Map<String, dynamic> template) =>
      MergeTag.listFrom(template['merge_tags'], mergeSourceField);

  /// Every keystroke re-asks whether the form can be saved.
  ///
  /// It has to: automation_builder's Create button was computed once with
  /// nothing listening, so typing the name last left it dead until something
  /// else forced a rebuild. A blocked button that never explains itself is
  /// worse than no button.
  void _changed() {
    if (mounted) setState(() {});
  }

  void _adopt(ContractField field) {
    final question = _Question(
      vocabulary: widget.vocabulary,
      published: true,
      key: field.key,
      label: field.label,
      type: field.type,
      filledBy: field.filledBy,
      options: field.options,
      isRequired: field.required,
      sensitive: field.sensitive,
      isExpiry: field.isExpiry,
      retired: field.retired,
      shownWhenKey: field.shownWhen?.key,
      shownWhenOption: field.shownWhen?.option,
    );

    question.label.addListener(_changed);
    question.key.addListener(_changed);
    question.options.addListener(_changed);

    _questions.add(question);
  }

  Future<void> _loadHistory() async {
    final response = await widget.api.get('/hr/contracts/$_id/versions');

    if (!mounted || !response.ok || response.data is! List) return;

    setState(() {
      _history = List<Map<String, dynamic>>.from(
        (response.data as List).whereType<Map>().map(Map<String, dynamic>.from),
      );
    });
  }

  // ── editing the list ─────────────────────────────────────────────

  void _add() {
    final question = _Question(vocabulary: widget.vocabulary, published: false);

    question.label.addListener(() {
      _followLabel(question);
      _changed();
    });
    question.key.addListener(_changed);
    question.options.addListener(_changed);

    setState(() => _questions.add(question));
  }

  /// Keep an underived key following the wording.
  ///
  /// The key is what the answer is filed under, what goes into an export and
  /// what a merge tag names — three places somebody typing it by hand can get
  /// it wrong, in a form that is already asking them for a label. So it is
  /// derived, shown, and editable by anybody who wants it to be something else.
  void _followLabel(_Question question) {
    if (question.keyIsMine || question.published) return;

    question.key.text = _uniqueKey(
      _slug(question.label.text),
      except: question,
    );
  }

  String _uniqueKey(String base, {required _Question except}) {
    if (base.isEmpty) return '';

    final taken = {
      for (final question in _questions)
        if (!identical(question, except)) question.key.text.trim(),
    };

    if (!taken.contains(base) && !widget.vocabulary.isCoreKey(base)) {
      return base;
    }

    for (var n = 2; n < 100; n++) {
      final candidate = '${base}_$n';

      if (!taken.contains(candidate) &&
          !widget.vocabulary.isCoreKey(candidate)) {
        return candidate;
      }
    }

    return base;
  }

  /// "Bank account number" → `bank_account_number`.
  ///
  /// Lower case, letters digits and underscores, starting with a letter. It
  /// generates rather than validates: the server owns the rule and its refusal
  /// says what a key is for, and this only exists so that the ordinary path
  /// never reaches it.
  static String _slug(String label) {
    final buffer = StringBuffer();
    var gap = true;

    for (final rune in label.toLowerCase().runes) {
      final character = String.fromCharCode(rune);

      if (RegExp(r'[a-z0-9]').hasMatch(character)) {
        buffer.write(character);
        gap = false;
      } else if (!gap) {
        buffer.write('_');
        gap = true;
      }
    }

    var slug = buffer.toString();

    while (slug.endsWith('_')) {
      slug = slug.substring(0, slug.length - 1);
    }

    if (slug.isEmpty) return '';

    // A key has to start with a letter, and "2nd emergency contact" is a
    // perfectly reasonable question.
    if (!RegExp(r'^[a-z]').hasMatch(slug)) slug = 'q_$slug';

    if (slug.length > 64) slug = slug.substring(0, 64);

    while (slug.endsWith('_')) {
      slug = slug.substring(0, slug.length - 1);
    }

    return slug;
  }

  void _move(int from, int to) {
    if (to < 0 || to >= _questions.length) return;

    setState(() {
      final question = _questions.removeAt(from);
      _questions.insert(to, question);
    });
  }

  /// Removing a question that was never published, and retiring one that was.
  ///
  /// Two different acts wearing one button, and the difference is the plan's:
  /// fields are never deleted, because a record already carrying an answer
  /// would lose the question it was given to. A question added on this screen
  /// and not yet published has no answers behind it and no history to protect,
  /// so it is simply taken off the list.
  void _removeOrRetire(_Question question) {
    if (!question.published) {
      setState(() {
        _questions.remove(question);
        question.dispose();
      });
      return;
    }

    setState(() => question.retired = true);
  }

  // ── whether it can be saved, and why not ─────────────────────────

  /// The one thing stopping a save, in the words of whoever has to fix it.
  ///
  /// Only ever "this form is not finished". Every judgement about whether a
  /// finished form is ALLOWED belongs to the server — see the class docblock —
  /// and its refusals are written to be shown verbatim.
  String? get _blocker {
    if (!widget.canEdit) {
      return 'Your role can read contracts here and not change them.';
    }

    if (!widget.vocabulary.isUsable) {
      return 'The kinds of question this app can ask could not be loaded, so '
          'there is nothing to build one out of.';
    }

    if (_name.text.trim().isEmpty) return 'Give it a name first.';

    // Mirrored from the server, which requires a non-empty list, and mirrored
    // the way automation_builder mirrors "add at least one thing for it to do":
    // it is a completeness rule about the form rather than a judgement about
    // what the form says, and the alternative is a filled-in screen refused
    // with "the fields field is required".
    if (_questions.isEmpty) {
      return 'Add at least one question. The ones above are asked whatever the '
          'contract says, so a template with none of its own would be a name '
          'and nothing else.';
    }

    final keys = <String>{};

    for (var i = 0; i < _questions.length; i++) {
      final question = _questions[i];
      final at = 'Question ${i + 1}';

      if (question.label.text.trim().isEmpty) {
        return '$at has no wording, so nobody would know what it is asking for.';
      }

      if (question.key.text.trim().isEmpty) {
        return '$at has no key. It is what the answer is stored against.';
      }

      if (!keys.add(question.key.text.trim())) {
        return '$at reuses the key “${question.key.text.trim()}”. Two questions '
            'filed under one name means one answer overwrites the other.';
      }

      if (widget.vocabulary.isCoreKey(question.key.text.trim())) {
        return '$at uses “${question.key.text.trim()}”, which every contract '
            'already asks. It is on the form whatever this template says.';
      }

      if (question.type == null) return '$at has no kind of answer chosen.';

      if (question.filledBy == null) {
        return '$at does not say who fills it in.';
      }

      // Which kinds take a list is the catalogue's answer, not a literal here.
      if (question.takesOptions && question.parsedOptions.isEmpty) {
        return '$at is a list to choose from and has no answers in it, so it '
            'could never be answered.';
      }
    }

    return null;
  }

  bool get _renamed =>
      _name.text.trim() != _originalName ||
      _description.text.trim() != _originalDescription.trim();

  List<Map<String, dynamic>> get _payload => [
    for (final question in _questions) question.toJson(),
  ];

  /// The wording as it goes on the wire.
  ///
  /// Null when there is nothing in it, the same as the description: a body of
  /// spaces is not a contract, and "" and null should not be two states of the
  /// same emptiness on the server.
  String? get _sentBody {
    final text = _body.text.trim();

    return text.isEmpty ? null : text;
  }

  // ── saving ───────────────────────────────────────────────────────

  /// Creating one. There is no diff to show — everything is new — so the
  /// preview in step 4 is the whole of what this is about to commit.
  Future<void> _create() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/hr/contracts', {
      'name': _name.text.trim(),
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      'body': _sentBody,
      // The cover is part of the version, so it travels in the request that
      // makes version 1 — never as a second write afterwards, which would be a
      // contract that briefly existed without the front page it was designed
      // with.
      'cover': _cover.toJson(),
      'fields': _payload,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onSaved();
  }

  /// Publishing. The panel previews it first — see ContractDiffPanel.
  Future<void> _publish() async {
    final published = await showSidePanel<bool>(
      context,
      builder: (_) => _PublishFlow(
        api: widget.api,
        vocabulary: widget.vocabulary,
        templateId: _id!,
        fields: _payload,
        body: _sentBody,
        cover: _cover.toJson(),
        renamedFrom: _renamed ? _originalName : null,
        commit: _commit,
      ),
    );

    if (published == true) widget.onSaved();
  }

  /// The write itself, once somebody has read the diff and said yes.
  ///
  /// The rename goes first and is its own request, because renaming a template
  /// is not part of a version — the questions are the version. It is deliberate
  /// that a rename can succeed and a publish then fail: the two are separate
  /// facts and the panel reports the second one, rather than pretending an
  /// atomicity the API does not offer.
  ///
  /// Returns the server's refusal, or null when everything landed.
  Future<String?> _commit({required bool versionToPublish}) async {
    if (_renamed) {
      final renamed = await widget.api.patch('/hr/contracts/$_id', {
        'name': _name.text.trim(),
        'description': _description.text.trim().isEmpty
            ? null
            : _description.text.trim(),
      });

      if (!renamed.ok) return renamed.reason;

      _originalName = _name.text.trim();
      _originalDescription = _description.text.trim();
    }

    if (!versionToPublish) return null;

    final published = await widget.api.post('/hr/contracts/$_id/versions', {
      'fields': _payload,
      // The wording travels with the questions, because it is the same version.
      // Publishing a field list without the body it was written against would
      // pin a document to answers it does not mention.
      'body': _sentBody,
      // And the cover with both, for the same reason: it is the first page of
      // the document this version produces, so a version that did not carry it
      // would be a document whose front page came from somewhere else.
      'cover': _cover.toJson(),
    });

    if (published.ok) _originalCover = _cover;

    return published.ok ? null : published.reason;
  }

  // ── the form ─────────────────────────────────────────────────────

  /// The tab: the form down one side, the contract down the other.
  ///
  /// ── Why the split is here and not inside step 4 ──────────────
  ///
  /// It was inside step 4, and the owner was blunt about it:
  ///
  ///   *"I specifically told you to do 50% each, as in the whole tab, not
  ///    inside the form"*
  ///
  /// A page in a 620px box between two form sections is a preview widget. The
  /// document is the thing being made, so it gets half the screen and stays
  /// there — visible while the contract is named and while questions are
  /// added, not only while somebody happens to be scrolled to the wording.
  /// Each side scrolls on its own, which is the point: reaching step 6 does
  /// not scroll the contract away.
  @override
  Widget build(BuildContext context) {
    final blocker = _blocker;

    // A question may have changed in step 3, which the body's own listener
    // cannot see. Cheap, and it no-ops unless the questions really moved.
    _page.syncQuestions();

    return LayoutBuilder(
      builder: (context, constraints) {
        // R7. Side by side is a DESKTOP layout: two columns at 375px are two
        // unusable columns, so below the threshold they become one pane with a
        // control saying which you are looking at. Only the chosen side is
        // built — there is no second column off screen.
        final wide = constraints.maxWidth >= _splitAbove;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!wide) ...[
              TabBarLike(
                level: TabLevel.tertiary,
                labels: const [
                  ('Set it up', Icons.edit_outlined),
                  ('The contract', Icons.description_outlined),
                ],
                selected: _reading ? 1 : 0,
                onSelected: (index) => setState(() => _reading = index == 1),
              ),
              const Divider(height: 1),
            ],
            Expanded(
              child: wide
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // flex 1 and flex 1. Half and half, as asked for.
                        Expanded(child: _form(context)),
                        const VerticalDivider(width: 1, thickness: 1),
                        Expanded(child: ContractPagePane(page: _page)),
                      ],
                    )
                  : _reading
                  ? ContractPagePane(page: _page)
                  : _form(context),
            ),
            const Divider(height: 1),
            _footer(context, blocker),
          ],
        );
      },
    );
  }

  /// Everything that decides what the contract says, in order, scrolling.
  Widget _form(BuildContext context) {
    return SingleChildScrollView(
      key: const ValueKey('tour-hr-contract-form'),
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Center(
        child: ConstrainedBox(
          // Half of a wide monitor is still wide enough to need a measure —
          // a 1400px line of body text is not readable.
          constraints: const BoxConstraints(maxWidth: 860),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_isEdit) _banner(context),
              _nameStep(context),
              const SizedBox(height: 26),
              _coreStep(context),
              const SizedBox(height: 26),
              _questionsStep(context),
              const SizedBox(height: 26),
              _previewStep(context),
              if (_isEdit) ...[
                const SizedBox(height: 26),
                _historyStep(context),
              ],
              // ── Last, because it is the one thing that grows ──────
              //
              // It was fourth, with a fixed 360px surface, and a writing
              // surface that cannot grow is the wrong shape for the one field
              // on the screen that holds a five-page document. Letting it grow
              // where it was would have pushed the two steps under it further
              // down the page with every paragraph — the owner's objection:
              // *"it should grow, which i believe the best idea would be to
              // move it as the last item, so it does not push the others
              // endlessly."*
              //
              // Nothing below it now, so it can be as long as the contract is.
              // The order still reads: decide what is asked, check who is asked
              // it, then make the document — its front page, then the wording
              // that uses the answers.
              const SizedBox(height: 26),
              _coverStep(context),
              const SizedBox(height: 26),
              _bodyStep(context),
              if (_error != null) ...[
                const SizedBox(height: 20),
                ErrorNote(_error!),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _banner(BuildContext context) {
    final version = widget.template?['version'];

    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Row(
        children: [
          const Icon(Icons.description_outlined, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Editing “$_originalName”'
              '${version == null ? '' : ', currently on version $version'}. '
              'Nothing here changes a contract anybody has already signed — '
              'publishing decides what the NEXT person is asked.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }

  Widget _nameStep(BuildContext context) {
    return NumberedStep(
      number: 1,
      title: 'Name it',
      note:
          'What whoever runs an onboarding picks from, so it should say who '
          'it is for rather than what it is.',
      child: FieldPair(
        first: TextField(
          controller: _name,
          enabled: widget.canEdit && !_busy,
          decoration: const InputDecoration(
            labelText: 'Name',
            hintText: 'Technician, full time',
          ),
        ),
        second: TextField(
          controller: _description,
          enabled: widget.canEdit && !_busy,
          decoration: const InputDecoration(
            labelText: 'What it is for (optional)',
            hintText:
                'Who this contract is used for, for whoever reads it '
                'next',
          ),
        ),
      ),
    );
  }

  /// What onboarding asks whatever the contract says, drawn and locked.
  ///
  /// Shown rather than left implicit, for a reason the plan names: without it
  /// somebody adds "First name" of their own and wonders why the form asks
  /// twice. Not editable, because these live on the person's own profile and a
  /// second copy here would be a second home for one fact — the one-home rule.
  ///
  /// ── This step used to be wrong, about a person ───────────────
  ///
  /// It drew `reserved_keys`, which is the list of keys a template may NOT
  /// declare — eight of them — under a heading saying they are always asked.
  /// Onboarding asks six. Two of the eight are reserved without ever being
  /// asked: the display name, which the application recomposes from the parts
  /// on every save, and the middle name, which somebody adds in their own
  /// account if they have one.
  ///
  /// So this step told whoever configures onboarding that every new starter
  /// would be asked for a middle name, and the owner objected on behalf of the
  /// people who do not have one. Nothing was demanding it. This screen was.
  ///
  /// ── Which list is drawn, and why that one ────────────────────
  ///
  /// `merge_tags.core` from the catalogue — the server's published answer to
  /// "what does every onboarding collect", assembled from `CommonCore::asked()`
  /// and documented as the six on [MergeTagSyntax.core]. `reserved_keys`
  /// answers a different question and keeps its own job in [_blocker], where a
  /// key somebody is typing is checked against it. Two questions, two lists,
  /// and this step had been reading the wrong one.
  ///
  /// ── And there is now a BETTER list, which this cannot reach ───
  ///
  /// The catalogue grew `asked` on every reserved entry and a pre-filtered
  /// `core` beside them, which is the same six with `required` on each — the
  /// declaration this step actually wants. It does not arrive:
  /// `ContractVocabulary.fromJson` reads `reserved_keys ?? core`, so the new
  /// list is shadowed by the old one and `CoreField` has no `asked` to parse.
  /// That is core/contracts.dart, which belongs to another agent this round.
  ///
  /// What is drawn is identical either way — `merge_tags.core` IS
  /// `CommonCore::asked()`, published by the same `resolvable()` call — so this
  /// is the right list reached the long way round rather than a different
  /// answer. The one seam: that list exists to be INSERTED into the wording, so
  /// an entry sent without a tag is dropped before it gets here. Nothing sends
  /// one, because `resolvable()` writes a tag for every entry it publishes; an
  /// asked question that could not be merged would go missing from this step,
  /// and reading the catalogue's own `core` is what closes it for good.
  Widget _coreStep(BuildContext context) {
    final theme = Theme.of(context);
    final asked = widget.vocabulary.mergeTags.core;

    return NumberedStep(
      number: 2,
      title: 'Always asked',
      note: asked.isEmpty
          ? 'The questions every onboarding asks could not be loaded, so this '
                'list is empty rather than short. They are still asked.'
          : 'Asked on every onboarding whatever this contract says, because an '
                'invitation cannot be addressed without them. They live on the '
                'person’s own profile, so this template does not get a second '
                'copy — and you cannot add one of these yourself.',
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final field in asked)
            Tooltip(
              // The server's sentence about where the fact lives, or the key,
              // which is at least true. An empty tooltip is a control that
              // reacts to a hover and then says nothing.
              message: field.note ?? field.key,
              child: Container(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest.withValues(
                    alpha: 0.5,
                  ),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.lock_outline, size: 14),
                    const SizedBox(width: 8),
                    // Flexible: these are laid out in a Wrap, so a long label
                    // on a phone gets the width of the screen and no more.
                    Flexible(
                      child: Text(
                        field.label,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    if (_mayBeLeftBlank(field.key)) const Tag('Optional'),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// Whether the server has SAID this question may be left blank.
  ///
  /// The owner's third request was about the middle name, and half of the
  /// answer is that it is not asked at all — that is the list above. The other
  /// half is that some of the six genuinely are optional, and somebody looking
  /// at this step should be able to see which before they wonder whether the
  /// platform is about to demand a fact a person does not have.
  ///
  /// Read off the matching reserved entry, where `CoreField.required` already
  /// parses the flag the catalogue now publishes (`CommonCore::isRequired`),
  /// because the asked entries carry none — they are tag definitions, and a tag
  /// has no opinion about whether the question behind it has to be answered.
  ///
  /// ── Only "optional" is ever drawn, and that is on purpose ────
  ///
  /// `CoreField.required` defaults TRUE when the server sends nothing, so this
  /// cannot tell "required" from "nobody said". It does not try. The mark is
  /// the exception rather than the rule, so a server that has not yet published
  /// requiredness marks nothing at all and the list is simply drawn — which is
  /// the honest degrade. Inventing "Required" on every chip would be this end
  /// stating a platform rule nobody sent it.
  bool _mayBeLeftBlank(String key) {
    for (final reserved in widget.vocabulary.core) {
      if (reserved.key == key) return !reserved.required;
    }

    return false;
  }

  Widget _questionsStep(BuildContext context) {
    final theme = Theme.of(context);

    return NumberedStep(
      number: 3,
      title: 'What this contract adds',
      note:
          'In the order they will be asked. Removing one retires it — it '
          'stops being asked and stays readable on every record that already '
          'answered it, which is why nothing here is ever deleted.',
      action: widget.canEdit
          ? TextButton.icon(
              onPressed: _busy ? null : _add,
              icon: const Icon(Icons.add, size: 16),
              label: const Text('Add a question'),
            )
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_questions.isEmpty)
            Text(
              'Nothing yet. This is where a contract stops being a name — the '
              'wage, the bank account, the uniform size, whatever this one '
              'needs that the questions above do not already cover.',
              style: theme.textTheme.bodyMedium,
            ),
          for (var i = 0; i < _questions.length; i++)
            Padding(
              key: _questions[i].rowKey,
              padding: const EdgeInsets.only(bottom: 12),
              child: _questionCard(context, i, _questions[i]),
            ),
        ],
      ),
    );
  }

  Widget _questionCard(BuildContext context, int index, _Question question) {
    final theme = Theme.of(context);
    final enabled = widget.canEdit && !_busy && !question.retired;

    return Card(
      margin: EdgeInsets.zero,
      // The side this question belongs to, as a stripe down the edge of the
      // card. The list is then readable as a shape before it is readable as
      // words, which is the point of answering `filled_by` twice.
      //
      // A border rather than a sibling box in a stretched Row: the card sits in
      // a scroll view, so a Row told to stretch is a Row handed infinite height,
      // and that is a thrown assertion rather than a tall card.
      child: Container(
        decoration: BoxDecoration(
          border: Border(
            left: BorderSide(
              width: 5,
              color: _sideColour(context, question.filledBy),
            ),
          ),
          borderRadius: const BorderRadius.horizontal(
            left: Radius.circular(12),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  CircleAvatar(
                    radius: 12,
                    backgroundColor: theme.colorScheme.surfaceContainerHighest,
                    child: Text(
                      '${index + 1}',
                      style: const TextStyle(fontSize: 11),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: question.retired
                        // A Wrap, because "Ask it again" beside it can leave
                        // this almost no width on a phone — and then a Row
                        // shrinks the label to nothing and overflows by the
                        // width of the tag regardless. The tag drops under
                        // the label instead.
                        ? Wrap(
                            crossAxisAlignment: WrapCrossAlignment.center,
                            spacing: 8,
                            runSpacing: 4,
                            children: [
                              Text(
                                question.label.text,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.titleSmall?.copyWith(
                                  color: theme.colorScheme.onSurfaceVariant,
                                ),
                              ),
                              const Tag('Retired', warn: true),
                            ],
                          )
                        : TextField(
                            controller: question.label,
                            enabled: enabled,
                            decoration: const InputDecoration(
                              labelText: 'The question',
                              isDense: true,
                              hintText: 'Bank account number',
                            ),
                          ),
                  ),
                  if (widget.canEdit) ..._cardActions(index, question),
                ],
              ),
              if (question.retired)
                Padding(
                  padding: const EdgeInsets.only(left: 36, top: 8),
                  child: Text(
                    'Not asked on new onboardings. Every record that '
                    'already answered it still shows the answer, which is '
                    'why it is retired rather than gone.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              else ...[
                const SizedBox(height: 14),
                Padding(
                  padding: const EdgeInsets.only(left: 36, right: 8),
                  child: _questionBody(context, question, enabled),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _cardActions(int index, _Question question) {
    if (question.retired) {
      return [
        TextButton(
          onPressed: _busy
              ? null
              : () => setState(() => question.retired = false),
          child: const Text('Ask it again'),
        ),
      ];
    }

    return [
      IconButton(
        icon: const Icon(Icons.arrow_upward, size: 18),
        tooltip: 'Ask this one earlier',
        onPressed: _busy || index == 0 ? null : () => _move(index, index - 1),
      ),
      IconButton(
        icon: const Icon(Icons.arrow_downward, size: 18),
        tooltip: 'Ask this one later',
        onPressed: _busy || index == _questions.length - 1
            ? null
            : () => _move(index, index + 1),
      ),
      IconButton(
        icon: Icon(
          question.published ? Icons.do_not_disturb_on_outlined : Icons.close,
          size: 18,
        ),
        // Two different acts, so two different words. "Delete" on something
        // that cannot be deleted would be a promise the server refuses.
        tooltip: question.published
            ? 'Retire this question'
            : 'Remove this question',
        onPressed: _busy ? null : () => _removeOrRetire(question),
      ),
    ];
  }

  Widget _questionBody(BuildContext context, _Question question, bool enabled) {
    final theme = Theme.of(context);

    // The server declares which kinds of question carry a list; this draws the
    // box for them. It compared the type to the literal `'choice'`, which is the
    // client re-stating a server rule in the one screen whose docblock says it
    // does not — and a seventh kind that took options would have arrived in the
    // dropdown with nowhere to type them.
    final takesOptions = question.takesOptions;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              flex: 3,
              child: SearchPickerField<String>(
                       value: widget.vocabulary.knowsType(question.type ?? '')
                    ? question.type
                    : null,
                       label: 'Kind of answer',
                       // Fixed once published, and the reason is worth saying rather
                       // than greying a control and leaving somebody to guess.
                       helperText: question.published
                      ? 'Fixed — answers have already been stored as this kind. '
                            'Retire it and add a new question instead.'
                      : null,
                       helperMaxLines: 3,
                       isDense: true,
                       options: [
                  for (final type in widget.vocabulary.types)
                    SearchOption(value: type.key, label: type.label),
                ],
                       onChanged: enabled && !question.published
                    ? (value) => setState(() {
                        question.type = value;
                        if (value != 'date') question.isExpiry = false;
                      })
                    : null,
                     ),
            ),
            const SizedBox(width: 14),
            Expanded(
              flex: 2,
              child: SwitchListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                value: question.isRequired,
                title: const Text('Has to be answered'),
                onChanged: enabled
                    ? (value) => setState(() => question.isRequired = value)
                    : null,
              ),
            ),
          ],
        ),
        if (question.type == 'date') ...[
          const SizedBox(height: 8),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            value: question.isExpiry,
            title: const Text('This date is an expiry'),
            subtitle: const Text(
              'Licences and certificates that lapse. Leave a date of birth unmarked.',
            ),
            onChanged: enabled
                ? (value) => setState(() => question.isExpiry = value)
                : null,
          ),
        ],
        if (takesOptions) ...[
          const SizedBox(height: 12),
          TextField(
            controller: question.options,
            enabled: enabled,
            minLines: 2,
            maxLines: 6,
            decoration: const InputDecoration(
              labelText: 'The answers to choose from',
              isDense: true,
              // Lines rather than commas, because an answer is a phrase and
              // phrases contain commas — see _Question.parsedOptions.
              helperText: 'One per line.',
            ),
          ),
        ],
        const SizedBox(height: 16),
        _filledByRow(context, question, enabled),
        const SizedBox(height: 12),
        _shownWhenRow(context, question, enabled),
        const SizedBox(height: 12),
        _sensitiveRow(context, question, enabled),
        if (question.published) ...[
          const SizedBox(height: 8),
          Text(
            'Stored as ${question.key.text}',
            style: theme.textTheme.bodySmall,
          ),
        ] else
          _keyRow(context, question, enabled),
      ],
    );
  }

  Widget _shownWhenRow(
    BuildContext context,
    _Question question,
    bool enabled,
  ) {
    final theme = Theme.of(context);
    final self = question.key.text.trim();
    final gates = [
      for (final other in _questions)
        if (other != question &&
            other.takesOptions &&
            other.key.text.trim().isNotEmpty &&
            other.key.text.trim() != self &&
            other.sentOptions.isNotEmpty)
          other,
    ];

    if (gates.isEmpty) return const SizedBox.shrink();

    _Question? gate;
    for (final other in gates) {
      if (other.key.text.trim() == question.shownWhenKey) {
        gate = other;
        break;
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('When it is asked', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(
              label: const Text('Always'),
              selected: question.shownWhenKey == null,
              onSelected: enabled
                  ? (_) => setState(() {
                      question.shownWhenKey = null;
                      question.shownWhenOption = null;
                    })
                  : null,
            ),
            for (final other in gates)
              ChoiceChip(
                label: Text(
                  other.label.text.trim().isEmpty
                      ? other.key.text.trim()
                      : other.label.text.trim(),
                ),
                selected: question.shownWhenKey == other.key.text.trim(),
                onSelected: enabled
                    ? (_) => setState(() {
                        question.shownWhenKey = other.key.text.trim();
                        final options = other.sentOptions;
                        if (question.shownWhenOption == null ||
                            !options.any(
                              (o) => optionSlug(o) == question.shownWhenOption,
                            )) {
                          question.shownWhenOption = options.isEmpty
                              ? null
                              : optionSlug(options.first);
                        }
                      })
                    : null,
              ),
          ],
        ),
        if (gate != null) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final option in gate.sentOptions)
                ChoiceChip(
                  label: Text(option),
                  selected: question.shownWhenOption == optionSlug(option),
                  onSelected: enabled
                      ? (_) => setState(
                          () => question.shownWhenOption = optionSlug(option),
                        )
                      : null,
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Hidden on the form until that answer is picked. Keep the matching '
            'clause in the contract with {{#${gate.key.text.trim()}.${question.shownWhenOption}}} … {{/${gate.key.text.trim()}.${question.shownWhenOption}}}.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  /// Three chips, all three answers visible, and the consequence written
  /// underneath in the server's own words.
  Widget _filledByRow(BuildContext context, _Question question, bool enabled) {
    final theme = Theme.of(context);
    final chosen = question.filledBy;
    final description = chosen == null
        ? null
        : widget.vocabulary.filledByDescription(chosen);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Who fills it in', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final option in widget.vocabulary.filledBy)
              ChoiceChip(
                label: Text(option.label),
                avatar: Icon(_sideIcon(option.key), size: 16),
                selected: chosen == option.key,
                // The other chip in this app with an avatar AND a selected
                // state, and the same defect — see TabBarLike for the whole
                // reason. Material paints the tick on top of the avatar and
                // darkens the avatar to make it readable, so the icon that
                // identifies the option vanishes under a grey disc at the exact
                // moment you pick it.
                showCheckmark: false,
                onSelected: enabled
                    ? (_) => setState(() => question.filledBy = option.key)
                    : null,
              ),
          ],
        ),
        if (description != null) ...[
          const SizedBox(height: 6),
          Text(description, style: theme.textTheme.bodySmall),
        ],
        if (chosen == null) ...[
          const SizedBox(height: 6),
          Text(
            'There is no default on purpose. A question that silently belonged '
            'to you could be a bank account nobody ever asked them for.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  /// The tick, and what it commits the organization to.
  ///
  /// A checkbox labelled "sensitive" means nothing. These four consequences are
  /// what the plan says the flag DOES, and the last sentence is the part that
  /// keeps it honest: nothing is being collected yet, because onboarding is
  /// what asks these questions. Stating the four as though they were already
  /// running would be a claim about code that does not exist, over a bank
  /// account number.
  Widget _sensitiveRow(BuildContext context, _Question question, bool enabled) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          controlAffinity: ListTileControlAffinity.leading,
          value: question.sensitive,
          title: const Text('Treat the answer as sensitive'),
          subtitle: const Text(
            'For an answer this business would be answerable for if it leaked '
            '— a bank account, a document number, a medical note.',
          ),
          onChanged: enabled
              ? (value) => setState(() => question.sensitive = value ?? false)
              : null,
        ),
        if (question.sensitive)
          Container(
            margin: const EdgeInsets.only(top: 6),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest.withValues(
                alpha: 0.5,
              ),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'The answer will be encrypted where it is stored, hidden until '
              'somebody with permission reveals it — and revealing it recorded '
              'against their name — left out of exports unless it is asked for '
              'on purpose, and listed in the data inventory an erasure request '
              'is answered from.\n\n'
              'Nothing is being collected yet: onboarding is the part that asks '
              'these questions. This decides how the answer will be handled '
              'rather than changing anything today.',
              style: theme.textTheme.bodySmall,
            ),
          ),
      ],
    );
  }

  /// The key, hidden until somebody wants it.
  Widget _keyRow(BuildContext context, _Question question, bool enabled) {
    if (!question.keyShown) {
      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                question.key.text.isEmpty
                    ? 'Stored under a name taken from the wording.'
                    : 'Stored as ${question.key.text}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            if (enabled)
              TextButton(
                onPressed: () => setState(() => question.keyShown = true),
                child: const Text('Change'),
              ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: TextField(
        controller: question.key,
        enabled: enabled,
        onChanged: (_) => question.keyIsMine = true,
        decoration: const InputDecoration(
          labelText: 'Stored as',
          isDense: true,
          helperMaxLines: 3,
          helperText:
              'Lower case, letters numbers and underscores. It goes '
              'into exports, merge tags and the data inventory, and it cannot '
              'be changed once this question has been published.',
        ),
      ),
    );
  }

  /// The front page — the owner's *"ability to create a cover for the contract
  /// as well"*.
  ///
  /// ── Where it sits, and why it is not inside the wording ──────
  ///
  /// Immediately before the wording, so the two steps that make the DOCUMENT
  /// are next to each other and are reached after the questions are settled.
  /// Not inside the writing step: the wording is one long text box that grows
  /// with the contract, and a picture picker halfway down it would be a control
  /// somebody has to scroll a five-page agreement to find.
  ///
  /// ── It says what pressing it costs, before the press ─────────
  ///
  /// A cover is part of the version. Somebody who thinks they are swapping a
  /// picture is publishing a new version of an agreement, and that sentence has
  /// to be on the step and in the panel rather than discovered on the publish
  /// screen. The designer already talks this way about the wording; this is the
  /// same fact about the same document.
  ///
  /// ── Automations ──────────────────────────────────────────────
  ///
  /// It emits nothing of its own, deliberately. The cover is not an event —
  /// publishing a version is, and the server already emits
  /// `contract.version_published` carrying `wording_changed` beside the field
  /// counts. A cover change rides that, and the flag naming it belongs beside
  /// `wording_changed` in the same payload rather than in a second event that
  /// fires for the same act.
  Widget _coverStep(BuildContext context) {
    return NumberedStep(
      // Before the wording, which stays last. History only exists while
      // editing, so both numbers move with it rather than being hardcoded.
      number: _isEdit ? 6 : 5,
      title: 'Give it a cover',
      note:
          'A front page before the agreement starts — the logo your '
          'organization has already uploaded, or a file of your own. Most '
          'contracts have none. The cover is part of the version, the same as '
          'the wording, so changing it publishes a new one.',
      action: widget.canEdit
          ? TextButton.icon(
              onPressed: _busy ? null : _chooseCover,
              icon: const Icon(Icons.image_outlined, size: 16),
              label: Text(_cover.isNone ? 'Choose a cover' : 'Change it'),
            )
          : null,
      child: _coverState(context),
    );
  }

  /// What the cover IS right now, drawn rather than left to be inferred.
  ///
  /// "None" gets the same box as the other two. An empty space where a picture
  /// would go reads as something that failed to load; a stated "No cover" reads
  /// as a decision, which is what it is for nearly every contract anybody
  /// writes.
  Widget _coverState(BuildContext context) {
    final theme = Theme.of(context);
    final unpublished = _isEdit && _cover != _originalCover;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(
          alpha: 0.35,
        ),
        borderRadius: BorderRadius.circular(12),
      ),
      // R7. A thumbnail beside a paragraph is two columns, and two columns
      // inside a step that is already indented is a squeezed picture beside a
      // squeezed sentence on a phone. Below the threshold the picture goes
      // above the words instead.
      child: LayoutBuilder(
        builder: (context, constraints) {
          final thumbnail = _coverThumbnail(context);
          final words = Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(_coverTitle, style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),
              Text(_coverNote, style: theme.textTheme.bodySmall),
              if (unpublished) ...[
                const SizedBox(height: 8),
                Text(
                  'This is not on the published version yet. It arrives when '
                  'you publish, with everything else on this screen.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ],
          );

          if (constraints.maxWidth < 340) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [thumbnail, const SizedBox(height: 12), words],
            );
          }

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              thumbnail,
              const SizedBox(width: 14),
              Expanded(child: words),
            ],
          );
        },
      ),
    );
  }

  /// A picture of the cover, using the widget that already draws a mark.
  ///
  /// [LogoBadge] fetches bytes through the API rather than pointing an `<img>`
  /// at a guessable path, falls back to an icon when there is nothing there,
  /// and refuses to crop artwork to fill its box — three rules a second
  /// implementation here would have had to get right again.
  Widget _coverThumbnail(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final path = switch (_cover.source) {
      CoverSource.logo => '/branding/organization',
      // Null until the server says where the upload can be read, which is
      // ordinary — an icon then, never a URL invented at this end.
      CoverSource.file => _cover.imagePath,
      CoverSource.none => null,
    };

    if (path == null) {
      return Container(
        width: 48,
        height: 60,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: scheme.surface,
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: scheme.outlineVariant),
        ),
        child: Icon(
          _cover.isNone ? Icons.crop_portrait : Icons.image_outlined,
          size: 20,
          color: scheme.onSurfaceVariant,
        ),
      );
    }

    return LogoBadge(
      api: widget.api,
      path: path,
      // Read-only here: this is a picture OF the choice, and the choice is made
      // in the panel. A badge that could change the organization's mark from
      // inside a contract designer would be editing branding from the wrong
      // screen entirely.
      hasOwn: false,
      mayEdit: false,
      size: 56,
      fallbackIcon: Icons.image_outlined,
    );
  }

  String get _coverTitle => switch (_cover.source) {
    CoverSource.none => 'No cover',
    CoverSource.logo => 'Your organization’s logo',
    CoverSource.file => 'A file of your own',
  };

  String get _coverNote => switch (_cover.source) {
    CoverSource.none =>
      'The document starts on the first page of the agreement. Most contracts '
          'do, and nothing about one is missing.',
    CoverSource.logo =>
      'The mark uploaded under branding, on a page of its own. It is not copied '
          'here — change the logo there and every contract using it follows.',
    CoverSource.file =>
      _cover.filename ?? 'An image uploaded for this contract.',
  };

  Future<void> _chooseCover() async {
    final chosen = await showSidePanel<ContractCover>(
      context,
      builder: (_) =>
          ContractCoverPanel(api: widget.api, cover: _cover),
    );

    // Null is a cancelled panel and never "no cover" — that answer arrives as
    // ContractCover.none, which is why it is a value rather than a null.
    if (chosen == null || !mounted) return;

    setState(() => _cover = chosen);
  }

  /// The contract itself — the wording, and the page it makes.
  Widget _bodyStep(BuildContext context) {
    return NumberedStep(
      // Last, and History only exists while editing — so the number depends on
      // whether it is there. Hardcoding 7 would misnumber every new contract.
      number: _isEdit ? 7 : 6,
      title: 'Write the contract',
      note:
          'The agreement itself, in your words. Anything in double braces is '
          'filled in when somebody is onboarded — click a field to drop its '
          'tag in where the cursor is, rather than remembering what it was '
          'called. It appears on the page beside this as you type.',
      child: ContractWriting(
        page: _page,
        vocabulary: widget.vocabulary,
        fields: () => [for (final question in _questions) question.toField()],
        canEdit: widget.canEdit,
      ),
    );
  }

  Widget _previewStep(BuildContext context) {
    return NumberedStep(
      number: 4,
      title: 'What each side sees',
      note:
          'The form as it will be asked. This is the answer to “who fills it '
          'in” drawn rather than written down — a question in the wrong list '
          'here is a question in the wrong list on the day somebody starts.',
      child: ContractFormPreview(
        vocabulary: widget.vocabulary,
        fields: [for (final question in _questions) question.toField()],
        formats: widget.formats,
      ),
    );
  }

  /// Every version, and none of them editable.
  ///
  /// There is no "put this one back" here, unlike the automation builder's
  /// history. Restoring an old rule un-does a mistake; restoring an old
  /// contract version would silently start asking questions that were
  /// deliberately retired, and the way to do that on purpose is to bring the
  /// question back in step 3 and publish it — which leaves a version saying so.
  Widget _historyStep(BuildContext context) {
    final current = widget.template?['version'];

    return NumberedStep(
      number: 5,
      title: 'History',
      note: _history.isEmpty
          ? 'Every publish writes one of these, and each record remembers which '
                'one produced it.'
          : 'Every publish wrote one of these and none of them can be edited, '
                'because each record remembers which one produced it.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final version in _history)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Row(
                children: [
                  Text('Version ${version['version']}'),
                  if (version['is_current'] == true ||
                      (version['is_current'] == null &&
                          version['version'] == current))
                    const Tag('In use'),
                ],
              ),
              subtitle: Text(
                [
                  formatStamp(
                    version['created_at'],
                    'date unknown',
                    formats: widget.formats,
                  ),
                  // Both spellings, because who wrote a version is worth
                  // showing and the wire word for it is not settled between
                  // "who created the row" and "who published it".
                  if (version['created_by'] is String)
                    'published by ${version['created_by']}'
                  else if (version['published_by'] is String)
                    'published by ${version['published_by']}',
                  if (version['notes'] is String &&
                      (version['notes'] as String).isNotEmpty)
                    version['notes'] as String,
                ].join(' · '),
              ),
            ),
        ],
      ),
    );
  }

  Widget _footer(BuildContext context, String? blocker) {
    return Padding(
      key: const ValueKey('tour-hr-contract-footer'),
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 16),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final note = Text(
            // The button says no; this says why. A disabled control with no
            // explanation is the thing people file bugs about.
            blocker ??
                (_isEdit
                    ? 'Publishing shows you exactly what changes before it '
                          'commits, and applies to everybody onboarded '
                          'afterwards.'
                    : 'It is saved as version 1, and every onboarding from '
                          'then on uses it.'),
            style: Theme.of(context).textTheme.bodySmall,
          );

          final buttons = [
            TextButton(
              onPressed: _busy ? null : widget.onCancel,
              child: Text(widget.canEdit ? 'Cancel' : 'Back'),
            ),
            FilledButton(
              onPressed: _busy || blocker != null
                  ? null
                  : (_isEdit ? _publish : _create),
              child: Text(
                _isEdit ? 'Review and publish' : 'Create the contract',
              ),
            ),
          ];

          // R7. "Review and publish" beside "Cancel" beside a sentence is
          // about 370px of intrinsic width before the sentence gets any, so
          // on a phone the two stack. The buttons keep the bottom, where the
          // primary action belongs, and the reason it is disabled sits above
          // it rather than being squeezed to one word a line.
          // Wrapping rather than a Row even here: "Create the contract" beside
          // "Cancel" is wider than a phone, so the two go one above the other
          // at the bottom of the range.
          final actions = Wrap(
            alignment: WrapAlignment.end,
            spacing: 10,
            runSpacing: 8,
            children: buttons,
          );

          if (constraints.maxWidth < 560) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [note, const SizedBox(height: 12), actions],
            );
          }

          return Row(
            children: [
              Expanded(child: note),
              const SizedBox(width: 16),
              actions,
            ],
          );
        },
      ),
    );
  }

  // ── how a side reads at a glance ─────────────────────────────────

  /// A colour and a glyph per side.
  ///
  /// Mapped by key in Dart for the reason `moduleScreens` gives: a server can
  /// send neither a `Color` nor an `IconData`. The WORDS for these three all
  /// come from the catalogue; only the paint is here, and an unknown key gets
  /// the neutral pair rather than being drawn as one of the two parties.
  ///
  /// Written as comparisons rather than as a `switch` on purpose: a bare
  /// identifier in a Dart pattern is a VARIABLE pattern, so `filledByEmployer =>`
  /// would bind every value it was given and quietly answer "employer" for all
  /// three.
  Color _sideColour(BuildContext context, String? filledBy) {
    final scheme = Theme.of(context).colorScheme;

    // Two colours and a neutral, not three colours. Three shades of the same
    // family are not tellable apart at five pixels wide, which would make the
    // stripe decoration rather than information — and decoration is what this
    // was when it came out violet.
    if (filledBy == filledByEmployer) return scheme.primary;
    if (filledBy == filledByEmployee) return scheme.tertiary;
    if (filledBy == filledByEither) return scheme.outline;

    return scheme.outlineVariant;
  }

  static IconData _sideIcon(String filledBy) {
    if (filledBy == filledByEmployer) return Icons.business_center_outlined;
    if (filledBy == filledByEmployee) return Icons.person_outline;
    if (filledBy == filledByEither) return Icons.sync_alt;

    return Icons.help_outline;
  }
}

/// Previewing the publish, then doing it.
///
/// A small stateful host around [ContractDiffPanel], which stays a drawing of a
/// diff and nothing else. The fetch lives here because a panel opened through
/// `showSidePanel` is built once — its `pageBuilder` does not rebuild when the
/// screen behind it does — so the thing that changes while it is open has to own
/// its own state.
class _PublishFlow extends StatefulWidget {
  const _PublishFlow({
    required this.api,
    required this.vocabulary,
    required this.templateId,
    required this.fields,
    required this.body,
    required this.cover,
    required this.renamedFrom,
    required this.commit,
  });

  final ApiClient api;
  final ContractVocabulary vocabulary;
  final String templateId;
  final List<Map<String, dynamic>> fields;

  /// The wording, sent with the preview so the server can diff it. Without it
  /// the panel would show every change except the one to the contract.
  final String? body;

  /// The cover, sent with the preview for exactly the same reason.
  ///
  /// Null is a real value here — it is a contract with no front page — so this
  /// goes on the wire either way rather than being omitted when it is null.
  ///
  /// The diff that comes back is the SERVER's, and this end does not add a
  /// cover line to it from what it knows. Two implementations of "did the
  /// version change" is the divergence [ContractDiffPanel] documents at length,
  /// and a client that answered yes while the server wrote no would publish a
  /// version identical to the one before it.
  final Map<String, dynamic>? cover;

  final String? renamedFrom;

  /// Does the writing, and answers with the server's refusal or null.
  final Future<String?> Function({required bool versionToPublish}) commit;

  @override
  State<_PublishFlow> createState() => _PublishFlowState();
}

class _PublishFlowState extends State<_PublishFlow> {
  ContractDiff? _diff;
  int? _version;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _preview();
  }

  Future<void> _preview() async {
    final response = await widget.api.post(
      '/hr/contracts/${widget.templateId}/versions',
      {
        'fields': widget.fields,
        'body': widget.body,
        'cover': widget.cover,
        'preview': true,
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    final data = response.data;
    final body = data is Map ? Map<String, dynamic>.from(data) : const {};

    // `diff` beside the version number, or the diff on its own. Two readings of
    // one answer, for the reason PermissionCatalogue.fromResponse gives: a
    // wording difference between the two halves of a contract should not become
    // a panel that renders nothing and says nothing about why.
    final node = body['diff'] is Map ? body['diff'] : body;

    // ── The cover is answered BESIDE the diff, not inside it ─────
    //
    // The endpoint sends `cover: {changed, from, to}` alongside `diff`, and
    // says why: `VersionDiff` compares field lists and bodies and is also what
    // the version-history screen renders, so growing it would change a shape
    // two screens share. `diff.is_empty` is therefore about the questions and
    // the wording, and this is about the front page.
    //
    // READ, and not worked out here. The server's own no-op guard is
    // `$diff->isEmpty() && ! $coverChange['changed']`, and the line below is
    // that same composition of the same two answers rather than a second
    // opinion about either — which matters, because a cover-only change is a
    // real version and without this the panel would offer no button for the
    // one act the owner asked for.
    final cover = body['cover'];

    setState(() {
      _diff = ContractDiff.fromJson({
        ...Map<String, dynamic>.from(node as Map),
        if (cover is Map && cover['changed'] == true) 'is_empty': false,
      });
      _version = (body['version'] as num?)?.toInt();
    });
  }

  Future<void> _confirm() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final refusal = await widget.commit(
      versionToPublish: _diff?.isNotEmpty ?? false,
    );

    if (!mounted) return;

    if (refusal != null) {
      setState(() {
        _busy = false;
        _error = refusal;
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final diff = _diff;

    // Nothing to commit: the questions are unchanged and so is the name. The
    // panel then has one job, which is to say so.
    final nothingToDo =
        diff != null && diff.isEmpty && widget.renamedFrom == null;

    return ContractDiffPanel(
      vocabulary: widget.vocabulary,
      diff: diff,
      version: _version,
      renamedFrom: widget.renamedFrom,
      busy: _busy,
      error: _error,
      onConfirm: diff == null || nothingToDo ? null : _confirm,
    );
  }
}
