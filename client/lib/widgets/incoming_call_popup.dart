import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';

import '../core/api_client.dart';
import '../core/phone_call_service.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';

/// The caller, in a window, on top of whatever you were doing.
///
/// ── What Jose asked for ──────────────────────────────────────────
///
/// 2026-09-22: *"When someone is receiving a call i want a popup to appear...
/// with the Phone Number of the Costumer or Name if it's in our Database
/// (Phone number below in that case). Placeholder Basic Social Media Person
/// Image. Accept or Ignore Buttons in green and red. this needs to be in
/// conjunction with what we already have of course, and also must be a setting
/// to be enabled or disabled."*
///
/// ── ON unless somebody switches it off ─────────────────────────
///
/// It was off by default at first, and Jose took a call that showed only the
/// strip: *"remember i asked for a pop up"* (2026-09-25). The strip stays for
/// everybody; the window is on for everybody who has not switched it off.
/// `phone.incoming_popup`, default true.
///
/// ── Why it is drawn rather than PUSHED ──────────────────────────
///
/// A `showDialog` here would be a route, and a route has to be popped by
/// whoever pushed it. But the commonest way a ringing call ends is that
/// NOBODY here does anything: the caller gives up, or a colleague answers
/// first and Twilio cancels this leg. A pushed dialog then sits on screen over
/// a call that no longer exists, and the only way out is a button that now
/// answers nothing — the same class of bug as the incoming bar that used to
/// outlive its call, and worse, because a modal cannot be ignored.
///
/// So this is a widget in the shell's Stack that watches the service and draws
/// nothing when there is nothing ringing. It cannot get out of step with the
/// call, because it IS the call's state on screen.
class IncomingCallPopup extends StatefulWidget {
  const IncomingCallPopup({
    super.key,
    required this.api,
    required this.session,
    this.service,
  });

  final ApiClient api;
  final Session session;

  /// The desk. Defaults to the app-wide one; a test passes its own.
  final PhoneCallService? service;

  @override
  State<IncomingCallPopup> createState() => _IncomingCallPopupState();
}

class _IncomingCallPopupState extends State<IncomingCallPopup> {
  late final PhoneCallService _service =
      widget.service ?? PhoneCallService.current;

  /// The number we last asked about, so one ring is one lookup.
  String? _askedAbout;

  /// The name behind that number, when the database knows one.
  String? _name;

  @override
  void initState() {
    super.initState();
    _service.addListener(_onServiceChanged);
    _onServiceChanged();
  }

  @override
  void dispose() {
    _service.removeListener(_onServiceChanged);
    super.dispose();
  }

  void _onServiceChanged() {
    if (!mounted) return;

    // NOT DURING SOMEBODY ELSE'S BUILD. The desk can notify from code that a
    // build triggers, and a setState then asserts "called during build"
    // (Apple, 2026-10-06: iOS debug, a few seconds after launch). Deferred to
    // the end of the frame, where it is legal and changes nothing visible.
    if (SchedulerBinding.instance.schedulerPhase ==
        SchedulerPhase.persistentCallbacks) {
      SchedulerBinding.instance.addPostFrameCallback((_) => _onServiceChanged());
      return;
    }

    setState(() {});

    final ringing = _service.incomingFrom;

    // A new call, or the end of one. Both reset the name: leaving the last
    // caller's name up while a different number rings is the one mistake this
    // window can make that is worse than showing no name at all.
    if (ringing != _askedAbout) {
      _askedAbout = ringing;
      _name = null;

      if (ringing != null && ringing.isNotEmpty) _lookUp(ringing);
    }
  }

  Future<void> _lookUp(String e164) async {
    final response = await widget.api.get(
      '/phone/caller?e164=${Uri.encodeQueryComponent(e164)}',
    );
    if (!mounted || !response.ok) return;

    // STILL THE SAME CALL. A slow lookup that lands after the caller gave up
    // and somebody else rang would otherwise label the new call with the old
    // one's name.
    if (_askedAbout != e164) return;

    final data = response.data as Map<String, dynamic>? ?? const {};
    final name = (data['name'] as String? ?? '').trim();

    setState(() => _name = name.isEmpty ? null : name);
  }

