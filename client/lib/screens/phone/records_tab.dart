import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';

/// Calls, voicemail and texts. Empty until the list is built.
class RecordsTab extends StatelessWidget {
  const RecordsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'There are no calls or messages yet.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodyLarge,
        ),
      ),
    );
  }
}
