import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import 'mail_data.dart';
import 'mailbox_rail.dart';
import 'message_body.dart';
import 'message_html.dart';
import 'message_list.dart';
import 'reply_panel.dart';
import 'settings_tab.dart';

/// Email — a window onto the addresses an organization shares.
///
/// ── What this module is, in the owner's words ───────────────────
///
/// *"The difference is the capacity to let people answer emails they don't
/// regularly have access to — like company wide emails that receive customer
/// emails or forms."*
///
/// So: `info@`, `bookings@`, `careers@`. Not somebody's personal mailbox, and
/// not a CRM mail integration — mail is deliberately NOT linked to customers or
/// jobs. Which of an organization's inboxes a role reaches is granted per
/// inbox, and the list endpoint answers with what this person may open in
/// the company they are looking at, so there is no filtering in this file
/// to get wrong.
///
/// ── Read live, and hold nothing ─────────────────────────────────
///
/// *"we don't want to store thousands and thousands of emails on our
/// database."* Headers arrive when the list is drawn and a body arrives when a
/// message is opened. Nothing here syncs, nothing mirrors a folder, and the
/// state below is a screen's worth of what is on screen — it goes when the tab
/// does.
///
/// ── Two faces: Inbox, and Settings ──────────────────────────────
///
/// *"we should have 2 sub tabs on the email module, one on the inbox itself
/// and one for it's settings."* The strip is TabBarLike, which is what HrScreen
/// draws its five with and what settings_shell and automations_screen use —
/// one destination with two faces rather than a second application inside the
/// application.
///
/// Inbox is everything below in this file and is unchanged by the split.
/// Settings is where a connection is made, replaced and taken away, and where
/// an inbox that has stopped working says why — see settings_tab.dart, which is
/// also the only place in this client that reaches the connect endpoints.
///
/// The Settings tab is not built until somebody opens it, and then it keeps its
/// State: opening Email should not cost a second request for a page most
/// readers never look at, and glancing back at the mail should not throw the
/// list of connections away. Same arrangement, and the same reasons, as HR's.
///
/// ── Why there is no "Email" heading above this ──────────────────
///
/// The sidebar already says it, in the organization's own word for the module,
/// and HrScreen makes the same call for the same reason: the product's name
/// printed twice in two inches. The heading this screen does have is the one
/// nobody can afford to miss — which inbox you are in. See [InboxBar].
class EmailScreen extends StatefulWidget {
  const EmailScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<EmailScreen> createState() => _EmailScreenState();
}

/// Which single pane is on screen, below the width that fits three.
enum _Pane { inboxes, messages, message }

class _EmailScreenState extends State<EmailScreen> {
  /// Above this the three panes sit side by side; below it there is one.
  ///
  /// ── R7, and the measurement behind the number ───────────────
  ///
  /// List-plus-detail is the layout that breaks worst on a phone, and this one
  /// is list-plus-list-plus-detail. Three columns at 375px are three unusable
  /// columns; at 700 — a tablet held upright, and a browser window somebody has
  /// dragged narrow — the message list is down to about 200px, which is a
  /// subject line every three words.
  ///
  /// 1000 is where the three of them still have room to be read: 260 for the
  /// inboxes leaves 740 to split between a list of subjects and the message
  /// itself. Below it, one pane at a time with the inbox named above it, and
  /// the panes that are not on screen are NOT BUILT — an off-screen column is
  /// still a column that lays out, overflows and is read aloud by a screen
  /// reader.
  static const double _splitAbove = 1000;

  /// The inbox rail's width on a wide screen. Enough for an address on one or
  /// two lines beside its unread count.
  static const double _railWidth = 260;

  bool _loadingMailboxes = true;
  String? _mailboxesError;
  List<Mailbox> _mailboxes = const [];
  Mailbox? _selected;

  bool _loadingList = true;
  String? _listError;
  List<MessageHeader> _messages = const [];
  int _page = 1;
  int _lastPage = 1;
  String? _nextCursor;
  bool _fetching = false;
  String? _pageError;

