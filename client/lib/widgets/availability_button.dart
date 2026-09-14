import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/phone_call_service.dart';

/// Whether you are taking calls, from anywhere in the app.
///
/// ── Why it is in the chrome and not on the Dial screen ───────────
///
/// It used to be a "Do not disturb" button on the Dial pad, which meant the
/// only way to stop your phone ringing was to navigate to the phone — and the
/// moment you were doing something else, which is exactly when you want it,
/// it was two taps away and out of sight. Somebody in a meeting does not open
/// the Dial pad to say they are in a meeting.
///
/// ── Why a dot and not the word ───────────────────────────────────
///
/// The bar is narrow and shared. A dot on a handset reads at a glance and
/// survives 375px, where "Do not disturb" cannot sit next to Clock In without
/// one of them being dropped into a menu — and a control that is sometimes in
/// a menu is a control nobody trusts to be there.
///
/// ── Why it draws nothing for somebody with no line ───────────────
///
/// An availability light for a person with no phone is a control that cannot
/// mean anything: they are not going to be rung either way. The server answers
/// `has_line` for exactly this, rather than the app guessing from permissions
/// — holding `phone.view` is not the same as being on a line.
class AvailabilityButton extends StatefulWidget {
  const AvailabilityButton({super.key, required this.api});

  final ApiClient api;

  @override
  State<AvailabilityButton> createState() => _AvailabilityButtonState();
}

class _AvailabilityButtonState extends State<AvailabilityButton>
    with WidgetsBindingObserver {
  bool _hasLine = false;
  bool _busy = false;
  String _state = 'registered';

  /// The desk, not a timer.
  ///
  /// This button used to read presence once in initState and never again, and
  /// it is mounted for the whole session — so the moment anything else changed
  /// presence, the icon and the truth came apart and stayed apart. Taking a
  /// call changes it, so that was every call.
  ///
  /// Listening to the desk rather than polling means it is right in the same
  /// frame the call starts and ends, and there is no interval during which it
  /// is knowingly wrong.
  late final PhoneCallService _service = PhoneCallService.current;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _service.addListener(_deskChanged);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _service.removeListener(_deskChanged);
    super.dispose();
  }

  /// Somebody came back to this app, so ask the server what they are now.
  ///
  /// ── Why this exists ──────────────────────────────────────────────
  ///
  /// Availability belongs to the PERSON and this button belongs to one app.
  /// It read the server once, at startup, and after that only heard about
  /// changes its own app made — so setting Do Not Disturb on the web left the
  /// desktop showing a green dot indefinitely. Jose reported exactly that:
  /// web away, desktop available, and a call arriving while the two disagreed.
  ///
  /// ── Why the app coming forward and not a timer ───────────────────
  ///
  /// The moment the answer matters is the moment somebody looks, and that is
  /// what this is. A periodic refresh would also work and costs more than it
  /// buys: it polls every desk in the company all day to catch a change that
  /// only matters when a person is reading the icon, and `Timer.periodic`
  /// makes `pumpAndSettle` wait for ever, so it would have to be defused in
  /// every test that renders the app bar — which is most of them.
  ///
  /// Switching windows counts. That is the case in the report: two apps side
  /// by side, and clicking between them is what brings this one forward.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load();
  }

  /// A call started or ended.
  ///
  /// Refetched rather than assumed when it ENDS, because the server is what
  /// decides what somebody goes back to — the desk asked for `registered`, and
  /// whether that took is the server's answer, not ours.
  void _deskChanged() {
    if (!mounted) return;

    // The server has settled since the last look — the desk has finished
    // telling it the call is over. Refetch, because the read taken the moment
    // the call ended was answered while `on_call` was still set, and believing
    // that one is how the icon stuck until the app restarted.
    final token = _service.presenceToken;
    if (token != _wasPresenceToken) {
      _wasPresenceToken = token;
      _load();

      return;
    }

    final onCall = _service.onCall;
    if (onCall == _wasOnCall) return;
    _wasOnCall = onCall;

    if (onCall) {
      setState(() {});

      return;
    }

    _load();
  }

  bool _wasOnCall = false;
  int _wasPresenceToken = 0;

  Future<void> _load() async {
    final response = await widget.api.get('/phone/presence/me');
    if (!mounted || !response.ok) return;

    final data = response.body['data'];
    if (data is! Map) return;

    setState(() {
      _hasLine = data['has_line'] == true;
      _state = (data['state'] ?? 'registered').toString();
    });
  }

  Future<void> _toggle() async {
    if (_busy) return;

    // On a call is not a state a toggle sets, and not one it can clear. The
    // server refuses to overwrite it; this keeps the button from pretending
    // otherwise while the request is in flight.
    //
    // Wrap-up IS clearable, and this is the button that clears it: "registered"
    // sent while wrapping up is a person saying they are ready, which the
    // server tells apart from a call ending by the state it already holds.
    final next = _state == 'dnd' ? 'registered' : 'dnd';

    setState(() => _busy = true);

    final response = await widget.api.put('/phone/presence/me', {
      'state': next,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) _state = next;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_hasLine) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final away = _state == 'dnd';

    // The desk first: it knows a call has started before the server has been
    // told, and it knows one has ended before the refetch lands. The server's
    // own `on_call` is still honoured for a call somebody is on from ANOTHER
    // device on the same account.
    final onCall = _service.onCall || _state == 'on_call';

    // Finishing the last call. Drawn as its own thing rather than folded into
    // on-call: the phone is free, the person is not, and somebody glancing at
    // this needs to know tapping it puts them straight back in the queue.
    final wrappingUp = !onCall && _state == 'wrap_up';

    return IconButton(
      onPressed: _busy || onCall ? null : _toggle,
      tooltip: switch (_state) {
        'dnd' => 'Not taking calls — tap to start again',
        'on_call' => 'On a call',
        'wrap_up' => 'Finishing the last call — tap when you are ready',
        _ => 'Taking calls — tap to stop',
      },
      icon: Stack(
        clipBehavior: Clip.none,
        children: [
          Icon(away ? Icons.phone_disabled_outlined : Icons.phone_outlined),

          // The dot carries the state, so the answer survives being glanced
          // at. Colour alone would not: it is the one thing a colourblind
          // reader loses, which is why the handset changes shape too.
          Positioned(
            right: -1,
            bottom: -1,
            child: Container(
              width: 9,
              height: 9,
              decoration: BoxDecoration(
                color: away
                    ? theme.colorScheme.error
                    : onCall || wrappingUp
                    ? theme.colorScheme.tertiary
                    : theme.colorScheme.primary,
                shape: BoxShape.circle,
                border: Border.all(color: theme.colorScheme.surface, width: 1.5),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
