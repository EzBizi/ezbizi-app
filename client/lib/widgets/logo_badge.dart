import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';

/// An organization's or company's mark, with the controls to change it (§12).
///
/// ── Fetched as bytes, not pointed at with an <img> ───────────────
///
/// A logo is served through an authenticated endpoint, because a public one at
/// a guessable path would let anybody enumerate the platform's customers. That
/// rules out `Image.network`: it cannot carry the bearer token. So the bytes
/// come down through ApiClient like any other response and are decoded here.
///
/// ── Franchises do not have one ───────────────────────────────────
///
/// A franchise is a geography (§3), not a brand, so this widget is never built
/// for one. A company with no mark of its own falls back to the organization's,
/// which the server does at read time — inherited, not copied, so changing the
/// organization's changes every company that never set one.
class LogoBadge extends StatefulWidget {
  const LogoBadge({
    super.key,
    required this.api,
    required this.path,
    required this.hasOwn,
    required this.mayEdit,
    required this.onChanged,
    this.size = 44,
    this.fallbackIcon = Icons.apartment_outlined,
  });

  final ApiClient api;

  /// The branding endpoint for this subject — `/branding/organization` or
  /// `/branding/companies/<id>`.
  final String path;

  /// Whether this subject has a logo OF ITS OWN, as opposed to showing the
  /// organization's. Drives whether "Remove" is offered — offering it for an
  /// inherited mark would promise something it cannot do.
  final bool hasOwn;

  final bool mayEdit;
  final VoidCallback onChanged;
  final double size;
  final IconData fallbackIcon;

  @override
  State<LogoBadge> createState() => _LogoBadgeState();
}

class _LogoBadgeState extends State<LogoBadge> {
  Uint8List? _bytes;
  bool _busy = true;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  @override
  void didUpdateWidget(LogoBadge old) {
    super.didUpdateWidget(old);

    // The path stays the same while the image behind it changes, so a rebuild
    // alone would keep showing the old one after an upload.
    if (old.hasOwn != widget.hasOwn) _fetch();
  }

  Future<void> _fetch() async {
    setState(() => _busy = true);

    final bytes = await widget.api.getBytes(widget.path);

    if (!mounted) return;

    setState(() {
      _busy = false;
      _bytes = bytes == null ? null : Uint8List.fromList(bytes);
    });
  }

  Future<void> _upload() async {
    final picked = await Files.pickImage();

    if (picked == null || !mounted) return;

    setState(() => _busy = true);

    final response =
        await widget.api.postFile(widget.path, 'logo', picked.name, picked.bytes);

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _busy = false);

      // The server's own words. It refuses SVG, oversized files and huge
      // dimensions for different reasons, and "could not upload" tells nobody
      // which of them they hit.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_reason(response))),
      );
      return;
    }

    await _fetch();
    widget.onChanged();
  }

  Future<void> _remove() async {
    setState(() => _busy = true);

    await widget.api.delete(widget.path);

    if (!mounted) return;

    await _fetch();
    widget.onChanged();
  }

  String _reason(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;
    final first = errors?.values.firstOrNull;

    return (first is List && first.isNotEmpty)
        ? first.first as String
        : response.message;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final badge = Container(
      width: widget.size,
      height: widget.size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: _busy
          ? const Center(
              child: SizedBox(
                width: 14,
                height: 14,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            )
          : _bytes == null
              ? Icon(widget.fallbackIcon,
                  size: widget.size * 0.45, color: scheme.onSurfaceVariant)
              // `contain`, never `cover`. A logo cropped to fill a square is a
              // logo with its edges cut off, which is the one thing a brand
              // asset must not have done to it.
              : Padding(
                  padding: EdgeInsets.all(widget.size * 0.12),
                  child: Image.memory(_bytes!, fit: BoxFit.contain),
                ),
    );

    if (!widget.mayEdit) return badge;

    return PopupMenuButton<String>(
      tooltip: 'Change the logo',
      position: PopupMenuPosition.under,
      itemBuilder: (_) => [
        PopupMenuItem(
          value: 'upload',
          child: Text(_bytes == null ? 'Upload a logo' : 'Replace it'),
        ),
        if (widget.hasOwn)
          const PopupMenuItem(value: 'remove', child: Text('Remove')),
      ],
      onSelected: (v) => v == 'upload' ? _upload() : _remove(),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          badge,
          Positioned(
            right: -3,
            bottom: -3,
            child: Container(
              padding: const EdgeInsets.all(3),
              decoration: BoxDecoration(
                color: scheme.surface,
                shape: BoxShape.circle,
                border: Border.all(color: scheme.outlineVariant),
              ),
              child: Icon(Icons.edit_outlined,
                  size: 11, color: scheme.onSurfaceVariant),
            ),
          ),
        ],
      ),
    );
  }
}
