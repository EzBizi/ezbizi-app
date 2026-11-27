import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';
import 'logo_cropper.dart';
import 'side_panel.dart';

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
    this.version = '',
    this.onChanged,
    this.size = 44,
    this.bare = false,
    this.aspect,
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

  /// A token that moves whenever the mark this badge DISPLAYS changes.
  ///
  /// Sent to the endpoint as a cache-buster and used to decide when to refetch.
  /// It replaced `hasOwn` for that job, which could not work: a company
  /// inheriting the organization's logo has hasOwn false before AND after the
  /// organization's is removed, so four companies kept painting an image that
  /// no longer existed.
  final String version;
  /// Null when the badge is only being displayed — a read-only one has
  /// nothing to tell anybody about.
  final VoidCallback? onChanged;
  final double size;

  /// No frame and no padding — for places where the mark is the point rather
  /// than a slot you might click. Boxed artwork reads as a disabled button.
  final bool bare;

  /// 'square' or 'wide'. A wide lockup drawn in a square box is a lockup
  /// nobody can read, so the slot takes the shape of what is in it.
  final String? aspect;

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

    // The path stays the same while the image behind it changes — on upload,
    // on removal, and on any change to the organization's mark that an
    // inheriting company displays. The version is the only thing that moves in
    // all three cases.
    if (old.version != widget.version || old.path != widget.path) _fetch();
  }

  Future<void> _fetch() async {
    setState(() => _busy = true);

    // The version rides along, so the browser cannot serve a previous mark from
    // its own cache after the file behind the URL has changed.
    final bytes = await widget.api.getBytes(
      widget.version.isEmpty ? widget.path : '${widget.path}?v=${widget.version}',
    );

    if (!mounted) return;

    setState(() {
      _busy = false;
      _bytes = bytes == null ? null : Uint8List.fromList(bytes);
    });
  }

  Future<void> _upload() async {
    final picked = await Files.pickImage();

    if (picked == null || !mounted) return;

    // The shapes come from the server, so the cropper's output and the
    // validator's expectation are the same numbers rather than two copies.
    final shapes = await widget.api.get('/branding/shapes');

    if (!mounted) return;

    if (!shapes.ok) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(shapes.message)));
      return;
    }

    // Cropped before it is sent, never after. The server refuses anything that
    // is not exactly one of the two sizes, and almost no logo on anybody's disk
    // already is — so this is what turns that rule from a wall into a step.
    final cropped = await showSidePanel<Uint8List>(
      context,
      builder: (_) => LogoCropper(
        bytes: Uint8List.fromList(picked.bytes),
        shapes: (shapes.body['shapes'] as Map).cast<String, dynamic>(),
      ),
    );

    if (cropped == null || !mounted) return;

    setState(() => _busy = true);

    final response =
        await widget.api.postFile(widget.path, 'logo', 'logo.png', cropped);

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
    widget.onChanged?.call();
  }

  Future<void> _remove() async {
    setState(() => _busy = true);

    final response = await widget.api.delete(widget.path);

    if (!mounted) return;

    // Audit 2026-11-27: a refused removal said nothing.
    if (!response.ok) {
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_reason(response))),
      );
      return;
    }

    await _fetch();
    widget.onChanged?.call();
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

    // 4:1 for a lockup, 1:1 for a mark. The height is what stays fixed — a row
    // of logos with different heights is exactly the raggedness the fixed
    // shapes exist to prevent.
    final isWide = widget.aspect == 'wide';

    final badge = Container(
      width: isWide ? widget.size * 4 : widget.size,
      height: widget.size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: widget.bare ? null : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(widget.bare ? 6 : 10),
        border: widget.bare ? null : Border.all(color: scheme.outlineVariant),
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
                  padding: EdgeInsets.all(widget.bare ? 0 : widget.size * 0.12),
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
