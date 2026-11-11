import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'google_campaigns_view.dart';
import 'meta_campaigns_view.dart';

/// Marketing › Ads (Jose, 2026-11-09): running Google and Meta campaigns,
/// one advertiser at a time behind the same switch, the same layout. Google's
/// report - accounts, KPIs, channels, over time - is Reports › Google Ads.
class AdsManagerScreen extends StatefulWidget {
  const AdsManagerScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<AdsManagerScreen> createState() => _AdsManagerScreenState();
}

/// Which advertiser was open, kept across tab switches.
String _source = 'google';

class _AdsManagerScreenState extends State<AdsManagerScreen> {
  Widget _switch() => SegmentedButton<String>(
    key: const ValueKey('ads-source'),
    showSelectedIcon: false,
    segments: const [
      ButtonSegment(value: 'google', label: Text('Google')),
      ButtonSegment(value: 'meta', label: Text('Meta')),
    ],
    selected: {_source},
    onSelectionChanged: (s) => setState(() => _source = s.first),
  );

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(16),
    child: _source == 'meta'
        ? MetaCampaignsView(
            api: widget.api,
            session: widget.session,
            sourceSwitch: _switch(),
          )
        : GoogleCampaignsView(
            api: widget.api,
            session: widget.session,
            sourceSwitch: _switch(),
          ),
  );
}
