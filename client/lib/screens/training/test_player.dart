import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// The test-taking screen (task-13 brief) — what `GuidePlayer`'s own "Test"
/// rail entry (guide_player.dart) opens once its gate is satisfied, and what
/// a standalone test card in Learn's shelves (learn_shelves.dart) opens
/// directly.
///
/// `GET /training/learn/tests/{id}` (`LearnController::showTest`) hands back
/// a STRIPPED test — `questions[].choices[]` never carries `correct`
/// (`LearnerView::stripAnswerKey`) — so nothing here can grade locally. An
/// attempt is graded server-side against the STORED test
/// (`TestGrader::grade`) by `POST .../attempts`, which answers only
/// `{score, passed, expires_on}`.
///
/// ── The guide gate, read the same way the rail reads it ────────────
///
/// A test with `requires_guide: true` and a real `guide_id` cannot be
/// submitted until that guide is finished (`LearnController::submit`,
/// `Refusal::trainingGuideNotFinished`). The show endpoint does not say
/// whether THIS learner has finished it, so [_load] makes the SAME
/// `GET /training/learn` call `GuidePlayer` makes and reads the guide's own
/// `completed_at` off the identical `assigned`/`library` union — never a
/// second, hand-rolled definition of "finished". While the gate is unmet
/// there is no Submit control at all (§4: a control that is offered must be
/// a control that works), only the sentence explaining why.
///
/// ── The retake cap is a best-effort gate, not a promise ─────────────
///
/// `max_attempts` is a real cap the server enforces
/// (`LearnController::submit`, a locked read plus a count), but nothing this
/// LEARNER-facing read exposes says how many attempts have already been
/// made — only `last_taken_at` (at least one, or none). [_capReached] can
/// only prove the cap is reached from what it can actually count: attempts
/// submitted in THIS sitting, plus one known prior attempt when
/// `last_taken_at` is set. That is EXACT for `max_attempts == 1` and a safe
/// UNDER-count otherwise — it never disables Submit the server would allow,
/// and in the case it cannot see (several prior attempts in an earlier
/// sitting, `max_attempts` above 1) it can still let one submission through
/// that the server refuses. That refusal is shown verbatim as
/// [_submitError] (`response.reason`) rather than invented, exactly like
/// every other refusal this client shows — closing the gap for good needs an
/// attempts-taken count in a response this endpoint does not send today.
class TestPlayer extends StatefulWidget {
  const TestPlayer({
    super.key,
    required this.api,
    required this.session,
    required this.testId,
    required this.onLeave,
  });

  final ApiClient api;
  final Session session;
  final String testId;
  final VoidCallback onLeave;

  @override
  State<TestPlayer> createState() => _TestPlayerState();
}

class _TestPlayerState extends State<TestPlayer> {
  bool _loading = true;
  String? _error;

  Map<String, dynamic>? _test;
  List<Map<String, dynamic>> _questions = const [];
  List<int?> _answers = const [];

  bool _guideFinished = true;
  int? _guideScreenCount;

  int _sessionAttempts = 0;
  bool _submitting = false;
  String? _submitError;
  Map<String, dynamic>? _result;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/training/learn/tests/${widget.testId}');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final data = Map<String, dynamic>.from(response.body['data'] as Map);
    final requiresGuide = data['requires_guide'] == true;
    final guideId = data['guide_id'] as String?;

    var guideFinished = true;
    int? guideScreenCount;

    if (requiresGuide && guideId != null) {
      final learn = await widget.api.get('/training/learn');
      if (!mounted) return;

      if (learn.ok) {
        final entries = [
          ..._rows(learn.body['assigned']),
          ..._rows(learn.body['library']),
        ];
        final guide = entries.firstWhere(
          (e) => e['type'] == 'guide' && e['id'] == guideId,
          orElse: () => const <String, dynamic>{},
        );
        guideFinished = guide.isNotEmpty && guide['completed_at'] != null;
        guideScreenCount = guide['screen_count'] is num ? (guide['screen_count'] as num).toInt() : null;
      } else {
        // Cannot confirm either way — fail closed rather than offer a
        // Submit the server may refuse (§4).
        guideFinished = false;
      }
    }

    final questions = (data['questions'] as List? ?? const [])
        .whereType<Map>()
        .map((q) => Map<String, dynamic>.from(q))
        .toList();

