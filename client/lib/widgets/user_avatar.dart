import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// Somebody's profile picture, fetched with the token that is allowed to see it.
///
/// ── Why this is not an Image.network ─────────────────────────────
///
/// It was, and it never displayed. `GET /users/{user}/avatar` sits inside the
/// authenticated group — deliberately, because §9 treats a guessable avatar URL
/// as a leak of who works where, across every tenant. `NetworkImage` is the
/// browser fetching a URL on its own: no Authorization header, so a 401, so an
/// empty circle. Uploading a photo appeared to work, the button changed to
/// "Change", and nothing was ever drawn.
///
/// [LogoBadge] had already hit this and solved it the same way. The lesson that
/// keeps repeating in this codebase is that solving it in one widget is not
/// solving it — so this is the shared one, and the top bar and the profile card
/// both take it.
///
/// ── Falling back to the initial ──────────────────────────────────
///
/// While the bytes are in flight, and forever if there is no photo, this shows
/// the first letter of the name. Deliberately not a spinner: an avatar is
/// decoration around identity, and a spinner where a face goes draws the eye to
/// the least important thing on the screen.
class UserAvatar extends StatefulWidget {
  const UserAvatar({
    super.key,
    required this.api,
    required this.userId,
    required this.name,
    this.radius = 16,
    this.hasAvatar = true,
    this.version = '',
    this.bytes,
  });

  final ApiClient api;
  final String userId;

  /// For the initial, when there is no picture to draw.
  final String name;

  final double radius;

  /// False skips the request entirely. The caller usually knows from `/me`, and
  /// asking for a photo that is not there is a round trip per render.
  final bool hasAvatar;

  /// Changes when the photo does, so a replacement is refetched rather than
  /// served from the browser's cache of the same URL.
  final String version;

  /// Bytes the caller already has — straight after an upload, where waiting for
  /// a refetch makes choosing a photo feel like it did not work.
  final Uint8List? bytes;

  @override
  State<UserAvatar> createState() => _UserAvatarState();
}

class _UserAvatarState extends State<UserAvatar> {
  // One fetch per person and version for the whole app: a list of fifty
  // people draws fifty of these (Jose, 2026-11-13: photos everywhere). A
  // missing photo is cached too, as null, so it is not asked for again.
  // ponytail: never evicted; one small entry per person seen this session.
  static final Map<String, Future<Uint8List?>> _cache = {};

  Uint8List? _bytes;

  @override
  void initState() {
    super.initState();
    if (widget.hasAvatar) _fetch();
  }

  @override
  void didUpdateWidget(UserAvatar old) {
    super.didUpdateWidget(old);

    // Version covers a replacement, hasAvatar covers the first upload and the
    // removal, userId covers an impersonation swapping who is signed in.
    if (old.version != widget.version ||
        old.hasAvatar != widget.hasAvatar ||
        old.userId != widget.userId) {
      if (widget.hasAvatar) {
        _fetch();
      } else {
        setState(() => _bytes = null);
      }
    }
  }

  Future<void> _fetch() async {
    final path =
        '/users/${widget.userId}/avatar'
        '${widget.version.isEmpty ? '' : '?v=${widget.version}'}';

    final bytes = await _cache.putIfAbsent(path, () async {
      final raw = await widget.api.getBytes(path);
      return raw == null ? null : Uint8List.fromList(raw);
    });

    if (!mounted) return;

    setState(() => _bytes = bytes);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final shown = widget.bytes ?? _bytes;

    return CircleAvatar(
      radius: widget.radius,
      backgroundColor: scheme.primaryContainer,
      foregroundImage: shown == null ? null : MemoryImage(shown),
      // Bytes that are not a picture draw the initials, not a blank circle.
      onForegroundImageError: shown == null
          ? null
          : (_, _) {
              if (mounted && widget.bytes == null) {
                setState(() => _bytes = null);
              }
            },
      child: shown != null
          ? null
          : Text(
              userInitials(widget.name),
              style: TextStyle(
                // Proportional, so one widget serves the 16px bar and the 44px
                // card without either caller passing a font size that has to be
                // kept in step with the radius.
                fontSize: widget.radius * 0.7,
                fontWeight: FontWeight.w600,
                color: scheme.onPrimaryContainer,
              ),
            ),
    );
  }
}

/// Up to two initials, "Jose Luis Demasi" -> "JL"; "?" for no name.
String userInitials(String name) {
  final letters = name
      .trim()
      .split(RegExp(r'\s+'))
      .where((part) => part.isNotEmpty)
      .take(2)
      .map((part) => part[0].toUpperCase())
      .join();
  return letters.isEmpty ? '?' : letters;
}
