import 'package:flutter/material.dart';

import 'package:flutter/services.dart' show FilteringTextInputFormatter;

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/tag.dart';

/// The test builder — the right half of layout 8's storyboard
/// (`docs/previews/training-layouts.html`, task-15 brief), and also its own
/// standalone screen for a test with no guide attached.
///
/// `GET /training/tests/{id}` (`TestsController::show`) hands back the
/// AUTHOR's own copy — `questions[].choices[].correct` included, unlike the
/// learner's stripped `GET /training/learn/tests/{id}` (`test_player.dart`'s
/// own docblock) — because writing a question means seeing which choice is
/// already marked right.
///
/// ── The five options, and nothing invented beyond them ──────────────
///
/// `requires_guide` ("Must finish the guide before the test"), `pass_mark`
/// ("Pass mark"), `max_attempts` ("Retakes allowed" — blank means
/// unlimited), `valid_for_days` ("A pass runs out after" — blank means a
/// pass never expires), and `count_attempts_after_pass` ("Count every
/// retake, even a pass" — off, the default, is the owner's failures-only
/// ruling, 2026-09-09, the fix for a permanent-lockout defect the
/// fifth-option brief's own first cut of this option still had (a passing
/// attempt spent the cap's one slot, which stayed hidden until that pass
/// itself lapsed): only a FAILED attempt counts toward `max_attempts`, and
/// only when it was not taken while an earlier pass was still valid — a
/// pass itself never counts, and neither does a retake, whatever its
/// outcome, taken while one still holds. Both conditions are load-bearing
/// (see `LapsedPasses::walkCountingTowardCap`'s own docblock for why
/// neither alone closes the lockout), which is what the row's hint now
/// says rather than leaving an author to guess). [TestEditorPanel] edits
/// exactly these five plus the questions themselves; nothing else on this
/// screen is a sixth option dressed up as one.
///
/// ── The server's sentence, never this client's own wording ─────────────
///
/// `TestsController::rules()` bounds every field server-side, and two rules
/// beyond simple bounds are enforced by the CONTROLLER, not the validator:
/// every question needs exactly one correct choice
/// (`assertExactlyOneCorrectPerQuestion`), and `requires_guide: true` with no
/// `guide_id` is refused (`assertGuideGateHasATarget`). Neither sentence is
/// retyped here — [TestEditorPanel._save] shows `response.reason` verbatim,
/// the same field this client shows every other refusal through
/// (`ApiResponse.reason`, api_client.dart).
class TestBuilder extends StatefulWidget {
  const TestBuilder({
    super.key,
    required this.api,
    required this.session,
    required this.testId,
    this.onLeave,
  });

  final ApiClient api;
  final Session session;
  final String testId;

  /// Pops the route by default — set by `TrainingSettingsTab._openTest`
  /// (training_screen.dart) to pop the route IT pushed rather than falling
  /// through to [Navigator.maybePop], the same `onLeave` shape
  /// `GuidePlayer`/`TestPlayer` already use for the routes they push.
  final VoidCallback? onLeave;

  @override
  State<TestBuilder> createState() => _TestBuilderState();
}

class _TestBuilderState extends State<TestBuilder> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _test;

  /// Every guide this test could gate on — fetched once, only here, because
  /// only the standalone screen needs a picker at all: [GuideBuilder] embeds
  /// [TestEditorPanel] with `guideIdFixed` already set to the guide it is
  /// itself editing, and a test can only ever gate on the one guide it
  /// belongs beside.
  List<Map<String, dynamic>> _guides = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _test == null;
      _error = null;
    });

    final response = await widget.api.get('/training/tests/${widget.testId}');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final guidesResponse = await widget.api.get('/training/guides', {'per_page': '200'});
    if (!mounted) return;

    setState(() {
      _loading = false;
      _test = Map<String, dynamic>.from(response.body['data'] as Map);
      _guides = guidesResponse.ok ? _mapList(guidesResponse.body['data']) : const [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = _test?['title'] as String? ?? 'Test';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                TextButton.icon(
                  onPressed: widget.onLeave ?? () => Navigator.maybePop(context),
                  icon: const Icon(Icons.arrow_back, size: 18),
                  label: const Text('Leave'),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    title,
                    style: theme.textTheme.titleMedium,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _test == null
                ? const SizedBox.shrink()
                : SingleChildScrollView(
                    padding: const EdgeInsets.all(16),
                    child: TestEditorPanel(
                      key: const Key('training.test-editor'),
                      api: widget.api,
                      testId: widget.testId,
                      test: _test!,
                      availableGuides: _guides,
                      onSaved: (updated) => setState(() => _test = updated),
                    ),
                  ),
          ),
        ),
      ],
    );
  }
}

