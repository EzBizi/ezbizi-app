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
    'facebook_ads': 'assets/social/facebook.svg',
    'instagram_professional': 'assets/social/instagram.svg',
    'threads': 'assets/social/threads.svg',
    'linkedin': 'assets/social/linkedin.svg',
    'tiktok': 'assets/social/tiktok.svg',
    'pinterest': 'assets/social/pinterest.svg',
    'youtube': 'assets/social/youtube.svg',
    'x': 'assets/social/x.svg',
    'bluesky': 'assets/social/bluesky.svg',

    // Both Google connections wear the Google G rather than a product mark.
    //
    // They are different products with different logos, and the row's own label
    // already says which — "Google Ads", "Google Business Profile" — so the G
    // identifies the account's origin, which is the question somebody scanning
    // this list is actually asking. Before this they fell through to the grey
    // square below, which reads as a network nobody has drawn yet rather than
    // as Google.
    'google_ads': 'assets/social/google.svg',
    'google_business_profile': 'assets/social/google.svg',

    // Bundled with the Thumbtack connection and never mapped, so Leads drew
    // the grey square beside the one provider that has a mark of its own.
    'thumbtack': 'assets/social/thumbtack.svg',

    // In assets/social all along and never mapped, so Integrations drew the
    // grey square for them (Jose, 2026-10-13).
    'mailchimp': 'assets/social/mailchimp.svg',
    'workiz': 'assets/social/workiz.svg',
    'airtable': 'assets/social/airtable.svg',
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
