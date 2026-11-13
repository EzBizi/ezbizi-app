import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../widgets/user_avatar.dart';

/// Monday's person hover card (Jose 11-13): photo, name, presence, local
/// time, role, and contact details folded away until asked for. Hovering
/// [child] for a moment opens it; the details come from
/// `GET task-boards/{board}/people/{user}`, fetched once per card.
class PersonHoverCard extends StatefulWidget {
  const PersonHoverCard({
    super.key,
    required this.api,
    required this.boardId,
    required this.userId,
    required this.child,
  });

  final ApiClient api;
  final String boardId;
  final String userId;
  final Widget child;

  @override
  State<PersonHoverCard> createState() => _PersonHoverCardState();
}

class _PersonHoverCardState extends State<PersonHoverCard> {
  final _portal = OverlayPortalController();
  final _link = LayerLink();
  Map<String, dynamic>? _data;
  bool _inChild = false, _inCard = false, _contact = false;

  Future<void> _show() async {
    await Future<void>.delayed(const Duration(milliseconds: 350));
    if (!mounted || !_inChild) return;
    _portal.show();
    if (_data != null) return;
    final r = await widget.api.get(
      '/task-boards/${widget.boardId}/people/${widget.userId}',
    );
    if (!mounted || !r.ok) return;
    setState(() => _data = (r.data as Map).cast<String, dynamic>());
  }

  void _maybeHide() {
    Future<void>.delayed(const Duration(milliseconds: 200), () {
      if (mounted && !_inChild && !_inCard) {
        _portal.hide();
        setState(() => _contact = false);
      }
    });
  }

  @override
  Widget build(BuildContext context) => CompositedTransformTarget(
    link: _link,
    child: OverlayPortal(
      controller: _portal,
      overlayChildBuilder: (context) => CompositedTransformFollower(
        link: _link,
        targetAnchor: Alignment.bottomLeft,
        offset: const Offset(0, 4),
        child: Align(
          alignment: Alignment.topLeft,
          child: MouseRegion(
            onEnter: (_) => _inCard = true,
            onExit: (_) {
              _inCard = false;
              _maybeHide();
            },
            child: _card(context),
          ),
        ),
      ),
      child: MouseRegion(
        onEnter: (_) {
          _inChild = true;
          _show();
        },
        onExit: (_) {
          _inChild = false;
          _maybeHide();
        },
        child: widget.child,
      ),
    ),
  );

  Widget _card(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final d = _data;
    return Material(
      key: ValueKey('person-card-${widget.userId}'),
      elevation: 4,
      color: scheme.surface,
      borderRadius: BorderRadius.circular(12),
      child: SizedBox(
        width: 260,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: d == null
              ? const SizedBox(
                  height: 40,
                  child: Center(child: LinearProgressIndicator()),
                )
              : Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Stack(
                          children: [
                            UserAvatar(
                              api: widget.api,
                              userId: widget.userId,
                              name: '${d['name']}',
                              radius: 22,
                              hasAvatar: d['has_avatar'] == true,
                            ),
                            Positioned(
                              right: 0,
                              bottom: 0,
                              child: Container(
                                width: 11,
                                height: 11,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  border: Border.all(
                                    color: scheme.surface,
                                    width: 2,
                                  ),
                                  color: _presenceColour(
                                    scheme,
                                    d['presence'] as String?,
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${d['name']}',
                                style: theme.textTheme.titleSmall,
                              ),
                              if (d['role_name'] != null)
                                Container(
                                  margin: const EdgeInsets.only(top: 3),
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 7,
                                    vertical: 1,
                                  ),
                                  decoration: BoxDecoration(
                                    color: scheme.secondaryContainer,
                                    borderRadius: BorderRadius.circular(99),
                                  ),
                                  child: Text(
                                    '${d['role_name']}',
                                    style: theme.textTheme.labelSmall,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Text(
                      '${_presenceLabel(d['presence'] as String?)} · '
                      'Local time ${d['local_time'] ?? '—'}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: 6),
                    InkWell(
                      key: ValueKey('person-card-contact-${widget.userId}'),
                      onTap: () => setState(() => _contact = !_contact),
                      child: Row(
                        children: [
                          Text(
                            'Contact details',
                            style: theme.textTheme.labelMedium?.copyWith(
                              color: scheme.primary,
                            ),
                          ),
                          Icon(
                            _contact ? Icons.expand_less : Icons.expand_more,
                            size: 16,
                            color: scheme.primary,
                          ),
                        ],
                      ),
                    ),
                    if (_contact) ...[
                      for (final v in [d['email'], d['phone']])
                        if (v is String && v.isNotEmpty)
                          SelectableText(v, style: theme.textTheme.bodySmall),
                    ],
                  ],
                ),
        ),
      ),
    );
  }

  static Color _presenceColour(ColorScheme s, String? p) => switch (p) {
    'registered' => s.tertiary,
    'on_call' || 'wrap_up' || 'dnd' => s.error,
    _ => s.outlineVariant,
  };

  static String _presenceLabel(String? p) => switch (p) {
    'registered' => 'Available',
    'on_call' => 'On a call',
    'wrap_up' => 'Wrapping up a call',
    'dnd' => 'Do not disturb',
    _ => 'Offline',
  };
}