/// The four options, the question editor, and Save — used both by
/// [TestBuilder] standalone and embedded in [GuideBuilder]'s own "Its test"
/// column (guide_builder.dart).
///
/// Purely a CONTROLLED widget over data its caller already loaded: it takes
/// [test] already fetched and never fetches its own copy, which is what lets
/// [GuideBuilder] embed it without a second `AsyncBody`/spinner fighting the
/// page's own — [GuideBuilder] loads the guide's test as part of its own
/// single load and hands the result straight in.
class TestEditorPanel extends StatefulWidget {
  const TestEditorPanel({
    super.key,
    required this.api,
    required this.testId,
    required this.test,
    this.guideIdFixed,
    this.availableGuides = const [],
    this.onSaved,
  });

  final ApiClient api;
  final String testId;
  final Map<String, dynamic> test;

  /// Set only by [GuideBuilder]: the guide this test is already filed
  /// beside, which the guide gate can only ever point at. Non-null hides the
  /// guide picker — there is nothing to pick.
  final String? guideIdFixed;

  /// Only consulted when [guideIdFixed] is null.
  final List<Map<String, dynamic>> availableGuides;

  final ValueChanged<Map<String, dynamic>>? onSaved;

  @override
  State<TestEditorPanel> createState() => _TestEditorPanelState();
}

class _TestEditorPanelState extends State<TestEditorPanel> {
  late TextEditingController _title;
  late TextEditingController _passMark;
  late TextEditingController _maxAttempts;
  late TextEditingController _validForDays;
  late bool _requiresGuide;
  late bool _countAttemptsAfterPass;
  String? _guideId;

  late List<Map<String, dynamic>> _questions;

  bool _saving = false;
  String? _saveError;

  /// A per-question/per-choice CLIENT-SIDE id, present on every map in
  /// [_questions] under `_localId` and never sent to the server (`_save`
  /// builds a fresh map with only the four server-known fields per
  /// question/choice). Exists solely so [_QuestionEditorCard]'s text-field
  /// keys can name the ITEM rather than its current list position.
  ///
  /// ── Why list index cannot be the key ────────────────────────────────
  ///
  /// `TextFormField`'s `initialValue` seeds its internal editing state only
  /// ONCE, on the element's first build — a later rebuild with a different
  /// `initialValue` but the SAME `Key` does not reset it, because Flutter's
  /// reconciliation treats same-key-same-type as "this is still the same
  /// field, keep its state." Deleting question 2 of [ALPHA, BRAVO, CHARLIE]
  /// shifts CHARLIE into index 1's slot, but the field keyed
  /// `training.question-1-prompt` keeps showing BRAVO's already-typed text —
  /// so a save at that point overwrites CHARLIE's prompt with text the
  /// author wrote while looking at BRAVO's. A stable id survives the
  /// deletion attached to the item it was minted for, so the key for
  /// whatever is now at index 1 changes, forcing Flutter to tear down the
  /// old field (and its stale internal state) and mount a fresh one seeded
  /// from the right data. See `_ScreenEditor`'s own key in guide_builder.dart
  /// for the same fix applied to a screen's body/title fields.
  int _nextLocalId = 0;

  String _newLocalId() => 'local-${_nextLocalId++}';

  @override
  void initState() {
    super.initState();
    final test = widget.test;

    _title = TextEditingController(text: test['title'] as String? ?? '');
    _passMark = TextEditingController(text: '${test['pass_mark'] ?? 80}');
    _maxAttempts = TextEditingController(text: test['max_attempts']?.toString() ?? '');
    _validForDays = TextEditingController(text: test['valid_for_days']?.toString() ?? '');
    _requiresGuide = test['requires_guide'] == true;
    _countAttemptsAfterPass = test['count_attempts_after_pass'] == true;
    _guideId = widget.guideIdFixed ?? test['guide_id'] as String?;

    _questions = (test['questions'] as List? ?? const [])
        .whereType<Map>()
        .map(
          (q) => {
            '_localId': _newLocalId(),
            'prompt': q['prompt'] as String? ?? '',
            'choices': (q['choices'] as List? ?? const [])
                .whereType<Map>()
                .map(
                  (c) => {
                    '_localId': _newLocalId(),
                    'text': c['text'] as String? ?? '',
                    'correct': c['correct'] == true,
                  },
                )
                .toList(),
          },
        )
        .toList();
  }