  /// Which list request is the current one.
  ///
  /// The same ticket customers_screen keeps, and needed harder here: switching
  /// inbox while a slow inbox is still answering would otherwise land one
  /// mailbox's messages under another mailbox's name, which on this screen is
  /// the mistake the whole design is arranged against.
  int _listRequest = 0;

  String? _openId;
  MessageDetail? _open;
  bool _loadingMessage = false;
  String? _messageError;
  int _messageRequest = 0;

  _Pane _pane = _Pane.messages;

  /// Inbox's place in the strip below, and where this module opens.
  ///
  /// The mail is what somebody came for; the connections behind it are what
  /// they come for once, or on the day one breaks.
  static const _inboxTab = 0;

  int _tab = _inboxTab;

  /// Which sub-tabs have been shown at least once — HrScreen's set, for the
  /// two reasons its own note gives: a tab nobody has opened must not fetch,
  /// and a tab they have opened must not fetch again every time they glance at
  /// the other one.
  final Set<int> _shown = {_inboxTab};

  @override
  void initState() {
    super.initState();
    _loadMailboxes();
  }

  Future<void> _loadMailboxes() async {
    setState(() {
      _loadingMailboxes = _mailboxes.isEmpty;
      _mailboxesError = null;
    });

    final response = await widget.api.get('/email/mailboxes');

    if (!mounted) return;

    setState(() {
      _loadingMailboxes = false;

      if (!response.ok) {
        _mailboxesError = response.message;
        return;
      }

      final data = response.data;

      _mailboxes = [
        for (final entry in (data is List ? data : const []))
          if (entry is Map<String, dynamic>) Mailbox.fromJson(entry),
      ];

      // Landing in the first one rather than on a chooser: an inbox is named on
      // screen at all times, so there is no ambiguity about where you are, and
      // a screen whose first act is to ask a question nobody has an opinion
      // about is a screen with an extra step in it. Nothing can be SENT without
      // passing the address twice more.
      _selected = _mailboxes.isEmpty ? null : _mailboxes.first;
      _pane = _Pane.messages;
    });

    if (_selected != null) _loadMessages();
  }

