import 'package:ezbizi_client/widgets/social_network_mark.dart';
import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const networks = [
    'facebook_page',
    'instagram_professional',
    'threads',
    'linkedin',
    'tiktok',
    'pinterest',
    'youtube',
    'x',
    'bluesky',
  ];

  testWidgets('each catalogue network loads its bundled brand mark', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              SocialNetworkMark(network: 'facebook_page'),
              SocialNetworkMark(network: 'instagram_professional'),
              SocialNetworkMark(network: 'threads'),
              SocialNetworkMark(network: 'linkedin'),
              SocialNetworkMark(network: 'tiktok'),
              SocialNetworkMark(network: 'pinterest'),
              SocialNetworkMark(network: 'youtube'),
              SocialNetworkMark(network: 'x'),
              SocialNetworkMark(network: 'bluesky'),
            ],
          ),
        ),
      ),
    );

    expect(find.byType(SvgPicture), findsNWidgets(networks.length));
    expect(tester.takeException(), isNull);
  });
}