  /// The one combination on this screen that can permanently lock a learner
  /// out, said where it is chosen rather than discovered by the person it
  /// happens to: every retake counted + a cap + an expiry means a learner who
  /// spends the cap can never certify again, and nothing in the product
  /// removes a past attempt, so no admin can undo it per learner.
  ///
  /// The behaviour is the owner's decided call (2026-09-09, told the
  /// consequence plainly), so this warns and does not refuse — an author who
  /// wants exactly this still gets it. It is only shown for the ON setting:
  /// with the switch off the cap counts failures since the last pass, which
  /// has no such dead end.
  ///
  /// A [ListenableBuilder] on the two fields rather than listeners plus
  /// setState — the switch already rebuilds through setState, and the two
  /// numbers are the only other inputs the condition reads.
  Widget _lockoutWarning() {
    return ListenableBuilder(
      listenable: Listenable.merge([_maxAttempts, _validForDays]),
      builder: (context, _) {
        if (!_countAttemptsAfterPass || _parseInt(_maxAttempts.text) == null || _parseInt(_validForDays.text) == null) {
          return const SizedBox.shrink();
        }

        final theme = Theme.of(context);

        return Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Text(
            'With all three set, a learner who uses up the retakes can never pass '
            'this test again: their pass runs out, and every attempt they have '
            'already made still counts. Nothing removes a past attempt.',
            key: const Key('training.option-lockout-warning'),
            style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
          ),
        );
      },
    );
  }

  @override
  void dispose() {
    _title.dispose();
    _passMark.dispose();
    _maxAttempts.dispose();
    _validForDays.dispose();
    super.dispose();
  }

  void _addQuestion() {
    setState(() {
      _questions = [
        ..._questions,
        {
          '_localId': _newLocalId(),
          'prompt': '',
          'choices': [
            {'_localId': _newLocalId(), 'text': '', 'correct': false},
            {'_localId': _newLocalId(), 'text': '', 'correct': false},
          ],
        },
      ];
    });
  }

  void _removeQuestion(int index) => setState(() => _questions.removeAt(index));

  void _addChoice(int qIndex) => setState(() {
    (_questions[qIndex]['choices'] as List).add({
      '_localId': _newLocalId(),
      'text': '',
      'correct': false,
    });
  });

  void _removeChoice(int qIndex, int cIndex) =>
      setState(() => (_questions[qIndex]['choices'] as List).removeAt(cIndex));

  void _selectCorrect(int qIndex, int cIndex) => setState(() {
    final choices = _questions[qIndex]['choices'] as List;
    for (var i = 0; i < choices.length; i++) {
      (choices[i] as Map)['correct'] = i == cIndex;
    }
  });

  int? _parseInt(String text) => text.trim().isEmpty ? null : int.tryParse(text.trim());

  // Tests are created as drafts; without this nobody could ever take one.
  late String _status = widget.test['status'] as String? ?? 'draft';

  Future<void> _togglePublish() async {
    final next = _status == 'published' ? 'draft' : 'published';
    final response = await widget.api.patch('/training/tests/${widget.testId}', {'status': next});
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _saveError = response.reason);
      return;
    }
    setState(() => _status = next);
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _saveError = null;
    });

    final body = <String, dynamic>{
      'title': _title.text,
      'requires_guide': _requiresGuide,
      'guide_id': widget.guideIdFixed ?? _guideId,
      'pass_mark': _parseInt(_passMark.text) ?? (widget.test['pass_mark'] as num?)?.toInt() ?? 80,
      'max_attempts': _parseInt(_maxAttempts.text),
      'valid_for_days': _parseInt(_validForDays.text),
      'count_attempts_after_pass': _countAttemptsAfterPass,
      'questions': [
        for (final q in _questions)
          {
            'prompt': q['prompt'],
            'choices': [
              for (final c in (q['choices'] as List)) {'text': c['text'], 'correct': c['correct'] == true},
            ],
          },
      ],
    };

    final response = await widget.api.patch('/training/tests/${widget.testId}', body);
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // The server's own sentence, shown verbatim — see this file's own
        // docblock, "The server's sentence, never this client's own
        // wording". `response.reason` is `assertExactlyOneCorrectPerQuestion`
        // or `assertGuideGateHasATarget`'s exact 422 text when either fires,
        // and every other validation failure's field-level message
        // otherwise.
        _saveError = response.reason;
      });
      return;
    }

    setState(() => _saving = false);
    widget.onSaved?.call(Map<String, dynamic>.from(response.body['data'] as Map));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      key: const Key('training.test-editor-panel'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Title', style: theme.textTheme.labelMedium),
        const SizedBox(height: 4),
        TextField(
          key: const Key('training.option-title'),
          controller: _title,
          decoration: const InputDecoration(isDense: true, border: OutlineInputBorder()),
        ),
        const SizedBox(height: 18),
        Text('Questions', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        for (var q = 0; q < _questions.length; q++) ...[
          _QuestionEditorCard(
            index: q,
            questionId: _questions[q]['_localId'] as String,
            question: _questions[q],
            onPromptChanged: (text) => setState(() => _questions[q]['prompt'] = text),
            onSelectCorrect: (c) => _selectCorrect(q, c),
            onChoiceTextChanged: (c, text) =>
                setState(() => (_questions[q]['choices'] as List)[c]['text'] = text),
            onAddChoice: () => _addChoice(q),
            onRemoveChoice: (c) => _removeChoice(q, c),
            onRemoveQuestion: () => _removeQuestion(q),
          ),
          const SizedBox(height: 10),
        ],
        OutlinedButton.icon(
          key: const Key('training.add-question'),
          onPressed: _addQuestion,
          icon: const Icon(Icons.add, size: 16),
          label: const Text('Add question'),
        ),
        const SizedBox(height: 20),
        Divider(color: EzBiziTheme.edge(context)),
        const SizedBox(height: 8),
        _OptionRow(
          label: 'Must finish the guide before the test',
          hint: 'The test unlocks only once its guide is finished',
          trailing: Switch(
            key: const Key('training.option-requires-guide'),
            value: _requiresGuide,
            onChanged: (value) => setState(() => _requiresGuide = value),
          ),
        ),
        if (widget.guideIdFixed == null)
          _OptionRow(
            label: 'Which guide',
            hint: 'Only used while the toggle above is on',
            // No `SizedBox` fixing this to a pixel width any more (review
            // finding — see `_OptionRow.trailingWidth`'s own docblock).
            // `isExpanded: true` is required, not cosmetic: without it,
            // `DropdownButtonFormField` sizes its closed-state display from
            // the WIDEST item's own intrinsic content width — measured via
            // an internal off-screen layout that ignores whatever width its
            // parent actually gave it — so a long guide title overflows
            // that internal layout even while sitting inside a correctly
            // constrained parent. `isExpanded: true` makes the button fill
            // whatever width it is given instead (200px at a wide layout,
            // the full row at a narrow one) and lets each item's own
            // `overflow: TextOverflow.ellipsis` (below) actually take
            // effect, which is what stops a long title from being the
            // thing that overflows once the button itself no longer does.
            trailingWidth: 200,
            trailing: SearchPickerField<String?>(
                        key: const Key('training.option-guide-id'),
                        value: _guideId,
                        border: OutlineInputBorder(),
                        isDense: true,
                        options: [
                const SearchOption(value: null, label: 'None'),
                for (final guide in widget.availableGuides)
                  SearchOption(value: guide['id'] as String?, label: guide['title'] as String? ?? ''),
              ],
                        onChanged: (value) => setState(() => _guideId = value),
                      ),
          ),
        _OptionRow(
          label: 'Pass mark',
          hint: 'Percent of questions right',
          trailing: _NumberField(key: const Key('training.option-pass-mark'), controller: _passMark),
        ),
        _OptionRow(
          label: 'Retakes allowed',
          hint: 'Blank means as many as they need',
          trailing: _NumberField(key: const Key('training.option-max-attempts'), controller: _maxAttempts),
        ),
        _OptionRow(
          label: 'A pass runs out after',
          hint: 'Then it reappears in their Learn tab as a retake',
          trailing: _NumberField(
            key: const Key('training.option-valid-for-days'),
            controller: _validForDays,
            suffix: 'days',
          ),
        ),
        _OptionRow(
          label: 'Count every retake, even a pass',
          hint: 'Off counts only failures, and only since the last pass',
          trailing: Switch(
            key: const Key('training.option-count-attempts-after-pass'),
            value: _countAttemptsAfterPass,
            onChanged: (value) => setState(() => _countAttemptsAfterPass = value),
          ),
        ),
        _lockoutWarning(),
        const SizedBox(height: 14),
        if (_saveError != null) ...[ErrorNote(_saveError!), const SizedBox(height: 12)],
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ElevatedButton(
              key: const Key('training.save-test'),
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving…' : 'Save'),
            ),
            Tag(_status == 'published' ? 'Published' : 'Draft — nobody sees it yet'),
            OutlinedButton(
              key: const Key('training.publish-test'),
              onPressed: _togglePublish,
              child: Text(_status == 'published' ? 'Unpublish' : 'Publish'),
            ),
          ],
        ),
      ],
    );
  }
}

