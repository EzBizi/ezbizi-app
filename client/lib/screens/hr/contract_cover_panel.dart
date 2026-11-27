import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';
import '../../widgets/logo_badge.dart';
import '../../widgets/side_panel.dart';

/// Choosing the front page of a contract — the owner's *"ability to create a
/// cover for the contract as well, which means being able to use either the
/// company logo that's already been uploaded or a new file altogether."*
///
/// ── Two offers, and the first one is one press ───────────────────
///
/// Most organizations that want a cover want their own mark on it, and their
/// own mark is already on the platform: it was uploaded under branding, it is
/// what [LogoBadge] draws everywhere else, and asking somebody to find the file
/// again and upload a second copy of it would be asking them to create a second
/// home for one picture. So the logo is a REFERENCE — this panel stores "the
/// organization's logo", not a copy of it, and an organization that later
/// changes its mark changes what every contract pointing at it shows.
///
/// A file of your own is the second offer, for the covers a logo cannot be: a
/// designed front page, a photograph, a scanned letterhead.
///
/// ── Why this is not a second logo uploader ───────────────────────
///
/// [LogoBadge] crops to one of two published shapes before it sends anything,
/// because the branding endpoint refuses a file that is not exactly one of them.
/// A cover is a PAGE, and a page cropped to a 4:1 lockup is not a cover. So the
/// file path here is a plain upload with no cropper, and the logo path does not
/// upload at all — which is the opposite of duplicating that widget's rules.
/// Where a logo is merely being SHOWN, this file uses [LogoBadge] itself.
///
/// ── A cover is part of the version, and it says so ───────────────
///
/// This is the sentence that has to arrive before the press rather than after
/// it. Changing the cover is not tweaking a picture: the cover is part of the
/// document, the document is what somebody signs, and the version is what a
/// signature is pinned to. So it publishes a new version exactly as rewording a
/// clause does, and the subtitle of this panel says that in the same voice the
/// designer already uses for the wording.
///
/// ── The wire shape ───────────────────────────────────────────────
///
///   read    `cover` on `GET /hr/contracts/{contract}` — absent or null for no
///           cover, `{"source": "logo"}`, or `{"source": "file", "upload_id":
///           …, "filename": …, "image_path": …}` (`ContractVersion::coverPayload`).
///   write   `cover` beside `fields` and `body` on `POST /hr/contracts` and on
///           `POST /hr/contracts/{contract}/versions`, preview and publish
///           alike — null, or the same map without `image_path`, which is the
///           server's own answer to where the bytes can be read.
///   upload  `POST /hr/contracts/covers`, multipart field `cover`, answering
///           `{"data": {"upload_id": …, "filename": …, "image_path": …}}`.
///
/// The upload is not hung off a contract id, at both ends and for the same
/// stated reason: a contract being created does not have one — the hole
/// `MergeTagSyntax` documents for the tag list — and a picker that worked while
/// editing and refused while creating would be a cover you could only add on
/// the second visit.
///
/// `PATCH /hr/contracts/{contract}` REFUSES a cover, by name, with a paragraph
/// saying that changing it publishes a version. Nothing here sends one there.
///
/// ── What the server publishes that this does not yet read ────────
///
/// `catalogue.cover` carries `sources` (the two keys with the server's own
/// words for them), `format` and `max_kilobytes`. None of it reaches this
/// screen: `ContractVocabulary` does not parse a `cover` block, and that class
/// belongs to another agent this round. So the two options are worded here and
/// the size and format rules are left to the server's refusal, which arrives
/// verbatim — the same division the designer already keeps, blocking on an
/// unfinished form and never on a policy it has restated.
///
/// Nothing here invents a state when the server says nothing: an absent `cover`
/// is [ContractCover.none], which is what almost every contract is.
class ContractCoverPanel extends StatefulWidget {
  const ContractCoverPanel({
    super.key,
    required this.api,
    required this.cover,
    this.pickImage = Files.pickImage,
    this.upload,
  });

  final ApiClient api;

  /// What the contract's cover is as the designer holds it, so the panel opens
  /// on the answer already given rather than on nothing.
  final ContractCover cover;

  /// How a file is chosen.
  ///
  /// Injected for the same reason `files_io.dart` exists at all: the picker is
  /// the browser's own `<input type="file">`, there is no such element off the
  /// web, and the stub answers null. Without a seam here the only testable
  /// thing about the file option would be that it is drawn — never that
  /// choosing one uploads it and becomes the cover.
  final Future<PickedBytes?> Function() pickImage;

