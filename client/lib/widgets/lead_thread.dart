import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/money.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../screens/customers/customer_detail_panel.dart';
import '../screens/jobs/job_detail_panel.dart';
import '../screens/phone/call_tab.dart';
import '../screens/phone/recording_playback_bar.dart';
import '../screens/phone/recording_player.dart';
import '../screens/scheduling/availability_pane.dart';
import 'ai_mark.dart';
import 'async_body.dart';
import 'book_lead_panel.dart';
import 'side_panel.dart';
import 'search_picker.dart';
import 'status_pill.dart';

/// The reply box's one-line height, which Send, Suggest and the emoji
/// button share.
const double _controlHeight = 48;

/// The tray's emoji: the ones a reply to a customer reaches for. Phones have
/// the full set on their keyboard.
// ponytail: a fixed set, not a searchable picker; add emoji_picker_flutter if
// people ask for one that is not here.
const _emojis = [
  '😊', '🙂', '😀', '😄', '😁', '😂', '😉', '😍', '🥰', '😎', '🤔', '😅', //
  '😇', '🙃', '😮', '😢', '😬', '🙄', '👍', '👎', '👌', '🙏', '👏', '🙌', //
  '👋', '🤝', '💪', '✌️', '🤞', '👉', '✅', '❌', '⚠️', '⭐', '🔥', '🎉', //
  '💯', '❤️', '📅', '⏰', '🕒', '📞', '💬', '📧', '📍', '🏠', '🔧', '🧹', //
  '🚚', '📷', '🧾', '🌞', //
];

/// What was said on one enquiry: every call and message, oldest first.
///
/// Desktop layout 1 draws this beside the list; mobile layout 4 pushes it full
/// screen. Same widget behind both doors. The header comes from the row the
/// list already holds, so it is on screen before the thread arrives, and the
/// thread is one fetch of `GET /leads/{id}`.
///
/// ── Play is fetch-then-play ──────────────────────────────────────
///
/// Google's recording URL answers only to the account's bearer token, so the
/// server fetches it and streams it back; the client gets bytes and hands them
/// to the same [RecordingPlayer] the Phone module uses. There is no streaming:
/// the whole file arrives before a sound comes out, and a play button silent
/// for three seconds on a twelve-minute call reads as broken. So the control
/// says "Fetching the recording…" while it waits, and that state is tested.
class LeadThread extends StatefulWidget {
  const LeadThread({
    super.key,
    required this.api,
    required this.lead,
    this.player,
    this.onStatusChanged,
    this.onRead,
    this.onReplied,
    this.onStar,
    this.onNote,
    this.session,
    this.onBooked,
  });

  final ApiClient api;

  /// The row from the list: enough for the header without a fetch.
  final Map<String, dynamic> lead;

  /// Tests inject a fake; the widget makes and owns one otherwise.
  final RecordingPlayer? player;

  /// Null hides the status control (somebody who may only read).
  final Future<void> Function(String status)? onStatusChanged;

  /// Called once when the thread opens: this person has now seen the lead.
  final Future<void> Function()? onRead;

  /// Called after a reply has been accepted by the server.
  ///
  /// The row on the left is a CACHE of the newest message — its preview line,
  /// its time, and which side spoke last, which is also what the Unreplied
  /// filter reads. The server rebuilds that cache when it stores the reply;
  /// nothing told the screen, so the thread showed the message and the row
  /// went on quoting the one before it (Jose, 2026-09-09: "i replied a to sean
  /// lang, but it does not update on the left side"). Reloading rather than
  /// patching in place because the list is ordered by last activity: the lead
  /// somebody just answered belongs at the top, and only the server's order is
  /// the real one.
  final Future<void> Function()? onReplied;

  /// Star and note are marks on the lead, shared. Each returns the lead as
  /// the server now holds it (author and time on the note), or null when
  /// the save was refused.
  final Future<Map<String, dynamic>?> Function(bool starred)? onStar;
  final Future<Map<String, dynamic>?> Function(String? note)? onNote;

  /// Needed to book (the franchise list, the display formats, the job
  /// panel). Book is drawn only when both this and [onBooked] are given.
  final Session? session;

  /// The server's booking answer (lead, customer, customer_created, job).
  /// Returns the lead as the list now holds it, or null to keep the
  /// answer's own copy.
  final Future<Map<String, dynamic>?> Function(Map<String, dynamic> result)?
  onBooked;

  @override
  State<LeadThread> createState() => _LeadThreadState();
}

class _LeadThreadState extends State<LeadThread> {
  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  bool get _ownsPlayer => widget.player == null;

  /// The lead as last saved. Starts as the row the list handed over and
  /// follows what onStar / onNote return, so a pushed screen on a phone
  /// shows the note's byline without the list having to rebuild it.
  late Map<String, dynamic> _lead = widget.lead;

  /// The customer this number already is, with their jobs - from the lead's
  /// own read, not the list row.
  Map<String, dynamic>? _customer;

  /// The customer card is one line until opened, so it does not eat the
  /// thread (Jose, 2026-09-30: "it starts to reduce the reply window").
  bool _customerOpen = false;

  /// A phone's header folds its details (the source, ZIP, availability, the
  /// note, the customer) behind one control, so the conversation keeps the
  /// screen: they took two thirds of it and left two messages in view (Jose,
  /// 2026-09-30: "a lot of wasted space and u can barely see the message
  /// window").
  bool _showDetails = false;

  /// Account, Google status, and a name/address/ZIP read out of what the
  /// customer wrote (LeadDetails on the server) - A1 Tools' side facts.
  Map<String, dynamic>? _details;

  /// A website booking's day and window, and where it stands (Jose, 2026-10-08).
  Map<String, dynamic>? _booking;

  /// Everybody else with this lead open right now (LeadPresence), refreshed
  /// with every re-read of the lead.
  List<String> _viewers = const [];

  bool _editingNote = false;
  bool _savingNote = false;
  final _note = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _conversations = const [];

  /// Whether Google will take a reply: text enquiries only, account still
  /// connected. The server says; the screen never guesses from lead_type.
  bool _canReply = false;

  /// Why not, from the channel that refused. Null while a reply can be sent,
  /// and null on an older server — the composer's fallback sentence names no
  /// provider, because a screen that invents a reason invents the wrong one.
  String? _cannotReplyBecause;

  /// Customers whose number matches this lead's, for the booking panel.
  List<Map<String, dynamic>> _matches = const [];

  final _compose = TextEditingController();
  bool _sending = false;
  String? _sendError;

  /// The emoji tray above the reply box (Jose, 2026-10-01: "a button for an
  /// emoji picker on the left of send"). Inline, not a popup.
  bool _emoji = false;

  /// A draft is offered only where a reply can go and the model is on.
  bool _canSuggest = false;

  /// Canned replies beside Send, already filled in for this customer and
  /// brand (`leads.reply_templates`). A tap puts the text in the box to read
  /// first; it never sends by itself.
  List<Map<String, dynamic>> _templates = const [];
  bool _suggesting = false;

  /// The words Suggest put in the box. Marked as AI's while the box still
  /// holds exactly them; the first change makes them the person's.
  String? _aiDraft;

  /// How often an OPEN conversation re-reads itself.
  ///
  /// Faster than the list beside it, because this is somebody mid-conversation
  /// with a member of the public: ten seconds is the gap between them sending
  /// a message and it appearing. The list can afford twenty; a person waiting
  /// for an answer cannot.
  ///
  /// One row, from our own database. No external quota is spent.
  static const _pollEvery = Duration(seconds: 10);

  Timer? _poll;

  /// A website chat is live: the visitor is on the page waiting, so it reads
  /// itself every few seconds, and both sides see the other typing (Jose,
  /// 2026-10-01: "nice for the customer to see x is typing").
  bool get _isLiveChat => _lead['source'] == 'website_chat';
  bool _visitorTyping = false;
  DateTime _typedAt = DateTime.fromMillisecondsSinceEpoch(0);

  void _typing() {
    if (!_isLiveChat || _compose.text.trim().isEmpty) return;
    final now = DateTime.now();
    if (now.difference(_typedAt) < const Duration(seconds: 3)) return;
    _typedAt = now;
    unawaited(widget.api.post('/leads/${widget.lead['id']}/typing', const {}));
  }