/// One option row — a label (with a muted explanatory line under it, the
/// mockup's own `.opt .lab i`) and a trailing control. `.opt`'s own shape
/// (training-layouts.html), redrawn in widgets rather than CSS.
///
/// ── Below [_narrowBelow] the row stacks, and [trailingWidth] stops
/// applying (review finding) ─────────────────────────────────────────────
///
/// At a wide layout the label and [trailing] sit side by side — [trailing]
/// pinned to [trailingWidth] when the caller passes one (the "Which guide"
/// picker's own 200px, matching its old fixed `SizedBox`). Below
/// [_narrowBelow] — the same 600 `GuidePlayer.splitAbove`/
/// `InventoryScreen.splitAbove` already use as this module's own narrow-
/// layout floor — the row becomes a [Column] instead: label and hint on
/// top, [trailing] given the ROW's own full width underneath, and
/// [trailingWidth] is never applied. A fixed pixel width that is safe at a
/// wide layout is exactly what overflowed the "Which guide" picker at
/// 375px — the label+hint column had nowhere near enough of the remaining
/// ~143px to sit beside a 200px-wide dropdown, and the dropdown's own
/// closed-state layout (see that call site's docblock) overflowed within
/// itself besides. No fixed width may survive at that size; stacking is
/// what removes it rather than shrinking it further.
class _OptionRow extends StatelessWidget {
  const _OptionRow({required this.label, required this.hint, required this.trailing, this.trailingWidth});

