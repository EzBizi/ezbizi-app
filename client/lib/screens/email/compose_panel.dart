import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../core/soft_keyboard.dart';

/// Starting a conversation — as the INBOX, and saying so before anything leaves.
///
/// ── The same rule as replying, and the same reason ──────────────
///
/// *"info sends as info"*. The person typing is not the sender here either: a
/// message that went out as jenny.smith@ would put a customer's answer in a
/// mailbox only Jenny can see, which is the failure the whole module exists to
/// avoid. So the address is stated above the box and again on the button, and
/// it comes from the MAILBOX record the server sent — never assembled here.
///
/// ── Where this differs from [ReplyPanel] ────────────────────────
///
/// Reply shows the recipient and subject as FACTS, because they belong to the
/// message being answered and the server decides them. Here somebody has to
/// type them, so they are fields — and that is the whole difference. Everything
/// about who the message goes out as is identical, deliberately: two send paths
/// with two different sender rules is how one of them ends up wrong.
///
/// ── A panel rather than a dialog ────────────────────────────────
///
/// House rule: no pop-ups, a screen or a right-hand panel.
class ComposePanel extends StatefulWidget {
  const ComposePanel({
    super.key,
    required this.api,
    required this.mailboxId,
    required this.sendAs,
  });

  final ApiClient api;
  final String mailboxId;

  /// The sending address, from the mailbox record. The button that opens this
  /// panel is the one place that decides whether sending is possible at all,
  /// so this widget never draws a "sending as nobody" state.
  final String sendAs;

  @override
  State<ComposePanel> createState() => ComposePanelState();
}

/// Public so a widget test can hand the panel a file without opening the
/// operating system's file picker, which a widget test cannot do.
class ComposePanelState extends State<ComposePanel> {
  final _to = TextEditingController();
  final _subject = TextEditingController();
  final _body = TextEditingController();

  /// Held in memory only, and only until the message is sent.
  ///
  /// Nothing is uploaded ahead of time and nothing is written down anywhere —
  /// the files go up inside the same request as the message. So closing this
  /// panel is a complete cancel, with nothing to tidy up on the server and no
  /// half-uploaded file waiting to be collected.
  final _files = <({String name, List<int> bytes})>[];

  bool _busy = false;
  String? _error;

  /// Matches the server: five files, two megabytes together.
  static const _maxFiles = 5;
  static const _maxTotalBytes = 2 * 1024 * 1024;

  int get _totalBytes => _files.fold(0, (sum, f) => sum + f.bytes.length);

