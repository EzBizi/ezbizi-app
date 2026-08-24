import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// Official brand mark for a social network, keyed by the server's `value`.
///
/// Bundled as SVG so the catalogue renders with no extra request. These are
/// the network marks, not a letter in a coloured square.
class SocialNetworkMark extends StatelessWidget {
  const SocialNetworkMark({super.key, required this.network, this.size = 32});

  /// Catalogue `value` — `facebook_page`, `instagram_professional`, …
  final String network;
  final double size;

  static const _assets = {
    'facebook_page': 'assets/social/facebook.svg',
    'instagram_professional': 'assets/social/instagram.svg',
    'threads': 'assets/social/threads.svg',
    'linkedin': 'assets/social/linkedin.svg',
    'tiktok': 'assets/social/tiktok.svg',
    'pinterest': 'assets/social/pinterest.svg',
    'youtube': 'assets/social/youtube.svg',
    'x': 'assets/social/x.svg',
    'bluesky': 'assets/social/bluesky.svg',
  };

  @override
  Widget build(BuildContext context) {
    final asset = _assets[network];
    if (asset == null) {
      return SizedBox(
        width: size,
        height: size,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: const Color(0xFF5F6368),
            borderRadius: BorderRadius.circular(size * 0.22),
          ),
        ),
      );
    }
    return SvgPicture.asset(
      asset,
      width: size,
      height: size,
      fit: BoxFit.contain,
      semanticsLabel: network,
    );
  }
}
