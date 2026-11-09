import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/money.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../screens/customers/customer_detail_panel.dart';
import '../screens/jobs/job_detail_panel.dart';
import '../screens/phone/call_tab.dart';
import '../screens/phone/recording_playback_bar.dart';
import '../screens/phone/recording_player.dart';
import 'lead_form_answers.dart';
import 'lead_media.dart';
import 'leads_hidden.dart';
import '../screens/scheduling/availability_pane.dart';
import 'ai_mark.dart';
import 'call_text.dart';
import 'async_body.dart';
import 'book_job_panel.dart';
import 'lead_edit_panel.dart';
import 'lead_source_details.dart';
import 'mention_field.dart';
import 'proofread_hint.dart';
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
    this.noteMentions,
    this.session,
    this.onBooked,
    this.onHidden,
    this.onBack,
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

  /// Who the note box @mentioned, read by [onNote]'s owner on save (monday
  /// port, 2026-11-09). Null: no @ picker.
  final MentionController? noteMentions;

  /// Needed to book (the franchise list, the display formats, the job
  /// panel). Book is drawn only when both this and [onBooked] are given.
  final Session? session;

  /// The server's booking answer (lead, customer, customer_created, job).
  /// Returns the lead as the list now holds it, or null to keep the
  /// answer's own copy.
  final Future<Map<String, dynamic>?> Function(Map<String, dynamic> result)?
  onBooked;

  /// After this lead was hidden (design 160): it leaves the list.
  final Future<void> Function()? onHidden;

  /// A phone's way back to the list, drawn at the start of the thread's one
  /// bar (design 171) rather than as a row of its own above it.
  final VoidCallback? onBack;

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

  /// Design 161: the form answers and the note, each opened in place from
  /// the header, and the header folded to its name row while the thread is
  /// scrolled back.
  bool _formOpen = false;
  bool _noteOpen = false;
  bool _collapsed = false;

  /// Account, Google status, and a name/address/ZIP read out of what the
  /// customer wrote (LeadDetails on the server) - A1 Tools' side facts.
  Map<String, dynamic>? _details;

  /// The lead's own load, for the source details (design 159).
  Map<String, dynamic>? _loaded;

  /// A website booking's day and window, and where it stands (Jose, 2026-10-08).
  Map<String, dynamic>? _booking;

  /// The lead's merged ad form (design 160, C1), from the read or the row.
  late Map<String, dynamic> _formLead = widget.lead;

  /// An earlier-opening offer this thread is about (Scheduling, 2026-10-28):
  /// the customer said yes, so the job moves in one click.
  Map<String, dynamic>? _offer;
  bool _moving = false;

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

  /// When Meta's reply window closes (Messenger, Instagram, WhatsApp), or
  /// null for a source without one (design 186, Jose 2026-11-09).
  DateTime? _windowEndsAt;

  /// WhatsApp's approved templates, offered once its window has closed.
  List<Map<String, dynamic>> _waTemplates = const [];

  /// Customers whose number matches this lead's, for the booking panel.
  List<Map<String, dynamic>> _matches = const [];

  final _compose = TextEditingController();
  bool _sending = false;
  String? _sendError;

  /// The emoji tray above the reply box (Jose, 2026-10-01: "a button for an
  /// emoji picker on the left of send"). Inline, not a popup.
  bool _emoji = false;

  /// Design 171: a phone's composer is + / the box / Send; + opens Suggest,
  /// emoji and the quick replies. The quick replies also show by themselves
  /// while the box is focused and empty.
  bool _tools = false;
  final _composeFocus = FocusNode(debugLabel: 'Reply');
  bool _composeEmpty = true;
  bool _composeFocused = false;

  void _composerState() {
    final empty = _compose.text.isEmpty;
    final focused = _composeFocus.hasFocus;
    if (!mounted || (empty == _composeEmpty && focused == _composeFocused)) {
      return;
    }
    setState(() {
      _composeEmpty = empty;
      _composeFocused = focused;
    });
  }

  /// A draft is offered only where a reply can go and the model is on.
  bool _canSuggest = false;

  /// "Did you mean" under the box (ProofreadHint); off where the business
  /// switched leads.proofread off.
  bool _canProofread = false;

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

  /// Somebody else writing to this customer right now, and whether this
  /// person chose to reply anyway (Jose, 2026-10-20: two people answered one
  /// customer four times in a minute).
  String? _replyingOther;
  bool _replyAnyway = false;

  /// When this thread was last read, on the server's clock; sent with a reply
  /// so a reply somebody else sent since then stops it ([_conflict]).
  String? _seenAt;
  String? _conflict;

  void _typing() {
    // Every lead, not only a live chat: the typing mark is also the "is
    // replying" hold the other viewers see.
    if (_compose.text.trim().isEmpty) return;
    final now = DateTime.now();
    if (now.difference(_typedAt) < const Duration(seconds: 3)) return;
    _typedAt = now;
    unawaited(widget.api.post('/leads/${widget.lead['id']}/typing', const {}));
  }

  @override
  void initState() {
    super.initState();
    _load();
    // Its note is read now (unread notes, monday port 2026-11-09).
    if ((widget.lead['unread_notes'] as num? ?? 0) > 0) {
      unawaited(
        widget.api.post('/note-reads', {
          'type': 'lead',
          'id': widget.lead['id'],
        }),
      );
    }
    _poll = Timer.periodic(
      _isLiveChat ? const Duration(seconds: 3) : _pollEvery,
      (_) => _load(quiet: true),
    );
    _compose.addListener(_typing);
    _compose.addListener(_composerState);
    _composeFocus.addListener(_composerState);
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

  Widget _withMentions(Widget box) => widget.noteMentions == null
      ? box
      : MentionField(
          api: widget.api,
          type: 'lead',
          id: '${widget.lead['id']}',
          controller: _note,
          mentions: widget.noteMentions!,
          child: box,
        );

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
        _noteOpen = true;
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
    _composeFocus.dispose();
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

  Future<void> _send({bool force = false}) async {
    final text = _compose.text.trim();
    if (text.isEmpty || _sending) return;

    setState(() {
      _sending = true;
      _sendError = null;
      _conflict = null;
    });

    final response = await widget.api.post(
      '/leads/${widget.lead['id']}/messages',
      {'text': text, 'seen_at': ?_seenAt, if (force) 'force': true},
    );
    if (!mounted) return;

    // Somebody else answered since this thread was read: say who and what,
    // and let this person decide (the server's check, so it holds for two
    // people who never saw each other).
    if (response.statusCode == 409) {
      setState(() {
        _sending = false;
        _conflict = response.message;
      });
      unawaited(_load(quiet: true));
      return;
    }

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
      _windowEndsAt = DateTime.tryParse(
        data['reply_window_ends_at'] as String? ?? '',
      )?.toLocal();
      _waTemplates = (data['whatsapp_templates'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _canSuggest = data['can_suggest'] == true;
      _canProofread = data['can_proofread'] == true;
      _templates = (data['reply_templates'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _customer = data['customer'] as Map<String, dynamic>?;
      _details = data['details'] as Map<String, dynamic>?;
      _loaded = data;
      _booking = data['booking'] as Map<String, dynamic>?;
      _formLead = {
        ...widget.lead,
        ...?(data['lead'] as Map<String, dynamic>?),
        for (final k in const ['form_answers', 'form_name', 'form_submitted_at'])
          if (data.containsKey(k)) k: data[k],
      };
      if (widget.lead['lead_type'] == 'Reschedule' && _offer == null) {
        _loadOffer();
      }
      _viewers = [for (final v in (data['viewers'] as List? ?? const [])) '$v'];
      _replyingOther = data['replying'] as String?;
      if (_replyingOther == null) _replyAnyway = false;
      _seenAt = data['seen_at'] as String? ?? _seenAt;
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
      'follow_up' => 'follow_up',
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
          SearchOption(value: 'follow_up', label: 'Follow Up'),
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
      final words = Text(text, key: ValueKey(key), maxLines: 1, softWrap: false);
      return fill
          ? FittedBox(fit: BoxFit.scaleDown, child: words)
          : words;
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
        ButtonSegment(
          value: 'new',
          label: label('Lead', 'status-lead'),
        ),
        // Somebody reached out and is waiting, or will try again (Jose,
        // 2026-10-14). Clears the red mark; the customer writing reopens it.
        ButtonSegment(
          value: 'follow_up',
          label: label('Follow Up', 'status-follow-up'),
        ),
        // Nobody owes the customer an answer - "Thank you" was their last
        // word. Clears the red warning (Jose, 2026-10-01); writing again
        // reopens the lead.
        ButtonSegment(
          value: 'completed',
          label: label('Completed', 'status-completed'),
        ),
        // A real customer who declined or wants what we do not do (Jose,
        // 2026-10-09).
        ButtonSegment(
          value: 'lost',
          label: label('Lost', 'status-lost'),
        ),
      ],
      selected: {current},
      onSelectionChanged: (picked) {
        if (picked.first != current) widget.onStatusChanged!(picked.first);
      },
    );

    return fill ? SizedBox(width: double.infinity, child: control) : control;
  }

  /// From this width of header the six moves are one segmented control on
  /// the name row; narrower, the same moves as a picker (design 161).
  static const double _segmentsFrom = 1100;

  /// Desktop: the moves, then Book, the one that matters most, last. On a
  /// phone Book is full width and the moves share a row under it.
  /// Two groups, apart (Jose, 2026-09-30): where the lead stands - Archive,
  /// Not a lead, Lead - and what to do about the customer - Call, Book.
  Widget _actions(Map<String, dynamic> lead, {bool picker = false}) => Wrap(
    spacing: 16,
    runSpacing: 8,
    alignment: WrapAlignment.end,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      if (widget.onStatusChanged != null)
        picker
            ? SizedBox(width: 150, child: _moves(lead, fill: true))
            : _moves(lead),
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
          // Name, ZIP, classification, dispatcher, and the caller's
          // history (UI queue 103). With the right to change the lead.
          if (widget.onStatusChanged != null)
            OutlinedButton.icon(
              key: const ValueKey('lead-edit'),
              onPressed: _edit,
              icon: const Icon(Icons.edit_outlined, size: 18),
              label: const Text('Edit'),
            ),
          if (_canBook)
            FilledButton(
              key: const ValueKey('book-open'),
              onPressed: _openBooking,
              child: const Text('Book'),
            ),
          if (widget.session?.me?.can(leadsHiddenPermission) == true &&
              widget.onStatusChanged != null)
            LeadHideMenu(
              api: widget.api,
              lead: lead,
              onHidden: widget.onHidden,
            ),
        ],
      ),
    ],
  );

  /// Design 161: the avatar, the name with its marks and chips (one source
  /// chip, the status, who else is viewing, the note), and on a desktop the
  /// moves and Edit / Book / ... at its end.
  Widget _nameRow(
    ThemeData theme,
    Map<String, dynamic> lead,
    LeadSourceFields src, {
    required bool narrow,
    required double width,
    bool withName = true,
  }) {
    final starred = lead['starred'] == true;
    final note = ((lead['note'] as String?) ?? '').trim();
    final canEdit = widget.onNote != null;

    final marks = Wrap(
      spacing: 6,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (!withName)
          _copyButton(
            leadTitle(lead),
            key: const ValueKey('copy-name'),
            what: 'Name',
          )
        else
          ConstrainedBox(
            constraints: BoxConstraints(maxWidth: width * (narrow ? 0.6 : 0.4)),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: Text(
                    leadTitle(lead),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                // Jose, 2026-10-19: "Leads - copy name".
                _copyButton(
                  leadTitle(lead),
                  key: const ValueKey('copy-name'),
                  what: 'Name',
                ),
              ],
            ),
          ),
        if (leadNameIsAi(lead))
          const AiMark(key: ValueKey('thread-ai-name'), tooltip: aiNameTooltip),
        if (src.sourceKey != null)
          LeadSourceChip(src.sourceKey, key: const ValueKey('thread-source-chip')),
        leadStatusPill(lead['status'], key: const ValueKey('thread-status')),
        if (leadAiHolds(lead, 'status'))
          AiMark(
            key: const ValueKey('thread-ai-status'),
            tooltip: [
              'Status set by AI from the conversation. Change it to override.',
              ?(lead['ai_reason'] as String?),
            ].join('\n'),
          ),
        if (widget.onStar != null)
          IconButton(
            key: const ValueKey('thread-star'),
            tooltip: starred ? 'Unstar' : 'Star',
            iconSize: 18,
            visualDensity: VisualDensity.compact,
            onPressed: _star,
            icon: Icon(
              starred ? Icons.star : Icons.star_border,
              color: starred
                  ? theme.colorScheme.primary
                  : theme.colorScheme.outline,
            ),
          ),
        if (_viewers.isNotEmpty) _viewingChip(),
        if (note.isNotEmpty)
          _headerChip(
            theme,
            key: const ValueKey('note-chip'),
            icon: Icons.sticky_note_2_outlined,
            label: _noteOpen ? 'Note ▴' : 'Note ▾',
            tone: ToneColours.of(context, Tone.caution),
            onTap: () => setState(() => _noteOpen = !_noteOpen),
          )
        else if (canEdit && !_editingNote)
          _headerChip(
            theme,
            key: const ValueKey('note-add'),
            icon: Icons.edit_outlined,
            label: 'Add a note',
            onTap: () {
              _note.clear();
              setState(() => _editingNote = true);
            },
          ),
      ],
    );

    if (!withName) return marks;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        LeadAvatar(
          key: const ValueKey('thread-avatar'),
          name: leadTitle(lead),
          url: src.avatarUrl,
          radius: 16,
        ),
        const SizedBox(width: 10),
        Expanded(flex: 2, child: marks),
        if (!narrow) ...[
          const SizedBox(width: 12),
          // Wraps under itself rather than push the row past its edge.
          Flexible(
            flex: 3,
            child: Align(
              alignment: AlignmentDirectional.centerEnd,
              child: _actions(lead, picker: width < _segmentsFrom),
            ),
          ),
        ],
      ],
    );
  }

  /// Design 171: a phone's one bar - back, the avatar and name, where the
  /// lead stands, Book, and ... for everything else in the header.
  Widget _phoneBar(
    ThemeData theme,
    Map<String, dynamic> lead,
    LeadSourceFields src,
  ) => Row(
    children: [
      if (widget.onBack != null)
        IconButton(
          key: const ValueKey('thread-back'),
          tooltip: 'All leads',
          visualDensity: VisualDensity.compact,
          onPressed: widget.onBack,
          icon: const Icon(Icons.arrow_back),
        ),
      LeadAvatar(
        key: const ValueKey('thread-avatar'),
        name: leadTitle(lead),
        url: src.avatarUrl,
        radius: 16,
      ),
      const SizedBox(width: 8),
      Expanded(
        child: Text(
          leadTitle(lead),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.titleMedium,
        ),
      ),
      if (widget.onStatusChanged != null) ...[
        const SizedBox(width: 6),
        SizedBox(width: 118, child: _moves(lead, fill: true)),
      ],
      // Book as a filled icon: the worded button left the name ~60 px
      // ("Adelen…", phone audit 2026-11-04). Design 171 keeps one bar.
      if (_canBook) ...[
        const SizedBox(width: 4),
        IconButton.filled(
          key: const ValueKey('book-open'),
          tooltip: 'Book',
          onPressed: _openBooking,
          icon: const Icon(Icons.event_available_outlined),
        ),
      ],
      IconButton(
        key: const ValueKey('thread-details-toggle'),
        tooltip: _showDetails ? 'Hide details' : 'Details',
        visualDensity: VisualDensity.compact,
        onPressed: () => setState(() => _showDetails = !_showDetails),
        icon: Icon(_showDetails ? Icons.expand_less : Icons.more_horiz),
      ),
    ],
  );

  /// A small rounded chip in the header: the note, the viewers.
  Widget _headerChip(
    ThemeData theme, {
    required Key key,
    required IconData icon,
    required String label,
    ToneColours? tone,
    VoidCallback? onTap,
    String? tooltip,
  }) {
    final fill = tone?.fill ?? theme.colorScheme.surfaceContainerHighest;
    final ink = tone?.ink ?? theme.colorScheme.onSurfaceVariant;
    Widget chip = Material(
      color: fill,
      borderRadius: BorderRadius.circular(999),
      child: InkWell(
        key: key,
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 14, color: ink),
              const SizedBox(width: 4),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 100),
                child: Text(
                  label,
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
      ),
    );
    if (tooltip != null) chip = Tooltip(message: tooltip, child: chip);
    return chip;
  }

  /// Design 161: one wrapping line of labelled facts, each field once and
  /// only when it has something; a phone shows Phone and Job, then More.
  Widget _facts(
    ThemeData theme,
    Map<String, dynamic> lead,
    LeadSourceFields src, {
    required String? company,
    required String line,
    required bool narrow,
  }) {
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final labelStyle = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
    );
    final all = !narrow || _showDetails;

    Widget fact(String label, Widget value, {Key? key}) => Row(
      key: key,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: labelStyle),
        const SizedBox(width: 4),
        Flexible(child: value),
      ],
    );
    Widget text(String v, {Key? key}) => Text(
      v,
      key: key,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodySmall,
    );

    final phone = lead['phone'] as String?;
    final d = _details;
    final named = (lead['contact_name'] as String?)?.trim().isNotEmpty == true;
    final zip = d?['zip'] as String?;
    final address = (d?['address'] as String?) ?? src.address;
    final checked = (d?['checked_address'] as Map?)?['exact'] == true
        ? '${(d!['checked_address'] as Map)['address']}'
        : null;
    final mayCheck =
        d != null &&
        widget.session?.me?.can('scheduling.availability.view') == true;
    final where = [
      ?address,
      if (zip != null && !(address ?? '').contains(zip)) 'ZIP $zip',
    ].join(' · ');

    final availability = TextButton(
      key: const ValueKey('check-availability'),
      style: TextButton.styleFrom(
        visualDensity: VisualDensity.compact,
        padding: const EdgeInsets.symmetric(horizontal: 4),
        minimumSize: Size.zero,
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      ),
      // No zip yet: the address they wrote, which the pane looks up.
      onPressed: () => _openAvailability(zip ?? address),
      child: Text(
        zip == null ? 'Check availability' : 'Check availability for $zip',
      ),
    );

    final children = <Widget>[
      if (showsNumberLine(lead))
        fact(
          'Phone',
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(child: text(formatPhoneNumber(phone))),
              _copyButton(
                phone!,
                key: const ValueKey('copy-phone'),
                what: 'Number',
              ),
            ],
          ),
        ),
      if (all && src.email != null)
        fact('Email', SelectableText(src.email!, style: theme.textTheme.bodySmall)),
      if (all && !named && d?['name'] is String)
        fact('Name', text('${d!['name']}')),
      if (all && checked != null)
        fact(
          'Address',
          Row(
            key: const ValueKey('lead-address-checked'),
            mainAxisSize: MainAxisSize.min,
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
                  child: text(checked),
                ),
              ),
              if (mayCheck) ...[const SizedBox(width: 4), availability],
            ],
          ),
        )
      else if (all && where.isNotEmpty)
        fact(
          'Address',
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              text(where, key: const ValueKey('lead-address')),
              // Only when the ZIP shown is the one AI wrote.
              if (zip != null &&
                  leadAiHolds(lead, 'postal_code') &&
                  lead['postal_code'] == zip) ...[
                const SizedBox(width: 4),
                const AiMark(
                  key: ValueKey('lead-ai-zip'),
                  tooltip:
                      'ZIP filled in by AI from what the customer said. '
                      'Edit it to change it.',
                ),
              ],
              if (mayCheck) ...[
                Text(' · ', style: quiet),
                availability,
              ],
            ],
          ),
        )
      else if (all && mayCheck)
        availability,
      if (src.jobType != null)
        fact('Job', text(src.jobType!, key: const ValueKey('details-job'))),
      if (all && src.adName != null)
        fact(
          'Via ad',
          Tooltip(
            message: [
              ?src.headline,
              if (src.campaignName != null) 'Campaign ${src.campaignName}',
              if (src.pageName != null) 'Page ${src.pageName}',
            ].join('\n'),
            child: text(src.adName!, key: const ValueKey('details-ad')),
          ),
        ),
      if (all && src.handle != null)
        fact('Handle', text(src.handle!, key: const ValueKey('details-handle'))),
      // Whose lead it is: chimney prices went to a garage-door lead (Jose,
      // 2026-09-30).
      if (all && company != null)
        CompanyTag(company, key: const ValueKey('thread-company')),
      if (all && d != null) ..._factPills(lead, d),
      if (all && line.isNotEmpty)
        Text(line, style: quiet, maxLines: 1, overflow: TextOverflow.ellipsis),
      if (all && hasFormAnswers(_formLead))
        _foldLink(
          theme,
          key: const ValueKey('form-answers-toggle'),
          label: 'Form answers',
          open: _formOpen,
          onTap: () => setState(() => _formOpen = !_formOpen),
        ),
    ];

    return Wrap(
      key: const ValueKey('thread-facts'),
      spacing: 14,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: children,
    );
  }

  Widget _foldLink(
    ThemeData theme, {
    required Key key,
    required String label,
    required bool open,
    required VoidCallback onTap,
  }) => InkWell(
    key: key,
    onTap: onTap,
    child: Text(
      '$label ${open ? '▴' : '▾'}',
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.primary,
        fontWeight: FontWeight.w700,
      ),
    ),
  );

  Future<void> _edit() async {
    final saved = await showLeadEditPanel(
      context,
      api: widget.api,
      lead: _lead,
      player: widget.player,
    );
    if (saved != null && mounted) setState(() => _lead = {..._lead, ...saved});
  }

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
    await showBookJobPanel(
      context,
      api: widget.api,
      session: widget.session!,
      prefill: BookPrefill.fromLead(
        _lead,
        matches: _matches,
        details: _details,
        bookFranchiseId: _loaded?['book_franchise_id'] as String?,
        said: [
          for (final c in _conversations)
            if (c['from'] != 'ADVERTISER' && c['text'] is String)
              c['text'] as String,
        ],
      ),
      // POST jobs/book answers with the job, not the lead: the lead shows
      // its job here, and the next load brings the server's own copy.
      onBooked: (result) async {
        final job = result['job'] as Map<String, dynamic>?;
        final lead = {..._lead, 'job': job};
        final fresh = await widget.onBooked!({'lead': lead, 'job': job});
        if (!mounted) return;
        setState(() => _lead = fresh ?? lead);
      },
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
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final company = leadCompany(lead);
    final src = LeadSourceFields.from(lead, _loaded);
    // The company is on screen as its tag, so the line does not say it again.
    // Without the source's word: its chip says it (design 161, one chip).
    final line = leadHeaderLine(
      lead,
      company: company == null,
      withSource: src.sourceKey == null,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          key: const ValueKey('thread-header'),
          padding: narrow
              ? const EdgeInsets.fromLTRB(8, 4, 4, 4)
              : const EdgeInsets.fromLTRB(16, 10, 16, 8),
          child: LayoutBuilder(
            builder: (context, box) {
              // Design 161: scrolled back, only the name row stays. A phone
              // (design 171) keeps one bar, its details behind the bar's ...
              // and never open while the keyboard is up.
              final folded = narrow
                  ? !_showDetails || MediaQuery.viewInsetsOf(context).bottom > 0
                  : _collapsed && !_editingNote;
              final working = [
                if (_noteOpen || _editingNote) _noteBlock(theme, lead),
                if (_booking != null) _bookingBlock(theme),
                if (_offer != null) _offerBlock(theme),
              ];
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (narrow)
                    _phoneBar(theme, lead, src)
                  else
                    _nameRow(theme, lead, src, narrow: narrow, width: box.maxWidth),
                  if (!folded) ...[
                    if (narrow)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: _nameRow(
                          theme,
                          lead,
                          src,
                          narrow: true,
                          width: box.maxWidth,
                          withName: false,
                        ),
                      ),
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: _facts(
                        theme,
                        lead,
                        src,
                        company: company,
                        line: line,
                        narrow: narrow,
                      ),
                    ),
                    if (_formOpen && hasFormAnswers(_formLead))
                      FormAnswersSection(
                        lead: _formLead,
                        skipping: [
                          leadTitle(lead),
                          ?src.phone,
                          ?(lead['phone'] as String?),
                          ?src.email,
                        ],
                      ),
                    // What it looks like, read by a model, beside what
                    // somebody decided. Only when a reading exists.
                    if (lead['classification'] is String)
                      Text(
                        [
                          'Looks like ${classificationWord(lead['classification'] as String)}',
                          if ((lead['summary'] as String?)?.isNotEmpty == true)
                            lead['summary'] as String,
                        ].join(' · '),
                        key: const ValueKey('classification-line'),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                          fontStyle: FontStyle.italic,
                        ),
                      ),
                    if (lead['credit'] is Map)
                      _creditLine(theme, lead['credit'] as Map),
                    if (!narrow) ...working,
                    if (_customer != null) _customerCard(theme, _customer!),
                    if (lead['job'] is Map)
                      _bookedLine(
                        theme,
                        lead['job'] as Map,
                        matched: lead['job_matched_by'] == 'phone',
                      ),
                  ],
                  // What somebody is in the middle of stays on a phone
                  // whether the details are open or not.
                  if (narrow) ...working,
                ],
              );
            },
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Design 161: the thread gets the space - scrolled back from the
          // newest message, the header folds to its name row.
          child: NotificationListener<ScrollUpdateNotification>(
            onNotification: (n) {
              if (n.depth != 0 || n.metrics.axis != Axis.vertical) return false;
              final px = n.metrics.pixels;
              if (!_collapsed && px > 40) {
                setState(() => _collapsed = true);
              } else if (_collapsed && px < 8) {
                setState(() => _collapsed = false);
              }
              return false;
            },
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
                : _windowEndsAt != null || _waTemplates.isNotEmpty
                ? _windowClosed(theme)
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

  bool get _narrow => MediaQuery.sizeOf(context).width < 600;

  /// "3h", "45m" or "5d" until [at], as the thread words times.
  static String _until(DateTime at) {
    final left = at.difference(DateTime.now());
    if (left.inMinutes < 60) return '${left.inMinutes < 1 ? 1 : left.inMinutes}m';
    if (left.inHours < 24) return '${left.inHours}h';
    return '${left.inDays}d';
  }

  /// "Reply window closes in 3h" above the box; amber in its last two hours.
  Widget? _windowLine(ThemeData theme) {
    final ends = _windowEndsAt;
    if (ends == null || !ends.isAfter(DateTime.now())) return null;
    final soon = ends.difference(DateTime.now()) < const Duration(hours: 2);
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(
        'Reply window closes in ${_until(ends)}',
        key: const ValueKey('reply-window'),
        style: theme.textTheme.bodySmall?.copyWith(
          color: soon
              ? StateColour.of(context).caution
              : theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  /// The window has closed: why, in the channel's words, a box that says so,
  /// and on WhatsApp the one thing that can still go - an approved template.
  Widget _windowClosed(ThemeData theme) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                _cannotReplyBecause ??
                    'This enquiry cannot be replied to from here.',
                key: const ValueKey('reply-window-closed'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
            if (_waTemplates.isNotEmpty) ...[
              const SizedBox(width: 8),
              FilledButton(
                key: const ValueKey('send-template'),
                onPressed: _sendTemplate,
                child: const Text('Send a template'),
              ),
            ],
          ],
        ),
        if (_windowEndsAt != null) ...[
          const SizedBox(height: 8),
          const TextField(
            key: ValueKey('reply-box-closed'),
            enabled: false,
            decoration: InputDecoration(
              hintText: 'Reply window closed',
              isDense: true,
            ),
          ),
        ],
      ],
    ),
  );

  Future<void> _sendTemplate() async {
    final sent = await showSidePanel<bool>(
      context,
      builder: (_) => _WhatsAppTemplatePanel(
        api: widget.api,
        leadId: '${widget.lead['id']}',
        templates: _waTemplates,
      ),
    );
    if (sent == true && mounted) await _load(quiet: true);
  }

  Widget _composer(ThemeData theme) => Padding(
    padding: _narrow
        ? const EdgeInsets.fromLTRB(4, 6, 8, 6)
        : const EdgeInsets.fromLTRB(12, 8, 12, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ?_windowLine(theme),
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
        if (_conflict != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              key: const ValueKey('reply-conflict'),
              children: [
                Expanded(
                  child: Text(
                    _conflict!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                ),
                TextButton(
                  key: const ValueKey('reply-send-anyway'),
                  onPressed: _sending ? null : () => _send(force: true),
                  child: const Text('Send anyway'),
                ),
              ],
            ),
          ),
        if (_replyingOther != null && !_replyAnyway)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              key: const ValueKey('reply-held'),
              children: [
                Expanded(
                  child: Text(
                    '${_replyingOther!.isEmpty ? 'Someone' : _replyingOther} is replying…',
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontStyle: FontStyle.italic,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                TextButton(
                  key: const ValueKey('reply-anyway'),
                  onPressed: () => setState(() => _replyAnyway = true),
                  child: const Text('Reply anyway'),
                ),
              ],
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
        if (_templates.isNotEmpty &&
            (!_narrow || _tools || (_composeFocused && _composeEmpty)))
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Builder(
              builder: (context) {
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
                // Design 161: at every width now.
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
                tooltip: 'Written by AI. Read it before sending: nothing goes '
                    'until you press Send.',
              ),
            ),
          ),
        if (_narrow && _tools) _phoneTools(theme),
        if (_narrow) _phoneComposerRow(theme) else Row(
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
                spellCheckConfiguration: WidgetsBinding
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
              // Held while somebody else is replying, until "Reply anyway".
              onPressed: _sending || (_replyingOther != null && !_replyAnyway)
                  ? null
                  : _send,
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
        ProofreadHint(
          api: widget.api,
          controller: _compose,
          path: '/leads/${widget.lead['id']}/proofread',
          enabled: _canProofread,
        ),
      ],
    ),
  );

  /// The reply box itself: the phone's and the desktop's are one field.
  Widget _replyBox({required bool narrow}) => TextField(
    key: const ValueKey('reply-box'),
    controller: _compose,
    focusNode: _composeFocus,
    // Redrawn only while a draft is marked: the mark goes the moment the
    // words change.
    onChanged: _aiDraft == null ? null : (_) => setState(() {}),
    // The phone's own spell check and suggestions (Jose, 2026-09-30: "hellu"
    // was not corrected). Windows and the web build have no native checker
    // Flutter can call.
    spellCheckConfiguration:
        WidgetsBinding
            .instance
            .platformDispatcher
            .nativeSpellCheckServiceDefined
        ? const SpellCheckConfiguration()
        : null,
    minLines: 1,
    maxLines: narrow ? 5 : 4,
    textInputAction: TextInputAction.newline,
    enabled: !_sending,
    decoration: InputDecoration(
      isDense: true,
      hintText: narrow ? 'Message' : 'Reply to the customer',
      border: narrow
          ? OutlineInputBorder(borderRadius: BorderRadius.circular(24))
          : const OutlineInputBorder(),
      // 12 + a 24 line + 12 is the 48 the buttons beside it are.
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
    ),
  );

  bool get _sendHeld => _sending || (_replyingOther != null && !_replyAnyway);

  /// Design 171: + / the box, the full width / a round Send.
  Widget _phoneComposerRow(ThemeData theme) => Row(
    crossAxisAlignment: CrossAxisAlignment.end,
    children: [
      SizedBox.square(
        dimension: _controlHeight,
        child: IconButton(
          key: const ValueKey('reply-tools'),
          tooltip: _tools ? 'Close' : 'Suggest, emoji and quick replies',
          onPressed: () => setState(() {
            _tools = !_tools;
            if (!_tools) _emoji = false;
          }),
          icon: Icon(_tools ? Icons.close : Icons.add),
        ),
      ),
      Expanded(child: _replyBox(narrow: true)),
      const SizedBox(width: 6),
      SizedBox.square(
        dimension: _controlHeight,
        child: IconButton.filled(
          key: const ValueKey('reply-send'),
          tooltip: _sending ? 'Sending…' : 'Send',
          onPressed: _sendHeld ? null : _send,
          icon: _sending
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.send, size: 20),
        ),
      ),
    ],
  );

  /// What + opens on a phone, above the quick replies: Suggest and emoji.
  Widget _phoneTools(ThemeData theme) => Padding(
    key: const ValueKey('reply-tools-row'),
    padding: const EdgeInsets.only(left: 8, bottom: 6),
    child: Wrap(
      spacing: 8,
      runSpacing: 6,
      children: [
        if (_canSuggest)
          OutlinedButton.icon(
            key: const ValueKey('reply-suggest'),
            onPressed: _sending || _suggesting ? null : _suggest,
            icon: const Icon(Icons.auto_awesome, size: 18),
            label: Text(_suggesting ? 'Drafting…' : 'Suggest'),
          ),
        OutlinedButton.icon(
          key: const ValueKey('reply-emoji'),
          style: OutlinedButton.styleFrom(
            backgroundColor: _emoji
                ? theme.colorScheme.secondaryContainer
                : null,
          ),
          onPressed: _sending ? null : () => setState(() => _emoji = !_emoji),
          icon: const Icon(Icons.emoji_emotions_outlined, size: 18),
          label: Text(_emoji ? 'Hide emoji' : 'Emoji'),
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
                    total == 0 ? 'No jobs yet' : total == 1 ? '1 job' : '$total jobs',
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
                icon: Icon(_customerOpen ? Icons.expand_less : Icons.expand_more),
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
                    formatMinor((job['amount_minor'] as num).toInt(), job['currency'] as String),
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
            _withMentions(
              TextField(
                key: const ValueKey('note-box'),
                controller: _note,
                minLines: 1,
                maxLines: 4,
                autofocus: true,
                enabled: !_savingNote,
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: 'A note for whoever picks this up next (@ to mention)',
                  border: OutlineInputBorder(),
                ),
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
      // Its "Add a note" chip is on the name row (design 161).
      return const SizedBox.shrink();
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
    final items = withFormLine(threadItems(_conversations), _formLead);

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
      if (item is FormLine) return FormSystemLine(lead: _formLead);
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
          outside:
              mine &&
              c['automatic'] != true &&
              c['sent_by'] == null &&
              !sentFromPhone(c),
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
                if (sender != null) Positioned(right: 0, bottom: -6, child: sender),
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
    const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    final date = DateTime.tryParse('${b['date']}');
    String hm(Object? t) {
      final parts = '$t'.split(':');
      final h = int.tryParse(parts.first) ?? 0;
      return '${h % 12 == 0 ? 12 : h % 12}:${parts.length > 1 ? parts[1] : '00'} ${h < 12 ? 'AM' : 'PM'}';
    }

    final when = [
      if (date != null) '${days[date.weekday - 1]}, ${shortMonths[date.month - 1]} ${date.day}',
      '${hm(b['start_time'])} - ${hm(b['end_time'])}',
    ].join(' · ');
    final by = b['decided_by'] is String ? ' by ${b['decided_by']}' : '';
    // Booked as a job already (Kayla Culbreth, 2026-10-27): no Approve, which
    // would text the customer the window they asked for, not the one booked.
    final job = b['job'] is Map ? b['job'] as Map : null;
    // How it sits beside booked work, for the dispatcher only (Jose,
    // 2026-10-13: "the client does not need to see the recommendation").
    final fit = b['fit'] is Map ? b['fit'] as Map : null;
    String? fitLine;
    if (fit != null) {
      final near = (fit['nearby'] as num?)?.toInt() ?? 0;
      final miles = fit['nearest_miles'] is num ? ', nearest ${fit['nearest_miles']} mi' : '';
      final jobs = '$near job${near == 1 ? '' : 's'} nearby$miles';
      if (fit['verdict'] == 'good') {
        fitLine = 'Good fit: beside $jobs';
      } else if (fit['verdict'] == 'alone') {
        fitLine = 'No booked work nearby that day';
      } else {
        final d = DateTime.tryParse('${fit['date']}');
        final at = [
          if (d != null) '${days[d.weekday - 1]}, ${shortMonths[d.month - 1]} ${d.day}',
          '${hm(fit['start_time'])} - ${hm(fit['end_time'])}',
        ].join(' · ');
        fitLine = 'Better: $at ($jobs)';
      }
    }

    return Padding(
      key: const ValueKey('lead-booking'),
      padding: const EdgeInsets.only(top: 6, bottom: 2),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Icon(Icons.event_available_outlined, size: 18, color: theme.colorScheme.primary),
          Text('Requested: $when', style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
          if (job != null)
            Text(
              ['Booked in ${job['source']}', if (job['when'] is String) job['when']].join(' · '),
              key: const ValueKey('booking-job'),
              style: theme.textTheme.bodySmall,
            )
          else if (status == 'pending') ...[
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
          if (fitLine != null)
            Text(
              fitLine,
              key: const ValueKey('booking-fit'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, fit!['verdict'] == 'better' ? Tone.caution : Tone.ok).ink,
              ),
            ),
        ],
      ),
    );
  }

  /// A Google Local Services lead AI thinks Google should refund (Jose,
  /// 2026-10-13): AI's reason, and a button a person presses to ask Google.
  Widget _creditLine(ThemeData theme, Map credit) {
    final reason = creditReasonWord(credit['reason'] as String?);
    final requested = credit['requested_at'] != null;
    final colours = ToneColours.of(context, requested ? Tone.neutral : Tone.caution);
    return Padding(
      key: const ValueKey('lead-credit'),
      padding: const EdgeInsets.only(top: 6),
      child: Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          _pill(
            requested
                ? 'Credit requested · ${creditDecisionWord(credit['decision'] as String?)}'
                : 'Google credit? $reason',
            colours,
            icon: Icons.money_off_outlined,
          ),
          if (!requested) ...[
            const AiMark(tooltip: 'AI read the conversation and thinks Google '
                'should refund this lead. Nothing is sent until you press '
                'Request credit.'),
            TextButton(
              key: const ValueKey('lead-credit-request'),
              onPressed: _requestingCredit ? null : () => _requestCredit(credit['reason'] as String),
              child: const Text('Request credit'),
            ),
          ],
        ],
      ),
    );
  }

  bool _requestingCredit = false;

  Future<void> _requestCredit(String reason) async {
    setState(() => _requestingCredit = true);
    final response = await widget.api.post(
      '/leads/${widget.lead['id']}/google-credit',
      {'reason': reason},
    );
    if (!mounted) return;
    setState(() {
      _requestingCredit = false;
      if (response.ok && response.data is Map) {
        _lead = Map<String, dynamic>.from(response.data as Map);
      }
    });
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(content: Text(response.message)),
      );
    }
  }

  Future<void> _loadOffer() async {
    final response = await widget.api.get('/scheduling/reschedule-offers', {
      'lead_id': '${widget.lead['id']}',
    });
    if (!mounted || !response.ok || response.data is! Map) return;
    setState(() => _offer = Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _moveOffer() async {
    setState(() => _moving = true);
    final response = await widget.api.post(
      '/scheduling/reschedule-offers/${_offer!['id']}/move',
      const {},
    );
    if (!mounted) return;
    setState(() => _moving = false);
    if (response.ok) {
      _offer = null;
      await _loadOffer();
    } else {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(content: Text(response.message)),
      );
    }
  }

  Widget _offerBlock(ThemeData theme) {
    final o = _offer!;
    String hm(Object? t) {
      final parts = '$t'.split(':');
      final h = int.tryParse(parts.first) ?? 0;
      return '${h % 12 == 0 ? 12 : h % 12}:${parts.length > 1 ? parts[1] : '00'} ${h < 12 ? 'AM' : 'PM'}';
    }

    final window = o['start_time'] == null ? '' : ' · ${hm(o['start_time'])} - ${hm(o['end_time'])}';
    final moved = o['moved_at'] != null;
    return Padding(
      key: const ValueKey('lead-reschedule-offer'),
      padding: const EdgeInsets.only(top: 6, bottom: 2),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Icon(Icons.event_repeat_outlined, size: 18, color: theme.colorScheme.primary),
          Text(
            '${moved ? 'Moved to' : 'Offered'}: ${o['open_date']}$window',
            style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
          ),
          if (!moved) Text('Booked for ${o['booked_for']}', style: theme.textTheme.bodySmall),
          if (o['can_move'] == true)
            FilledButton(
              key: const ValueKey('reschedule-move'),
              onPressed: _moving ? null : _moveOffer,
              child: const Text('Move appointment'),
            ),
        ],
      ),
    );
  }

  Future<void> _decideBooking(String decision) async {
    // Two literal paths, so ClientCallsRealRoutesTest can match each route.
    final response = decision == 'approve'
        ? await widget.api.post('/leads/${widget.lead['id']}/booking/approve', const {})
        : await widget.api.post('/leads/${widget.lead['id']}/booking/decline', const {});
    if (!mounted) return;
    if (response.ok && response.data is Map) {
      setState(() => _booking = Map<String, dynamic>.from(response.data as Map));
    } else {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(content: Text(response.message)),
      );
    }
  }

  /// The account it came through, Google's own status, and what the customer
  /// told us about themselves - with a way to check availability for the ZIP
  /// without leaving Leads (Jose, 2026-09-28).
  /// The account and the source's own status, in colour, as A1 Tools draws
  /// them (Jose, 2026-09-29): the account in orange, Google's status in its
  /// meaning. On the header's source line since UI queue 108.
  List<Widget> _factPills(Map<String, dynamic> lead, Map<String, dynamic> d) {
    // The source's own status, named after the source: a Workiz lead's
    // status read "Google: ..." (UI/UX tour, 2026-10-14).
    final source = lead['source'] as String?;
    final statusFrom = source == null || source == 'local_services'
        ? 'Google'
        : sourceWord(source);
    return [
      if (d['account'] is String)
        _pill(
          '${d['account']}',
          PersonColours.hue(context, 5),
          icon: Icons.campaign_outlined,
          key: const ValueKey('lead-account'),
        ),
      if (d['google_status'] is String)
        _pill(
          '$statusFrom: ${d['google_status']}',
          ToneColours.of(
            context,
            googleStatusTone(d['google_status'] as String),
          ),
          key: const ValueKey('lead-google-status'),
        ),
    ];
  }

  Widget _pill(String text, ToneColours colours, {IconData? icon, Key? key}) => Container(
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
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: colours.ink),
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

  /// The folded preview's speakers, coloured as CallTranscriptText colours
  /// them: Dispatcher primary, Customer ok ink, IVR menu muted italic.
  static TextSpan _speakers(BuildContext context, String text) {
    final label = RegExp(r'^(IVR menu|Dispatcher|Customer): ');
    final theme = Theme.of(context);
    final lines = text.split('\n');
    return TextSpan(
      children: [
        for (var i = 0; i < lines.length; i++) ...[
          if (label.firstMatch(lines[i]) case final m?) ...[
            TextSpan(
              text: m.group(0),
              style: switch (m.group(1)) {
                'Dispatcher' => TextStyle(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w700,
                ),
                'Customer' => TextStyle(
                  color: ToneColours.of(context, Tone.ok).ink,
                  fontWeight: FontWeight.w700,
                ),
                _ => TextStyle(
                  color: theme.colorScheme.onSurfaceVariant,
                  fontStyle: FontStyle.italic,
                ),
              },
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
          // Open, the app's one transcript: coloured speakers, selectable,
          // one copy icon (Reports' CallTranscriptText, 2026-11-02). Folded,
          // four lines with the same colours. A short one is never folded.
          if (open || text.length <= 240)
            CallTranscriptText(text, style: theme.textTheme.bodySmall)
          else
            Text.rich(
              _bySpeaker.hasMatch(text)
                  ? _speakers(context, text)
                  : TextSpan(text: text),
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

  /// "Tanya viewing", a chip beside the name (design 161): somebody else
  /// may be answering it. The whole sentence is its tooltip.
  Widget _viewingChip() {
    final names = _viewers.length == 1
        ? _viewers.first
        : '${_viewers.sublist(0, _viewers.length - 1).join(', ')} and ${_viewers.last}';

    return _headerChip(
      Theme.of(context),
      key: const ValueKey('lead-viewers'),
      icon: Icons.visibility_outlined,
      label:
          '${_viewers.first}${_viewers.length > 1 ? ' +${_viewers.length - 1}' : ''} viewing',
      tone: ToneColours.of(context, Tone.caution),
      tooltip:
          '$names ${_viewers.length == 1 ? 'is' : 'are'} viewing this lead',
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
      builder: (_) => SidePanel(
        title: 'Availability',
        scrollable: false,
        child: pane,
      ),
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
    final all = (c['attachments'] as List? ?? const []).cast<String>();
    final media = [for (final a in all) if (mediaKind(a) != null) a];
    final attachments = [for (final a in all) if (mediaKind(a) == null) a];
    final phone = mine && sentFromPhone(c);

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

    // Approved design 158, option 1 + 4: a question the customer TAPPED on a
    // Meta Click-to-Message ad stays in their position, but with no fill, a
    // dashed tertiary outline and a tag, so it reads as what they asked
    // without passing for something they typed. A missing flag is false.
    final adQuestion = !mine && c['ad_question'] == true;
    const padding = EdgeInsets.fromLTRB(11, 8, 11, 6);

    final content = Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (adQuestion)
            Padding(
              padding: const EdgeInsets.only(bottom: 2),
              child: Text(
                'AD QUESTION (META)',
                key: ValueKey('ad-question-${c['id']}'),
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.bold,
                  color: scheme.tertiary,
                ),
              ),
            ),
          // Selectable, so part of a message can be copied too (the
          // dispatchers, via Jose, 2026-09-29: "copy and paste").
          if ((c['text'] as String?)?.isNotEmpty == true)
            SelectableText(
              c['text'] as String,
              style: theme.textTheme.bodyMedium?.copyWith(color: ink),
            ),
          for (var i = 0; i < media.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: LeadMedia(
                key: ValueKey('media-${c['id']}-$i'),
                url: media[i],
                kind: mediaKind(media[i])!,
                api: widget.api,
                player: _player,
                ink: ink,
                onExpired: () => _load(quiet: true),
              ),
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
                if (phone)
                  Padding(
                    padding: const EdgeInsets.only(right: 3),
                    child: Icon(
                      Icons.smartphone,
                      key: ValueKey('sent-from-phone-${c['id']}'),
                      size: 12,
                      color: quiet,
                    ),
                  ),
                Flexible(
                  child: Text(
                    phone
                        ? 'Sent from phone · $when'
                        : adQuestion
                        ? '$who · $when · tapped on your ad'
                        : '$who · $when',
                    style: theme.textTheme.labelSmall?.copyWith(color: quiet),
                  ),
                ),
                // The whole message in one press, as A1 Tools has it.
                if ((c['text'] as String?)?.isNotEmpty == true)
                  InkWell(
                    key: ValueKey('copy-${c['id']}'),
                    borderRadius: BorderRadius.circular(8),
                    onTap: () async {
                      await Clipboard.setData(ClipboardData(text: c['text'] as String));
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
      );

    if (adQuestion) {
      return CustomPaint(
        key: ValueKey('ad-question-bubble-${c['id']}'),
        painter: _DashedOutline(color: scheme.tertiary, radius: 12),
        child: Padding(padding: padding, child: content),
      );
    }
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: fill,
        border: mine ? null : Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: content,
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
    void outside() => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

    return Stack(
      children: [
        InkWell(
          key: ValueKey('attachment-$n'),
          onTap: () => setState(() => open ? _openImages.remove(url) : _openImages.add(url)),
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
            // Solid green so it stands out on any photo (Jose, 2026-10-13:
            // "too transparent, make it colorful ... like green").
            child: Material(
              color: ToneColours.of(context, Tone.ok).ink,
              shape: const CircleBorder(),
              child: IconButton(
                key: ValueKey('attachment-outside-$n'),
                tooltip: 'Open in the browser',
                visualDensity: VisualDensity.compact,
                iconSize: 16,
                color: ToneColours.of(context, Tone.ok).fill,
                icon: const Icon(Icons.open_in_new),
                onPressed: outside,
              ),
            ),
          ),
      ],
    );
  }

  /// A call recording's file name: when, then who the lead is, without the
  /// characters a file name cannot hold.
  String _recordingName(Object? occurredAt) {
    final who =
        ((widget.lead['contact_name'] as String?)?.trim().isNotEmpty == true
                ? widget.lead['contact_name'] as String
                : '${widget.lead['phone'] ?? ''}')
            .replaceAll(RegExp(r'[/:*?"<>|]'), '')
            .replaceAll(r'\', '')
            .trim();
    return '${RecordingPlaybackBar.nameFor(occurredAt, null)} $who'.trim();
  }

  /// One press puts [text] on the clipboard and says so, as a message's copy does.
  Widget _copyButton(String text, {required Key key, required String what}) {
    final quiet = Theme.of(context).colorScheme.onSurfaceVariant;
    return InkWell(
      key: key,
      borderRadius: BorderRadius.circular(8),
      onTap: () async {
        await Clipboard.setData(ClipboardData(text: text));
        if (!mounted) return;
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(
          SnackBar(content: Text('$what copied')),
        );
      },
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        child: Icon(
          Icons.copy_outlined,
          size: 14,
          semanticLabel: 'Copy ${what.toLowerCase()}',
          color: quiet,
        ),
      ),
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
                      // An unanswered RingCentral call says what happened
                      // ("Missed call", "Voicemail", "Possible spam") rather
                      // than 0:00 (Jose, 2026-10-16).
                      ms == null && (c['text'] as String? ?? '').isNotEmpty
                          ? '$who called · ${c['text']}'
                          : '$who called · ${formatDuration(ms as num?)}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(
                      hasRecording
                          ? when
                          // RingCentral sends it a few minutes after the call
                          // (Jose, 2026-10-19: the Fetching tag).
                          : c['text'] == 'Fetching recording'
                          ? '$when · fetching recording'
                          : '$when · no recording',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
                // Which of our numbers, and its line (Jose, 2026-10-20: "can we
                // show What number they called as well?").
                if ((c['line_number'] as String?)?.isNotEmpty == true)
                  Text(
                    [
                      '${who == 'Customer' ? 'to' : 'from'} ${formatPhoneNumber(c['line_number'] as String?)}',
                      if ((c['line_name'] as String?)?.isNotEmpty == true)
                        c['line_name'] as String,
                      // Who picked up, or whose phones rang (Jose, 2026-10-20).
                      if ((c['answered_by'] as String?)?.isNotEmpty == true)
                        'answered by ${c['answered_by']}'
                      else if ((c['rang'] as String?)?.isNotEmpty == true)
                        'rang ${c['rang']}',
                    ].join(' · '),
                    key: ValueKey('call-line-$id'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
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
                    // Reports' Download (Jose, 2026-10-27), for whoever can
                    // play it: "2026-10-19 1432 Kayla Culbreth".
                    downloadName: _recordingName(c['occurred_at']),
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
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
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

/// Google's credit reason, in words.
String creditReasonWord(String? reason) => switch (reason) {
      'SPAM' => 'Spam',
      'SOLICITATION' => 'Someone selling',
      'JOB_TYPE_MISMATCH' => 'Service we do not offer',
      'GEO_MISMATCH' => 'Outside our area',
      'DUPLICATE' => 'Duplicate',
      _ => 'Other',
    };

/// Google's creditIssuanceDecision, in words.
String creditDecisionWord(String? decision) => switch (decision) {
      'SUCCESS_NOT_REACHED_THRESHOLD' || 'SUCCESS_REACHED_THRESHOLD' =>
        'credited',
      'FAIL_OVER_THRESHOLD' => 'refused, credit limit reached',
      'FAIL_NOT_ELIGIBLE' => 'refused, not eligible',
      _ => 'sent',
    };

/// Whether [field] still holds the value AI wrote: the API's `ai` list,
/// which drops a field once a person changes it (UI queue 77).
bool leadAiHolds(Map<String, dynamic> lead, String field) =>
    (lead['ai'] as List?)?.contains(field) == true;

/// The title is the name AI filled in, rather than a number or a customer.
bool leadNameIsAi(Map<String, dynamic> lead) =>
    leadAiHolds(lead, 'contact_name') &&
    (lead['contact_name'] as String?)?.trim().isNotEmpty == true;

const String aiNameTooltip =
    'Name filled in by AI from what the customer said. Edit it to change it.';

/// The title of a lead: the name, or the number somebody would call back.
String leadTitle(Map<String, dynamic> lead) {
  final name = (lead['contact_name'] as String?)?.trim();
  if (name != null && name.isNotEmpty) return name;
  // A number that is already a customer is shown by the customer's name.
  final customer = ((lead['customer'] as Map?)?['name'] as String?)?.trim();
  if (customer != null && customer.isNotEmpty) return customer;
  final phone = lead['phone'] as String?;
  if (phone != null && phone.isNotEmpty) return formatPhoneNumber(phone);
  final email = (lead['email'] as String?)?.trim();
  if (email != null && email.isNotEmpty) return email;

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
String leadHeaderLine(
  Map<String, dynamic> lead, {
  bool company = true,
  bool withSource = true,
}) {
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
  // `site` since 2026-10-19: `summary` held the host until Grok's one-line
  // read replaced it, and forms then showed no website (Jose).
  final host = (source == 'website_chat' ||
          source == 'website_form' ||
          source == 'website_booking')
      ? (lead['site'] as String?)
      : null;

  final place =
      (host != null && host.isNotEmpty ? host : null) ??
      (lead['franchise'] as Map?)?['name'] as String? ??
      (company ? ((lead['company'] as Map?)?['name'] as String?) : null);

  return [
    ?service,
    if (withSource) sourceWord(source),
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
  'workiz' => 'Workiz',
  'ringcentral' => 'RingCentral',
  'ez_calls' || 'ez_texts' || 'promotions' => 'EzBizi',
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

  final time = clock?.call(at.hour, at.minute) ??
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
  final label = '${shortWeekdays[at.weekday - 1]}, ${shortMonths[at.month - 1]} ${at.day}';
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
    'follow_up' => 'Follow Up',
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
    'follow_up' => Tone.caution,
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

/// A 1.5px dashed rounded-rectangle outline, for the ad-question bubble.
class _DashedOutline extends CustomPainter {
  const _DashedOutline({required this.color, required this.radius});

  final Color color;
  final double radius;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    final rrect = RRect.fromRectAndRadius(
      Offset.zero & size,
      Radius.circular(radius),
    ).deflate(0.75);
    const dash = 5.0, gap = 4.0;
    for (final metric in (Path()..addRRect(rrect)).computeMetrics()) {
      for (var d = 0.0; d < metric.length; d += dash + gap) {
        canvas.drawPath(metric.extractPath(d, d + dash), paint);
      }
    }
  }

  @override
  bool shouldRepaint(_DashedOutline old) =>
      old.color != color || old.radius != radius;
}

/// An approved WhatsApp template for a lead whose 24-hour window has closed:
/// pick one, fill its values, see what the customer will read, send.
class _WhatsAppTemplatePanel extends StatefulWidget {
  const _WhatsAppTemplatePanel({
    required this.api,
    required this.leadId,
    required this.templates,
  });

  final ApiClient api;
  final String leadId;
  final List<Map<String, dynamic>> templates;

  @override
  State<_WhatsAppTemplatePanel> createState() => _WhatsAppTemplatePanelState();
}

class _WhatsAppTemplatePanelState extends State<_WhatsAppTemplatePanel> {
  Map<String, dynamic>? _template;
  final List<TextEditingController> _values = [];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in _values) {
      c.dispose();
    }
    super.dispose();
  }

  void _pick(String? name) {
    final template = widget.templates
        .where((t) => t['name'] == name)
        .firstOrNull;
    for (final c in _values) {
      c.dispose();
    }
    _values
      ..clear()
      ..addAll([
        for (var i = 0; i < ((template?['parameters'] as num?)?.toInt() ?? 0); i++)
          TextEditingController()..addListener(() => setState(() {})),
      ]);
    setState(() {
      _template = template;
      _error = null;
    });
  }

  String get _preview => '${_template?['preview'] ?? ''}'.replaceAllMapped(
    RegExp(r'\{\{(\d+)\}\}'),
    (m) {
      final i = int.parse(m[1]!) - 1;
      return i < _values.length && _values[i].text.trim().isNotEmpty
          ? _values[i].text
          : m[0]!;
    },
  );

  bool get _ready =>
      _template != null &&
      !_busy &&
      _values.every((c) => c.text.trim().isNotEmpty);

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post(
      '/leads/${widget.leadId}/whatsapp-template',
      {
        'template': _template!['name'],
        'variables': [for (final c in _values) c.text.trim()],
      },
    );
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.message;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SidePanel(
      title: 'Send a WhatsApp template',
      actions: [
        FilledButton(
          key: const ValueKey('template-send'),
          onPressed: _ready ? _send : null,
          child: Text(_busy ? 'Sending…' : 'Send'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SearchPickerField<String>(
            key: const ValueKey('template-pick'),
            label: 'Template',
            value: _template?['name'] as String?,
            options: [
              for (final t in widget.templates)
                SearchOption(value: '${t['name']}', label: '${t['name']}'),
            ],
            onChanged: _busy ? null : _pick,
          ),
          if (_template != null) ...[
            const SizedBox(height: 12),
            Container(
              key: const ValueKey('template-preview'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerLow,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(_preview),
            ),
            for (var i = 0; i < _values.length; i++) ...[
              const SizedBox(height: 12),
              TextField(
                key: ValueKey('template-value-${i + 1}'),
                controller: _values[i],
                enabled: !_busy,
                decoration: InputDecoration(labelText: '{{${i + 1}}}'),
              ),
            ],
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              key: const ValueKey('template-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
