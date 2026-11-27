import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

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

  Widget _ringing(BuildContext context, PhoneCallService service, String from) {
    final theme = Theme.of(context);

    // THE WHOLE WIDTH. Inside the shell's Column a bare Material shrinks to
    // its Wrap and sat as a centred box; Jose, 2026-09-25: *"make it so that
    // top bar Extends 100% not just centered"*.
    return Material(
      key: const Key('incoming-call-bar'),
      color: theme.colorScheme.primaryContainer,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Wrap(
          alignment: WrapAlignment.center,
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

    // THE WHOLE WIDTH. Inside the shell's Column a bare Material shrinks to
    // its Wrap and sat as a centred box; Jose, 2026-09-25: *"make it so that
    // top bar Extends 100% not just centered"*.
    return Material(
      key: const Key('on-call-bar'),
      color: theme.colorScheme.secondaryContainer,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Wrap(
          alignment: WrapAlignment.center,
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
            // MOVE IT, rather than hang up and ring back.
            //
            // Jose asked for this by name. It sits beside Hang up because the
            // moment somebody wants it is the moment they are standing up,
            // and this bar is the one part of the phone that is on screen in
            // every module — which is where they will be looking.
            _MoveCallButton(service: service),
            _Keypad(service: service),
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

/// "Move to my phone", and what it says when it cannot.
///
/// Its own widget because it is the only part of this bar with state: the
/// request takes a moment, and a button that can be pressed twice would ask
/// Twilio to redirect the same call twice — the second one aimed at a leg that
/// has already moved.
///
/// The failure is shown in place rather than as a snack bar. Somebody who has
/// just pressed this is holding a live call and about to walk away from the
/// screen; "that call has already ended" has to be readable where they are
/// looking, not two seconds ago at the bottom of the window.
class _MoveCallButton extends StatefulWidget {
  const _MoveCallButton({required this.service});

  final PhoneCallService service;

  @override
  State<_MoveCallButton> createState() => _MoveCallButtonState();
}

class _MoveCallButtonState extends State<_MoveCallButton> {
  bool _moving = false;
  String? _error;

  Future<void> _move() async {
    setState(() {
      _moving = true;
      _error = null;
    });

    final reason = await widget.service.transferToMyOtherDevices();
    if (!mounted) return;

    setState(() {
      _moving = false;
      _error = reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_error != null) {
      return Text(
        _error!,
        key: const Key('on-call-move-error'),
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.error,
        ),
      );
    }

    return OutlinedButton.icon(
      key: const Key('on-call-move'),
      icon: const Icon(Icons.phonelink_ring_outlined, size: 18),
      label: Text(_moving ? 'Moving…' : 'Move to my phone'),
      onPressed: _moving ? null : _move,
    );
  }
}

/// Keys pressed on the live call, for a menu at the other end (Jose,
/// 2026-11-11: a call from Meta said "press 1" and there was nothing to
/// press). Opens in place; digits typed on the keyboard work while it is open.
class _Keypad extends StatefulWidget {
  const _Keypad({required this.service});

  final PhoneCallService service;

  @override
  State<_Keypad> createState() => _KeypadState();
}

class _KeypadState extends State<_Keypad> {
  bool _open = false;
  String _typed = '';
  final _focus = FocusNode();

  static const _keys = '123456789*0#';

  @override
  void dispose() {
    _focus.dispose();
    super.dispose();
  }

  void _press(String key) {
    widget.service.sendDigits(key);
    unawaited(HapticFeedback.selectionClick());
    setState(() => _typed += key);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final toggle = TextButton.icon(
      key: const Key('on-call-keypad'),
      onPressed: () {
        setState(() => _open = !_open);
        if (_open) _focus.requestFocus();
      },
      icon: const Icon(Icons.dialpad, size: 18),
      label: Text(_open ? 'Hide keypad' : 'Keypad'),
    );
    if (!_open) return toggle;

    return Focus(
      focusNode: _focus,
      onKeyEvent: (node, event) {
        final key = event.character;
        if (event is KeyDownEvent && key != null && _keys.contains(key)) {
          _press(key);
          return KeyEventResult.handled;
        }
        return KeyEventResult.ignored;
      },
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          toggle,
          Text(
            _typed.isEmpty ? 'Press a key' : _typed,
            key: const Key('on-call-keypad-typed'),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 4),
          SizedBox(
            width: 180,
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final key in _keys.split(''))
                  SizedBox(
                    width: 52,
                    height: 40,
                    child: OutlinedButton(
                      key: Key('on-call-key-$key'),
                      style: OutlinedButton.styleFrom(padding: EdgeInsets.zero),
                      onPressed: () => _press(key),
                      child: Text(key, style: theme.textTheme.titleMedium),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
