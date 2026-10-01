import 'dart:convert';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import 'signature_pad.dart';

/// How a drawn signature travels: a PNG as a data URL (HR, c2bc2668).
const signaturePngUrl = 'data:image/png;base64,';

/// What a [SignatureField] holds: new strokes, or the signer's saved
/// signature standing in for them.
class SignatureFieldController extends ChangeNotifier {
  SignatureFieldController() {
    ink.addListener(notifyListeners);
  }

  final ink = SignatureController();

  /// The signer's own saved signature (`GET /me/signature`), and whether it
  /// is standing in for new strokes.
  String? _saved;
  bool _usingSaved = false;

  /// Something to sign with: strokes, or the saved one.
  bool get ready => _usingSaved || !ink.isEmpty;

  /// The signature as it is sent: a PNG data URL. Null while there is none.
  Future<String?> dataUrl() async {
    if (_usingSaved) return _saved;
    final png = await ink.toPng();
    return png == null ? null : '$signaturePngUrl${base64Encode(png)}';
  }

  void _useSaved(bool on) {
    ink.clear();
    _usingSaved = on;
    notifyListeners();
  }

  @override
  void dispose() {
    ink.dispose();
    super.dispose();
  }
}

/// The pad, with A1 Tools' saved signature beside it: "Use my saved
/// signature" puts the signer's own in the pad's place, "Save as my
/// signature" keeps a new one for next time, Clear starts again.
class SignatureField extends StatefulWidget {
  const SignatureField({
    super.key,
    required this.api,
    required this.controller,
    this.enabled = true,
  });

  final ApiClient api;
  final SignatureFieldController controller;
  final bool enabled;

  @override
  State<SignatureField> createState() => _SignatureFieldState();
}

class _SignatureFieldState extends State<SignatureField> {
  bool _savingIt = false;
  String? _saveNote;

  SignatureFieldController get _c => widget.controller;

  @override
  void initState() {
    super.initState();
    _loadSaved();
  }

  /// Quiet when there is none, or when the server has no such endpoint yet:
  /// the button to use it just does not appear.
  Future<void> _loadSaved() async {
    final response = await widget.api.get('/me/signature');
    final drawing = response.ok && response.data is Map
        ? (response.data as Map)['drawing']
        : null;
    if (!mounted ||
        drawing is! String ||
        !drawing.startsWith(signaturePngUrl)) {
      return;
    }
    setState(() => _c._saved = drawing);
  }

  Future<void> _saveDrawing() async {
    final drawing = await _c.dataUrl();
    if (drawing == null || !mounted) return;
    setState(() {
      _savingIt = true;
      _saveNote = null;
    });
    final response = await widget.api.put('/me/signature', {
      'drawing': drawing,
    });
    if (!mounted) return;
    setState(() {
      _savingIt = false;
      if (response.ok) _c._saved = drawing;
      _saveNote = response.ok ? 'Saved as your signature.' : response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListenableBuilder(
      listenable: _c,
      builder: (context, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_c._usingSaved)
            Container(
              key: const Key('signature-saved'),
              height: 200,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: EzBiziTheme.paper,
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
                border: Border.all(color: theme.colorScheme.primary, width: 2),
              ),
              child: Image.memory(
                base64Decode(_c._saved!.substring(signaturePngUrl.length)),
                fit: BoxFit.contain,
              ),
            )
          else
            SignaturePad(
              key: const Key('signature-pad'),
              controller: _c.ink,
              enabled: widget.enabled,
            ),
          Wrap(
            alignment: WrapAlignment.end,
            spacing: 4,
            children: [
              if (_c._saved != null && !_c._usingSaved)
                TextButton.icon(
                  key: const Key('signature-use-saved'),
                  onPressed: widget.enabled ? () => _c._useSaved(true) : null,
                  icon: const Icon(Icons.history_edu, size: 18),
                  label: const Text('Use my saved signature'),
                ),
              if (!_c.ink.isEmpty)
                TextButton.icon(
                  key: const Key('signature-save'),
                  onPressed: !widget.enabled || _savingIt ? null : _saveDrawing,
                  icon: const Icon(Icons.bookmark_add_outlined, size: 18),
                  label: Text(_savingIt ? 'Saving…' : 'Save as my signature'),
                ),
              TextButton.icon(
                key: const Key('signature-clear'),
                onPressed: !_c.ready || !widget.enabled
                    ? null
                    : () => _c._useSaved(false),
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Clear'),
              ),
            ],
          ),
          if (_saveNote != null)
            Align(
              alignment: Alignment.centerRight,
              child: Text(_saveNote!, style: theme.textTheme.bodySmall),
            ),
        ],
      ),
    );
  }
}