  @override
  void initState() {
    super.initState();
    // Send lives or dies on there being both a recipient and something to say.
    _to.addListener(() => setState(() {}));
    _body.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _to.dispose();
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  /// Recipients, split on commas and semicolons.
  ///
  /// Both separators because people paste from mail programs that use either,
  /// and a semicolon silently treated as part of an address produces a refusal
  /// naming an address nobody typed.
  List<String> get _recipients => _to.text
      .split(RegExp(r'[,;]'))
      .map((address) => address.trim())
      .where((address) => address.isNotEmpty)
      .toList();

  bool get _canSend =>
      !_busy && _recipients.isNotEmpty && _body.text.trim().isNotEmpty;

  /// The half of [_attach] that follows the picker, exposed for tests.
  ///
  /// The OS file picker cannot be opened from a widget test, and stubbing it
  /// would leave the interesting half — what this panel does with the bytes,
  /// and what it then puts on the wire — asserted against a stub instead of
  /// against the panel.
  @visibleForTesting
  void debugAddFile(String name, List<int> bytes) {
    setState(() => _files.add((name: name, bytes: bytes)));
  }

  /// Refused HERE as well as at the server, and the sentence is the same size.
  ///
  /// Not because the server's check is doubted — it is the one that counts —
  /// but because a person who has just waited for a 2MB upload to be rejected
  /// has been made to wait for news this screen already had.
  Future<void> _attach() async {
    if (_files.length >= _maxFiles) return;

    final picked = await Files.pickBytes();

    if (picked == null || !mounted) return;

    if (_totalBytes + picked.bytes.length > _maxTotalBytes) {
      setState(() {
        // Says WHY, not only what. The limit is not a number somebody picked
        // — it is the tightest of the three mail services this can send
        // through, and a person who hits it can act on that ("share a link")
        // where they cannot act on "no".
        _error = 'Those files come to more than 2 MB together. Mail services cap '
            'what can travel inside a single message, so send fewer, or share '
            'the large ones as a link instead.';
      });
      return;
    }

    setState(() {
      _error = null;
      _files.add((name: picked.name, bytes: picked.bytes));
    });
  }

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    // Two shapes for one endpoint: JSON when there is nothing to attach,
    // multipart when there is. Not multipart always — a message with no files
    // has no reason to travel as a form, and JSON is what every other verb in
    // this client sends.
    final response = _files.isEmpty
        ? await widget.api.post(
            '/email/mailboxes/${widget.mailboxId}/messages',
            {
              'to': _recipients,
              'subject': _subject.text.trim(),
              'body': _body.text.trim(),
            },
          )
        : await widget.api.postFiles(
            '/email/mailboxes/${widget.mailboxId}/messages',
            'attachments',
            _files,
            fields: {
              // Repeated keys, because a multipart form has no arrays — this is
              // the shape PHP reads back as `to[0]`, `to[1]`.
              for (var i = 0; i < _recipients.length; i++)
                'to[$i]': _recipients[i],
              'subject': _subject.text.trim(),
              'body': _body.text.trim(),
            },
          );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        // The server's own sentence, in full — a refused send is one of the
        // places where knowing WHY is what tells somebody what to do next.
        _error = response.reason;
      });
      return;
    }

    if (mounted) Navigator.of(context).pop(widget.sendAs);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final count = _recipients.length;

    return SidePanel(
      title: 'New message',
      subtitle: 'From ${widget.sendAs}',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _canSend ? _send : null,
          // The address on the button as well as above it. This is the last
          // thing read before the message leaves, and "Send" alone is the word
          // that lets somebody send from the wrong inbox without ever having
          // been told which one they were in.
          child: Text(_busy ? 'Sending…' : 'Send as ${widget.sendAs}'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: theme.colorScheme.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: theme.colorScheme.primary.withValues(alpha: 0.35),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Sending as ${widget.sendAs}',
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 6),
                Text(
                  'This goes out as the inbox, not as you. Whoever gets it sees '
                  'that address, and their answer comes back here where '
                  'everybody can see it.',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _to,
            enabled: !_busy,
            autofocus: autofocusOnOpen,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: 'To',
              hintText: 'name@example.com',
              // Says what the commas do, rather than leaving somebody to find
              // out by having a message go to one person instead of three.
              helperText: count > 1
                  ? '$count recipients — separate them with commas.'
                  : 'Separate several with commas.',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _subject,
            enabled: !_busy,
            decoration: const InputDecoration(
              labelText: 'Subject',
              // Not required, and the server fills it in rather than sending a
              // blank one — some mail servers treat a subjectless message as
              // suspicious.
              helperText: 'Optional.',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _body,
            enabled: !_busy,
            minLines: 6,
            maxLines: null,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              labelText: 'Message',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Wrap, not Row: five filenames beside a button overflow a 375px
              // panel, and a chip that has been pushed off the edge is a file
              // somebody cannot remove.
              TextButton.icon(
                onPressed: (_busy || _files.length >= _maxFiles) ? null : _attach,
                icon: const Icon(Icons.attach_file, size: 18),
                label: Text(_files.isEmpty ? 'Attach a file' : 'Attach another'),
              ),
              for (final file in _files)
                InputChip(
                  label: Text(file.name),
                  onDeleted: _busy ? null : () => setState(() => _files.remove(file)),
                  deleteIconColor: theme.colorScheme.onSurfaceVariant,
                ),
            ],
          ),
          if (_files.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              // The running total, because the limit is on the SUM and a person
              // adding a fourth file cannot otherwise tell how close they are.
              '${_files.length} of $_maxFiles files · '
              '${(_totalBytes / 1024).round()} KB of 2048 KB',
              style: theme.textTheme.labelSmall,
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