  /// How the chosen file is sent, or null for the real thing.
  ///
  /// A seam for the same kind of reason, and one worth writing down rather
  /// than shrugging at: `ApiClient.postFile` builds an `http.MultipartRequest`
  /// and calls `send()` on it, and `BaseRequest.send()` constructs a `Client()`
  /// of its own — so the client an ApiClient was handed, mock or otherwise,
  /// never sees an upload. Without this there is no way to assert that a
  /// refusal is shown rather than a cover half-chosen, or that the version
  /// ends up pointing at the id the server answered with.
  final Future<ApiResponse> Function(PickedBytes picked)? upload;

  @override
  State<ContractCoverPanel> createState() => _ContractCoverPanelState();
}

class _ContractCoverPanelState extends State<ContractCoverPanel> {
  late ContractCover _choice = widget.cover;

  /// The organization's mark, and whether the question has been answered yet.
  ///
  /// Fetched rather than assumed, because the answer decides what this panel
  /// may offer. An organization with no logo must not be given a one-press
  /// option that would produce a blank page, and it must not be given a dead
  /// control either — it is told where a logo is uploaded.
  Uint8List? _logo;
  bool _logoKnown = false;

  /// The image just chosen, so the panel can draw what was picked before any
  /// contract has been published against it.
  Uint8List? _picked;

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadLogo();
  }

  Future<void> _loadLogo() async {
    final bytes = await widget.api.getBytes('/branding/organization');

    if (!mounted) return;

    setState(() {
      _logoKnown = true;
      _logo = bytes == null ? null : Uint8List.fromList(bytes);
    });
  }

  /// The real upload — see [ContractCoverPanel.upload].
  Future<ApiResponse> _send(PickedBytes picked) => widget.api.postFile(
    '/hr/contracts/covers',
    'cover',
    picked.name,
    picked.bytes,
  );

  Future<void> _chooseFile() async {
    final picked = await widget.pickImage();

    if (picked == null || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await (widget.upload ?? _send)(picked);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        // The server's own words. It refuses an oversized file, a file that is
        // not an image, and a file whose transfer never finished for three
        // different reasons, and "could not upload" tells nobody which.
        _error = response.reason;
      });
      return;
    }

    final data = response.data;
    final row = data is Map
        ? Map<String, dynamic>.from(data)
        : const <String, dynamic>{};
    final id = row['upload_id'] as String?;

    if (!mounted) return;

    if (id == null) {
      setState(() {
        _busy = false;
        // Not silently kept. Without an id there is nothing to put in the
        // version, so a cover that LOOKED chosen would publish as no cover at
        // all — which is the failure somebody would only find on the document.
        _error =
            'The file went up, but the server did not say where it was kept, '
            'so there is nothing this version could point at. Try again — and '
            'if it keeps happening, the cover has not been stored and choosing '
            'it would publish a contract with no front page.';
      });
      return;
    }

    setState(() {
      _busy = false;
      _picked = Uint8List.fromList(picked.bytes);
      _choice = ContractCover.file(
        uploadId: id,
        filename: row['filename'] as String? ?? picked.name,
        imagePath: row['image_path'] as String?,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'The cover',
      // The consequence, before the press. The designer says the same thing
      // about the wording in the same voice, because it is the same fact: the
      // version is what a signature is pinned to, and the cover is part of it.
      subtitle:
          'The front page of the document. A cover is part of the version, the '
          'same as the wording — choosing one here changes nothing on its own, '
          'and publishing it writes a new version that everybody onboarded '
          'afterwards gets.',
      width: 480,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(_choice),
          // "Leave it with no cover" rather than a greyed-out button or a
          // silent Cancel: choosing to have none is a decision somebody makes
          // on purpose, and it deserves a sentence that reads like one.
          child: Text(
            _choice.isNone ? 'Leave it with no cover' : 'Use this cover',
          ),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_error != null) ...[
            // A box rather than a snackbar: over a side panel that is gone in
            // four seconds, and the sentence is still needed while they go
            // and find a smaller file.
            ErrorNote(_error!),
            const SizedBox(height: 16),
          ],
          _option(
            context,
            source: CoverSource.none,
            title: 'No cover',
            note:
                'The document starts on the first page of the agreement. Most '
                'contracts do, and nothing about one is missing.',
            onChosen: () => setState(() => _choice = ContractCover.none),
          ),
          const SizedBox(height: 10),
          _logoOption(context),
          const SizedBox(height: 10),
          _fileOption(context),
        ],
      ),
    );
  }

  /// The mark the organization has already uploaded, offered as a reference.
  Widget _logoOption(BuildContext context) {
    final hasLogo = _logo != null;

    return _option(
      context,
      source: CoverSource.logo,
      title: 'The logo you have already uploaded',
      note: !_logoKnown
          ? 'Looking for your organization’s mark…'
          : hasLogo
          ? 'Your organization’s mark, on a page of its own. Each version keeps '
                'the logo as it was when published; after changing the logo, '
                'publish a new version for it to appear.'
          // R8: a sentence saying what to do, not a disabled control that
          // explains nothing.
          : 'Nobody has uploaded a logo for your organization yet. Add one on '
                'the Organization screen and it will be offered here.',
      // Not choosable until there is something to choose. A cover pointing at a
      // logo that does not exist is a blank first page on a signed contract.
      onChosen: hasLogo
          ? () => setState(() => _choice = ContractCover.logo)
          : null,
      trailing: hasLogo ? _Thumbnail(bytes: _logo) : null,
    );
  }

  Widget _fileOption(BuildContext context) {
    final chosen = _choice.source == CoverSource.file;

    return _option(
      context,
      source: CoverSource.file,
      title: 'A file of your own',
      note: chosen && _choice.filename != null
          ? _choice.filename!
          : 'A designed front page, a photograph, a scanned letterhead — an '
                'image used for this contract and nothing else.',
      // Choosing this option IS choosing a file: there is nothing to select
      // until one has been picked, so the row and the button do the same thing
      // rather than leaving a selected option with no file behind it.
      onChosen: _busy ? null : _chooseFile,
      trailing: chosen && _picked != null ? _Thumbnail(bytes: _picked) : null,
      action: TextButton(
        onPressed: _busy ? null : _chooseFile,
        child: Text(
          _busy
              ? 'Uploading…'
              : chosen
              ? 'Replace it'
              : 'Choose an image',
        ),
      ),
    );
  }

  /// One of the three answers.
  ///
  /// A row rather than a dropdown, for the reason the designer gives about
  /// `filled_by`: all the answers visible with the consequence of each written
  /// underneath beats one answer visible and two behind a tap. It stacks down
  /// the panel, so R7 costs nothing here — at 375px the panel is the window and
  /// each option is still one full-width row.
  Widget _option(
    BuildContext context, {
    required CoverSource source,
    required String title,
    required String note,
    required VoidCallback? onChosen,
    Widget? trailing,
    Widget? action,
  }) {
    final theme = Theme.of(context);
    final selected = _choice.source == source;

    return Semantics(
      inMutuallyExclusiveGroup: true,
      selected: selected,
      enabled: onChosen != null,
      button: true,
      child: Material(
        color: selected
            ? theme.colorScheme.primaryContainer.withValues(alpha: 0.45)
            : theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          onTap: onChosen,
          borderRadius: BorderRadius.circular(12),
          child: Container(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: selected
                    ? theme.colorScheme.primary
                    : theme.colorScheme.outlineVariant,
                width: selected ? 2 : 1,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // The glyph is not the only signal — the border and the fill
                    // move too. Colour alone would leave the chosen option
                    // unreadable to anybody who cannot tell these two apart.
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Icon(
                        selected
                            ? Icons.radio_button_checked
                            : Icons.radio_button_unchecked,
                        size: 18,
                        color: onChosen == null
                            ? theme.colorScheme.outline
                            : null,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(title, style: theme.textTheme.titleSmall),
                          const SizedBox(height: 2),
                          Text(note, style: theme.textTheme.bodySmall),
                        ],
                      ),
                    ),
                    if (trailing != null) ...[
                      const SizedBox(width: 12),
                      trailing,
                    ],
                  ],
                ),
                if (action != null)
                  Align(alignment: Alignment.centerRight, child: action),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A small picture of a cover, drawn the way [LogoBadge] draws a mark.
