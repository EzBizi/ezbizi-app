import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
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
  AlertPopups(this.api, {this.onDismissed});

  final ApiClient api;

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
                              Icons.campaign_outlined,
                              size: 15,
                              color: caution.ink,
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              alert['sender'] == null
                                  ? 'Alert'
                                  : 'Alert from ${alert['sender']}',
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
                      Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton(
                          key: const Key('alert-dismiss'),
                          onPressed: () =>
                              unawaited(popups.dismiss('${alert['id']}')),
                          child: const Text('Dismiss'),
                        ),
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
