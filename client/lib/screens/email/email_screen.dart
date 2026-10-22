import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import 'compose_panel.dart';
import 'mail_data.dart';
import 'mailbox_rail.dart';
import 'message_body.dart';
import 'message_html.dart';
import 'message_list.dart';
import 'reply_panel.dart';
import 'settings_tab.dart';
import 'workspace_tab.dart';

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
  /// Above this the inbox rail sits beside the list (or the open mail);
  /// below it there is one pane at a time.
  ///
  /// ── R7, and the measurement behind the number ───────────────
  ///
  /// Gmail is rail-plus-content, not list-plus-preview. A third column at
  /// 375px is unusable; at 700 the list used to shrink to a subject every
  /// three words beside a preview. The list now takes the whole content
  /// pane, and opening a message replaces it.
  ///
  /// 1000 is where the rail (260) still leaves a readable list. Below it,
  /// one pane at a time with the inbox named above it, and the panes that
  /// are not on screen are NOT BUILT.
  static const double _splitAbove = 1000;

  /// The inbox rail's width on a wide screen. Enough for an address on one or
  /// two lines beside its unread count.
  static const double _railWidth = 260;

  bool _loadingMailboxes = true;
  String? _mailboxesError;

  /// The server's word for WHY the inbox list is empty, when it is.
  /// null until the first load answers, which is why the fallback arm of
  /// [_emptyTitle] is the old scope wording rather than a blank.
  String? _emptyBecause;
  List<Mailbox> _mailboxes = const [];
  Mailbox? _selected;

  /// Combined list of every inbox this person can open.
  bool _allInboxes = false;
  final Map<String, String?> _cursorByMailbox = {};
  final Map<String, int> _pageByMailbox = {};
  final Map<String, int> _lastPageByMailbox = {};
  List<String> _combinedNotes = const [];

  bool _loadingList = true;
  String? _listError;
  List<MessageHeader> _messages = const [];

  /// Which messages on screen are handled: id -> {at, by}.
  ///
  /// Kept beside the rows rather than folded into MessageHeader, for the same
  /// reason the server keeps it out of the message shape: a header describes
  /// what a PROVIDER returned, and "somebody here has handled this" is a fact
  /// about EzBizi that no provider knows. `by` is null when an automation did
  /// it, which the chip says in words.
  Map<String, Map<String, dynamic>> _dealt = const {};
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

  final TextEditingController _search = TextEditingController();
  String _submittedSearch = '';
  String _folder = 'INBOX';
  bool _unreadOnly = false;
  Set<String> _checked = {};

  _Pane _pane = _Pane.messages;

  /// Inbox's place in the strip below, and where this module opens.
  ///
  /// The mail is what somebody came for; the connections behind it are what
  /// they come for once, or on the day one breaks.
  static const _inboxTab = 'inbox';

  /// The tab being shown, BY NAME rather than by index.
  ///
  /// Indices were fine while everybody saw both. Once a tab can be hidden the
  /// index of the other one moves -- a role with only Settings would select
  /// index 0 and be shown the mail, which is the permission failing open in the
  /// one direction nobody would notice. A name cannot drift.
  String _tab = _inboxTab;

  /// Which sub-tabs have been shown at least once — HrScreen's set, for the
  /// two reasons its own note gives: a tab nobody has opened must not fetch,
  /// and a tab they have opened must not fetch again every time they glance at
  /// the other one.
  final Set<String> _shown = {_inboxTab};

  /// Every tab this screen has, with the permission key that opens it.
  ///
  /// Jose, 2026-09-17: "i should be able to grant access by sub tab as well".
  /// Each is granted separately as `email.<key>.view`, declared in
  /// `ModuleServiceProvider` and drawn in the roles editor under a Tabs
  /// heading. THE KEYS HERE AND THE DECLARATION THERE ARE THE SAME TWO, and
  /// nothing mechanical can check that across PHP and Dart — but a key with no
  /// declaration grants nobody, so the tab vanishes for everyone rather than
  /// appearing for everyone. That is the safe direction of that mistake and the
  /// one somebody reports.
  ///
  /// Labels are the registry's, copied from here rather than the reverse.
  static const _declaredTabs = <(String, String, IconData)>[
    (_inboxTab, 'Inbox', Icons.inbox_outlined),
    ('settings', 'Settings', Icons.settings_outlined),
    // UI queue 134: each connected Workspace's people and their signatures.
    ('workspace', 'Google Workspace', Icons.badge_outlined),
  ];

  /// Absent `/me` answers NO, not yes — HrScreen's rule. A null here is a load
  /// that has not finished, and "I do not know yet" must draw nothing.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('email.$tab.view') ?? false;

  List<(String, String, IconData)> get _visibleTabs =>
      [for (final tab in _declaredTabs) if (_mayOpen(tab.$1)) tab];

  @override
  void initState() {
    super.initState();
    DeepLinks.pending.addListener(_followLink);
    _loadMailboxes();
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_followLink);
    _search.dispose();
    super.dispose();
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

      final meta = response.body['meta'];
      _emptyBecause = meta is Map && meta['empty_because'] is String
          ? meta['empty_because'] as String
          : null;

      final data = response.data;

      // Jose, 2026-10-13: Eve was given 10 inboxes and saw all 30 -- the
      // rest were ones she may manage but not open. Only openable ones here.
      _mailboxes = [
        for (final entry in (data is List ? data : const []))
          if (entry is Map<String, dynamic>) Mailbox.fromJson(entry),
      ].where((mailbox) => mailbox.mayRead).toList();

      // Two or more inboxes land on All inboxes so mail in the second
      // one is not hidden behind a pick. One inbox is that inbox —
      // combining it would be a second name for the same list.
      _allInboxes = _mailboxes.length > 1;
      _selected = _allInboxes || _mailboxes.isEmpty ? null : _mailboxes.first;
      _pane = _Pane.messages;
    });

    if (_allInboxes || _selected != null) _loadMessages();

    // A single-inbox customer is landed straight in that inbox without ever
    // passing through _select, so discovery has to be started here as well.
    // Without this the one case that needs folders asked for — one mailbox,
    // likely IMAP — is the one case that never asks.
    final landedOn = _selected;

    if (landedOn != null) _loadFolders(landedOn);

    // A notification tapped before the inboxes were known waited for them.
    _followLink();
  }

  /// Opens the exact email a notification is about (Bar via Jose,
  /// 2026-10-12: "a new email notification should open that email").
  ///
  /// Taken only once the inboxes are known -- the link names the inbox by
  /// address. An inbox this person cannot open is not in the list, so the
  /// link is dropped and they stay on Email's home, as before.
  void _followLink() {
    if (_loadingMailboxes || DeepLinks.pending.value?.module != 'email') return;

    final link = DeepLinks.take('email');
    final address = link?.mailbox?.toLowerCase();

    if (link == null || link.type != 'email_message' || address == null) return;

    final mailbox = _mailboxes.where((m) => m.address.toLowerCase() == address).firstOrNull;

    if (mailbox == null) return;

    _openMessage(MessageHeader(
      id: link.id,
      fromName: '',
      fromAddress: '',
      subject: '',
      preview: '',
      receivedAt: null,
      unread: true,
      hasAttachments: false,
      seenBy: const [],
      mailboxId: mailbox.id,
    ));
  }

  Future<void> _loadMessages({int page = 1, String? cursor}) async {
    if (_allInboxes) {
      await _loadAll(
        append: (cursor != null && cursor.isNotEmpty) || page > 1,
      );
      return;
    }

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
    if (_submittedSearch.isNotEmpty) {
      query['search'] = _submittedSearch;
    }
    if (_folder != 'INBOX') {
      query['label'] = _folder;
    }
    if (_unreadOnly) {
      query['unread'] = '1';
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

      final parsed = _messagePage(
        response.data,
        response.body['meta'],
        mailbox: mailbox,
      );
      _messages = appending ? [..._messages, ...parsed.rows] : parsed.rows;
      _dealt = appending ? {..._dealt, ..._dealtFrom(response.data)} : _dealtFrom(response.data);
      _page = parsed.page;
      _lastPage = parsed.lastPage;
      _nextCursor = parsed.nextCursor;
    });
  }

  /// The handled map off a page response, or empty when it carried none.
  ///
  /// Empty rather than null: "no message on this page is handled" and "this
  /// build asked a server that does not send it" both mean the same thing HERE
  /// — draw no chips — and inventing a third state for a shape that is simply
  /// absent would put a nullable through every row for nothing.
  Map<String, Map<String, dynamic>> _dealtFrom(Object? data) {
    if (data is! Map || data['dealt'] is! Map) return const {};

    final out = <String, Map<String, dynamic>>{};

    (data['dealt'] as Map).forEach((key, value) {
      if (key is String && value is Map) {
        out[key] = Map<String, dynamic>.from(value);
      }
    });

    return out;
  }

  /// Mark the open message handled, or take it back.
  ///
  /// The list is what shows it, so the map is updated in place rather than
  /// re-fetching the page: a refetch would cost a provider round trip to learn
  /// something this client already knows, and on a slow mailbox the chip would
  /// appear a second after the click.
  Future<void> _toggleDealt(Mailbox mailbox, String messageId) async {
    final wasDealt = _dealt.containsKey(messageId);

    final response = wasDealt
        ? await widget.api.delete('/email/mailboxes/${mailbox.id}/messages/$messageId/dealt')
        : await widget.api.post('/email/mailboxes/${mailbox.id}/messages/$messageId/dealt', const {});

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.reason)),
      );
      return;
    }

    setState(() {
      final next = {..._dealt};

      if (wasDealt) {
        next.remove(messageId);
      } else {
        final data = response.data;
        next[messageId] = data is Map && data['dealt'] is Map
            ? Map<String, dynamic>.from(data['dealt'] as Map)
            : <String, dynamic>{'at': null, 'by': null};
      }

      _dealt = next;
    });
  }

  /// Live API: `{ messages, next_cursor }`. Tests / older assumption: a list
  /// plus `meta.page`. Both have to produce the same rows.
  ({List<MessageHeader> rows, int page, int lastPage, String? nextCursor})
  _messagePage(Object? data, Object? meta, {Mailbox? mailbox}) {
    final metaMap = meta is Map<String, dynamic> ? meta : const <String, dynamic>{};

    if (data is List) {
      final page = metaMap['page'] as int? ?? 1;
      final last = metaMap['last_page'] as int? ?? page;
      return (
        rows: [
          for (final entry in data)
            if (entry is Map<String, dynamic>)
              MessageHeader.fromJson(entry, mailbox: mailbox),
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
            if (entry is Map<String, dynamic>)
              MessageHeader.fromJson(entry, mailbox: mailbox),
        ],
        page: 1,
        lastPage: (next != null && next.isNotEmpty) ? 2 : 1,
        nextCursor: (next != null && next.isNotEmpty) ? next : null,
      );
    }

    return (rows: const [], page: 1, lastPage: 1, nextCursor: null);
  }

  Future<void> _loadAll({required bool append}) async {
    if (_mailboxes.isEmpty) return;
    if (append && _fetching) return;

    final request = ++_listRequest;

    setState(() {
      _loadingList = !append && _messages.isEmpty;
      _fetching = true;
      _listError = null;
      _pageError = null;
      if (!append) {
        _combinedNotes = const [];
        _cursorByMailbox.clear();
        _pageByMailbox.clear();
        _lastPageByMailbox.clear();
      }
    });

    final targets = [
      for (final mailbox in _mailboxes)
        if (mailbox.opensFolder(_folder))
          if (!append || _mailboxHasMore(mailbox.id)) mailbox,
    ];

    if (targets.isEmpty) {
      if (!mounted || request != _listRequest) return;
      setState(() {
        _loadingList = false;
        _fetching = false;
        if (!append) _messages = const [];
        _nextCursor = null;
      });
      return;
    }

    final results = await Future.wait(
      targets.map((mailbox) async {
        final query = <String, String>{};
        if (append) {
          final cursor = _cursorByMailbox[mailbox.id];
          if (cursor != null && cursor.isNotEmpty) {
            query['cursor'] = cursor;
          } else {
            query['page'] = '${(_pageByMailbox[mailbox.id] ?? 1) + 1}';
          }
        }
        if (_submittedSearch.isNotEmpty) {
          query['search'] = _submittedSearch;
        }
        if (_folder != 'INBOX' && mailbox.opensFolder(_folder)) {
          query['label'] = _folder;
        }
        if (_unreadOnly) {
          query['unread'] = '1';
        }

        final response = query.isEmpty
            ? await widget.api.get('/email/mailboxes/${mailbox.id}/messages')
            : await widget.api.get(
                '/email/mailboxes/${mailbox.id}/messages',
                query,
              );

        return (mailbox: mailbox, response: response);
      }),
    );

    if (!mounted || request != _listRequest) return;

    final rows = <MessageHeader>[if (append) ..._messages];
    final notes = <String>[if (append) ..._combinedNotes];
    var anyOk = false;

    for (final result in results) {
      final mailbox = result.mailbox;
      final response = result.response;

      if (!response.ok) {
        final sentence = response.message;
        if (!notes.contains(sentence)) notes.add(sentence);
        continue;
      }

      anyOk = true;
      final parsed = _messagePage(
        response.data,
        response.body['meta'],
        mailbox: mailbox,
      );
      rows.addAll(parsed.rows);
      _cursorByMailbox[mailbox.id] = parsed.nextCursor;
      _pageByMailbox[mailbox.id] = parsed.page;
      _lastPageByMailbox[mailbox.id] = parsed.lastPage;
    }

    rows.sort(_byNewest);
    final unique = <MessageHeader>[];
    final seen = <String>{};
    for (final row in rows) {
      if (seen.add('${row.mailboxId}:${row.id}')) unique.add(row);
    }

    setState(() {
      _loadingList = false;
      _fetching = false;
      _combinedNotes = notes;
      if (!anyOk) {
        _listError = notes.join('\n');
        if (!append) _messages = const [];
        return;
      }
      _messages = unique;
      _nextCursor = _combinedHasMore() ? 'more' : null;
      _page = 1;
      _lastPage = _nextCursor == null ? 1 : 2;
    });
  }

  bool _mailboxHasMore(String id) {
    final cursor = _cursorByMailbox[id];
    if (cursor != null && cursor.isNotEmpty) return true;

    return (_pageByMailbox[id] ?? 1) < (_lastPageByMailbox[id] ?? 1);
  }

  bool _combinedHasMore() =>
      _mailboxes.any((mailbox) => _mailboxHasMore(mailbox.id));

  int _byNewest(MessageHeader a, MessageHeader b) {
    final at = DateTime.tryParse(a.receivedAt ?? '');
    final bt = DateTime.tryParse(b.receivedAt ?? '');
    if (at == null && bt == null) return 0;
    if (at == null) return 1;
    if (bt == null) return -1;
    return bt.compareTo(at);
  }

  Mailbox? _mailboxById(String id) {
    for (final mailbox in _mailboxes) {
      if (mailbox.id == id) return mailbox;
    }
    return null;
  }

  Mailbox? _mailboxForOpen() {
    if (_openId == null) return _selected;
    for (final row in _messages) {
      if (row.id == _openId) {
        return _mailboxById(row.mailboxId) ?? _selected;
      }
    }
    return _selected;
  }

  /// Open one message. The body is fetched now, because it was never held.
  ///
  /// Opening is also what records the read: the server notes it on this
  /// request, which is what puts "Seen by" on somebody else's screen. There is
  /// deliberately no second call announcing it — two ways of saying the same
  /// thing is two states that can disagree.
  Future<void> _openMessage(MessageHeader header) async {
    final mailbox = _mailboxById(header.mailboxId) ?? _selected;

    if (mailbox == null) return;

    final request = ++_messageRequest;
    final id = header.id;

    setState(() {
      _openId = id;
      _open = null;
      _loadingMessage = true;
      _messageError = null;
      _pane = _Pane.message;
    });

    final response = await widget.api.get(
      '/email/mailboxes/${mailbox.id}/messages/$id',
      // The conversation, so the server opens it in one fetch rather than two.
      header.threadId.isEmpty ? null : {'thread': header.threadId},
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
            if (row.id == id && row.mailboxId == header.mailboxId)
              row.copyWith(unread: false)
            else
              row,
        ];
      } else {
        _messageError = 'That message came back in a shape this app could not '
            'read. Try opening it again.';
      }
    });
  }

  void _selectAll() {
    if (_allInboxes) {
      setState(() => _pane = _Pane.messages);
      return;
    }

    setState(() {
      _allInboxes = true;
      _selected = null;
      _pane = _Pane.messages;

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
      _folder = 'INBOX';
      _unreadOnly = false;
      _submittedSearch = '';
      _search.clear();
      _checked = {};
      _combinedNotes = const [];
      _cursorByMailbox.clear();
      _pageByMailbox.clear();
      _lastPageByMailbox.clear();
    });

    _loadMessages();
  }

  void _select(Mailbox mailbox) {
    if (!_allInboxes && mailbox.id == _selected?.id) {
      setState(() => _pane = _Pane.messages);
      return;
    }

    setState(() {
      _allInboxes = false;
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
      _folder = 'INBOX';
      _unreadOnly = false;
      _submittedSearch = '';
      _search.clear();
      _checked = {};
      _combinedNotes = const [];
      _cursorByMailbox.clear();
      _pageByMailbox.clear();
      _lastPageByMailbox.clear();
    });

    _loadMessages();
    _loadFolders(mailbox);
  }

  /// Ask what folders this mailbox actually has.
  ///
  /// ── Only once, and only when a mailbox is opened ─────────────
  ///
  /// For IMAP the answer costs a connection to the customer's mail server, so
  /// it is not asked on the inbox listing — that would open one per mailbox
  /// before anybody had chosen any of them. Asked here, and remembered.
  ///
  /// ── A failure is silence, not a message ──────────────────────
  ///
  /// If this cannot be answered the folder list stays as the provider guess,
  /// which for IMAP is the Inbox alone. That is the honest fallback: the
  /// mailbox is still readable, and there is nothing useful to say to somebody
  /// about a folder list they were not looking at. The mail itself has its own
  /// error path, and that one does speak up.
  Future<void> _loadFolders(Mailbox mailbox) async {
    if (mailbox.discoveredFolders != null) return;

    final response = await widget.api.get('/email/mailboxes/${mailbox.id}/folders');

    if (!mounted || !response.ok) return;

    final data = response.data;

    if (data is! List) return;

    final folders = {
      for (final folder in data)
        if (folder is String && folder.isNotEmpty) folder,
    };

    if (folders.isEmpty) return;

    setState(() {
      _mailboxes = [
        for (final row in _mailboxes)
          row.id == mailbox.id ? row.withFolders(folders) : row,
      ];

      if (_selected?.id == mailbox.id) {
        _selected = _selected!.withFolders(folders);
      }
    });
  }

  void _closeMessage() {
    setState(() {
      _openId = null;
      _open = null;
      _messageError = null;
      _pane = _Pane.messages;
    });
  }

  void _runSearch(String value) {
    setState(() {
      _submittedSearch = value.trim();
      _openId = null;
      _open = null;
      _pane = _Pane.messages;
    });
    _loadMessages();
  }

  void _selectFolder(String folder) {
    if (folder == _folder) return;
    setState(() {
      _folder = folder;
      _openId = null;
      _open = null;
      _pane = _Pane.messages;
      _messages = const [];
      _loadingList = true;
      _checked = {};
    });
    _loadMessages();
  }

  void _toggleChecked(MessageHeader message) {
    setState(() {
      final next = {..._checked};
      if (!next.remove(message.id)) {
        next.add(message.id);
      }
      _checked = next;
    });
  }

  void _toggleCheckedAll() {
    setState(() {
      if (_checked.length == _messages.length) {
        _checked = {};
      } else {
        _checked = {for (final row in _messages) row.id};
      }
    });
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

  /// Starting a conversation rather than answering one.
  ///
  /// Gated on the same `canReply` the reply path uses, because the server gates
  /// both on being able to READ the inbox: somebody who may not open it may
  /// certainly not write from it, and there is deliberately no separate "may
  /// send" permission to consult. Drawing a button here that the server would
  /// refuse is the shape the house rule forbids.
  Future<void> _compose(Mailbox mailbox) async {
    final sent = await showSidePanel<String>(
      context,
      builder: (_) => ComposePanel(
        api: widget.api,
        mailboxId: mailbox.id,
        sendAs: mailbox.address,
      ),
    );

    if (sent == null || !mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Sent as $sent.')),
    );

    _loadMessages();
  }

  @override
  Widget build(BuildContext context) {
    final tabs = _visibleTabs;

    // Neither tab granted. Said rather than drawn as an empty strip above an
    // empty pane, which reads as a screen that failed rather than as a
    // permission somebody has to ask for.
    if (tabs.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No part of Email has been given to your role yet. Somebody who '
            'manages roles can grant the Inbox or Settings tab.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    // The selected tab may have just been taken away -- a role edited while
    // somebody is looking at the screen. Fall back to the first tab they DO
    // hold rather than indexing into a list that no longer contains it.
    final current = tabs.indexWhere((tab) => tab.$1 == _tab);
    final selected = current == -1 ? 0 : current;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-email-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: (index) => setState(() {
              _tab = tabs[index].$1;
              _shown.add(_tab);
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
              if (_mayOpen(_inboxTab))
                Offstage(offstage: _tab != _inboxTab, child: _inbox()),
              if (_mayOpen('settings'))
                Offstage(
                  offstage: _tab != 'settings',
                  child: _shown.contains('settings')
                    ? MailboxSettingsTab(
                        api: widget.api,
                        session: widget.session,
                      )
                    : const SizedBox.shrink(),
              ),
              if (_mayOpen('workspace'))
                Offstage(
                  offstage: _tab != 'workspace',
                  child: _shown.contains('workspace')
                    ? WorkspaceTab(api: widget.api, session: widget.session)
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
              _emptyTitle,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            Text(
              _emptyDetail,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    ),
  );

  /// Which empty this is, in the server's words. See `empty_because` in
  /// MailboxesController::index — the screen used to guess and got it wrong
  /// for everybody whose role simply held no grant.
  String get _emptyTitle => switch (_emptyBecause) {
        'none_granted' => 'Your role has not been given an inbox yet.',
        'none_connected' => 'No inbox has been connected yet.',
        _ => 'No inbox belongs to this part of the business yet.',
      };

  String get _emptyDetail => switch (_emptyBecause) {
        // Do NOT send this person hunting through companies. The inboxes are
        // here and in their view; the only thing missing is a grant, and only
        // somebody who manages mailboxes can give it.
        'none_granted' =>
          'Inboxes have been set up, but none of them has been given to your '
              'role yet. Somebody who manages mailboxes can do that in '
              'Settings — until then there is nothing here to read.',
        'none_connected' =>
          'Nobody has added a shared inbox to this business yet. Settings is '
              'where the first one is added, and each role is then given the '
              'ones it should be able to read.',
        _ => 'An inbox added on another company stays there — switch to that '
            'company, or to the organization, to see it. Settings is where '
            'one is added, and each role is then given the ones it should '
            'be able to read.',
      };

  Widget _panes(bool wide) {
    final openMailbox = _mailboxForOpen();
    final listMailbox = _selected ?? _mailboxes.first;
    final barMailbox = _openId != null ? openMailbox : _selected;
    final combinedBar = _allInboxes && _openId == null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        InboxBar(
          key: const ValueKey('tour-email-inbox-bar'),
          mailbox: barMailbox,
          allInboxes: combinedBar,
          allAddresses: [
            for (final mailbox in _mailboxes) mailbox.address,
          ],
          // Back from the list to the inboxes, on a narrow screen. Back
          // from a message is on the reading toolbar, like Gmail.
          onBack: wide || _pane != _Pane.messages || _openId != null
              ? null
              : () => setState(() => _pane = _Pane.inboxes),
          backTooltip: 'Back to the inboxes',
        ),
        const Divider(height: 1),
        Expanded(
          child: wide
              ? _wide(listMailbox, openMailbox)
              : _narrow(listMailbox, openMailbox),
        ),
      ],
    );
  }

  Widget _wide(Mailbox listMailbox, Mailbox? openMailbox) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      SizedBox(width: _railWidth, child: _rail()),
      const VerticalDivider(width: 1, thickness: 1),
      // Gmail: the list OR the message, never both. A preview column beside
      // the list is the thing that squeezed every mail into half a pane.
      Expanded(
        child: _openId != null
            ? _message(openMailbox ?? listMailbox)
            : _listColumn(listMailbox),
      ),
    ],
  );

  /// One pane, and only the one on screen is built.
  Widget _narrow(Mailbox listMailbox, Mailbox? openMailbox) =>
      switch (_pane) {
        _Pane.inboxes => _rail(),
        _Pane.messages => _listColumn(listMailbox),
        _Pane.message => _message(openMailbox ?? listMailbox),
      };

  Widget _rail() => MailboxRail(
    key: const ValueKey('tour-email-inboxes'),
    mailboxes: _mailboxes,
    selectedId: _selected?.id,
    onSelected: _select,
    allSelected: _allInboxes,
    onSelectAll: _mailboxes.length > 1 ? _selectAll : null,
    selectedFolder: _folder,
    onFolderSelected: _selectFolder,
  );

  Widget _listColumn(Mailbox mailbox) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: TextField(
            key: const ValueKey('tour-email-search'),
            controller: _search,
            textInputAction: TextInputAction.search,
            onSubmitted: _runSearch,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              hintText: 'Search mail',
              prefixIcon: const Icon(Icons.search, size: 20),
              suffixIcon: _submittedSearch.isNotEmpty || _search.text.isNotEmpty
                  ? IconButton(
                      tooltip: 'Clear search',
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () {
                        _search.clear();
                        _runSearch('');
                      },
                    )
                  : null,
              isDense: true,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(24),
                borderSide: BorderSide.none,
              ),
              filled: true,
              fillColor: theme.colorScheme.surfaceContainerHighest
                  .withValues(alpha: 0.5),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: _checked.isEmpty
              ? Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    // ── NOT offered on the combined list ──────────
                    //
                    // In All inboxes there is no selected mailbox, and the
                    // list column falls back to `_mailboxes.first` so it has
                    // something to draw. Sending on that fallback would pick
                    // an inbox the reader never chose — the exact failure this
                    // screen is arranged against, and unrecoverable once the
                    // message has left. So the button waits until somebody is
                    // standing in ONE inbox.
                    //
                    // Also gated on canReply, because the server gates sending
                    // on being able to read the inbox: a button it would
                    // refuse is the shape the house rule forbids.
                    if (_selected != null && mailbox.canReply)
                      FilledButton.tonalIcon(
                        key: const ValueKey('tour-email-compose'),
                        onPressed: () => _compose(mailbox),
                        icon: const Icon(Icons.edit_outlined, size: 18),
                        label: const Text('New message'),
                      ),
                    FilterChip(
                      key: const ValueKey('tour-email-unread'),
                      label: const Text('Unread'),
                      selected: _unreadOnly,
                      onSelected: (selected) {
                        setState(() {
                          _unreadOnly = selected;
                          _openId = null;
                          _open = null;
                        });
                        _loadMessages();
                      },
                    ),
                  ],
                )
              : _bulkBar(mailbox),
        ),
        if (_listError == null && _combinedNotes.isNotEmpty) ...[
          for (final note in _combinedNotes)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text(note, style: theme.textTheme.bodyMedium),
            ),
        ],
        const Divider(height: 1),
        Expanded(child: _list(mailbox)),
      ],
    );
  }

  Widget _list(Mailbox mailbox) => MessageListPane(
    key: const ValueKey('tour-email-list'),
    mailbox: mailbox,
    messages: _messages,
    loading: _loadingList,
    error: _listError,
    onRetry: _loadMessages,
    selectedId: _openId,
    onOpen: _openMessage,
    meId: widget.session.me!.id,
    formats: widget.session.me!.display,
    more: _nextCursor != null || _page < _lastPage,
    fetching: _fetching,
    pageError: _pageError,
    onMore: () => _nextCursor != null
        ? _loadMessages(cursor: _nextCursor)
        : _loadMessages(page: _page + 1),
    checkedIds: _checked,
    onToggleChecked: _toggleChecked,
    mailboxes: _mailboxes,
    showMailboxOnRow: _allInboxes,
    emptyLabel: _allInboxes ? 'any inbox' : null,
  );

  Widget _bulkBar(Mailbox mailbox) {
    return Row(
      children: [
        IconButton(
          tooltip: _checked.length == _messages.length
              ? 'Clear selection'
              : 'Select all on this page',
          icon: Icon(
            _checked.length == _messages.length
                ? Icons.check_box
                : Icons.check_box_outline_blank,
          ),
          onPressed: _toggleCheckedAll,
        ),
        Text('${_checked.length} selected'),
        const SizedBox(width: 8),
        IconButton(
          tooltip: 'Delete',
          icon: const Icon(Icons.delete_outline),
          onPressed: () => _bulk(mailbox, 'trash'),
        ),
        IconButton(
          tooltip: 'Mark as read',
          icon: const Icon(Icons.mark_email_read_outlined),
          onPressed: () => _bulk(mailbox, 'seen'),
        ),
        IconButton(
          tooltip: 'Mark as unread',
          icon: const Icon(Icons.mark_email_unread_outlined),
          onPressed: () => _bulk(mailbox, 'unseen'),
        ),
        IconButton(
          tooltip: 'Clear selection',
          icon: const Icon(Icons.close),
          onPressed: () => setState(() => _checked = {}),
        ),
      ],
    );
  }

  Future<void> _bulk(Mailbox mailbox, String action) async {
    final ids = _checked.toList();
    if (ids.isEmpty) return;

    final groups = <String, List<String>>{};
    for (final row in _messages) {
      if (!_checked.contains(row.id)) continue;
      final mailboxId = row.mailboxId.isEmpty ? mailbox.id : row.mailboxId;
      groups.putIfAbsent(mailboxId, () => []).add(row.id);
    }
    if (groups.isEmpty) {
      groups[mailbox.id] = ids;
    }

    String? refusal;
    for (final entry in groups.entries) {
      final response = await widget.api.post(
        '/email/mailboxes/${entry.key}/messages/bulk',
        {'action': action, 'ids': entry.value},
      );
      if (!mounted) return;
      if (!response.ok) {
        refusal = response.message;
        break;
      }
    }

    if (refusal != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(refusal)),
      );
      return;
    }

    setState(() {
      if (action == 'trash') {
        _messages = [
          for (final row in _messages)
            if (!_checked.contains(row.id)) row,
        ];
      } else {
        final unread = action == 'unseen';
        _messages = [
          for (final row in _messages)
            if (!_checked.contains(row.id))
              row
            else
              row.copyWith(unread: unread),
        ];
      }
      _checked = {};
    });
  }

  Widget _message(Mailbox mailbox) {
    // White, like the page Gmail puts mail on — not the app's grey chrome
    // (`scaffoldBackgroundColor`), which was showing around the header and
    // beside the iframe and made the preview look like a card on a tint.
    return ColoredBox(
      color: Colors.white,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _readingBar(mailbox),
          const Divider(height: 1),
          Expanded(
            child: _openId == null
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'Pick a message to read it.',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ),
                  )
                : AsyncBody(
                    loading: _loadingMessage,
                    error: _messageError,
                    onRetry: () {
                      for (final row in _messages) {
                        if (row.id == _openId) {
                          _openMessage(row);
                          return;
                        }
                      }
                    },
                    child: _open == null
                        ? const SizedBox.shrink()
                        : _MessageView(
                            mailbox: mailbox,
                            message: _open!,
                            meId: widget.session.me!.id,
                            formats: widget.session.me!.display,
                            refusal: _replyRefusal(mailbox, _open!),
                            onReply: () => _reply(mailbox, _open!),
                            dealt: _dealt.containsKey(_open!.id),
                            onToggleDealt: () => _toggleDealt(mailbox, _open!.id),
                            api: widget.api,
                          ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _readingBar(Mailbox mailbox) {
    final unread = _openId == null
        ? false
        : _messages.any((row) => row.id == _openId && row.unread);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Back to the messages',
            icon: const Icon(Icons.arrow_back),
            onPressed: _closeMessage,
          ),
          IconButton(
            tooltip: 'Delete',
            icon: const Icon(Icons.delete_outline),
            onPressed: _openId == null ? null : () => _trash(mailbox, _openId!),
          ),
          IconButton(
            tooltip: unread ? 'Mark as read' : 'Mark as unread',
            icon: Icon(unread ? Icons.mark_email_read_outlined : Icons.mark_email_unread_outlined),
            onPressed: _openId == null
                ? null
                : () => unread
                    ? _setSeen(mailbox, _openId!, seen: true)
                    : _setSeen(mailbox, _openId!, seen: false),
          ),
        ],
      ),
    );
  }

  Future<void> _setSeen(Mailbox mailbox, String id, {required bool seen}) async {
    final path = '/email/mailboxes/${mailbox.id}/messages/$id/seen';
    final response = seen
        ? await widget.api.post(path, const {})
        : await widget.api.delete(path);

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.message)),
      );
      return;
    }

    setState(() {
      _messages = [
        for (final row in _messages)
          if (row.id != id) row else row.copyWith(unread: !seen),
      ];
    });
  }

  Future<void> _trash(Mailbox mailbox, String id) async {
    final response = await widget.api.delete(
      '/email/mailboxes/${mailbox.id}/messages/$id',
    );

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(response.message)),
      );
      return;
    }

    setState(() {
      _messages = [for (final row in _messages) if (row.id != id) row];
    });
    _closeMessage();
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
    required this.dealt,
    required this.onToggleDealt,
    required this.api,
  });

  final Mailbox mailbox;
  final MessageDetail message;
  final String meId;
  final DisplayFormats formats;
  final String? refusal;
  final VoidCallback onReply;

  /// Is this message already marked handled?
  final bool dealt;

  /// Toggle it. The screen owns the map, so the pane only reports the press.
  final VoidCallback onToggleDealt;
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
              const SizedBox(height: 12),
              if (message.thread.length > 1)
                Text(
                  '${message.thread.length} messages',
                  style: theme.textTheme.bodyMedium,
                )
              else
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SenderAvatar(
                      name: message.fromName,
                      email: message.fromAddress,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            message.from,
                            style: theme.textTheme.bodyMedium,
                          ),
                          Text(
                            'To ${message.to.isEmpty ? mailbox.address : message.to.join(', ')} · '
                            '${formatStamp(message.receivedAt, 'No date', formats: formats)}',
                            style: theme.textTheme.bodyMedium,
                          ),
                        ],
                      ),
                    ),
                  ],
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
              // Wrap, not Row: "Reply as bookings@a-1chimney.com" beside a
              // second control overflows a 375px pane, and a button pushed off
              // the edge is an action somebody cannot reach.
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Refused(
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

                  /*
                   * Handled, which is NOT the same as read.
                   *
                   * Opening this message already marked it seen — that is what
                   * "Seen by" reads, and it is a fact about the person looking.
                   * This is the inbox's fact: the customer has been answered,
                   * or needed nothing. A busy shared inbox needs the second
                   * one, because "somebody glanced at it" does not tell the
                   * next colleague whether to act.
                   */
                  OutlinedButton.icon(
                    onPressed: onToggleDealt,
                    icon: Icon(
                      dealt ? Icons.check_circle : Icons.check_circle_outline,
                      size: 18,
                    ),
                    label: Text(dealt ? 'Handled' : 'Mark handled'),
                  ),
                ],
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: _conversation(mailbox, message)),
              if (_files.isNotEmpty) _attachments(context),
            ],
          ),
        ),
      ],
    );
  }

  Widget _conversation(Mailbox mailbox, MessageDetail message) {
    if (message.thread.length <= 1) {
      return _body(mailbox, message);
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
      itemCount: message.thread.length,
      separatorBuilder: (_, _) => const Divider(height: 24),
      itemBuilder: (context, index) {
        final part = message.thread[index];
        final theme = Theme.of(context);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SenderAvatar(
                  name: part.fromName,
                  email: part.fromAddress,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(part.from, style: theme.textTheme.titleSmall),
                      Text(
                        formatStamp(
                          part.receivedAt,
                          'No date',
                          formats: formats,
                        ),
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            SizedBox(height: 420, child: _body(mailbox, part)),
          ],
        );
      },
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

  /// Files on this message, or on every part of the conversation.
  ///
  /// Decision 2: nothing is stored. A tap streams the bytes through EzBizi
  /// and offers them as a download. A name with no token is listed only.
  List<MailAttachment> get _files {
    if (message.thread.isEmpty) return message.attachments;

    return [for (final part in message.thread) ...part.attachments];
  }

  Widget _attachments(BuildContext context) {
    final theme = Theme.of(context);
    final files = _files;

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Divider(height: 1),
          const SizedBox(height: 14),
          Text('ATTACHMENTS', style: theme.textTheme.labelSmall),
          const SizedBox(height: 8),
          for (final file in files)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: InkWell(
                onTap: file.token == null
                    ? null
                    : () => _downloadAttachment(context, file),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Icon(
                        Icons.attach_file,
                        size: 18,
                        color: theme.colorScheme.primary,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          file.size == null
                              ? file.name
                              : '${file.name} · ${_size(file.size!)}',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: file.token == null
                                ? null
                                : theme.colorScheme.primary,
                            decoration: file.token == null
                                ? null
                                : TextDecoration.underline,
                          ),
                        ),
                      ),
                      if (file.token != null)
                        Icon(
                          Icons.download_outlined,
                          size: 18,
                          color: theme.colorScheme.primary,
                        ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _downloadAttachment(
    BuildContext context,
    MailAttachment file,
  ) async {
    final token = file.token;
    if (token == null) return;

    final bytes = await api.getBytes(
      '/email/mailboxes/${mailbox.id}/messages/${message.id}/file'
      '?src=${Uri.encodeComponent(token)}'
      '&name=${Uri.encodeComponent(file.name)}',
    );

    if (!context.mounted) return;

    if (bytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('That file could not be loaded.')),
      );
      return;
    }

    Files.download(file.name, bytes);
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
