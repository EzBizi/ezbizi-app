import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/client_platform.dart';
import '../core/desktop_updater.dart';
import 'get_the_apps.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';

/// Alerts a colleague sent this person from Communication › Alerts (UI queue
/// 65).
///
/// Jose, 2026-10-07: "This sort of alert notifications should be a pop up on
/// the screen, that they can dismiss". Of the drawn layouts he chose "2": the
/// middle of the screen over a dimmed app, nothing else to click until
/// Dismiss, several one after another.
///
/// The one layer in the app that blocks the screen, by his ruling, named in
/// UI-DESIGN-QUEUE 65. A route rather than an overlay entry so that focus and
/// Back stay with it: an overlay would leave the field underneath taking the
/// keystrokes. Only Dismiss closes it.
class AlertPopups {
  AlertPopups(this.api, {this.onDismissed, this.onOpenSuggestion});

  final ApiClient api;

  /// A suggestion's own pop-up (approved, live) opens it.
  final void Function(String id)? onOpenSuggestion;

  /// After the server has heard, so the bell's count catches up.
  final VoidCallback? onDismissed;

  /// What is still to be dismissed, oldest first.
  final queue = ValueNotifier<List<Map<String, dynamic>>>(const []);

  /// Dismissed here before the next poll has heard so, so a poll in between
  /// does not bring one back.
  final _dismissed = <String>{};
  bool _showing = false;

  /// Every open alert, after each poll of the bell.
  void show(
    BuildContext context,
    List<Map<String, dynamic>> open, {
    required DisplayFormats formats,
  }) {
    queue.value = [
      for (final alert in open)
        if (!_dismissed.contains('${alert['id']}')) alert,
    ];
    if (queue.value.isEmpty || _showing) return;

    _showing = true;
    unawaited(
      showGeneralDialog<void>(
        context: context,
        barrierDismissible: false,
        barrierColor: Colors.black.withValues(alpha: 0.32),
        pageBuilder: (context, _, _) => PopScope(
          canPop: false,
          child: _AlertCard(popups: this, formats: formats),
        ),
      ).whenComplete(() => _showing = false),
    );
  }

  /// Answered (UI queue 200): the server closed it with the answer, so there
  /// is no read call to make.
  void answered(String id) {
    _dismissed.add(id);
    queue.value = [
      for (final alert in queue.value)
        if ('${alert['id']}' != id) alert,
    ];
    onDismissed?.call();
  }

  Future<void> dismiss(String id) async {
    _dismissed.add(id);
    queue.value = [
      for (final alert in queue.value)
        if ('${alert['id']}' != id) alert,
    ];
    final response = await api.post('/notifications/$id/read', {});
    // Not recorded, so it comes back with the next poll rather than being
    // lost: dismissed means the server knows.
    if (!response.ok) {
      _dismissed.remove(id);
      return;
    }
    onDismissed?.call();
  }
}

class _AlertCard extends StatelessWidget {
  const _AlertCard({required this.popups, required this.formats});

  final AlertPopups popups;
  final DisplayFormats formats;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final caution = ToneColours.of(context, Tone.caution);

    return ValueListenableBuilder<List<Map<String, dynamic>>>(
      valueListenable: popups.queue,
      builder: (context, alerts, _) {
        // Emptied by Dismiss, or by a poll that found it dismissed elsewhere.
        if (alerts.isEmpty) {
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (context.mounted && ModalRoute.of(context)?.isCurrent == true) {
              Navigator.of(context).pop();
            }
          });
          return const SizedBox.shrink();
        }