  Future<void> _loadMessages({int page = 1, String? cursor}) async {
    final mailbox = _selected;

    if (mailbox == null) return;
    final appending = (cursor != null && cursor.isNotEmpty) || page > 1;
    if (appending && _fetching) return;

    final request = ++_listRequest;

    setState(() {
      // A spinner only when there is nothing to keep — AsyncBody's rule.
      _loadingList = !appending && _messages.isEmpty;
      _fetching = true;
      _listError = null;
      _pageError = null;
    });

    final query = <String, String>{};
    if (cursor != null && cursor.isNotEmpty) {
      query['cursor'] = cursor;
    } else if (page > 1) {
      query['page'] = '$page';
    }

    final response = query.isEmpty
        ? await widget.api.get('/email/mailboxes/${mailbox.id}/messages')
        : await widget.api.get('/email/mailboxes/${mailbox.id}/messages', query);

    if (!mounted || request != _listRequest) return;

    setState(() {
      _loadingList = false;
      _fetching = false;

      if (!response.ok) {
        // The server's sentence, verbatim, where the rows would have been.
        //
        // This is the single most important refusal on the screen. An inbox
        // whose token has expired, or whose IMAP host has stopped answering,
        // must never be drawn as an empty list: "no mail" is a sentence nobody
        // investigates, and a shared inbox that silently stops delivering is a
        // customer who never got an answer.
        if (!appending) {
          _listError = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final parsed = _messagePage(response.data, response.body['meta']);
      _messages = appending ? [..._messages, ...parsed.rows] : parsed.rows;
      _page = parsed.page;
      _lastPage = parsed.lastPage;
      _nextCursor = parsed.nextCursor;
    });
  }

  /// Live API: `{ messages, next_cursor }`. Tests / older assumption: a list
  /// plus `meta.page`. Both have to produce the same rows.
  ({List<MessageHeader> rows, int page, int lastPage, String? nextCursor})
  _messagePage(Object? data, Object? meta) {
    final metaMap = meta is Map<String, dynamic> ? meta : const <String, dynamic>{};

    if (data is List) {
      final page = metaMap['page'] as int? ?? 1;
      final last = metaMap['last_page'] as int? ?? page;
      return (
        rows: [
          for (final entry in data)
            if (entry is Map<String, dynamic>) MessageHeader.fromJson(entry),
        ],
        page: page,
        lastPage: last,
        nextCursor: null,
      );
    }

    if (data is Map<String, dynamic>) {
      final messages = data['messages'];
      final next = data['next_cursor'] as String?;
      return (
        rows: [
          for (final entry in (messages is List ? messages : const []))
            if (entry is Map<String, dynamic>) MessageHeader.fromJson(entry),
        ],
        page: 1,
        lastPage: (next != null && next.isNotEmpty) ? 2 : 1,
        nextCursor: (next != null && next.isNotEmpty) ? next : null,
      );
    }

    return (rows: const [], page: 1, lastPage: 1, nextCursor: null);
  }

  /// Open one message. The body is fetched now, because it was never held.
  ///
  /// Opening is also what records the read: the server notes it on this
  /// request, which is what puts "Seen by" on somebody else's screen. There is
  /// deliberately no second call announcing it — two ways of saying the same
  /// thing is two states that can disagree.
  Future<void> _openMessage(String id) async {
    final mailbox = _selected;

    if (mailbox == null) return;

    final request = ++_messageRequest;

    setState(() {
      _openId = id;
      _open = null;
      _loadingMessage = true;
      _messageError = null;
      _pane = _Pane.message;
    });

    final response = await widget.api.get(
      '/email/mailboxes/${mailbox.id}/messages/$id',
    );

    if (!mounted || request != _messageRequest) return;

    setState(() {
      _loadingMessage = false;

      if (!response.ok) {
        _messageError = response.message;
        return;
      }

      final data = response.data;

      if (data is Map<String, dynamic>) {
        _open = MessageDetail.fromJson(data);
        // The row said it was unread and it is not any more. Corrected here
        // rather than by refetching the list, which would cost a request and
        // scroll somebody back to the top of it.
        _messages = [
          for (final row in _messages)
            if (row.id != id)
              row
            else
              MessageHeader(
                id: row.id,
                fromName: row.fromName,
                fromAddress: row.fromAddress,
                subject: row.subject,
                preview: row.preview,
                receivedAt: row.receivedAt,
                unread: false,
                hasAttachments: row.hasAttachments,
                seenBy: row.seenBy,
              ),
        ];
      } else {
        _messageError = 'That message came back in a shape this app could not '
            'read. Try opening it again.';
      }
    });
  }

  void _select(Mailbox mailbox) {
    if (mailbox.id == _selected?.id) {
      setState(() => _pane = _Pane.messages);
      return;
    }

    setState(() {
      _selected = mailbox;
      _pane = _Pane.messages;

      // Everything on screen belonged to the inbox being left. A message from
      // the last inbox still sitting in the detail pane under a new inbox's
      // name is the exact confusion this screen must not produce.
      _messages = const [];
      _loadingList = true;
      _listError = null;
      _pageError = null;
      _page = 1;
      _lastPage = 1;
      _nextCursor = null;
      _openId = null;
      _open = null;
      _messageError = null;
      _loadingMessage = false;
    });

    _loadMessages();
  }

  /// Why replying is not offered, or null when it is.
  ///
  /// Two different refusals with two different remedies, and both are sentences
  /// (R8) rather than anything internal. The first belongs to the server, which
  /// knows both halves of the answer — the role's permission and this
  /// organization's grant on this inbox.
  String? _replyRefusal(Mailbox mailbox, MessageDetail message) {
    if (!mailbox.canReply) {
      return mailbox.replyRefusal ??
          'You can read ${mailbox.address} but not answer from it. Whoever '
              'looks after this inbox can give your role permission to reply.';
    }

    if (message.replyAs == null || message.replyAs!.isEmpty) {
      return 'This message came back without the address a reply would go out '
          'as, so there is nothing to answer as yet. Try opening it again.';
    }

    return null;
  }

  Future<void> _reply(Mailbox mailbox, MessageDetail message) async {
    final sent = await showSidePanel<String>(
      context,
      builder: (_) => ReplyPanel(
        api: widget.api,
        mailboxId: mailbox.id,
        message: message,
        replyAs: message.replyAs!,
      ),
    );

    if (sent == null || !mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      // The address again, at the end. Somebody who has just sent an answer on
      // behalf of a shared inbox should be told which one it went out as, not
      // congratulated.
      SnackBar(content: Text('Sent as $sent.')),
    );

    _loadMessages();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: const [
              ('Inbox', Icons.inbox_outlined),
              ('Settings', Icons.settings_outlined),
            ],
            selected: _tab,
            onSelected: (tab) => setState(() {
              _tab = tab;
              _shown.add(tab);
            }),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Offstage rather than IndexedStack, for the reason HrScreen and
          // automations_screen both give: IndexedStack leaves its hidden child
          // in the semantics tree, so a screen reader would read out the
          // settings page while somebody is looking at their mail. Offstage
          // takes it out of layout, painting and semantics and leaves its State
          // exactly where it was.
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(offstage: _tab != _inboxTab, child: _inbox()),
              Offstage(
                offstage: _tab == _inboxTab,
                child: _shown.contains(1)
                    ? MailboxSettingsTab(
                        api: widget.api,
                        session: widget.session,
                      )
                    : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// The mail itself — the tab that existed before there were two of them.
  Widget _inbox() {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= _splitAbove;

        return AsyncBody(
          loading: _loadingMailboxes,
          error: _mailboxesError,
          onRetry: _loadMailboxes,
          child: _mailboxes.isEmpty ? _noInboxes(context) : _panes(wide),
        );
      },
    );
  }

  /// Nothing connected — which is a setup step, not an error and not a blank.
  Widget _noInboxes(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 460),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.inbox_outlined,
              size: 34,
              color: Theme.of(context).colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              'No inbox belongs to this part of the business yet.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            Text(
              'An inbox added on another company stays there — switch to that '
              'company, or to the organization, to see it. Settings is where '
              'one is added, and each role is then given the ones it should '
              'be able to read.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    ),
  );

  Widget _panes(bool wide) {
    final mailbox = _selected!;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        InboxBar(
          mailbox: mailbox,
          // On a wide screen every pane is already on screen, so there is
          // nothing to go back to and a back arrow would be a control that
          // moves nothing.
          onBack: wide || _pane == _Pane.inboxes
              ? null
              : () => setState(
                  () => _pane = _pane == _Pane.message
                      ? _Pane.messages
                      : _Pane.inboxes,
                ),
          backTooltip: _pane == _Pane.message
              ? 'Back to the messages'
              : 'Back to the inboxes',
        ),
        const Divider(height: 1),
        Expanded(child: wide ? _wide(mailbox) : _narrow(mailbox)),
      ],
    );
  }

  Widget _wide(Mailbox mailbox) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      SizedBox(width: _railWidth, child: _rail()),
      const VerticalDivider(width: 1, thickness: 1),
      // The subjects and the message, at 2:3. A subject list needs less room
      // than the thing being read, and both need more than a third of what is
      // left once the inboxes have their column.
      Expanded(flex: 2, child: _list(mailbox)),
      const VerticalDivider(width: 1, thickness: 1),
      Expanded(flex: 3, child: _message(mailbox)),
    ],
  );

  /// One pane, and only the one on screen is built.
  Widget _narrow(Mailbox mailbox) => switch (_pane) {
    _Pane.inboxes => _rail(),
    _Pane.messages => _list(mailbox),
    _Pane.message => _message(mailbox),
  };

  Widget _rail() => MailboxRail(
    mailboxes: _mailboxes,
    selectedId: _selected?.id,
    onSelected: _select,
  );

  Widget _list(Mailbox mailbox) => MessageListPane(
    mailbox: mailbox,
    messages: _messages,
    loading: _loadingList,
    error: _listError,
    onRetry: _loadMessages,
    selectedId: _openId,
    onOpen: (header) => _openMessage(header.id),
    meId: widget.session.me!.id,
    formats: widget.session.me!.display,
    more: _nextCursor != null || _page < _lastPage,
    fetching: _fetching,
    pageError: _pageError,
    onMore: () => _nextCursor != null
        ? _loadMessages(cursor: _nextCursor)
        : _loadMessages(page: _page + 1),
  );

  Widget _message(Mailbox mailbox) {
    if (_openId == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Pick a message to read it.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    return AsyncBody(
      loading: _loadingMessage,
      error: _messageError,
      // The id is enough to ask again, so retrying does not depend on the row
      // still being in the list — a "Try again" that quietly did nothing
      // because the list had been refetched underneath it is a dead end.
      onRetry: () => _openMessage(_openId!),
      child: _open == null
          ? const SizedBox.shrink()
          : _MessageView(
              mailbox: mailbox,
              message: _open!,
              meId: widget.session.me!.id,
              formats: widget.session.me!.display,
              refusal: _replyRefusal(mailbox, _open!),
              onReply: () => _reply(mailbox, _open!),
              api: widget.api,
            ),
    );
  }
}

