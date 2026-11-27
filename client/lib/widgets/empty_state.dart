import 'package:flutter/material.dart';

/// "Nothing here yet", said one way across the app (UI queue 70, options 1
/// and 4). The UI review of 2026-10-08 found empty screens drawn about six
/// ways, many naming the next step as text ("Settings › Integrations")
/// instead of a button.
///
/// Centred where the list would be: a soft round icon, a bold line, one
/// sentence, and one button when there is a next step. On a phone the button
/// spans the width, where a thumb reaches it.
///
/// Pass [onAction] only to someone allowed to take the step. Everyone else
/// gets [otherwise] in its place, saying who can.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.actionIcon = Icons.add,
    this.onAction,
    this.otherwise,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final IconData actionIcon;
  final VoidCallback? onAction;
  final String? otherwise;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final phone = MediaQuery.sizeOf(context).width < 600;
    final muted = theme.textTheme.bodyMedium?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    return Center(
      child: SingleChildScrollView(
        // Never the page's scroller: inside HeaderAboveList's NestedScrollView
        // on iOS, taking the shared controller overflowed the stack and the
        // list read "could not be drawn" (Inventory > Orders, iPhone Duo,
        // mobile review 11-24).
        primary: false,
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 440),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: scheme.primaryContainer,
                foregroundColor: scheme.onPrimaryContainer,
                child: Icon(icon, size: 26),
              ),
              const SizedBox(height: 12),
              Text(
                title,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 6),
              Text(message, style: muted, textAlign: TextAlign.center),
              if (onAction != null && actionLabel != null) ...[
                const SizedBox(height: 16),
                SizedBox(
                  width: phone ? double.infinity : null,
                  child: FilledButton.icon(
                    onPressed: onAction,
                    icon: Icon(actionIcon),
                    label: Text(actionLabel!),
                  ),
                ),
              ] else if (otherwise != null) ...[
                const SizedBox(height: 12),
                Text(
                  otherwise!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