    setState(() {
      _loading = false;
      _test = data;
      _questions = questions;
      _answers = List<int?>.filled(questions.length, null);
      _guideFinished = guideFinished;
      _guideScreenCount = guideScreenCount;
    });
  }

  bool get _capReached {
    final max = _test?['max_attempts'];
    if (max is! num) return false;

    final knownPrior = _test?['last_taken_at'] != null ? 1 : 0;
    return (knownPrior + _sessionAttempts) >= max.toInt();
  }

  Future<void> _submit() async {
    setState(() {
      _submitting = true;
      _submitError = null;
    });

    final response = await widget.api.post(
      '/training/learn/tests/${widget.testId}/attempts',
      {'answers': _answers},
    );
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _submitting = false;
        _submitError = response.reason;
      });
      return;
    }

    setState(() {
      _submitting = false;
      _sessionAttempts++;
      _result = {
        'score': response.body['score'],
        'passed': response.body['passed'],
        'expires_on': response.body['expires_on'],
      };
    });
  }

  void _retake() {
    setState(() {
      _result = null;
      _submitError = null;
      _answers = List<int?>.filled(_questions.length, null);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = _test?['title'] as String? ?? '';

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
                  onPressed: widget.onLeave,
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
            child: _body(context),
          ),
        ),
      ],
    );
  }

  Widget _body(BuildContext context) {
    final theme = Theme.of(context);

    if (!_guideFinished) {
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              _guideScreenCount != null
                  ? 'Unlocks when all $_guideScreenCount are seen'
                  : 'This test requires finishing its guide first, and the '
                        'guide has not been finished yet.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
          ),
        ),
      );
    }

    final result = _result;
    if (result != null) {
      return _ResultPanel(
        result: result,
        session: widget.session,
        capReached: _capReached,
        onRetake: _capReached ? null : _retake,
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        for (var i = 0; i < _questions.length; i++) ...[
          _QuestionCard(
            index: i,
            question: _questions[i],
            selected: _answers[i],
            onSelect: (choice) => setState(() => _answers[i] = choice),
          ),
          const SizedBox(height: 12),
        ],
        if (_submitError != null) ...[
          ErrorNote(_submitError!),
          const SizedBox(height: 12),
        ],
        if (_capReached)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'This test allows at most ${(_test?['max_attempts'] as num).toInt()} '
              'attempts, and that many have already been made.',
              style: theme.textTheme.bodyMedium,
            ),
          ),
        ElevatedButton(
          key: const Key('training.submit-test'),
          onPressed: (_submitting || _capReached) ? null : _submit,
          child: Text(_submitting ? 'Submitting…' : 'Submit'),
        ),
      ],
    );
  }
}

List<Map<String, dynamic>> _rows(Object? raw) => raw is List
    ? raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : const [];

class _QuestionCard extends StatelessWidget {
  const _QuestionCard({
    required this.index,
    required this.question,
    required this.selected,
    required this.onSelect,
  });

  final int index;
  final Map<String, dynamic> question;
  final int? selected;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final prompt = question['prompt'] as String? ?? '';
    final choices = (question['choices'] as List? ?? const [])
        .whereType<Map>()
        .map((c) => c['text'] as String? ?? '')
        .toList();

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${index + 1}. $prompt', style: theme.textTheme.titleSmall),
          for (var c = 0; c < choices.length; c++)
            RadioListTile<int>(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text(choices[c]),
              value: c,
              // ignore: deprecated_member_use
              groupValue: selected,
              // ignore: deprecated_member_use
              onChanged: (value) => onSelect(value!),
            ),
        ],
      ),
    );
  }
}

/// The score the server graded, shown as it came back — never a client-side
/// tally, since the answer key never reached this screen to tally against.
class _ResultPanel extends StatelessWidget {
  const _ResultPanel({
    required this.result,
    required this.session,
    required this.capReached,
    required this.onRetake,
  });

  final Map<String, dynamic> result;
  final Session session;
  final bool capReached;
  final VoidCallback? onRetake;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final score = result['score'] is num ? (result['score'] as num).toInt() : 0;
    final passed = result['passed'] == true;
    final expiresOn = result['expires_on'] as String?;
    final expiresDay = expiresOn == null ? null : DateTime.tryParse(expiresOn);

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Score: $score%', style: theme.textTheme.headlineSmall),
              const SizedBox(height: 6),
              Text(passed ? 'Passed' : 'Not a pass', style: theme.textTheme.titleMedium),
              if (expiresDay != null) ...[
                const SizedBox(height: 6),
                Text(
                  'Valid until ${session.me!.display.formatDate(expiresDay)}',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
              if (!capReached && onRetake != null) ...[
                const SizedBox(height: 16),
                OutlinedButton(onPressed: onRetake, child: const Text('Take again')),
              ],
              if (capReached) ...[
                const SizedBox(height: 16),
                Text(
                  'That was the last attempt this test allows.',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