  @override
  void initState() {
    super.initState();
    _load();
    _poll = Timer.periodic(
      _isLiveChat ? const Duration(seconds: 3) : _pollEvery,
      (_) => _load(quiet: true),
    );
    _compose.addListener(_typing);
    // After the first frame: the parent answers by rebuilding its list, and
    // a setState during this widget's own build is an exception, not a mark.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) widget.onRead?.call();
    });
  }

  @override
  void didUpdateWidget(LeadThread old) {
    super.didUpdateWidget(old);
    if (!identical(old.lead, widget.lead)) _lead = widget.lead;
  }

  Future<void> _star() async {
    final fresh = await widget.onStar?.call(_lead['starred'] != true);
    if (!mounted || fresh == null) return;
    setState(() => _lead = fresh);
  }

  Future<void> _saveNote() async {
    setState(() => _savingNote = true);
    final text = _note.text.trim();
    final fresh = await widget.onNote?.call(text.isEmpty ? null : text);
    if (!mounted) return;
    setState(() {
      _savingNote = false;
      if (fresh != null) {
        _lead = fresh;
        _editingNote = false;
      }
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    // Off this lead on everybody else's screen now, not when the check-in
    // runs out (Jose, 2026-10-01). Fire and forget: the thread is closing.
    unawaited(widget.api.post('/leads/${widget.lead['id']}/leave', const {}));
    _note.dispose();
    _compose.dispose();
    _player.stop();
    if (_ownsPlayer) _player.dispose();
    super.dispose();
  }

  /// A sentence to a stranger in the customer's name: it goes because a
  /// person pressed Send. Appended to the thread as ours the moment Google
  /// takes it; on refusal the text stays in the box and the reason is shown.
  /// A draft into the box, never a send: the person reads it, changes it,
  /// and presses Send, or does not.
  Future<void> _suggest() async {
    if (_suggesting) return;
    setState(() {
      _suggesting = true;
      _sendError = null;
    });

    final response = await widget.api.post(
      '/leads/${widget.lead['id']}/suggest-reply',
      const {},
    );
    if (!mounted) return;

    setState(() {
      _suggesting = false;
      if (!response.ok) {
        _sendError = response.message;
        return;
      }
      final text = (response.data as Map?)?['text'] as String? ?? '';
      if (text.isNotEmpty) {
        _compose.text = text;
        _aiDraft = text;
      }
    });
  }

  Future<void> _send() async {
    final text = _compose.text.trim();
    if (text.isEmpty || _sending) return;

    setState(() {
      _sending = true;
      _sendError = null;
    });

    final response = await widget.api.post(
      '/leads/${widget.lead['id']}/messages',
      {'text': text},
    );
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _sending = false;
        _sendError = response.message;
      });
      return;
    }

    setState(() {
      _sending = false;
      _conversations = [
        ..._conversations,
        (response.data as Map<String, dynamic>? ?? const {}),
      ];
      _compose.clear();
      _aiDraft = null;
      _emoji = false;
    });

    // The thread has it; the row on the left does not until this fires.
    await widget.onReplied?.call();
  }

  /// Re-read this conversation.
  ///
  /// `quiet` is what makes the poll usable: without it every tick would set
  /// `_loading` and replace the thread with a spinner, which is a worse
  /// experience than the staleness it is fixing. A quiet pass changes the
  /// messages and nothing else - the compose box, the note editor and the
  /// recording player are all untouched, so a half-typed reply survives it.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    final response = await widget.api.get('/leads/${widget.lead['id']}');
    if (!mounted) return;

    // A quiet pass that fails says nothing. It will try again in ten seconds,
    // and replacing a readable conversation with an error because one poll
    // lost the network is the wrong trade.
    if (quiet && !response.ok) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data as Map<String, dynamic>? ?? const {};
      _conversations = List<Map<String, dynamic>>.from(
        (data['conversations'] as List? ?? const [])
            .cast<Map<String, dynamic>>(),
      );
      _canReply = data['can_reply'] == true;
      _cannotReplyBecause = data['cannot_reply_because'] as String?;
      _canSuggest = data['can_suggest'] == true;
      _templates = (data['reply_templates'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _customer = data['customer'] as Map<String, dynamic>?;
      _details = data['details'] as Map<String, dynamic>?;
      _booking = data['booking'] as Map<String, dynamic>?;
      _viewers = [for (final v in (data['viewers'] as List? ?? const [])) '$v'];
      _visitorTyping = data['visitor_typing'] == true;
      _matches = List<Map<String, dynamic>>.from(
        (data['customer_matches'] as List? ?? const [])
            .cast<Map<String, dynamic>>(),
      );
    });
  }

  /// Where the lead stands, and the way to move it, as one control: Archive,
  /// Not a lead, Lead, with the one it is now filled (Jose, 2026-09-30: "we
  /// should also add Lead as well to mark if something is a lead or not").
  /// It replaced two verbs on a New lead and a Back to New on any other, which
  /// left no way to say a lead IS one. A booked lead is a lead.
  Widget _moves(Map<String, dynamic> lead, {bool fill = false}) {
    final current = switch ('${lead['status'] ?? 'new'}') {
      'archived' => 'archived',
      'not_a_lead' => 'not_a_lead',
      'completed' => 'completed',
      'lost' => 'lost',
      _ => 'new',
    };

    // A phone's line beside Book is a picker since Lost made the moves five
    // (UI/UX, 2026-10-09): five equal segments shrank "Not a lead" and
    // "Completed" to about 7px while "Lead" stayed full size.
    if (fill) {
      return SearchPickerField<String>(
        key: const ValueKey('status-moves'),
        value: current,
        label: 'Status',
        isDense: true,
        options: const [
          SearchOption(value: 'archived', label: 'Archive'),
          SearchOption(value: 'not_a_lead', label: 'Not a lead'),
          SearchOption(value: 'new', label: 'Lead'),
          SearchOption(value: 'completed', label: 'Completed'),
          SearchOption(value: 'lost', label: 'Lost'),
        ],
        onChanged: (picked) {
          if (picked != null && picked != current) {
            widget.onStatusChanged!(picked);
          }
        },
      );
    }

    // On a phone's one line beside Book there is not room for four words at
    // full size: a label shrinks to fit rather than breaking mid-word
    // ("Archiv-e", 2026-10-01, once Completed made it four).
    Widget label(String text, String key) {
      final words = Text(
        text,
        key: ValueKey(key),
        maxLines: 1,
        softWrap: false,
      );
      return fill ? FittedBox(fit: BoxFit.scaleDown, child: words) : words;
    }

    final control = SegmentedButton<String>(
      key: const ValueKey('status-moves'),
      showSelectedIcon: false,
      style: fill
          ? SegmentedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 6),
            )
          : null,
      segments: [
        ButtonSegment(
          value: 'archived',
          label: label('Archive', 'status-archive'),
        ),
        ButtonSegment(
          value: 'not_a_lead',
          label: label('Not a lead', 'status-not-a-lead'),
        ),
        ButtonSegment(value: 'new', label: label('Lead', 'status-lead')),
        // Nobody owes the customer an answer - "Thank you" was their last
        // word. Clears the red warning (Jose, 2026-10-01); writing again
        // reopens the lead.
        ButtonSegment(
          value: 'completed',
          label: label('Completed', 'status-completed'),
        ),
        // A real customer who declined or wants what we do not do (Jose,
        // 2026-10-09).
        ButtonSegment(value: 'lost', label: label('Lost', 'status-lost')),
      ],
      selected: {current},
      onSelectionChanged: (picked) {
        if (picked.first != current) widget.onStatusChanged!(picked.first);
      },
    );

    return fill ? SizedBox(width: double.infinity, child: control) : control;
  }

  /// Below this a header's name line cannot hold the buttons as well, so
  /// they go under the lines instead. 880 since the moves became three
  /// (Archive, Not a lead, Lead) and a gap before Call and Book: at 640 the
  /// name line overflowed by 11px in a 740-wide thread.
  static const double _besideNameFrom = 880;

  /// Desktop: the moves, then Book, the one that matters most, last. On a
  /// phone Book is full width and the moves share a row under it.
  /// Two groups, apart (Jose, 2026-09-30): where the lead stands - Archive,
  /// Not a lead, Lead - and what to do about the customer - Call, Book.
  Widget _actions(Map<String, dynamic> lead) => Wrap(
    spacing: 24,
    runSpacing: 8,
    alignment: WrapAlignment.end,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      if (widget.onStatusChanged != null) _moves(lead),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (_canCall(lead))
            OutlinedButton.icon(
              key: const ValueKey('lead-call'),
              onPressed: () => _call(lead['phone'] as String),
              icon: const Icon(Icons.call_outlined, size: 18),
              label: const Text('Call'),
            ),
          if (_canBook)
            FilledButton(
              key: const ValueKey('book-open'),
              onPressed: _openBooking,
              child: const Text('Book'),
            ),
        ],
      ),
    ],
  );

  /// "When can I call you?" answered by calling them, from EzBizi's own
  /// phone, rather than by sending our number (Jose, 2026-09-30).
  bool _canCall(Map<String, dynamic> lead) =>
      (lead['phone'] as String?)?.isNotEmpty == true &&
      (widget.session?.me?.permissions.contains('phone.phone.view') ?? false);

  /// In a panel of its own, narrow enough that Call shows only its pad with
  /// the number typed in. Handed to showSidePanel bare, the whole three-pane
  /// Communication screen filled the window with no background, over Leads
  /// (Jose, 2026-10-12).
  Future<void> _call(String phone) => showSidePanel<void>(
    context,
    builder: (_) => SidePanel(
      title: 'Call',
      subtitle: phone,
      scrollable: false,
      child: CallTab(
        api: widget.api,
        session: widget.session!,
        initialNumber: phone,
      ),
    ),
  );

  bool get _canBook =>
      widget.session != null &&
      widget.onBooked != null &&
      _lead['job'] == null &&
      !_loading;

  /// The app's own side panel at every width - on a phone it fills the
  /// window - never a pushed full screen (Jose's containment rule).
  Future<void> _openBooking() async {
    final panel = BookLeadPanel(
      api: widget.api,
      session: widget.session!,
      lead: _lead,
      matches: _matches,
      said: [
        for (final c in _conversations)
          if (c['from'] != 'ADVERTISER' && c['text'] is String)
            c['text'] as String,
      ],
      onBooked: (result) async {
        Navigator.of(context).pop();
        final fresh = await widget.onBooked!(result);
        if (!mounted) return;
        setState(() {
          _lead = fresh ?? (result['lead'] as Map<String, dynamic>? ?? _lead);
        });
      },
    );

    await showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Book this lead',
        subtitle: 'Creates a customer and a job, and marks the lead booked.',
        child: panel,
      ),
    );
  }

  Future<void> _openJob(String id) async {
    final panel = JobDetailPanel(
      api: widget.api,
      session: widget.session!,
      id: id,
    );

    await showSidePanel<void>(context, builder: (_) => panel);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final lead = _lead;
    final starred = lead['starred'] == true;
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final company = leadCompany(lead);
    // The company is on screen as its tag, so the line does not say it again.
    final line = leadHeaderLine(lead, company: company == null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          key: const ValueKey('thread-header'),
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
          child: LayoutBuilder(
            builder: (context, box) => Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          // The name, its pill and its star take the line, and
                          // what follows sits at its end: beside a Spacer the
                          // name got half the room and wrapped on a phone.
                          Expanded(
                            child: Row(
                              children: [
                                Flexible(
                                  child: Text(
                                    leadTitle(lead),
                                    style: theme.textTheme.titleMedium,
                                  ),
                                ),
                                const SizedBox(width: 8),
                                // What it is, said as a pill: the corner
                                // field that read as a search box is gone
                                // (design 28).
                                leadStatusPill(
                                  lead['status'],
                                  key: const ValueKey('thread-status'),
                                ),
                                if (widget.onStar != null)
                                  IconButton(
                                    key: const ValueKey('thread-star'),
                                    tooltip: starred ? 'Unstar' : 'Star',
                                    iconSize: 18,
                                    onPressed: _star,
                                    icon: Icon(
                                      starred ? Icons.star : Icons.star_border,
                                      color: starred
                                          ? theme.colorScheme.primary
                                          : theme.colorScheme.outline,
                                    ),
                                  ),
                              ],
                            ),
                          ),
                          if (narrow)
                            TextButton.icon(
                              key: const ValueKey('thread-details-toggle'),
                              onPressed: () =>
                                  setState(() => _showDetails = !_showDetails),
                              icon: Icon(
                                _showDetails
                                    ? Icons.expand_less
                                    : Icons.expand_more,
                                size: 18,
                              ),
                              label: const Text('Details'),
                            ),
                          // Beside the name, sharing only its line: set beside
                          // the whole header they squeezed the lines under it.
                          if (!narrow && box.maxWidth >= _besideNameFrom) ...[
                            const SizedBox(width: 12),
                            _actions(lead),
                          ],
                        ],
                      ),
                      // The number on its own line only when the title is a
                      // name; when the title IS the number, saying it twice
                      // adds nothing.
                      if (showsNumberLine(lead) && !narrow)
                        Text(
                          formatPhoneNumber(lead['phone'] as String?),
                          style: theme.textTheme.bodySmall,
                        ),
                      Row(
                        children: [
                          // Whose lead it is, first on the line: chimney prices
                          // went to a garage-door lead (Jose, 2026-09-30).
                          if (company != null) ...[
                            CompanyTag(
                              company,
                              key: const ValueKey('thread-company'),
                            ),
                            const SizedBox(width: 6),
                          ],
                          Flexible(
                            child: Text(
                              narrow && showsNumberLine(lead)
                                  ? '${formatPhoneNumber(lead['phone'] as String?)} · $line'
                                  : line,
                              maxLines: narrow ? 1 : null,
                              overflow: narrow ? TextOverflow.ellipsis : null,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                        ],
                      ),
                      // What it looks like, read by a model, beside what
                      // somebody decided. Only when a reading exists.
                      if (lead['classification'] is String &&
                          (!narrow || _showDetails))
                        Text(
                          [
                            'Looks like ${classificationWord(lead['classification'] as String)}',
                            if ((lead['summary'] as String?)?.isNotEmpty ==
                                true)
                              lead['summary'] as String,
                          ].join(' · '),
                          key: const ValueKey('classification-line'),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                            fontStyle: FontStyle.italic,
                          ),
                        ),
                      if (_viewers.isNotEmpty) _viewingBanner(compact: narrow),
                      if (_booking != null) _bookingBlock(theme),
                      if (!narrow || _showDetails) ...[
                        if (_details != null) _detailsBlock(theme, lead),
                        _noteBlock(theme, lead),
                        if (_customer != null) _customerCard(theme, _customer!),
                      ],
                      if (lead['job'] is Map)
                        _bookedLine(
                          theme,
                          lead['job'] as Map,
                          matched: lead['job_matched_by'] == 'phone',
                        ),
                      // One line on a phone: where the lead stands, then
                      // Book. They were two full-width rows.
                      if (narrow &&
                          (_canBook || widget.onStatusChanged != null))
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Row(
                            children: [
                              if (widget.onStatusChanged != null)
                                Expanded(child: _moves(lead, fill: true)),
                              if (_canBook) ...[
                                const SizedBox(width: 8),
                                FilledButton(
                                  key: const ValueKey('book-open'),
                                  onPressed: _openBooking,
                                  child: const Text('Book'),
                                ),
                              ],
                            ],
                          ),
                        ),
                      if (!narrow && box.maxWidth < _besideNameFrom)
                        Padding(
                          padding: const EdgeInsets.only(top: 10),
                          child: Align(
                            alignment: AlignmentDirectional.centerEnd,
                            child: _actions(lead),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: ColoredBox(
            color: theme.colorScheme.surfaceContainerLow,
            child: AsyncBody(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _conversations.isEmpty ? _nothing(theme) : _thread(theme),
            ),
          ),
        ),
        // Only somebody who may change the lead may speak for the business,
        // and only where Google will take it. A call lead says why there is
        // no box rather than drawing one that would be refused.
        if (widget.onStatusChanged != null && !_loading) ...[
          const Divider(height: 1),
          // Clear of the phone's gesture bar: edge to edge, the last line
          // otherwise sits under the home indicator (UI/UX, 2026-09-28).
          SafeArea(
            top: false,
            child: _canReply
                ? _composer(theme)
                : Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
                    child: Text(
                      // The server's sentence, from the channel that refused. The
                      // fallback is source-free on purpose: a screen that invents a
                      // reason invents the wrong one, which is how a Thumbtack
                      // message came to be described as a Google phone call.
                      _cannotReplyBecause ??
                          'This enquiry cannot be replied to from here.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
          ),
        ],
      ],
    );
  }

  Widget _composer(ThemeData theme) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_sendError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              _sendError!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        if (_visitorTyping)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              typingLine(_lead['contact_name'] as String?),
              key: const ValueKey('visitor-typing'),
              style: theme.textTheme.bodySmall?.copyWith(
                fontStyle: FontStyle.italic,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        if (_templates.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: LayoutBuilder(
              builder: (context, box) {
                final chips = [
                  for (final t in _templates)
                    ActionChip(
                      key: ValueKey('reply-template-${t['name']}'),
                      avatar: const Icon(Icons.bolt_outlined, size: 16),
                      label: Text('${t['name']}'),
                      tooltip: '${t['text']}',
                      visualDensity: VisualDensity.compact,
                      materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      onPressed: _sending
                          ? null
                          : () => _compose.value = TextEditingValue(
                              text: '${t['text']}',
                              selection: TextSelection.collapsed(
                                offset: '${t['text']}'.length,
                              ),
                            ),
                    ),
                ];

                // A phone keeps them to one line that scrolls sideways: as
                // rows they took a quarter of the screen from the thread
                // (Jose, 2026-10-01, the same complaint as the header).
                if (box.maxWidth < 600) {
                  return SingleChildScrollView(
                    key: const ValueKey('reply-templates'),
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final (i, chip) in chips.indexed) ...[
                          if (i > 0) const SizedBox(width: 6),
                          chip,
                        ],
                      ],
                    ),
                  );
                }

                return Wrap(
                  key: const ValueKey('reply-templates'),
                  alignment: WrapAlignment.end,
                  spacing: 6,
                  runSpacing: 4,
                  children: chips,
                );
              },
            ),
          ),
        if (_emoji)
          Container(
            key: const ValueKey('reply-emoji-tray'),
            margin: const EdgeInsets.only(bottom: 6),
            padding: const EdgeInsets.all(6),
            constraints: const BoxConstraints(maxHeight: 132),
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: SingleChildScrollView(
              child: Wrap(
                children: [
                  for (final e in _emojis)
                    InkWell(
                      key: ValueKey('reply-emoji-$e'),
                      borderRadius: BorderRadius.circular(
                        EzBiziTheme.controlRadius,
                      ),
                      onTap: _sending ? null : () => _insertEmoji(e),
                      child: SizedBox.square(
                        dimension: 40,
                        child: Center(
                          child: Text(e, style: const TextStyle(fontSize: 22)),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        if (_aiDraft != null && _compose.text == _aiDraft)
          const Padding(
            padding: EdgeInsets.only(bottom: 6),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: AiMark.badge(
                'Draft by AI',
                key: ValueKey('reply-ai-draft'),
                tooltip:
                    'Written by AI. Read it before sending: nothing goes '
                    'until you press Send.',
              ),
            ),
          ),
        Row(
          // Bottom-aligned, and every control is a one-line box tall, so
          // they line up with the box and stay by its last line as it grows
          // (Jose, 2026-10-01: "the same size as the field").
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: TextField(
                key: const ValueKey('reply-box'),
                controller: _compose,
                // Redrawn only while a draft is marked: the mark goes the
                // moment the words change.
                onChanged: _aiDraft == null ? null : (_) => setState(() {}),
                // The phone's own spell check and suggestions (Jose,
                // 2026-09-30: "hellu" was not corrected). Windows and the
                // web build have no native checker Flutter can call.
                spellCheckConfiguration:
                    WidgetsBinding
                        .instance
                        .platformDispatcher
                        .nativeSpellCheckServiceDefined
                    ? const SpellCheckConfiguration()
                    : null,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.newline,
                enabled: !_sending,
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: 'Reply to the customer',
                  border: OutlineInputBorder(),
                  // 12 + a 24 line + 12 is the 48 the buttons beside it are.
                  contentPadding: EdgeInsets.symmetric(
                    horizontal: 14,
                    vertical: 12,
                  ),
                ),
              ),
            ),
            if (_canSuggest) ...[
              const SizedBox(width: 8),
              OutlinedButton(
                key: const ValueKey('reply-suggest'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size(0, _controlHeight),
                ),
                onPressed: _sending || _suggesting ? null : _suggest,
                child: Text(_suggesting ? 'Drafting…' : 'Suggest'),
              ),
            ],
            const SizedBox(width: 8),
            Tooltip(
              message: _emoji ? 'Hide emoji' : 'Emoji',
              child: OutlinedButton(
                key: const ValueKey('reply-emoji'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.square(_controlHeight),
                  padding: EdgeInsets.zero,
                  backgroundColor: _emoji
                      ? theme.colorScheme.secondaryContainer
                      : null,
                ),
                onPressed: _sending
                    ? null
                    : () => setState(() => _emoji = !_emoji),
                child: const Icon(Icons.emoji_emotions_outlined, size: 20),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.icon(
              key: const ValueKey('reply-send'),
              style: FilledButton.styleFrom(
                minimumSize: const Size(0, _controlHeight),
              ),
              onPressed: _sending ? null : _send,
              icon: _sending
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.send, size: 18),
              label: Text(_sending ? 'Sending…' : 'Send'),
            ),
          ],
        ),
      ],
    ),
  );

  /// Puts [emoji] where the cursor is, or over the selection, and leaves
  /// the tray open for the next one.
  void _insertEmoji(String emoji) {
    final value = _compose.value;
    final at = value.selection.isValid
        ? value.selection
        : TextSelection.collapsed(offset: value.text.length);
    _compose.value = TextEditingValue(
      text: value.text.replaceRange(at.start, at.end, emoji),
      selection: TextSelection.collapsed(offset: at.start + emoji.length),
    );
  }

  /// "Booked · Job 0142 · Thu 17 Sep, 2 PM", with the job one tap away.
  /// This number is already a customer: who, where, and what they have had
  /// done, newest first - read before answering. Jose, 2026-09-28.
  Widget _customerCard(ThemeData theme, Map customer) {
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final jobs = (customer['jobs'] as List? ?? const []).cast<Map>();
    final total = (customer['jobs_total'] as num?)?.toInt() ?? 0;
    final revenue = (customer['revenue_minor'] as num?)?.toInt() ?? 0;
    final currency = customer['currency'] as String?;

    final latest = jobs.isEmpty
        ? ''
        : formatJobWhen(jobs.first['when'] as String?);
    // Green: a known customer is good news (Jose, 2026-10-09).
    final green = ToneColours.of(context, Tone.ok);

    return Container(
      key: const ValueKey('lead-customer'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.fromLTRB(12, 2, 4, 2),
      decoration: BoxDecoration(
        color: green.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.person_outline, size: 18, color: green.ink),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  [
                    'Existing customer · ${customer['name']}',
                    if (customer['number'] != null) '${customer['number']}',
                    total == 0
                        ? 'No jobs yet'
                        : total == 1
                        ? '1 job'
                        : '$total jobs',
                    if (!_customerOpen && latest.isNotEmpty) 'last $latest',
                  ].join(' · '),
                  style: theme.textTheme.titleSmall?.copyWith(color: green.ink),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              IconButton(
                key: const ValueKey('lead-customer-more'),
                tooltip: _customerOpen ? 'Less' : 'Address and jobs',
                visualDensity: VisualDensity.compact,
                icon: Icon(
                  _customerOpen ? Icons.expand_less : Icons.expand_more,
                ),
                onPressed: () => setState(() => _customerOpen = !_customerOpen),
              ),
              if (widget.session != null)
                TextButton(
                  key: const ValueKey('lead-customer-open'),
                  onPressed: () => _openCustomer(customer['id'] as String),
                  child: const Text('Open customer'),
                ),
            ],
          ),
          if (_customerOpen && customer['address'] != null)
            Text('${customer['address']}', style: quiet),
          if (_customerOpen && revenue > 0 && currency != null)
            Text(formatMinor(revenue, currency), style: quiet),
          if (_customerOpen)
            for (final job in jobs)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(
                  [
                    formatJobWhen(job['when'] as String?),
                    '${job['title'] ?? 'Job ${job['number'] ?? ''}'}',
                    if (job['status'] != null) '${job['status']}',
                    if (job['amount_minor'] is num && job['currency'] is String)
                      formatMinor(
                        (job['amount_minor'] as num).toInt(),
                        job['currency'] as String,
                      ),
                  ].where((s) => s.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodySmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
        ],
      ),
    );
  }

  Future<void> _openCustomer(String id) async {
    final panel = CustomerDetailPanel(
      api: widget.api,
      session: widget.session!,
      id: id,
    );

    await showSidePanel<void>(context, builder: (_) => panel);
  }

  Widget _bookedLine(ThemeData theme, Map job, {bool matched = false}) {
    final when = formatJobWhen(job['scheduled_for'] as String?);
    final line = [
      // Found by the customer's number, not booked from this lead: say so.
      matched ? 'Matched by phone' : 'Booked',
      'Job ${job['number'] ?? ''}'.trim(),
      if (when.isNotEmpty) when,
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          Icon(Icons.check_circle, size: 16, color: theme.colorScheme.primary),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              line,
              key: const ValueKey('booked-line'),
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          if (widget.session != null && job['id'] is String)
            TextButton(
              key: const ValueKey('booked-open-job'),
              onPressed: () => _openJob(job['id'] as String),
              child: const Text('Open the job'),
            ),
        ],
      ),
    );
  }

  /// One shared line with Edit in place, and a byline: "Maya · 2h ago".
  ///
  /// A shared field that cannot say who wrote it is one people stop
  /// trusting, and the silent overwrite is the worse half; the server stamps
  /// the author and the time on every change.
  Widget _noteBlock(ThemeData theme, Map<String, dynamic> lead) {
    final scheme = theme.colorScheme;
    final note = lead['note'] as String?;
    final canEdit = widget.onNote != null;

    if (_editingNote) {
      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              key: const ValueKey('note-box'),
              controller: _note,
              minLines: 1,
              maxLines: 4,
              autofocus: true,
              enabled: !_savingNote,
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'A note for whoever picks this up next',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _savingNote
                      ? null
                      : () => setState(() => _editingNote = false),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 4),
                FilledButton(
                  key: const ValueKey('note-save'),
                  onPressed: _savingNote ? null : _saveNote,
                  child: Text(_savingNote ? 'Saving…' : 'Save'),
                ),
              ],
            ),
          ],
        ),
      );
    }

    if (note == null || note.isEmpty) {
      if (!canEdit) return const SizedBox.shrink();
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const ValueKey('note-add'),
          onPressed: () {
            _note.clear();
            setState(() => _editingNote = true);
          },
          icon: const Icon(Icons.edit_outlined, size: 16),
          label: const Text('Add a note'),
        ),
      );
    }

    // The byline goes on the LABEL line - "Note · Maya · 2h ago" - never
    // after the text: a note is up to 4,000 characters, so a trailing byline
    // lands wherever the last line ends and the eye cannot learn where to
    // look. Null shows nothing: a missing author and a relation not loaded
    // on some path look the same here, and "Unknown" would assert something
    // about the note when the truth is sometimes about the query.
    final by = (lead['note_by'] as Map?)?['name'] as String?;
    final when = leadWhen(lead['note_updated_at'] as String?);
    final label = ['Note', ?by, if (when.isNotEmpty) when].join(' · ');

    return Container(
      margin: const EdgeInsets.only(top: 9),
      padding: const EdgeInsets.fromLTRB(10, 7, 6, 7),
      decoration: BoxDecoration(
        color: scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  key: const ValueKey('note-byline'),
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSecondaryContainer,
                  ),
                ),
              ),
              if (canEdit)
                TextButton(
                  key: const ValueKey('note-edit'),
                  onPressed: () {
                    _note.text = note;
                    setState(() => _editingNote = true);
                  },
                  child: const Text('Edit'),
                ),
            ],
          ),
          Text(
            note,
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSecondaryContainer,
            ),
          ),
        ],
      ),
    );
  }

  Widget _nothing(ThemeData theme) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        // NOT "Google has not recorded a call or message on this enquiry."
        // That is a claim about Google's records made from ours, and it is
        // false in the ordinary case: the call is read on a slower tick than
        // the enquiry, so a lead that arrived a minute ago has one and this
        // screen had not fetched it yet. It reads as "the customer said
        // nothing", which is the one thing it must never say wrongly.
        'Nothing has reached EzBizi on this enquiry yet. A call or message '
        'usually appears within a few minutes of the enquiry itself.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
        textAlign: TextAlign.center,
      ),
    ),
  );

  Widget _thread(ThemeData theme) {
    final items = threadItems(_conversations);

    // Opens on the NEWEST message, as a chat does (the dispatchers, via Jose,
    // 2026-09-29: "it sends them to the top ... it should be sending them to
    // the bottom"). A reversed list starts at its end without scrolling, so a
    // long thread never has to be built to its bottom first.
    return ListView.builder(
      key: const ValueKey('thread-list'),
      reverse: true,
      padding: const EdgeInsets.all(14),
      itemCount: items.length,
      itemBuilder: (context, i) {
        final item = items[items.length - 1 - i];
        if (item is String) return _dayDivider(theme, item);
        final c = item as Map<String, dynamic>;
        final mine = c['from'] == 'ADVERTISER';
        // Our replies say who sent them (Jose, 2026-09-29). A reply with no
        // name was sent outside EzBizi - A1 Tools, Google - so it is not
        // "You", which would claim the reader sent it.
        final who = !mine
            ? 'Customer'
            : c['automatic'] == true
            ? 'Automation'
            : (c['sent_by'] as String?) ?? 'Sent outside EzBizi';
        final when = messageWhen(
          c['occurred_at'] as String?,
          clock: widget.session?.me?.display.formatTime,
        );

        final Widget body;
        if (c['channel'] == 'PHONE_CALL') {
          body = _callRow(theme, c, who: who, when: when);
        } else if (c['channel'] == 'BOOKING') {
          body = _bookingRow(theme, when: when);
        } else {
          body = _message(
            theme,
            c,
            mine: mine,
            // Ours, not automatic, and nobody in EzBizi named as sending it:
            // it went out from A1 Tools or Google directly.
            outside: mine && c['automatic'] != true && c['sent_by'] == null,
            automatic: mine && c['automatic'] == true,
            who: who,
            when: when,
          );
        }

        final reactions = (c['_reactions'] as List? ?? const []).cast<String>();
        final sender = c['automatic'] == true
            ? _sender(theme, null, automatic: true)
            : mine && c['sent_by'] is String
            ? _sender(theme, c['sent_by'] as String)
            : null;

        return Align(
          alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Padding(
              padding: EdgeInsets.only(bottom: reactions.isEmpty ? 8 : 18),
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Padding(
                    padding: EdgeInsets.only(right: sender == null ? 0 : 10),
                    child: body,
                  ),
                  // Who sent it, bottom right of the bubble, as A1 Tools does.
                  if (sender != null)
                    Positioned(right: 0, bottom: -6, child: sender),
                  // A reaction hangs on the message it answers (WhatsApp).
                  if (reactions.isNotEmpty)
                    Positioned(
                      left: mine ? null : 10,
                      right: mine ? 16 : null,
                      bottom: -14,
                      child: _reactionChip(theme, reactions),
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  /// "── Today ──" where the day changes (Jose, 2026-09-28).
  Widget _dayDivider(ThemeData theme, String day) => Padding(
    key: ValueKey('day-$day'),
    padding: const EdgeInsets.symmetric(vertical: 10),
    child: Row(
      children: [
        const Expanded(child: Divider()),
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 10),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(day, style: theme.textTheme.labelSmall),
        ),
        const Expanded(child: Divider()),
      ],
    ),
  );

  Widget _reactionChip(ThemeData theme, List<String> emojis) => Container(
    key: const ValueKey('reaction'),
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
    decoration: BoxDecoration(
      color: theme.colorScheme.surface,
      border: Border.all(color: theme.colorScheme.outlineVariant),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Text(emojis.join(' '), style: const TextStyle(fontSize: 14)),
  );

  /// Initials in the person's own colour; a bot for a rule.
  Widget _sender(ThemeData theme, String? name, {bool automatic = false}) {
    // Each person keeps one colour (the theme's person palette, AA in light
    // and dark); a rule is neutral.
    final colours = automatic
        ? ToneColours.of(context, Tone.neutral)
        : PersonColours.of(context, name!);
    final initials = automatic
        ? ''
        : name!
              .split(RegExp(r'\s+'))
              .where((w) => w.isNotEmpty)
              .take(2)
              .map((w) => w[0].toUpperCase())
              .join();

    return Tooltip(
      message: automatic ? 'Sent by an automation' : '$name · via EzBizi',
      child: CircleAvatar(
        key: ValueKey(automatic ? 'sender-bot' : 'sender-$initials'),
        radius: 10,
        backgroundColor: colours.fill,
        child: automatic
            ? Icon(Icons.smart_toy_outlined, size: 12, color: colours.ink)
            : Text(
                initials,
                style: TextStyle(
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  color: colours.ink,
                ),
              ),
      ),
    );
  }

  /// The day and arrival window somebody asked for on the website's booking
  /// widget, with Approve and Decline while it waits (Leads > Online Booking,
  /// Jose 2026-10-08). Approving keeps the window held until the job is
  /// booked; declining gives it back. Neither one messages the customer.
  Widget _bookingBlock(ThemeData theme) {
    final b = _booking!;
    final status = '${b['status']}';
    const days = [
      'Monday',
      'Tuesday',
      'Wednesday',
      'Thursday',
      'Friday',
      'Saturday',
      'Sunday',
    ];
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    final date = DateTime.tryParse('${b['date']}');
    String hm(Object? t) {
      final parts = '$t'.split(':');
      final h = int.tryParse(parts.first) ?? 0;
      return '${h % 12 == 0 ? 12 : h % 12}:${parts.length > 1 ? parts[1] : '00'} ${h < 12 ? 'AM' : 'PM'}';
    }

    final when = [
      if (date != null)
        '${days[date.weekday - 1]}, ${months[date.month - 1]} ${date.day}',
      '${hm(b['start_time'])} - ${hm(b['end_time'])}',
    ].join(' · ');
    final by = b['decided_by'] is String ? ' by ${b['decided_by']}' : '';

    return Padding(
      key: const ValueKey('lead-booking'),
      padding: const EdgeInsets.only(top: 6, bottom: 2),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Icon(
            Icons.event_available_outlined,
            size: 18,
            color: theme.colorScheme.primary,
          ),
          Text(
            'Requested: $when',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          if (status == 'pending') ...[
            FilledButton(
              key: const ValueKey('booking-approve'),
              onPressed: () => _decideBooking('approve'),
              child: const Text('Approve'),
            ),
            OutlinedButton(
              key: const ValueKey('booking-decline'),
              onPressed: () => _decideBooking('decline'),
              child: const Text('Decline'),
            ),
          ] else
            Text(
              '${status == 'approved' ? 'Approved' : 'Declined'}$by',
              key: const ValueKey('booking-status'),
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  Future<void> _decideBooking(String decision) async {
    // Two literal paths, so ClientCallsRealRoutesTest can match each route.
    final response = decision == 'approve'
        ? await widget.api.post(
            '/leads/${widget.lead['id']}/booking/approve',
            const {},
          )
        : await widget.api.post(
            '/leads/${widget.lead['id']}/booking/decline',
            const {},
          );
    if (!mounted) return;
    if (response.ok && response.data is Map) {
      setState(
        () => _booking = Map<String, dynamic>.from(response.data as Map),
      );
    } else {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.message)));
    }
  }

  /// The account it came through, Google's own status, and what the customer
  /// told us about themselves - with a way to check availability for the ZIP
  /// without leaving Leads (Jose, 2026-09-28).
  Widget _detailsBlock(ThemeData theme, Map<String, dynamic> lead) {
    final d = _details!;
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final named = (lead['contact_name'] as String?)?.trim().isNotEmpty == true;
    final zip = d['zip'] as String?;
    final address = d['address'] as String?;
    final facts = [
      if (d['account'] is String) 'Account: ${d['account']}',
      if (d['google_status'] is String) 'Google: ${d['google_status']}',
    ];
    final mayCheck =
        widget.session?.me?.can('scheduling.availability.view') == true;

    return Column(
      key: const ValueKey('lead-details'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // In colour, as A1 Tools draws them (Jose, 2026-09-29): the account
        // in orange, Google's own status in its meaning.
        if (facts.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 2),
            child: Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                if (d['account'] is String)
                  _pill(
                    '${d['account']}',
                    PersonColours.hue(context, 5),
                    icon: Icons.campaign_outlined,
                    key: const ValueKey('lead-account'),
                  ),
                if (d['google_status'] is String)
                  _pill(
                    'Google: ${d['google_status']}',
                    ToneColours.of(
                      context,
                      googleStatusTone(d['google_status'] as String),
                    ),
                    key: const ValueKey('lead-google-status'),
                  ),
              ],
            ),
          ),
        if (!named && d['name'] is String)
          Text('Name, from the conversation: ${d['name']}', style: quiet),
        // Google placed the address exactly: show its version, marked.
        if ((d['checked_address'] as Map?)?['exact'] == true)
          Row(
            key: const ValueKey('lead-address-checked'),
            children: [
              Icon(
                Icons.verified_outlined,
                size: 14,
                color: theme.colorScheme.primary,
              ),
              const SizedBox(width: 4),
              Flexible(
                child: Tooltip(
                  message:
                      'Checked with Google. The customer wrote: ${address ?? ''}',
                  child: Text(
                    '${(d['checked_address'] as Map)['address']}',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ),
            ],
          )
        else if (address != null || zip != null)
          Text(
            [
              ?address,
              if (zip != null && !(address ?? '').contains(zip)) 'ZIP $zip',
            ].join(' · '),
            key: const ValueKey('lead-address'),
            style: theme.textTheme.bodySmall,
          ),
        if (mayCheck)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('check-availability'),
              // No zip yet: the address they wrote, which the pane looks up.
              onPressed: () => _openAvailability(zip ?? address),
              icon: const Icon(Icons.event_available_outlined, size: 18),
              label: Text(
                zip == null
                    ? 'Check availability'
                    : 'Check availability for $zip',
              ),
            ),
          ),
      ],
    );
  }

  Widget _pill(String text, ToneColours colours, {IconData? icon, Key? key}) =>
      Container(
        key: key,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: colours.fill,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (icon != null) ...[
              Icon(icon, size: 13, color: colours.ink),
              const SizedBox(width: 4),
            ],
            Flexible(
              child: Text(
                text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: colours.ink,
                ),
              ),
            ),
          ],
        ),
      );

  /// "IVR menu:", "Dispatcher:" and "Customer:" in bold, where Grok has
  /// divided the call by speaker (Jose, 2026-10-12).
  static final _bySpeaker = RegExp(
    r'^(IVR menu|Dispatcher|Customer): ',
    multiLine: true,
  );

  static TextSpan _speakers(String text) {
    final label = RegExp(r'^(IVR menu|Dispatcher|Customer): ');
    final lines = text.split('\n');
    return TextSpan(
      children: [
        for (var i = 0; i < lines.length; i++) ...[
          if (label.firstMatch(lines[i]) case final m?) ...[
            TextSpan(
              text: m.group(0),
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            TextSpan(text: lines[i].substring(m.end)),
          ] else
            TextSpan(text: lines[i]),
          if (i < lines.length - 1) const TextSpan(text: '\n'),
        ],
      ],
    );
  }

  /// Transcripts opened to their full length, by conversation id.
  final _openTranscripts = <String>{};

  Widget _transcript(ThemeData theme, String id, String text) {
    final open = _openTranscripts.contains(id);

    // A quote with a thin rule rather than a grey box (UI queue 74): the
    // words are the call's, and a box made them read as a separate panel.
    return Container(
      key: ValueKey('transcript-$id'),
      width: double.infinity,
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.only(left: 10),
      decoration: BoxDecoration(
        border: Border(
          left: BorderSide(color: theme.colorScheme.outlineVariant, width: 3),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Transcript',
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w700,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 2),
          if (!_bySpeaker.hasMatch(text))
            open
                ? SelectableText(text, style: theme.textTheme.bodySmall)
                : Text(
                    text,
                    maxLines: 4,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  )
          else
            open
                ? SelectableText.rich(
                    _speakers(text),
                    style: theme.textTheme.bodySmall,
                  )
                : Text.rich(
                    _speakers(text),
                    maxLines: 4,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
          if (text.length > 240)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                key: ValueKey('transcript-toggle-$id'),
                style: TextButton.styleFrom(
                  padding: EdgeInsets.zero,
                  minimumSize: const Size(0, 32),
                ),
                onPressed: () => setState(() {
                  open ? _openTranscripts.remove(id) : _openTranscripts.add(id);
                }),
                child: Text(open ? 'Show less' : 'Show all'),
              ),
            ),
        ],
      ),
    );
  }

  /// Taken off the Follow up list in this view.
  bool _followedUp = false;

  /// Asked the price or a day, nothing booked: what puts a lead on Follow up.
  static bool _asksForFollowUp(Map c) {
    final s = c['signals'];
    if (s is! Map) return false;
    return (s['asked_price'] == true || s['asked_availability'] == true) &&
        s['booked'] == false;
  }

  /// Grok's one line on the call first, the transcript one tap away (Jose,
  /// 2026-10-12: "summary should show first with the option to see the full
  /// transcript"; UI queue 77 placement 1).
  Widget _callSummary(ThemeData theme, String id, Map c) {
    final open = _openTranscripts.contains('full-$id');
    final next = c['next_step'] as String?;
    final transcript = c['transcript'] as String?;
    return Column(
      key: ValueKey('call-summary-$id'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 8),
        const AiMark.badge(
          'Summary by AI',
          tooltip:
              'Written by AI from the call recording. Check the transcript for the exact words.',
        ),
        const SizedBox(height: 4),
        Text(c['summary'] as String, style: theme.textTheme.bodyMedium),
        if (next != null && next.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              'Next: $next',
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        if (_asksForFollowUp(c) && !_followedUp)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: ValueKey('followed-up-$id'),
              style: TextButton.styleFrom(
                padding: EdgeInsets.zero,
                minimumSize: const Size(0, 32),
              ),
              icon: const Icon(Icons.check, size: 16),
              label: const Text('Followed up'),
              onPressed: () async {
                setState(() => _followedUp = true);
                await widget.api.post(
                  '/leads/${widget.lead['id']}/follow-up-handled',
                  const {},
                );
              },
            ),
          ),
        if (transcript != null && transcript.isNotEmpty) ...[
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: ValueKey('full-transcript-toggle-$id'),
              style: TextButton.styleFrom(
                padding: EdgeInsets.zero,
                minimumSize: const Size(0, 32),
              ),
              onPressed: () => setState(() {
                open
                    ? _openTranscripts.remove('full-$id')
                    : _openTranscripts.add('full-$id');
              }),
              child: Text(open ? 'Hide transcript' : 'Show full transcript'),
            ),
          ),
          if (open) _transcript(theme, id, transcript),
        ],
      ],
    );
  }

  /// "Tanya is viewing this lead" - somebody else may be answering it.
  Widget _viewingBanner({bool compact = false}) {
    final colours = ToneColours.of(context, Tone.caution);
    final names = _viewers.length == 1
        ? _viewers.first
        : '${_viewers.sublist(0, _viewers.length - 1).join(', ')} and ${_viewers.last}';

    return Container(
      key: const ValueKey('lead-viewers'),
      margin: const EdgeInsets.only(top: 6, bottom: 2),
      padding: EdgeInsets.symmetric(horizontal: 10, vertical: compact ? 3 : 5),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(Icons.visibility_outlined, size: 16, color: colours.ink),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              '$names ${_viewers.length == 1 ? 'is' : 'are'} viewing this lead',
              maxLines: compact ? 1 : null,
              overflow: compact ? TextOverflow.ellipsis : null,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: colours.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _openAvailability(String? zip) async {
    final pane = AvailabilityPane(
      api: widget.api,
      session: widget.session!,
      visible: true,
      initialZip: zip,
    );

    await showSidePanel<void>(
      context,
      builder: (_) =>
          SidePanel(title: 'Availability', scrollable: false, child: pane),
    );
  }

  /// A booking, and where the details are.
  ///
  /// It used to draw an empty message bubble: Google sends no text on a
  /// booking conversation, so the thread showed a blank box attributed to the
  /// customer, which reads as a message they sent and left empty.
  Widget _bookingRow(ThemeData theme, {required String when}) => Container(
    padding: const EdgeInsets.fromLTRB(11, 9, 11, 8),
    decoration: BoxDecoration(
      color: theme.colorScheme.surface,
      border: Border.all(color: theme.colorScheme.outlineVariant),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          'Booked through Google · $when',
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          'Google does not send the customer on a booking. Their name, number '
          'and the time they chose are in your Local Services inbox.',
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    ),
  );

  Widget _message(
    ThemeData theme,
    Map<String, dynamic> c, {
    required bool mine,
    required String who,
    required String when,
    bool outside = false,
    bool automatic = false,
  }) {
    final attachments = (c['attachments'] as List? ?? const []).cast<String>();

    // Approved colour layout 3 / 6 (c31b50ad): ours is `primary` with
    // `onPrimary` text, the loudest this palette allows without red; theirs
    // stays `surface` with an outline all round. No leading-edge accent bar
    // anywhere, on Jose's ruling of 2026-09-08. `primary` inverts between the
    // themes (dark teal / white in light, bright cyan / near-black in dark),
    // so both are pinned in lead_thread_colour_test.dart.
    //
    // A reply sent outside EzBizi is RED rather than ours (Jose,
    // 2026-09-30: "make it so the sent outside ezbizi messages are red"):
    // said from somewhere nobody here can see, so it stands out from the
    // replies EzBizi sent.
    //
    // One an automation sent is GREEN (Jose, 2026-09-30: "the automation
    // message should also have a different color, instead of blue like the
    // other, maybe green or yellow"): the ok tone's fill and ink, which
    // read in both themes and are neither ours, the outside red, nor theirs.
    final scheme = theme.colorScheme;
    final rule = ToneColours.of(context, Tone.ok);
    final fill = !mine
        ? scheme.surface
        : outside
        ? scheme.error
        : automatic
        ? rule.fill
        : scheme.primary;
    final ink = !mine
        ? scheme.onSurface
        : outside
        ? scheme.onError
        : automatic
        ? rule.ink
        : scheme.onPrimary;
    final quiet = mine ? ink.withValues(alpha: 0.8) : scheme.onSurfaceVariant;

    return Container(
      padding: const EdgeInsets.fromLTRB(11, 8, 11, 6),
      decoration: BoxDecoration(
        color: fill,
        border: mine ? null : Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Selectable, so part of a message can be copied too (the
          // dispatchers, via Jose, 2026-09-29: "copy and paste").
          if ((c['text'] as String?)?.isNotEmpty == true)
            SelectableText(
              c['text'] as String,
              style: theme.textTheme.bodyMedium?.copyWith(color: ink),
            ),
          if (attachments.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (var i = 0; i < attachments.length; i++)
                    _attachment(theme, attachments[i], i + 1),
                ],
              ),
            ),
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: Text(
                    '$who · $when',
                    style: theme.textTheme.labelSmall?.copyWith(color: quiet),
                  ),
                ),
                // The whole message in one press, as A1 Tools has it.
                if ((c['text'] as String?)?.isNotEmpty == true)
                  InkWell(
                    key: ValueKey('copy-${c['id']}'),
                    borderRadius: BorderRadius.circular(8),
                    onTap: () async {
                      await Clipboard.setData(
                        ClipboardData(text: c['text'] as String),
                      );
                      if (!mounted) return;
                      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
                        const SnackBar(content: Text('Message copied')),
                      );
                    },
                    child: Padding(
                      padding: const EdgeInsets.only(left: 6),
                      child: Icon(
                        Icons.copy_outlined,
                        size: 14,
                        semanticLabel: 'Copy message',
                        color: quiet,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// A photo the enquirer sent. Google hosts it; a thumbnail when it loads
  /// and a plain link when it does not, because whether that host answers
  /// without a token is not something to assume on their behalf.
  /// The photos opened in place, by address.
  final Set<String> _openImages = {};

  /// A photo the customer sent. It opens IN the thread, larger, and closes
  /// the same way (Toula, 2026-10-01: "images should open in it not out of
  /// the page in ezbizi app"); a file that is not a picture, or a picture the
  /// app cannot draw, still opens outside, which is the only way to see it.
  Widget _attachment(ThemeData theme, String url, int n) {
    final open = _openImages.contains(url);
    void outside() =>
        launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

    return Stack(
      children: [
        InkWell(
          key: ValueKey('attachment-$n'),
          onTap: () => setState(
            () => open ? _openImages.remove(url) : _openImages.add(url),
          ),
          borderRadius: BorderRadius.circular(6),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: ConstrainedBox(
              constraints: open
                  ? const BoxConstraints(maxWidth: 320, maxHeight: 420)
                  : const BoxConstraints.tightFor(width: 44, height: 44),
              child: Image.network(
                url,
                fit: open ? BoxFit.contain : BoxFit.cover,
                // Not drawable here (a PDF, a host that refuses): opened, it
                // offers the browser beside it, as every opened file does.
                errorBuilder: (_, _, _) => SizedBox(
                  width: open ? 88 : 44,
                  height: 44,
                  child: ColoredBox(
                    color: theme.colorScheme.surfaceContainerHighest,
                    child: Align(
                      alignment: open ? Alignment.centerLeft : Alignment.center,
                      child: Padding(
                        padding: EdgeInsets.only(left: open ? 10 : 0),
                        child: Text('$n', style: theme.textTheme.labelSmall),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        if (open)
          Positioned(
            top: 4,
            right: 4,
            child: Material(
              color: theme.colorScheme.surface.withValues(alpha: 0.85),
              shape: const CircleBorder(),
              child: IconButton(
                key: ValueKey('attachment-outside-$n'),
                tooltip: 'Open in the browser',
                visualDensity: VisualDensity.compact,
                iconSize: 16,
                icon: const Icon(Icons.open_in_new),
                onPressed: outside,
              ),
            ),
          ),
      ],
    );
  }

  /// A call: its length and who placed it, and the same play bar Voicemail
  /// and Records use, with pause, scrub and how far through you are. Jose,
  /// 2026-09-09: "like the one in communications for the voicemail, right
  /// now it's too simple". The bar fetches through the lead's own endpoint
  /// with the connection's credentials and keeps the bytes, so a second play
  /// does not download again.
  Widget _callRow(
    ThemeData theme,
    Map<String, dynamic> c, {
    required String who,
    required String when,
  }) {
    final id = c['id'] as String;
    final hasRecording = c['has_recording'] == true;
    final ms = c['duration_ms'];
    // Green when the customer called, blue when we did (UI queue 74).
    final tone = ToneColours.of(
      context,
      who == 'Customer' ? Tone.ok : Tone.info,
    );

    return Container(
      // As wide as the thread allows, so every call lines up; a call with no
      // words used to shrink to its play bar.
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 17,
            backgroundColor: tone.fill,
            child: Icon(
              who == 'Customer' ? Icons.call_received : Icons.call_made,
              size: 18,
              color: tone.ink,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                // The time on the right while it fits; under the title when
                // a phone's line is short.
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  runSpacing: 2,
                  children: [
                    Text(
                      '$who called · ${formatDuration(ms as num?)}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(
                      hasRecording ? when : '$when · no recording',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
                if (hasRecording)
                  RecordingPlaybackBar(
                    key: ValueKey('recording-$id'),
                    player: _player,
                    dense: true,
                    // The call's own length, so the bar reads 0:00 / 3:14
                    // before anything is fetched.
                    knownLength: ms is num
                        ? Duration(milliseconds: ms.round())
                        : null,
                    load: () => widget.api.getBytes(
                      '/leads/${widget.lead['id']}/conversations/$id/recording',
                    ),
                  ),
                // The words, right under the recording (Jose, 2026-09-29): both
                // voices in one text, machine-transcribed on our own server - so it
                // is labelled as a transcript, not quoted. Four lines, then Show all.
                if ((c['summary'] as String?)?.isNotEmpty ?? false)
                  _callSummary(theme, id, c)
                else if ((c['transcript'] as String?)?.isNotEmpty ?? false)
                  _transcript(theme, id, c['transcript'] as String)
                else if (c['transcript_pending'] == true)
                  Padding(
                    padding: const EdgeInsets.only(top: 6, bottom: 2),
                    child: Container(
                      key: ValueKey('transcript-pending-$id'),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 3,
                      ),
                      decoration: BoxDecoration(
                        color: theme.colorScheme.surfaceContainerHighest,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.hourglass_top,
                            size: 12,
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                          const SizedBox(width: 6),
                          Text(
                            'Transcribing…',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The number on its own line under the title, unless the title IS the
/// number. A title taken from the matched customer is a name too (Jose,
/// 2026-09-30: Mark Morrow showed no number).
bool showsNumberLine(Map<String, dynamic> lead) {
  final phone = lead['phone'] as String?;
  return phone != null &&
      phone.isNotEmpty &&
      leadTitle(lead) != formatPhoneNumber(phone);
}

/// The title of a lead: the name, or the number somebody would call back.
String leadTitle(Map<String, dynamic> lead) {
  final name = (lead['contact_name'] as String?)?.trim();
  if (name != null && name.isNotEmpty) return name;
  // A number that is already a customer is shown by the customer's name.
  final customer = ((lead['customer'] as Map?)?['name'] as String?)?.trim();
  if (customer != null && customer.isNotEmpty) return customer;
  final phone = lead['phone'] as String?;
  if (phone != null && phone.isNotEmpty) return formatPhoneNumber(phone);

  /*
   * A booking is not a nameless enquiry, it is an appointment.
   *
   * Google's API returns `contactDetails: []` for lead_type BOOKING —
   * measured against production on 2026-09-10, and there is nowhere else to
   * look: the API's own field catalogue lists sixteen selectable fields on
   * local_services_lead and not one of them describes a booking, while a
   * search for any field named *booking* returns hotel and video campaign
   * settings only. The customer's details are in Google's Local Services
   * inbox, not on the wire.
   *
   * So "Unnamed enquiry" was true and useless. Naming the thing it IS lets
   * somebody act on it; 451 of these were sitting in the list looking like
   * enquiries nobody could identify.
   */
  if (lead['lead_type'] == 'BOOKING') return 'Booking through Google';

  return 'Unnamed enquiry';
}

/// "Fireplace chimney · Google Ads · Connecticut".
///
/// The service segment is OMITTED when Google sent none, not replaced with
/// "No service given". 1,576 of 23,875 live leads carry none (measured
/// 2026-09-08). Nobody can supply what Google did not send, so naming the
/// absence spends a line on a fact no reader can act on. That is a deliberate
/// departure from Your account, where a blank is something the reader can
/// fill in and silence reads as a failed load.
///
/// Never the category: 23,686 of those leads are classified "home inspector"
/// by Google, for a chimney company. A wrong statement about the business,
/// shown to the business.
///
/// [company] false leaves the company name out of the place, for where it is
/// already on screen as its [CompanyTag].
String leadHeaderLine(Map<String, dynamic> lead, {bool company = true}) {
  final service = humaniseService(lead['service'] as String?);
  final source = lead['source'] as String?;

  /*
   * For a website enquiry the useful place is the HOST, not the company.
   *
   * Jose, 2026-09-23: *"make the fix so it shows the full website name
   * instead ... so i know from which of the main or subdomains its coming
   * from"*. A-1 Chimney has 47 websites and every chat from all of them read
   * "Website chat · A-1 Chimney", which is true and answers nothing — he had
   * switched the chat off on one of the 47 and could not tell from the leads
   * that the other 46 were still sending.
   *
   * `summary` is where Web Management already puts the host when it creates
   * the lead, so this needs no new field and no new query. Scoped to the two
   * website sources deliberately: `summary` means something else for Google
   * and Thumbtack, and a host is the only thing worth swapping the company
   * name for.
   */
  final host = (source == 'website_chat' || source == 'website_form')
      ? (lead['summary'] as String?)
      : null;

  final place =
      (host != null && host.isNotEmpty ? host : null) ??
      (lead['franchise'] as Map?)?['name'] as String? ??
      (company ? ((lead['company'] as Map?)?['name'] as String?) : null);

  return [
    ?service,
    sourceWord(source),
    if (place != null && place.isNotEmpty) place,
  ].join(' · ');
}

/// Where the enquiry came from, in words. `local_services` is Google Ads;
/// Web Management's website chat and forms are their own sources.
String sourceWord(String? source) => switch (source) {
  'website_chat' => 'Website chat',
  'facebook' => 'Facebook',
  'instagram' => 'Instagram',
  'website_form' => 'Website form',
  'website_booking' => 'Online booking',
  'thumbtack' => 'Thumbtack',
  'whatsapp' => 'WhatsApp',
  _ => 'Google Ads',
};

/// BOOK / SUPPORT / ARCHIVE, in words. The wire value never reaches the screen.
String classificationWord(String? classification) => switch (classification) {
  'BOOK' => 'work to book',
  'SUPPORT' => 'an existing customer',
  'ARCHIVE' => 'nothing to act on',
  _ => 'an enquiry',
};

/// fireplace_chimney → "Fireplace chimney". Null in, null out.
///
/// ponytail: mechanical sentence-case, and it has a ceiling. An acronym comes
/// out wrong — hvac_repair → "Hvac repair" — and reads as the business having
/// misspelled its own trade. Measured across 23,875 live leads on 2026-09-08
/// there is exactly one value on the whole tenant, fireplace_chimney, so there
/// is nothing to map yet. Add a map from a real tenant's real list when one
/// arrives, not from guessed examples.
String? humaniseService(String? id) {
  final s = id?.trim();
  if (s == null || s.isEmpty) return null;
  final words = s.replaceAll('_', ' ').toLowerCase();
  return words[0].toUpperCase() + words.substring(1);
}

/// Google's word for the channel, and ours. PHONE_CALL is a call; everything
/// else Google records on a lead (MESSAGE, SMS, WHATSAPP, EMAIL, BOOKING) is
/// something somebody typed. None of the wire values go on screen, and not
/// a1leads' "LS Ads" / "LS Text" either — that is Rodrigo's shorthand.
String channelWord(String? channel) => switch (channel) {
  'PHONE_CALL' => 'Call',
  'LIVECHAT' => 'Chat',
  'FORM' => 'Form',
  // Somebody pressed Book on the Google ad. It fell through to "Text" and
  // put a message badge on an appointment nobody had written a word on.
  'BOOKING' => 'Booking',
  _ => 'Text',
};

/// 125000 → "2:05".
String formatDuration(num? ms) {
  final seconds = ((ms ?? 0) / 1000).floor();
  final m = seconds ~/ 60;
  final s = (seconds % 60).toString().padLeft(2, '0');
  return '$m:$s';
}

/// Relative for anything recent, absolute once it stops being "today".
String leadWhen(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';

  final ago = DateTime.now().difference(at);

  if (ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inDays < 7) return '${ago.inDays}d ago';

  return '${at.year}-${at.month.toString().padLeft(2, '0')}-'
      '${at.day.toString().padLeft(2, '0')}';
}

/// A message's time of day, and how long ago while that is still today's
/// news - "1:23 PM · 57m ago"; past a day, the time alone, since the day
/// divider above already says which day (Jose, 2026-10-01: "see the time of
/// the messages as well like 11:48 13:23"). In the organization's clock -
/// 12- or 24-hour - like every other time in the app; 24-hour only when no
/// session is at hand to say which.
String messageWhen(
  String? iso, {
  String Function(int hour, int minute)? clock,
}) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';

  final time =
      clock?.call(at.hour, at.minute) ??
      '${at.hour.toString().padLeft(2, '0')}:'
          '${at.minute.toString().padLeft(2, '0')}';

  return DateTime.now().difference(at).inHours < 24
      ? '$time · ${leadWhen(iso)}'
      : time;
}

/// The thread as drawn: a day label (String) where the date changes, then the
/// conversations - with each reaction taken out of the list and hung on the
/// message it quotes, under `_reactions`. A reaction whose message cannot be
/// found stays in the list as a message of its own, as it came.
List<Object> threadItems(List<Map<String, dynamic>> conversations) {
  String norm(String? s) => (s ?? '').replaceAll(RegExp(r'\s+'), ' ').trim();
  final shown = <Map<String, dynamic>>[];
  final items = <Object>[];
  String? lastDay;

  for (final c in conversations) {
    final reaction = c['reaction'] as Map?;
    if (reaction != null) {
      final quote = norm(reaction['quote'] as String?);
      Map<String, dynamic>? target;
      for (final m in shown.reversed) {
        final text = norm(m['text'] as String?);
        if (quote.isNotEmpty &&
            text.isNotEmpty &&
            (text.startsWith(quote) || quote.startsWith(text))) {
          target = m;
          break;
        }
      }
      if (target != null) {
        target['_reactions'] = [
          ...(target['_reactions'] as List? ?? const []),
          '${reaction['emoji']}',
        ];
        continue;
      }
    }

    final day = threadDay(c['occurred_at'] as String?);
    if (day != null && day != lastDay) {
      items.add(day);
      lastDay = day;
    }
    // A copy: reactions are hung on it, and the list is rebuilt every frame.
    final copy = Map<String, dynamic>.of(c);
    items.add(copy);
    shown.add(copy);
  }

  return items;
}

/// "Today", "Yesterday", or "Sat, Sep 26" - with the year when it is not this one.
String? threadDay(String? iso, {DateTime? now}) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return null;
  final today = now ?? DateTime.now();
  final d = DateTime(at.year, at.month, at.day);
  final t = DateTime(today.year, today.month, today.day);
  final days = t.difference(d).inDays;
  if (days == 0) return 'Today';
  if (days == 1) return 'Yesterday';
  const week = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const month = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];
  final label = '${week[at.weekday - 1]}, ${month[at.month - 1]} ${at.day}';
  return at.year == today.year ? label : '$label, ${at.year}';
}

/// A lead's status as a pill, the same in the thread's header and on the
/// list's rows (design 28).
StatusPill leadStatusPill(Object? status, {Key? key}) => StatusPill(
  switch (status) {
    'booked' => 'Booked',
    'archived' => 'Archived',
    'not_a_lead' => 'Not a lead',
    'completed' => 'Completed',
    'lost' => 'Lost',
    'job_applicant' => 'Job applicant',
    _ => 'New',
  },
  key: key,
  tone: switch (status) {
    'booked' => Tone.ok,
    'archived' => Tone.neutral,
    'not_a_lead' => Tone.caution,
    'completed' => Tone.ok,
    'lost' => Tone.neutral,
    'job_applicant' => Tone.review,
    _ => Tone.info,
  },
);

/// The lead's company name, or null when it carries none.
String? leadCompany(Map<String, dynamic> lead) {
  final name = (lead['company'] as Map?)?['name'] as String?;
  return name == null || name.trim().isEmpty ? null : name;
}

/// The company a lead belongs to, as a tag on the list's rows and in the
/// thread's header (Jose, 2026-09-30: "add a tag with the name of the company
/// it belongs to, so this doesn't happen again" - chimney prices went to a
/// garage-door lead). Outlined, and the same for every company: the filled
/// colours on a row already say its source and its status.
class CompanyTag extends StatelessWidget {
  const CompanyTag(this.name, {super.key});

  final String name;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ink = theme.colorScheme.onSurface;

    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 160),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(
          border: Border.all(color: theme.colorScheme.outline),
          borderRadius: BorderRadius.circular(4),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.storefront_outlined, size: 13, color: ink),
            const SizedBox(width: 3),
            Flexible(
              child: Text(
                name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: ink,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Google's own lead status as a colour: booked and active are good news,
/// declined is not, the rest is neutral.
Tone googleStatusTone(String status) => switch (status.toLowerCase()) {
  'booked' || 'active' => Tone.ok,
  'new' => Tone.info,
  'declined' || 'consumer declined' => Tone.bad,
  _ => Tone.neutral,
};

/// "Candy is typing…", or "Customer is typing…" when the lead has no name - or
/// only a number for one, which used to read "+1 is typing…".
@visibleForTesting
String typingLine(String? contactName) {
  final first = (contactName ?? '').trim().split(RegExp(r'\s+')).first;
  return RegExp(r"^[A-Za-z][A-Za-z'’-]+$").hasMatch(first)
      ? '$first is typing…'
      : 'Customer is typing…';
}
