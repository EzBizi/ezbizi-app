import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';

/// The desk. Until it is built, this is where to start.
class CallTab extends StatelessWidget {
  const CallTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Connect Twilio in Settings',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium,
        ),
      ),
    );
  }
}