///
/// `contain` and never `cover`, and the same bordered slot: a cover cropped to
/// fill a square is a cover with its edges cut off, which is the one thing
/// artwork must not have done to it.
class _Thumbnail extends StatelessWidget {
  const _Thumbnail({required this.bytes});

  final Uint8List? bytes;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: 44,
      height: 56,
      clipBehavior: Clip.antiAlias,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: bytes == null
          ? Icon(Icons.image_outlined, size: 18, color: scheme.onSurfaceVariant)
          : Image.memory(bytes!, fit: BoxFit.contain),
    );
  }
}

// ── the cover itself ───────────────────────────────────────────────

/// Where a contract's cover comes from.
///
/// [none] is a VALUE and not a null, and that is the whole of why this is an
/// enum rather than a nullable pointer at a file. A contract with no cover is
/// the ordinary case — almost every contract anybody writes — so "no cover" is
/// something somebody chose, drawn as deliberately as the other two and
/// published as deliberately. A null would make it the absence of an answer,
/// which is a different thing and reads on screen as something that failed.
enum CoverSource { none, logo, file }

/// A contract's cover, as this end holds it and as it goes on the wire.
///
/// See [ContractCoverPanel] for the shape assumed of the server, which is being
/// built in parallel and does not exist yet.
@immutable
class ContractCover {
  const ContractCover._(this.source, {this.uploadId, this.filename, this.imagePath});

