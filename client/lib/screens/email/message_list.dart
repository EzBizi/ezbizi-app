import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/theme.dart';
import 'sender_brand.dart';

/// Gmail-style sender mark: a letter on a colour, or the site's icon when
/// Google's favicon service has one for that domain (Apple, Google Cloud).
///
/// The image is an HTML `<img>` on web ([WebHtmlElementStrategy.prefer]).
/// CanvasKit cannot decode `google.com/s2/favicons` (no CORS), so the
/// letter was all anybody saw. The blue check is Gmail's verified mark
/// for known brands whose mail was not reported as failing DMARC.
class SenderAvatar extends StatelessWidget {
  const SenderAvatar({
    super.key,
    required this.name,
    required this.email,
    this.authenticated,
  });

  final String name;
  final String email;

  /// DMARC for this message, if the provider said. Null when it did not.
  final bool? authenticated;

  String get _letter {
    final source = name.trim().isNotEmpty ? name.trim() : email.trim();
    if (source.isEmpty) return '?';
    return source[0].toUpperCase();
  }

  Color get _color {
    final key = email.isNotEmpty ? email : name;
    var hash = 0;
    for (final unit in key.codeUnits) {
      hash = (hash + unit) & 0x7fffffff;
    }
    const palette = EzBiziTheme.avatarPalette;
    return palette[hash % palette.length];
  }

  @override
  Widget build(BuildContext context) {
    final letter = _letter;
    final url = SenderBrand.faviconUrl(email);
    final verified = SenderBrand.verified(email, authenticated: authenticated);
    final fallback = CircleAvatar(
      radius: 18,
      backgroundColor: _color,
      child: Text(
        letter,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w600,
          fontSize: 16,
        ),
      ),
    );

    Widget mark = fallback;

    if (url != null && kIsWeb) {
      mark = ClipOval(
        child: SizedBox(
          width: 36,
          height: 36,
          child: Image.network(
            url,
            fit: BoxFit.cover,
            webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
            errorBuilder: (_, _, _) => fallback,
            frameBuilder: (context, child, frame, wasSynchronouslyLoaded) {
              if (wasSynchronouslyLoaded || frame != null) return child;
              return fallback;
            },
          ),
        ),
      );
    }

    if (!verified) return mark;

    return SizedBox(
      width: 36,
      height: 36,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          mark,
          const Positioned(
            right: -1,
            bottom: -1,
            child: Tooltip(
              message: 'Verified sender',
              child: _VerifiedDot(),
            ),
          ),
        ],
      ),
    );
  }
}

class _VerifiedDot extends StatelessWidget {
  const _VerifiedDot();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 14,
      height: 14,
      decoration: BoxDecoration(
        color: const Color(0xFF1A73E8),
        shape: BoxShape.circle,
        // THE RING SEPARATES THE DOT FROM THE PAGE, so it is the page's
        // colour and not white. It was `Colors.white`, which drew a white
        // halo on the dark theme's surface — invisible as drift, because
        // there is exactly one of these and a ratchet counts instances.
        //
        // The CHECK stays white: it sits on the blue, which does not change
        // with the theme, so white is correct in both.
        border: Border.all(
          color: Theme.of(context).colorScheme.surface,
          width: 1.5,
        ),
      ),
      child: const Icon(Icons.check, size: 8, color: Colors.white),
    );
  }
}

/// "Seen by Marta Vieira" — the substitute for claiming a thread.
///
/// Decision 6: ownership of a thread was considered and deferred, so this line
/// is the only thing standing between a customer and two different answers to
/// the same email. It carries an icon as well as words because it has to be
/// noticeable in a scanned list, and words as well as an icon because a symbol
/// alone says nothing to a screen reader.
class SeenByLine extends StatelessWidget {
  const SeenByLine({super.key, required this.sentence});

  final String sentence;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(
          Icons.visibility_outlined,
          size: 14,
          color: theme.colorScheme.secondary,
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            sentence,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.secondary,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}