  final String label;
  final String hint;
  final Widget trailing;

  /// Applied to [trailing] only at a wide layout (see class docblock).
  /// `null` (every row but the guide picker) leaves [trailing] unsized —
  /// safe already, since [Switch] and `_NumberField` are both naturally
  /// small and were never the overflow risk here.
  final double? trailingWidth;

  static const double _narrowBelow = 600;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Two lines when stacked, one when there is a row's worth of width.
    //
    // At 375px this column gets 343px, and a hint has to say a whole rule in
    // it. Measured against this theme, three of the six hints on this screen
    // are 48 characters and need ~595px — so on a phone they all truncated
    // mid-clause, and the retake one truncated into "Off only counts
    // failures", which is precisely the failures-only rule the per-cycle
    // ruling exists to reject. A hint that ellipsises into a DIFFERENT rule is
    // worse than one that wraps.
    //
    // So the fix is the row, not the wording: shortening one hint would have
    // left the two siblings cut in the same place, and the honest sentence for
    // this option does not fit 343px in one line at any wording I could find.
    Widget labelColumn(int lines) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodyMedium, maxLines: lines, overflow: TextOverflow.ellipsis),
        Text(
          hint,
          style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          maxLines: lines,
          overflow: TextOverflow.ellipsis,
        ),
      ],
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 9),
      child: LayoutBuilder(
        builder: (context, constraints) {
          if (constraints.maxWidth < _narrowBelow) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [labelColumn(2), const SizedBox(height: 8), trailing],
            );
          }
          return Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Expanded(child: labelColumn(1)),
              const SizedBox(width: 10),
              trailingWidth == null ? trailing : SizedBox(width: trailingWidth, child: trailing),
            ],
          );
        },
      ),
    );
  }
}

/// A digits-only field for the three counts `_save` treats a blank as a
/// LEGITIMATE value for (unlimited retakes, a pass that never expires, or
/// "keep the stored pass mark") — never a parse failure standing in for one.
///
/// `int.tryParse` alone cannot tell "the author left this blank on purpose"
/// from "the author typed `3o` and never noticed it wasn't a number": both
/// come back `null`, and before this fix that null was read as the blank
/// case every time — a green save with `3o` still sitting in the box, and
/// `max_attempts` sent as unlimited. [FilteringTextInputFormatter.digitsOnly]
/// makes that ambiguity unreachable rather than trying to word a refusal for
/// it: a letter is dropped the instant it is typed, so whatever the box
/// shows IS always either digits or empty, and `_parseInt` (which still only
/// treats a truly empty box as the blank case) is never asked to guess.
class _NumberField extends StatelessWidget {
  const _NumberField({super.key, required this.controller, this.suffix});