  /// The ordinary case: the document starts on the agreement.
  static const ContractCover none = ContractCover._(CoverSource.none);

  /// The organization's own mark, COPIED into the version rather than
  /// referenced — so a rebrand does NOT reach a contract already published.
  ///
  /// This said the opposite, and the opposite is the bug the design exists to
  /// prevent. `organizations.logo_path` is mutable: pointing at it would mean
  /// replacing the logo silently rewrote the front page of every contract
  /// signed under the old one, years after the fact. A signed document whose
  /// first page can change is not a signed document.
  ///
  /// So the server copies the bytes at publish (see ContractVersion). The
  /// organization still never uploads its logo twice — it simply cannot alter
  /// a document somebody has already put their name to.
  static const ContractCover logo = ContractCover._(CoverSource.logo);

  /// An image uploaded for this contract.
  ///
  /// [uploadId] is required and is what the version points at. A file with no
  /// id is not a cover this end will carry: it would publish as nothing, and
  /// the first anybody would know is a signed contract with a blank first page.
  factory ContractCover.file({
    required String uploadId,
    String? filename,
    String? imagePath,
  }) => ContractCover._(
    CoverSource.file,
    uploadId: uploadId,
    filename: filename,
    imagePath: imagePath,
  );

  final CoverSource source;

  /// What the server called the stored upload — sent back when the version is
  /// published, and meaningless to this end beyond being carried.
  final String? uploadId;

  /// What it was called on the disk it came from, for the person who chose it.
  final String? filename;

  /// Where the bytes can be fetched, when the server says. Null is ordinary —
  /// a freshly uploaded cover is drawn from what was picked, and a server that
  /// offers no path gets an icon rather than an invented URL.
  final String? imagePath;

  bool get isNone => source == CoverSource.none;

  /// `cover` out of `GET /hr/contracts/{contract}`.
  ///
  /// Anything this does not recognise is [none]. That is the safe direction and
  /// the honest one: a cover this build cannot describe must not be drawn as a
  /// cover it invented, and it must not silently become a different one either
  /// — a template carrying a source added by a newer server reads as no cover
  /// here, which is visible, rather than as the logo, which would be a lie.
  factory ContractCover.fromJson(Object? node) {
    if (node is! Map) return none;

    final source = node['source'];

    if (source == 'logo') return logo;

    if (source == 'file') {
      final id = node['upload_id'];

      // No id, no cover. See [ContractCover.file].
      if (id is! String || id.isEmpty) return none;

      return ContractCover.file(
        uploadId: id,
        filename: node['filename'] as String?,
        imagePath: node['image_path'] as String?,
      );
    }

    return none;
  }

  /// What travels beside `fields` and `body`.
  ///
  /// Null for no cover, matching the way the body is sent: one spelling for one
  /// state, so the server never has to decide whether `{}` and null mean the
  /// same emptiness.
  ///
  /// [imagePath] is not sent. It is the server's answer to "where can this be
  /// read", and sending it back would be this end telling the server where it
  /// keeps its own files.
  Map<String, dynamic>? toJson() => switch (source) {
    CoverSource.none => null,
    CoverSource.logo => {'source': 'logo'},
    CoverSource.file => {'source': 'file', 'upload_id': uploadId},
  };

  @override
  bool operator ==(Object other) =>
      other is ContractCover &&
      other.source == source &&
      other.uploadId == uploadId;

  /// [filename] and [imagePath] are deliberately out of both this and `==`:
  /// they describe the same stored file, so two covers pointing at one
  /// [uploadId] are the same cover whatever the server called it. Including
  /// them would make a re-read of the same template look like a change worth
  /// publishing.
  @override
  int get hashCode => Object.hash(source, uploadId);
}
