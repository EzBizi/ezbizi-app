import 'package:flutter/material.dart';

/// A failure the user needs to read.
///
/// Shared rather than copied per screen: the server's messages are written to be
/// shown verbatim (they explain *why* a rule exists), and a second copy of this
/// widget is how one screen quietly starts truncating them.
class ErrorNote extends StatelessWidget {
  const ErrorNote(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.errorContainer.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.error_outline, size: 18, color: scheme.error),
          const SizedBox(width: 10),
          Expanded(
            child: Text(message, style: TextStyle(fontSize: 13, color: scheme.error)),
          ),
        ],
      ),
    );
  }
}

/// A load that failed, in place of the pane it was loading: the [ErrorNote]
/// and a Try again that calls [onRetry].
class ErrorRetry extends StatelessWidget {
  const ErrorRetry(this.message, {super.key, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          ErrorNote(message),
          const SizedBox(height: 12),
          TextButton(onPressed: onRetry, child: const Text('Try again')),
        ],
      ),
    );
  }
}

/// A one-line outcome in a floating snack bar, red when [isError].
void showFloatingSnack(
  BuildContext context,
  String message, {
  bool isError = false,
}) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(message),
      backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
      behavior: SnackBarBehavior.floating,
      width: 420,
    ),
  );
}