  final TextEditingController controller;
  final String? suffix;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 84,
      child: TextField(
        controller: controller,
        keyboardType: TextInputType.number,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        textAlign: TextAlign.center,
        decoration: InputDecoration(isDense: true, border: const OutlineInputBorder(), suffixText: suffix),
      ),
    );
  }
}

/// One question: its prompt, its choices (a radio each, the same
/// single-right-answer shape `test_player.dart`'s own `_QuestionCard`
/// shows a learner), and the authoring-only controls a learner never sees —
/// add/remove a choice, remove the question. Nothing here enforces "exactly
/// one correct" itself: leaving every choice unselected (a freshly [_addQuestion]ed
/// question, in particular) is exactly what lets a save reach the server's
/// own refusal — see this file's own docblock.
class _QuestionEditorCard extends StatelessWidget {
  const _QuestionEditorCard({
    required this.index,
    required this.questionId,
    required this.question,
    required this.onPromptChanged,
    required this.onSelectCorrect,
    required this.onChoiceTextChanged,
    required this.onAddChoice,
    required this.onRemoveChoice,
    required this.onRemoveQuestion,
  });

  final int index;

  /// This question's own stable id (`_TestEditorPanelState._newLocalId`),
  /// never its list position — see that field's own docblock for why the
  /// prompt/choice text fields below are keyed by this rather than [index].
  final String questionId;
  final Map<String, dynamic> question;
  final ValueChanged<String> onPromptChanged;
  final ValueChanged<int> onSelectCorrect;
  final void Function(int choiceIndex, String text) onChoiceTextChanged;
  final VoidCallback onAddChoice;
  final ValueChanged<int> onRemoveChoice;
  final VoidCallback onRemoveQuestion;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final choices = question['choices'] as List;
    final correctIndex = choices.indexWhere((c) => (c as Map)['correct'] == true);

    return Container(
      key: ValueKey('training.question-$index'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: TextFormField(
                  key: ValueKey('training.question-$questionId-prompt'),
                  initialValue: question['prompt'] as String,
                  onChanged: onPromptChanged,
                  decoration: InputDecoration(
                    isDense: true,
                    border: const OutlineInputBorder(),
                    hintText: 'Question ${index + 1}',
                  ),
                ),
              ),
              IconButton(
                key: ValueKey('training.remove-question-$index'),
                onPressed: onRemoveQuestion,
                icon: const Icon(Icons.delete_outline, size: 18),
                tooltip: 'Remove question',
              ),
            ],
          ),
          const SizedBox(height: 6),
          for (var c = 0; c < choices.length; c++)
            Row(
              children: [
                Radio<int>(
                  key: ValueKey('training.question-$index-choice-$c-correct'),
                  value: c,
                  // ignore: deprecated_member_use
                  groupValue: correctIndex == -1 ? null : correctIndex,
                  // ignore: deprecated_member_use
                  onChanged: (_) => onSelectCorrect(c),
                ),
                Expanded(
                  child: TextFormField(
                    key: ValueKey(
                      'training.question-$questionId-choice-'
                      '${(choices[c] as Map)['_localId']}-text',
                    ),
                    initialValue: (choices[c] as Map)['text'] as String,
                    onChanged: (text) => onChoiceTextChanged(c, text),
                    decoration: const InputDecoration(isDense: true, border: OutlineInputBorder()),
                  ),
                ),
                IconButton(
                  key: ValueKey('training.remove-choice-$index-$c'),
                  onPressed: choices.length > 2 ? () => onRemoveChoice(c) : null,
                  icon: const Icon(Icons.close, size: 16),
                  tooltip: 'Remove choice',
                ),
              ],
            ),
          TextButton.icon(
            key: ValueKey('training.add-choice-$index'),
            onPressed: onAddChoice,
            icon: const Icon(Icons.add, size: 14),
            label: const Text('Add choice'),
          ),
        ],
      ),
    );
  }
}

List<Map<String, dynamic>> _mapList(dynamic raw) {
  if (raw is! List) return const [];
  return [
    for (final entry in raw)
      if (entry is Map) Map<String, dynamic>.from(entry),
  ];
}
