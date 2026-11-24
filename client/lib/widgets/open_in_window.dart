import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_window.dart';

/// Right-click (or long-press) on a module or tab: "Open in new window"
/// (Jose, 2026-11-24). Draws [child] untouched where no second window can be
/// opened (phones), or when [path] is null.
class OpenInWindow extends StatelessWidget {
  const OpenInWindow({super.key, required this.path, required this.child});

  /// From [AppWindow.pathFor].
  final String? path;
  final Widget child;

  static Future<void> menu(BuildContext context, Offset at, String path) async {
    final overlay = Overlay.of(context).context.findRenderObject() as RenderBox;
    final picked = await showMenu<bool>(
      context: context,
      position: RelativeRect.fromRect(
        at & const Size(1, 1),
        Offset.zero & overlay.size,
      ),
      items: const [
        PopupMenuItem(
          key: ValueKey('open-in-new-window'),
          value: true,
          child: ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(Icons.open_in_new),
            title: Text('Open in new window'),
          ),
        ),
      ],
    );
    if (picked == true) await AppWindow.open(path);
  }

  @override
  Widget build(BuildContext context) {
    final to = path;
    if (to == null || !AppWindow.canOpen) return child;
    final menuArea = GestureDetector(
      key: ValueKey('open-in-window:$to'),
      behavior: HitTestBehavior.translucent,
      onSecondaryTapUp: (d) => menu(context, d.globalPosition, to),
      onLongPressStart: (d) => menu(context, d.globalPosition, to),
      child: child,
    );
    if (!kIsWeb) return menuArea;
    // The browser draws its own menu on a right-click; hold it back only over
    // a module or tab, so text fields keep their copy and paste.
    return MouseRegion(
      onEnter: (_) => BrowserContextMenu.disableContextMenu(),
      onExit: (_) => BrowserContextMenu.enableContextMenu(),
      child: menuArea,
    );
  }
}
