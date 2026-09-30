import 'package:flutter/material.dart';

import '../core/theme.dart';

/// A picture that glows amber, in and out, while its owner's profile lacks
/// something.
///
/// Jose, 2026-09-30, from A1 Tools: *"if stuff was missing or incomplete from
/// ur profile, the profile pic had an effect like a pulsating aura, let's
/// implement that"*. The caution state, as light: the boxes it points at
/// are amber for the same reason.
///
/// Held still, glowing, when the platform asks for less motion; nothing at all
/// once the profile is complete. The pulse repaints only itself.
class ProfileAura extends StatefulWidget {
  const ProfileAura({
    super.key,
    required this.active,
    required this.child,
    this.pulse = true,
  });

  /// Something is missing. False draws [child] as it is.
  final bool active;

  /// False holds the glow still: on the profile itself, beside the amber
  /// boxes it is about, the picture is marked rather than calling out.
  final bool pulse;

  /// The round picture it glows around.
  final Widget child;

  @override
  State<ProfileAura> createState() => _ProfileAuraState();
}

class _ProfileAuraState extends State<ProfileAura>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );

  bool _still = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    _sync();
  }

  @override
  void didUpdateWidget(ProfileAura old) {
    super.didUpdateWidget(old);
    _sync();
  }

  void _sync() {
    if (widget.active && widget.pulse && !_still) {
      if (!_pulse.isAnimating) _pulse.repeat(reverse: true);
    } else {
      _pulse.stop();
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.active) return widget.child;

    final glow = StateColour.of(context).cautionGlow;

    return RepaintBoundary(
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, child) {
          final t = _still || !widget.pulse
              ? 0.6
              : Curves.easeInOut.transform(_pulse.value);

          return DecoratedBox(
            key: const ValueKey('profile-aura'),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                  color: glow.withValues(alpha: 0.45 + 0.45 * t),
                  blurRadius: 6 + 10 * t,
                  spreadRadius: 1 + 4 * t,
                ),
              ],
            ),
            child: child,
          );
        },
        child: widget.child,
      ),
    );
  }
}

/// "phone number, home address and photo": what a profile lacks, in the words
/// its boxes use. Empty for a complete one.
String profileMissingWords(List<String> missing) {
  const words = {
    'first_name': 'first name',
    'last_name': 'last name',
    'phone': 'phone number',
    'address': 'home address',
    'gender': 'gender',
    'date_of_birth': 'date of birth',
    'photo': 'photo',
  };
  final said = [
    for (final key in missing) words[key] ?? key.replaceAll('_', ' '),
  ];

  if (said.length < 2) return said.join();

  return '${said.sublist(0, said.length - 1).join(', ')} and ${said.last}';
}
