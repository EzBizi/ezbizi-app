import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/phone_call_service.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';

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
/// ── Why it is OFF unless somebody asks for it ───────────────────
///
/// It reverses a house rule, and the rule has a reason worth keeping: a
/// ringing phone must not take the app away from somebody halfway through
/// writing up a job. That is why the shell already shows a STRIP — you answer
/// and keep typing, and the work underneath keeps its state.
///
/// Jose asked for both, and asked for the switch himself, which is what
/// resolves it: the strip stays for everybody, and the window is a choice each
/// person makes. `phone.incoming_popup`, default false.
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

    return Positioned.fill(
      child: Material(
        // Dark enough to say the app is not the subject right now, light
        // enough that you can still see what you were doing underneath.
        color: Colors.black.withValues(alpha: 0.45),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 360),
            child: Card(
              key: const Key('incoming-call-popup'),
              margin: const EdgeInsets.all(24),
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
                              backgroundColor: const Color(0xFF1B873F),
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
}
