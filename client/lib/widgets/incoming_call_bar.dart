import 'package:flutter/material.dart';

import '../core/phone_call_service.dart';
import '../core/phone_numbers.dart';

/// The call, on top of whatever you were doing.
///
/// ── Why it is in the shell and not on the Dial pad ───────────────
///
/// The accept/decline banner used to be drawn by `CallTab`, which meant it
/// existed only while Communication › Phone was the open module. Somebody
/// writing up a job got no banner, and — worse, and the reason the service
/// moved too — no ring either, because the whole softphone went down with the
/// tab. This is the visible half of that fix.
///
/// ── A strip, never a modal ───────────────────────────────────────
///
/// House rule, and right here for its own reason: a ringing phone must not
/// take the app away. You answer while staying on the estimate you were
/// halfway through typing, and the work underneath keeps its state. The strip
/// sits in the same Column as the update notice, above the module.
///
/// ── Why it offers a way to the Dial screen ───────────────────────
///
/// Answering is not the whole job. The customer, the notes, the tag when the
/// call ends — those live on the desk, and somebody who answered from Fleet
/// Management needs one tap to get there rather than a hunt through the rail.
class IncomingCallBar extends StatelessWidget {
  const IncomingCallBar({super.key, required this.onOpenDial});

  /// Takes the reader to Communication › Phone › Dial.
  final VoidCallback onOpenDial;

  @override
  Widget build(BuildContext context) {
    final service = PhoneCallService.current;

    return AnimatedBuilder(
      animation: service,
      builder: (context, _) {
        final ringing = service.incomingFrom;

        if (ringing != null) return _ringing(context, service, ringing);
        if (service.onCall) return _connected(context, service);

        return const SizedBox.shrink();
      },
    );
  }

  Widget _ringing(
    BuildContext context,
    PhoneCallService service,
    String from,
  ) {
    final theme = Theme.of(context);

    return Material(
      key: const Key('incoming-call-bar'),
      color: theme.colorScheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 12,
          runSpacing: 8,
          children: [
            const Icon(Icons.phone_in_talk_outlined, size: 20),
            Text(
              // The number, not "Incoming call". Who is ringing is the thing
              // that decides whether you pick up.
              'Incoming call from ${formatPhoneNumber(from)}',
              style: theme.textTheme.titleSmall,
            ),
            FilledButton(
              key: const Key('incoming-call-answer'),
              onPressed: () {
                service.accept();
                onOpenDial();
              },
              child: const Text('Answer'),
            ),
            OutlinedButton(
              key: const Key('incoming-call-decline'),
              onPressed: service.decline,
              child: const Text('Decline'),
            ),
          ],
        ),
      ),
    );
  }

  /// Still on the call, wherever you have wandered to since answering.
  ///
  /// Without this, answering from another module left no trace anywhere: the
  /// audio was live and the only screen that said so was the one you were not
  /// on, so the way to hang up was to go and find it.
  Widget _connected(BuildContext context, PhoneCallService service) {
    final theme = Theme.of(context);

    return Material(
      key: const Key('on-call-bar'),
      color: theme.colorScheme.secondaryContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 12,
          runSpacing: 8,
          children: [
            const Icon(Icons.phone_in_talk, size: 20),
            Text('On a call', style: theme.textTheme.titleSmall),
            TextButton(
              key: const Key('on-call-open-dial'),
              onPressed: onOpenDial,
              child: const Text('Go to the phone'),
            ),
            OutlinedButton(
              key: const Key('on-call-hang-up'),
              onPressed: service.hangUp,
              child: const Text('Hang up'),
            ),
          ],
        ),
      ),
    );
  }
}