/// One message, open.
class _MessageView extends StatelessWidget {
  const _MessageView({
    required this.mailbox,
    required this.message,
    required this.meId,
    required this.formats,
    required this.refusal,
    required this.onReply,
    required this.api,
  });

  final Mailbox mailbox;
  final MessageDetail message;
  final String meId;
  final DisplayFormats formats;
  final String? refusal;
  final VoidCallback onReply;
  final ApiClient api;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final seen = mailbox.sharedRead ? seenBySentence(message.seenBy, meId) : null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                message.subject.isEmpty ? 'No subject' : message.subject,
                style: theme.textTheme.headlineMedium?.copyWith(fontSize: 21),
              ),
              const SizedBox(height: 6),
              Text(
                'From ${message.from}',
                style: theme.textTheme.bodyMedium,
              ),
              Text(
                'To ${message.to.isEmpty ? mailbox.address : message.to.join(', ')} · '
                '${formatStamp(message.receivedAt, 'No date', formats: formats)}',
                style: theme.textTheme.bodyMedium,
              ),
              if (seen != null) ...[
                const SizedBox(height: 8),
                SeenByLine(sentence: seen),
              ],
              const SizedBox(height: 12),
              // Under the header rather than beside it, at every width. A
              // button next to a subject line is the arrangement that squeezes
              // the subject into a two-word column at 375px — PanelHeader's own
              // bug, twice, and the fix there was the same shape as this.
              Align(
                alignment: Alignment.centerLeft,
                child: Refused(
                  reason: refusal,
                  child: FilledButton.icon(
                    onPressed: refusal == null ? onReply : null,
                    icon: const Icon(Icons.reply, size: 18),
                    // The address on the control that opens the composer, so
                    // the answer to "who will this come from" is on screen
                    // before anybody starts typing rather than after.
                    label: Text('Reply as ${mailbox.address}'),
                  ),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: _body(mailbox, message)),
              if (message.attachments.isNotEmpty) _attachments(context),
            ],
          ),
        ),
      ],
    );
  }

  Widget _body(Mailbox mailbox, MessageDetail message) {
    final html = message.html;
    if (kIsWeb && html != null && html.isNotEmpty) {
      return MessageHtmlView(html: html);
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
      child: MessageBody(
        blocks: message.blocks,
        api: api,
        picturePath:
            '/email/mailboxes/${mailbox.id}/messages/${message.id}/picture',
      ),
    );
  }

  /// What came with the message, named and not held.
  ///
  /// Decision 2 again: nothing is stored, so there is nothing to open from
  /// here. Listing the names anyway is what stops a message whose whole content
  /// is a quote PDF from reading as an empty one.
  Widget _attachments(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Divider(height: 1),
          const SizedBox(height: 14),
          Text('CAME WITH IT', style: theme.textTheme.labelSmall),
          const SizedBox(height: 8),
          for (final file in message.attachments)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.attach_file,
                    size: 15,
                    color: theme.textTheme.bodySmall?.color,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      file.size == null
                          ? file.name
                          : '${file.name} · ${_size(file.size!)}',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 6),
          Text(
            'Attachments stay with the mail provider. This window lists what '
            'arrived and does not download it.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  /// Bytes, in the units a person reads. Rounded rather than exact — the
  /// question being answered is "will this take a moment", not "how many
  /// bytes".
  static String _size(int bytes) {
    if (bytes < 1024) return '$bytes bytes';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).round()} KB';

    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}
