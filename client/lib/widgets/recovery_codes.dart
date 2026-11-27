import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'side_panel.dart';

/// The one and only showing of a set of recovery codes.
///
/// The server mints these when an authenticator is confirmed and cannot produce
/// them again — they are stored encrypted and there is no endpoint that reads
/// them back. Every path that confirms an enrolment must therefore end here, or
/// it destroys them: the request succeeded, the codes existed for the length of
/// one HTTP response, and nobody saw them.
class RecoveryCodesView extends StatelessWidget {
  const RecoveryCodesView({super.key, required this.codes});

  final List<String> codes;

  @override
  Widget build(BuildContext context) {
    if (codes.isEmpty) {
      return Text(
        'Confirmed, but the server returned no recovery codes. Re-enroll to get a '
        'set — an account with none has no way back from a lost phone.',
        style: Theme.of(context).textTheme.bodyMedium,
      );
    }

    // The one screen in the app that must not be dismissable.
    //
    // Everywhere else, a stray click on the scrim costs you a form you can
    // reopen. Here it costs codes that no longer exist anywhere — the server
    // minted them, showed them once, and stores only hashes. So the escapes
    // that every other panel offers are closed, and "Done" is the way out.
    //
    // canPop is what the scrim, Escape and the panel's own close button all
    // consult (they call Navigator.maybePop). The footer's Done calls pop
    // directly and is deliberately unaffected.
    //
    // It lives here rather than at the call sites because there are four of
    // them and there will be more; a guard you have to remember is a guard
    // somebody forgets.
    return PopScope(
      canPop: false,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Shown once and never again. Store them somewhere that is not the '
            'phone holding the authenticator — each one works a single time, in '
            'place of a six-digit code.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Theme.of(
                context,
              ).colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
              borderRadius: BorderRadius.circular(10),
            ),
            child: SelectableText(
              codes.join('\n'),
              style: const TextStyle(
                fontFamily: 'monospace',
                fontSize: 14,
                height: 1.6,
              ),
            ),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: codes.join('\n')));
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Recovery codes copied')),
                );
              }
            },
            icon: const Icon(Icons.copy_all_outlined, size: 18),
            label: const Text('Copy all'),
          ),
        ],
      ),
    );
  }
}

/// For paths that confirm an enrolment outside the setup panel.
class RecoveryCodesDialog extends StatelessWidget {
  const RecoveryCodesDialog({super.key, required this.codes});

  final List<String> codes;

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Recovery codes',
      actions: [
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done'),
        ),
      ],
      child: RecoveryCodesView(codes: codes),
    );
  }
}