  @override
  Widget build(BuildContext context) {
    final ringing = _service.incomingFrom;

    if (ringing == null) return const SizedBox.shrink();
    if (!(widget.session.me?.phoneIncomingPopup ?? false)) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    final number = formatPhoneNumber(ringing);

    // NO BACKDROP, AND IT MOVES. Jose, 2026-09-25, of the first version's
    // dimmed screen: *"it does block the screen... we can move it around like
    // draggable"*. A card, not a modal: everything around it stays clickable,
    // and a drag puts it wherever it is out of the way. The spot is kept for
    // the next call, since this State outlives each one.
    final screen = MediaQuery.sizeOf(context);
    final at = _clamp(
      _offset ?? Offset((screen.width - _width) / 2, screen.height * 0.3),
      screen,
    );

    return Positioned(
      left: at.dx,
      top: at.dy,
      child: MouseRegion(
        cursor: SystemMouseCursors.move,
        child: GestureDetector(
          // Where the pointer grabbed the card, kept from the PRESS: summing
          // deltas loses the slop the recogniser eats before it accepts, and
          // the card then trails the pointer.
          dragStartBehavior: DragStartBehavior.down,
          onPanStart: (drag) => _grip = drag.globalPosition - at,
          onPanUpdate: (drag) => setState(
            () => _offset = _clamp(drag.globalPosition - _grip, screen),
          ),
          child: SizedBox(
            width: _width,
            child: Card(
              key: const Key('incoming-call-popup'),
              margin: EdgeInsets.zero,
              elevation: 12,
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 24,
                  vertical: 28,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // The placeholder Jose asked for. Not an avatar we have —
                    // a customer record holds no photograph, and drawing a
                    // generic person is honest about that.
                    CircleAvatar(
                      radius: 40,
                      backgroundColor:
                          theme.colorScheme.surfaceContainerHighest,
                      child: Icon(
                        Icons.person,
                        size: 46,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: 18),

                    Text(
                      'Incoming call',
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: 6),

                    // NAME FIRST WHEN WE HAVE ONE, and the number under it —
                    // his words. When we do not, the number IS the identity
                    // and gets the large type rather than being shown twice.
                    Text(
                      _name ?? number,
                      textAlign: TextAlign.center,
                      style: theme.textTheme.titleLarge,
                    ),
                    if (_name != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        number,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],

                    const SizedBox(height: 24),

                    Row(
                      children: [
                        Expanded(
                          child: FilledButton.icon(
                            key: const Key('incoming-call-popup-accept'),
                            style: FilledButton.styleFrom(
                              backgroundColor: StateColour.of(context).on,
                              foregroundColor: Colors.white,
                            ),
                            onPressed: _service.accept,
                            icon: const Icon(Icons.call),
                            label: const Text('Accept'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: FilledButton.icon(
                            key: const Key('incoming-call-popup-ignore'),
                            style: FilledButton.styleFrom(
                              backgroundColor: const Color(0xFFC62828),
                              foregroundColor: Colors.white,
                            ),
                            onPressed: _service.decline,
                            icon: const Icon(Icons.call_end),
                            label: const Text('Ignore'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  static const double _width = 312;

  /// Where the card was dragged to, or null for the starting spot.
  Offset? _offset;

  /// The pointer's place on the card during a drag.
  Offset _grip = Offset.zero;

  /// Keeps the card on screen, so a drag or a smaller window cannot lose it.
  // ponytail: the height is a guess at the card's (~300); measure it with a
  // GlobalKey if a taller card ever gets pushed off the bottom.
  Offset _clamp(Offset o, Size screen) => Offset(
    o.dx.clamp(0, (screen.width - _width).clamp(0, double.infinity)),
    o.dy.clamp(0, (screen.height - 300).clamp(0, double.infinity)),
  );
}