        final alert = alerts.first;
        final when = formatListStamp(alert['created_at'], '', formats: formats);
        final question = alert['question'] is Map
            ? Map<String, dynamic>.from(alert['question'] as Map)
            : null;

        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Material(
                key: const Key('alert-popup'),
                color: theme.colorScheme.surface,
                elevation: 8,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(
                    EzBiziTheme.controlRadius,
                  ),
                  side: BorderSide(color: caution.ink.withValues(alpha: 0.35)),
                ),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 18, 20, 16),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          CircleAvatar(
                            radius: 12,
                            backgroundColor: caution.fill,
                            child: Icon(
                              question == null
                                  ? Icons.campaign_outlined
                                  : Icons.help_outline,
                              size: 15,
                              color: caution.ink,
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              [
                                question == null ? 'Alert' : 'Question',
                                if (alert['sender'] != null)
                                  'from ${alert['sender']}',
                              ].join(' '),
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: caution.ink,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          Text(
                            [
                              when,
                              if (alerts.length > 1) '1 of ${alerts.length}',
                            ].where((part) => part.isNotEmpty).join(' · '),
                            key: const Key('alert-popup-when'),
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      Text(
                        '${alert['title'] ?? ''}',
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 16),
                      // A question is answered, or put off with Later, which
                      // is Dismiss: the question stays open in the bell.
                      if (question != null)
                        QuestionAnswer(
                          key: ValueKey('question-${alert['id']}'),
                          api: popups.api,
                          question: question,
                          onAnswered: () => popups.answered('${alert['id']}'),
                          later: TextButton(
                            key: const Key('alert-later'),
                            onPressed: () =>
                                unawaited(popups.dismiss('${alert['id']}')),
                            child: const Text('Later'),
                          ),
                        )
                      else
                        Wrap(
                          alignment: WrapAlignment.end,
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            // Your suggestion was approved / is live (Jose,
                            // 2026-11-17): open it, and update to see it.
                            if (alert['suggestion_id'] case final String id
                                when popups.onOpenSuggestion != null)
                              TextButton(
                                key: const Key('alert-open-suggestion'),
                                onPressed: () {
                                  unawaited(popups.dismiss('${alert['id']}'));
                                  popups.onOpenSuggestion!(id);
                                },
                                child: const Text('Open it'),
                              ),
                            if (alert['update'] == true)
                              OutlinedButton(
                                key: const Key('alert-update'),
                                onPressed: () {
                                  unawaited(popups.dismiss('${alert['id']}'));
                                  unawaited(updateTheApp());
                                },
                                child: const Text('Update'),
                              ),
                            FilledButton(
                              key: const Key('alert-dismiss'),
                              onPressed: () =>
                                  unawaited(popups.dismiss('${alert['id']}')),
                              child: const Text('Dismiss'),
                            ),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// The update the app already has, from a pop-up's Update: the web page
/// reloads, the desktop app checks and installs, a phone opens its store.
Future<void> updateTheApp() async {
  if (ClientPlatform.isWeb) {
    ClientPlatform.reload();
    return;
  }
  final updater = DesktopUpdater.instance;
  if (updater.supported) {
    await updater.checkNow();
    if (updater.available != null) await updater.install();
    return;
  }
  final store = switch (ClientPlatform.name) {
    'ios' => appStoreUrl,
    'android' => googlePlayUrl,
    'macos' => macAppStoreUrl,
    _ => downloadUrl,
  };
  await launchUrl(Uri.parse(store), mode: LaunchMode.externalApplication);
}

/// A question's context, its choices and/or a reply box, and Send answer
/// (Jose, 2026-11-18; UI queue 200). Drawn in the pop-up and in the bell's
/// Questions tab, so an answer reads the same wherever it is given.
///
/// [question] is the server's `{alert_id, context, choices, allow_text}`.
class QuestionAnswer extends StatefulWidget {
  const QuestionAnswer({
    super.key,
    required this.api,
    required this.question,
    required this.onAnswered,
    this.later,
  });

  final ApiClient api;
  final Map<String, dynamic> question;

  /// The server took the answer, or already had one (409).
  final VoidCallback onAnswered;

  /// The pop-up's Later, beside Send answer.
  final Widget? later;

  @override
  State<QuestionAnswer> createState() => _QuestionAnswerState();
}

class _QuestionAnswerState extends State<QuestionAnswer> {
  String? _choice;
  final _text = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  List<String> get _choices => [
    for (final c in (widget.question['choices'] as List? ?? const [])) '$c',
  ];

  bool get _allowText => widget.question['allow_text'] == true;

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final text = _text.text.trim();
    final response = await widget.api.post(
      '/alerts/${widget.question['alert_id']}/reply',
      {'choice': ?_choice, if (text.isNotEmpty) 'text': text},
    );
    if (!mounted) return;
    // 409: answered already, here or on another device. Either way it is done.
    if (response.ok || response.statusCode == 409) {
      widget.onAnswered();
      return;
    }
    setState(() {
      _sending = false;
      _error = response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final context_ = '${widget.question['context'] ?? ''}'.trim();
    final choices = _choices;
    final ready =
        !_sending && (_choice != null || _text.text.trim().isNotEmpty);

    return Column(
      key: const Key('question-answer'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (context_.isNotEmpty) ...[
          Container(
            key: const Key('question-context'),
            padding: const EdgeInsets.fromLTRB(10, 7, 10, 7),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(6),
              border: Border(left: BorderSide(color: scheme.primary, width: 3)),
            ),
            child: Text(context_, style: theme.textTheme.bodySmall),
          ),
          const SizedBox(height: 10),
        ],
        for (final (i, choice) in choices.indexed)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: _Choice(
              key: ValueKey('question-choice-$i'),
              letter: String.fromCharCode(97 + i),
              label: choice,
              picked: _choice == choice,
              onTap: _sending
                  ? null
                  : () => setState(
                      () => _choice = _choice == choice ? null : choice,
                    ),
            ),
          ),
        if (_allowText)
          TextField(
            key: const Key('question-text'),
            controller: _text,
            minLines: 2,
            maxLines: 5,
            maxLength: 1000,
            enabled: !_sending,
            decoration: InputDecoration(
              hintText: choices.isEmpty
                  ? 'Your answer'
                  : 'Anything to add? (optional)',
              border: const OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() {}),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              _error!,
              key: const Key('question-error'),
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
            ),
          ),
        const SizedBox(height: 4),
        Row(
          children: [
            ?widget.later,
            const Spacer(),
            FilledButton(
              key: const Key('question-send'),
              onPressed: ready ? _send : null,
              child: Text(_sending ? 'Sending…' : 'Send answer'),
            ),
          ],
        ),
      ],
    );
  }
}

/// One choice: its letter and words, a full-width target 48 tall.
class _Choice extends StatelessWidget {
  const _Choice({
    super.key,
    required this.letter,
    required this.label,
    required this.picked,
    required this.onTap,
  });

  final String letter;
  final String label;
  final bool picked;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final ink = picked ? scheme.onPrimary : scheme.primary;
    return Semantics(
      selected: picked,
      button: true,
      child: Material(
        color: picked ? scheme.primary : scheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          side: BorderSide(color: picked ? scheme.primary : scheme.outline),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 11,
                    backgroundColor: picked
                        ? scheme.onPrimary
                        : scheme.primaryContainer,
                    child: Text(
                      letter,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: picked
                            ? scheme.primary
                            : scheme.onPrimaryContainer,
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      label,
                      style: TextStyle(color: ink, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// "(b) Google Ads · Search: we use that already", for an answer given.
String questionAnswerLine(Map<String, dynamic> reply, List<dynamic> choices) {
  final choice = reply['choice'] as String?;
  final text = '${reply['text'] ?? ''}'.trim();
  final i = choice == null ? -1 : choices.indexOf(choice);
  final picked = choice == null
      ? null
      : i < 0
      ? choice
      : '(${String.fromCharCode(97 + i)}) $choice';
  return [?picked, if (text.isNotEmpty) text].join(': ');
}
