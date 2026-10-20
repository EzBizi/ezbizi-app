import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/address_fields.dart';
import '../core/cascade_words.dart';
import '../widgets/error_note.dart';
import '../widgets/refused.dart';
import '../widgets/search_picker.dart';
import '../widgets/setting_editors.dart';
import '../widgets/side_panel.dart';
import '../widgets/sending_server_panel.dart';
import '../widgets/location_setting.dart';

/// Settings, at whichever scope is selected (plan §6).
///
/// The interesting part is showing INHERITANCE. A value that came from the
/// organization looks different from one set here, because "what is this set
/// to" and "where did it come from" are different questions — and without the
/// second, people change the wrong level and wonder why nothing happened.
///
/// ── Why there is a toolbar above the list ────────────────────────
///
/// Thirty rows in eight cards is not a screen anybody reads; it is one people
/// scroll past hunting for a single thing. An administrator arrives having
/// already decided what they want — "make passwords longer", "the tax rate for
/// this territory" — so the first control is a search box. The second answers
/// the question this screen exists for: how much of what you are looking at is
/// decided HERE, rather than somewhere above you.
///
/// ── Why the organization's own data lives at the bottom ──────────
///
/// Two §9 obligations — "export everything this organization holds"
/// (`/exports/bundle`, on the server since 2026-08-06) and "the record of what
/// it holds about people" (`/exports/inventory`, since 2026-08-11) — get their
/// FIRST interface here. No screen in this app had ever called either: until
/// this section, answering either obligation meant holding an API token and
/// knowing the URL. They did not move here from the "Import & export" tab that
/// was removed on 2026-08-12 — that tab listed a per-resource Export and Import
/// button and nothing else.
///
/// They are here rather than in a resource tab because neither is about a
/// resource, so neither has a tab to go in — "export everything this business
/// holds" is not a Customers feature. What they ARE about is the organization,
/// and they are decided by whoever administers it, which is who is reading this
/// screen. They are the one section here that is not a setting, so they sit
/// under their own heading at the end rather than among the rows, and only at
/// organization scope: they answer for the whole business and there is no
/// franchise-sized version of either.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({
    super.key,
    required this.api,
    required this.session,
    this.onlyGroup,
    this.scopeType,
    this.scopeId,
    this.scopeLabel,
    this.startSetHere = false,
  });

  final ApiClient api;
  final Session session;

  /// Another place than the current view: Organization's page for a company
  /// or branch draws that place's settings, editable there (Jose, 2026-10-20:
  /// "settings set here ... aren't there to set"). Null is the current view.
  final String? scopeType;
  final String? scopeId;
  final String? scopeLabel;

  /// Open on "Set here" rather than on everything.
  final bool startSetHere;

  /// Show one server group and nothing else. Work Hours' Settings tab reads
  /// its Clock in and paid-time-off rows this way (2026-09-24), so the rows,
  /// their controls and their permission checks are this screen's rather
  /// than a second copy that could drift from it. Null is the whole page.
  final String? onlyGroup;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

/// Which question the list is answering at the moment.
enum _Filter { all, setHere, notInEffect }

/// One heading and the rows under it, as the SERVER grouped them.
///
/// Keyed on the group's identifier rather than on the words printed above it:
/// two concerns could reasonably be given the same name, and the label is only
/// ever printed, never matched on.
class _Group {
  _Group(this.key, this.label);

  final String key;
  final String? label;
  final List<Map<String, dynamic>> rows = [];
}

/// What this setting is waiting for, when it is waiting for something.
///
/// `not_yet_in_effect` is one nullable sentence rather than a flag beside a
/// reason, so the two halves cannot disagree — null means the setting does
/// something, a string says what has to ship before it does. A setting that
/// saves, audits and changes no behaviour is the failure this whole area of
/// work exists to correct, and the honest thing a screen can do about one
/// nobody has wired yet is refuse to imply otherwise.
String? _notYetInEffect(Map<String, dynamic> setting) {
  final reason = setting['not_yet_in_effect'];

  return reason is String && reason.trim().isNotEmpty ? reason : null;
}

// ── the cascade's vocabulary, back where it started ──────────────
//
// These three were defined in the sending-identity panel until 2026-09-14,
// because that card asked the same question about the same cascade and the
// wording had to be ONE wording: a card saying "inherited" differently from the
// rows beside it is the confusion this screen exists to prevent. The card is
// gone on Jose's ruling ("GET RID OF IT", b32fc797); that reason is not, and
// every caller of these three was on this screen the whole time anyway.
//
// The reason is written out rather than quoted from the old file's docblock,
// which nobody can open any more.

/// Where a value came from, in words an administrator can act on.
///
/// Three states, and the third is not a rounding of the second. A null `source`
/// means nothing is set anywhere in the cascade and what is on screen is the
/// platform's — a different fact from inheriting a value somebody in this
/// organization chose. An admin who cannot tell "this franchise's own value"
/// from "the company's, showing through" edits what they take to be the
/// franchise and changes every place under that company.
/// The WORDS come from core/cascade_words.dart; the shape is unpacked here.
///
/// They moved out on 2026-09-15, when the mail-server card arrived on this same
/// screen carrying its own identical copy. Identical is the problem: two copies
/// of a sentence drift on the day somebody improves one of them, and these two
/// are drawn inches apart.
String provenanceSentence(Map<String, dynamic>? source) => provenanceWords(
  source == null ? null : source['name'] as String?,
  inherited: source != null && source['inherited'] == true,
);

/// True when the value on screen was chosen at the scope being looked at.
bool sourceIsOwn(Map<String, dynamic>? source) =>
    source != null && source['inherited'] != true;

/// A value that saves, audits and inherits like any other, and that nothing
/// reads yet. A setting which silently does nothing is the complaint this area
/// exists to answer, so the admission gets one phrasing rather than two.
String notInEffectSentence(String reason) => 'Not in effect yet — $reason';

/// The value on this row was chosen at the scope being looked at.
///
/// Distinct from "has a value" — nearly everything has one. This is the count
/// that says whether you are looking at a scope that overrides much, or at one
/// that simply follows its parent.
///
/// Delegates rather than re-deciding: `sourceIsOwn` is the same test the
/// sending-identity card makes about the same `source` block, and two answers
/// to "is this scope's own" is how one screen ends up counting a row the other
/// calls inherited.
bool _setHere(Map<String, dynamic> setting) =>
    sourceIsOwn(setting['source'] as Map<String, dynamic>?);

class _SettingsScreenState extends State<SettingsScreen> {
  bool _loading = true;
  String? _error;
  List<dynamic> _settings = [];

  /// The `organization` block from `/exports` — one nullable sentence per §9
  /// answer, saying why it is refused or null when it is not.
  ///
  /// Null while the request is in flight and null if it failed, and in both
  /// cases the section is not drawn. A card offering two buttons that cannot
  /// say whether they will work is worse than one that appears a moment later:
  /// the settings this screen exists for are already on screen and unaffected.
  Map<String, dynamic>? _dataAnswers;

  /// True while the bundle is being fetched, so the control can say so. A whole
  /// organization's records is not a request that returns instantly, and a
  /// button that looks untouched for six seconds gets pressed again.
  bool _exportingEverything = false;

  final TextEditingController _addressLine1 = TextEditingController();
  final TextEditingController _addressLine2 = TextEditingController();
  final TextEditingController _addressCity = TextEditingController();
  final TextEditingController _addressState = TextEditingController();
  final TextEditingController _addressPostal = TextEditingController();
  bool _savingAddress = false;

  /// The address as last saved or loaded, and the pause before saving a
  /// change: it saves as you type, like every row here (UI queue 110), once
  /// the typing stops, rather than behind a Save button of its own.
  String _savedAddress = '';
  Timer? _addressPause;

  String get _addressNow => [
    _addressLine1,
    _addressLine2,
    _addressCity,
    _addressState,
    _addressPostal,
  ].map((c) => c.text.trim()).join('|');

  void _addressChanged() {
    if (_addressNow == _savedAddress) return;
    _addressPause?.cancel();
    _addressPause = Timer(
      const Duration(milliseconds: 1200),
      _saveBusinessAddress,
    );
  }

  final TextEditingController _search = TextEditingController();
  _Filter _filter = _Filter.all;

  /// Whatever the top bar's switcher is pointing at.
  ///
  /// This screen used to carry its own chip row — the organization, its
  /// companies and their franchises — which was a second control doing the same
  /// job as the switcher. Two of them is how somebody ends up configuring one
  /// scope while looking at another's numbers.
  String get _scopeType => widget.scopeType ?? widget.session.me!.scope.type;
  String? get _scopeId =>
      widget.scopeId ??
      widget.session.me!.scope.id ??
      widget.session.me!.organization.id;

  /// The full chain — "Everdoors › Connecticut".
  ///
  /// "Settings for Connecticut" named one of two different places, each with
  /// its own values, and there was nothing on the screen to say which was being
  /// edited.
  String get _scopeLabel =>
      widget.scopeLabel ?? widget.session.me!.scope.fullPath;

  /// The rows, typed. Anything in the response that is not a row is skipped
  /// rather than taking the screen down around it.
  Iterable<Map<String, dynamic>> get _rows =>
      _settings.whereType<Map<String, dynamic>>();

  @override
  void initState() {
    super.initState();
    if (widget.startSetHere) _filter = _Filter.setHere;
    // Filtering is the only thing typing does, so a listener is the whole
    // wiring — no submit button, no debounce. Thirty rows do not need one.
    _search.addListener(() => setState(() {}));
    _fillBusinessAddress(widget.session.me?.organization);
    for (final field in [
      _addressLine1,
      _addressLine2,
      _addressCity,
      _addressState,
      _addressPostal,
    ]) {
      field.addListener(_addressChanged);
    }
    _load();
    _loadDataAnswers();
  }

  /// Whether this person may take the two whole-organization §9 answers.
  ///
  /// Asked of `/exports`, which is the endpoint that owns both gates, rather
  /// than worked out from the permission list on /me. `bundle` and `inventory`
  /// each require the view permission of EVERY exportable resource, and that
  /// list lives in ResourceMap on the server — a client deciding it for itself
  /// would be §4 reimplemented in Dart, and it would be wrong the day a third
  /// resource is added.
  Future<void> _loadDataAnswers() async {
    final response = await widget.api.get('/exports');

    if (!mounted || !response.ok) return;

    setState(() {
      _dataAnswers = response.body['organization'] as Map<String, dynamic>?;
    });
  }

  @override
  void dispose() {
    _search.dispose();
    _addressPause?.cancel();
    _addressLine1.dispose();
    _addressLine2.dispose();
    _addressCity.dispose();
    _addressState.dispose();
    _addressPostal.dispose();
    super.dispose();
  }

  /// A scope change refetches because the SHELL remounts this screen, not
  /// because anything here watches for one.
  ///
  /// There was a `_loadedFor` field and a `didChangeDependencies` comparing it,
  /// commented as "the scope the last load ran against, so a change in the top
  /// bar refetches". It never fired: nothing in this build depends on an
  /// inherited widget that changes when the scope does, so the callback does not
  /// run, and switching scope with that code in place left the previous scope's
  /// values on screen under the new scope's heading.
  ///
  /// What actually does the work is main.dart's
  /// `KeyedSubtree(key: ValueKey('type:id'))` around the body — a new key is a
  /// new element, so this State is discarded and rebuilt from initState. That is
  /// also why the first load below can safely blank the screen: after a scope
  /// change there is genuinely nothing to keep.
  Future<void> _load() async {
    setState(() {
      // Only when there is nothing to show. A refresh — the fallback in _apply,
      // or Try again — keeps the rows up rather than replacing the page with a
      // spinner.
      _loading = _settings.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/settings', {
      'scope_type': _scopeType,
      'scope_id': _scopeId!,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _settings = response.data as List<dynamic>;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _update(String key, dynamic value) async {
    final response = await widget.api.patch(
      '/settings/$key',
      {'value': value},
      {'scope_type': _scopeType, 'scope_id': _scopeId!},
    );

    if (!mounted) return;

    if (response.paymentRequired) {
      // §13 — a lapsed subscription, not a permissions problem. Saying "access
      // denied" when someone simply needs to pay is a support call.
      widget.session.noteReadOnly(response.message);
      _snack(response.message, isError: true);
      return;
    }

    if (!response.ok) {
      // Nothing on screen moves. A control showing a value the server refused
      // is worse than one that never changed, because it looks saved.
      //
      // For a switch or a dropdown that is free: they are drawn from the row,
      // and the row has not changed. A text field holds its own text, so it is
      // the one control that CAN sit there displaying a rejected value as
      // though it had been accepted — and it did. _InlineField puts itself back
      // when the future this returns completes.
      _snack(response.message, isError: true);
      return;
    }

    _apply(key, response.data);
    _snack('Saved');

    await _refreshDisplayFormats(key);
  }

  /// Pick up a display setting the server has just changed (§6).
  ///
  /// Changing how a date is written has to change the dates already on this
  /// page — the business-hours summary two rows down, and the quiet-hours one
  /// under it. Those read `session.me.display`, which came from /me and is now
  /// a value the server has replaced. Nothing else refetches /me, so without
  /// this the owner switches to 24h, watches the row say "Saved", and reads
  /// "5:00 PM" immediately beneath it — the original complaint, reproduced on
  /// the screen built to fix it.
  ///
  /// It does NOT reload this screen. /me is a different request; the rows here
  /// stay exactly as they are, main.dart's listener repaints the tree with the
  /// new formats, and the KeyedSubtree around the body is keyed on the SCOPE,
  /// which has not changed — so this State survives and nothing blanks. That
  /// matters: refetching /settings on every write is the behaviour the owner
  /// complained about and it is not being reintroduced by the back door.
  ///
  /// Asks DisplayFormats which keys those are rather than naming them here.
  /// One list, in the file that formats from it, so a third display setting
  /// starts refreshing the moment it starts being read.
  Future<void> _refreshDisplayFormats(String key) async {
    if (!DisplayFormats.known.containsKey(key)) return;

    await widget.session.refresh();
  }

  Future<void> _reset(String key) async {
    final response = await widget.api.delete('/settings/$key', {
      'scope_type': _scopeType,
      'scope_id': _scopeId!,
    });

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.message, isError: true);
      return;
    }

    // Applied FIRST, and the message read off the row that was accepted.
    //
    // This used to reach into the body for `source` before anything had checked
    // the body's shape, with a hard cast — so a `source` that was present but
    // not an object threw before _apply could run, and the fallback below never
    // happened: the control kept showing the value the server had just replaced,
    // with no snackbar at all. It could also announce "Back to the EzBizi
    // default" a moment before the fallback reload redrew the row as inherited
    // from somewhere, which is two contradictory statements about one setting.
    final applied = _apply(key, response.data);

    if (applied == null) {
      // _apply is refetching. It will show what is actually true in a moment,
      // so say only the part that is certain — the server accepted the reset.
      _snack('Reset');
    } else {
      // What it fell back TO is in the answer, so say that rather than guessing
      // from the scope we are sitting at. Dropping an override on a franchise
      // usually means inheriting from the company above — but when nothing
      // above holds a value either, the platform default applies and "Now
      // inherited" would name a level with nothing set on it.
      final source = applied['source'] as Map<String, dynamic>?;

      _snack(
        source == null
            ? 'Back to the EzBizi default'
            : 'Now inherited from ${source['name']}',
      );
    }

    // Inheriting a clock again changes it just as setting one does.
    await _refreshDisplayFormats(key);
  }

  /// Put the row the write just returned back where the old one was.
  ///
  /// The server answers a write with the setting's whole new state — value,
  /// source and all — so going back for the entire screen to learn what we have
  /// just been told is a round trip that buys nothing. It is also what the owner
  /// was complaining about: every write ended in `_load()`, and changing one
  /// dropdown refetched and rebuilt every setting on the page. Changing one
  /// dropdown should move one row.
  ///
  /// It splices the SERVER's row rather than patching the value locally because
  /// `source` is not something this end can work out. After a write it becomes
  /// "Set here"; after a reset it falls back to whichever ancestor still holds a
  /// value, which may not be the one directly above and may not exist at all.
  Map<String, dynamic>? _apply(String key, dynamic row) {
    final index = _settings.indexWhere((s) => s['key'] == key);

    // A body that is not the row we asked about — an unexpected shape, a
    // different key, or a setting this screen is not showing — leaves us unable
    // to say what the setting now is. Going back for the whole list is the slow
    // answer, but the alternative is a control still displaying the value the
    // server has replaced, and a wrong value that looks saved is the failure
    // worth paying to avoid.
    if (row is! Map<String, dynamic> || row['key'] != key || index < 0) {
      _load();
      return null;
    }

    setState(() => _settings[index] = row);

    return row;
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
        behavior: SnackBarBehavior.floating,
        width: 320,
      ),
    );
  }

  /// Does this row survive the search box and the chips?
  ///
  /// The key is searched as well as the label, because the words somebody has
  /// been handed by a support article or a security questionnaire are sometimes
  /// the key itself — a person told to check `password_min_length` should not
  /// have to translate it into English before they can find it.
  bool _matches(Map<String, dynamic> setting) {
    if (_filter == _Filter.setHere && !_setHere(setting)) return false;

    if (_filter == _Filter.notInEffect && _notYetInEffect(setting) == null) {
      return false;
    }

    final query = _search.text.trim().toLowerCase();

    if (query.isEmpty) return true;

    return [
      setting['label'],
      setting['key'],
      setting['help'],
      setting['group_label'],
    ].whereType<String>().any((text) => text.toLowerCase().contains(query));
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // A line saying what these settings belong to. The switcher above says
        // where you are; this says what changing something here would affect,
        // which is the question people actually get wrong.
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
          child: Text(
            _scopeType == 'organization'
                ? 'Settings for ${me.organization.name} — everything inherits these '
                      'unless a company or a '
                      '${me.organization.franchiseLabel.toLowerCase()} overrides them.'
                : 'Settings for $_scopeLabel. Anything left alone inherits from '
                      'above; anything set here overrides it.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        // Nothing to search and nothing to count until the first load lands.
        if (_rows.isNotEmpty) _toolbar(context),
        const SizedBox(height: 12),
        const Divider(height: 1),
        Expanded(child: _body(context)),
      ],
    );
  }

  bool _inGroup(Map<String, dynamic> setting) =>
      widget.onlyGroup == null || setting['group'] == widget.onlyGroup;

  /// Search, and the two counts worth knowing before reading anything.
  Widget _toolbar(BuildContext context) {
    // Counted over what THIS tab draws: inside Field › Settings, "Search 68
    // settings" was the whole organization's number (UI review, 2026-10-08).
    final rows = _rows.where(_inGroup);
    final total = rows.length;
    final setHere = rows.where(_setHere).length;
    final pending = rows.where((s) => _notYetInEffect(s) != null).length;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 14, 24, 0),
      child: Wrap(
        spacing: 12,
        runSpacing: 10,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 300,
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                isDense: true,
                hintText: 'Search $total settings',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon: _search.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close, size: 18),
                        tooltip: 'Clear',
                        onPressed: _search.clear,
                      ),
              ),
            ),
          ),

          // The one number that says how much of this scope is its own doing.
          // Zero is worth printing too: "nothing is overridden here, all of it
          // comes from above" is an answer, and it is the answer people are
          // most often surprised by.
          FilterChip(
            label: Text('Set here · $setHere'),
            selected: _filter == _Filter.setHere,
            onSelected: (on) =>
                setState(() => _filter = on ? _Filter.setHere : _Filter.all),
            tooltip: 'Decided at this level rather than inherited',
          ),

          // Only when there are any. A filter that can never match teaches
          // people the screen has dead buttons on it.
          if (pending > 0)
            FilterChip(
              label: Text('Not in effect · $pending'),
              selected: _filter == _Filter.notInEffect,
              onSelected: (on) => setState(
                () => _filter = on ? _Filter.notInEffect : _Filter.all,
              ),
              tooltip: 'Saved and audited, but nothing reads them yet',
            ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    if (_rows.isEmpty) {
      return const Center(
        child: Text('There are no settings to show at this scope.'),
      );
    }

    // Grouped the way the SERVER groups them, in the order it sent.
    //
    // This used to split the key on its dot and upper-case the front, so the
    // headings read "GENERAL", "OPS", "NUMBERING" — words nobody wrote, falling
    // out of identifiers. Replacing that with a Dart map of prefix to English
    // would have been the same bug one layer along, with the client still
    // deciding what the platform's concerns are called. So the row carries its
    // group and the words for it, and this end only prints them.
    //
    // A row that names no group is not given an invented heading. It goes in an
    // unlabelled section, which is honest about the client not knowing.
    final groups = <String, _Group>{};

    for (final setting in _rows) {
      if (!_matches(setting)) continue;
      if (!_inGroup(setting)) continue;

      groups
          .putIfAbsent(
            setting['group'] as String? ?? '',
            () => _Group(
              setting['group'] as String? ?? '',
              setting['group_label'] as String?,
            ),
          )
          .rows
          .add(setting);
    }

    // The organization's own details belong to the whole page, not to a tab
    // showing one group of it.
    final organizationData = widget.onlyGroup == null
        ? _organizationData(context)
        : null;

    if (groups.isEmpty && organizationData == null) {
      return _nothingMatches(context);
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        // Everything on this screen belongs to the ORGANIZATION and is set by
        // an administrator. Your own theme, password and second factor moved to
        // "Your account" — they were here, directly above the organization's
        // job statuses, which implied whoever could change one could change the
        // other. §2.1 is explicit that the two categories are not to be merged.
        for (final group in groups.values) ...[
          if (group.label != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 22, 4, 8),
              child: Text(
                group.label!,
                // Larger than the row titles under it (titleMedium): a heading
                // smaller than its contents read as a footnote (UI review,
                // 2026-10-08).
                style: Theme.of(
                  context,
                ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
              ),
            )
          else
            const SizedBox(height: 12),
          Card(
            child: Column(
              children: [
                for (var i = 0; i < group.rows.length; i++) ...[
                  if (i > 0) const Divider(height: 1),
                  _SettingRow(
                    setting: group.rows[i],
                    scopeType: _scopeType,
                    franchiseWord:
                        widget.session.me!.organization.franchiseLabel,
                    // Resolved by /me against this same scope (§6), so the
                    // times inside Business hours are written the way the
                    // Time format row three lines up says they should be.
                    formats: widget.session.me!.display,
                    onChanged: _update,
                    onReset: _reset,
                    api: widget.api,
                  ),
                ],
              ],
            ),
          ),
        ],
        // The organization's own cards belong to Settings, not to a module's
        // Settings tab showing one group.
        if (widget.onlyGroup == null) ?_businessAddressCard(context),

        /*
         * Self-contained on purpose: it fetches its own answer and holds its
         * own state, so this screen needs one line rather than the loader,
         * the field and the builder the deleted sending-identity card needed.
         * Filtered by the search box the same way the others are.
         */
        if (widget.onlyGroup == null &&
            _filter == _Filter.all &&
            SendingServerPanel.matchesSearch(_search.text))
          SendingServerPanel(api: widget.api, session: widget.session),
        ?organizationData,
      ],
    );
  }

  /// Which address this part of the business sends its own mail from (§6).
  ///
  /// A section rather than a row for the same reason the two §9 answers are:
  /// it is not one value with one editor. It is an address, a display name, a
  /// verification state that changes while nobody is looking at it, and a set
  /// of DNS records somebody has to copy into another system — none of which
  /// fits the 150px control at the end of a row.
  ///
  /// It appears at EVERY scope, unlike the data section below it, because the
  /// cascade is the point: a company overrides the organization's address and a
  /// place overrides the company's, and the level you are standing at is the
  /// only one you can set.
  ///
  /// Hidden while a filter chip is on, for the reason the data section is:
  /// "Set here" and "Not in effect" are questions about a settings ROW, and
  /// claiming a match would put this in a list it is not part of. The search
  /// box is different — the card answers for its own words.
  ///
  /// Nothing is drawn until the server has answered, and nothing at all if it
  /// did not. A card that cannot say which address is in force, or whether it
  /// works, is worse than one that appears a moment later; and against a build
  /// whose server half has not landed, silence beats a form promising to save
  /// something into a route that is not there.
  Widget? _businessAddressCard(BuildContext context) {
    if (_scopeType != 'organization' || _filter != _Filter.all) {
      return null;
    }
    if (!(widget.session.me?.can('organizations.edit') ?? false)) {
      return null;
    }
    if (!_addressMatchesSearch(_search.text)) {
      return null;
    }

    return Padding(
      padding: const EdgeInsets.only(top: 22),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Business address',
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 6),
              Text(
                'Printed on contracts as the address of this business.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              AddressFields(
                api: widget.api,
                line1: _addressLine1,
                line2: _addressLine2,
                city: _addressCity,
                state: _addressState,
                postal: _addressPostal,
              ),
              const SizedBox(height: 8),
              Text(
                _savingAddress ? 'Saving…' : 'Saved as you type.',
                key: const ValueKey('address-saved'),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }

  bool _addressMatchesSearch(String query) {
    final term = query.trim().toLowerCase();
    if (term.isEmpty) return true;
    const words = [
      'business address',
      'address',
      'street',
      'city',
      'state',
      'zip',
      'postal',
    ];
    return words.any((word) => word.contains(term) || term.contains(word));
  }

  void _fillBusinessAddress(OrganizationSummary? organization) {
    if (organization == null) return;
    _addressLine1.text =
        organization.addressLine1 ??
        (organization.address != null && organization.city == null
            ? organization.address!
            : '');
    _addressLine2.text = organization.addressLine2 ?? '';
    _addressCity.text = organization.city ?? '';
    _addressState.text = organization.state ?? '';
    _addressPostal.text = organization.postalCode ?? '';
    _savedAddress = _addressNow;
  }

  Future<void> _saveBusinessAddress() async {
    if (!mounted || _savingAddress) return;
    final sending = _addressNow;
    setState(() => _savingAddress = true);
    final response = await widget.api.patch('/organization', {
      'address_line1': _emptyToNull(_addressLine1.text),
      'address_line2': _emptyToNull(_addressLine2.text),
      'city': _emptyToNull(_addressCity.text),
      'state': _emptyToNull(_addressState.text.toUpperCase()),
      'postal_code': _emptyToNull(_addressPostal.text),
    });
    if (!mounted) return;
    setState(() => _savingAddress = false);
    if (response.ok) {
      _savedAddress = sending;
      await widget.session.refresh();
      if (!mounted) return;
      // Only when nothing was typed meanwhile; a later change saves itself.
      if (_addressNow == sending) {
        _fillBusinessAddress(widget.session.me?.organization);
      } else {
        _addressChanged();
      }
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Business address saved.')));
    } else if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }
  }

  String? _emptyToNull(String text) {
    final trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  Widget _nothingMatches(BuildContext context) {
    final query = _search.text.trim();

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            query.isEmpty
                ? 'Nothing here matches that filter.'
                : 'Nothing matches “$query”.',
            style: Theme.of(context).textTheme.bodyLarge,
          ),
          const SizedBox(height: 12),
          // One button clears both, because somebody staring at an empty list
          // rarely remembers which of the two narrowed it.
          OutlinedButton(
            onPressed: () => setState(() {
              _search.clear();
              _filter = _Filter.all;
            }),
            child: const Text('Show all settings'),
          ),
        ],
      ),
    );
  }

  // ── the organization's own data (§9) ─────────────────────────────

  /// The two whole-organization answers, or null when there is nothing to draw.
  ///
  /// Three reasons to draw nothing, and each is a decision:
  ///
  ///   - the `/exports` answer has not arrived, or did not. The section says
  ///     what a person may do; a version of it that cannot say is worse than
  ///     one that appears a moment later.
  ///   - the scope is a company or a franchise. Both answers cover the whole
  ///     business, and there is no franchise-sized version of either — offering
  ///     "export everything" under a franchise's heading would say there is.
  ///   - a filter chip is on. "Set here" and "Not in effect" are questions about
  ///     how a SETTING inherits, and neither of these is a setting; claiming a
  ///     match would put them in a list they do not belong to.
  ///
  /// The search box is different: somebody typing "export" is looking for
  /// exactly this, so it is matched on the words it actually shows.
  Widget? _organizationData(BuildContext context) {
    final answers = _dataAnswers;

    if (answers == null ||
        _scopeType != 'organization' ||
        _filter != _Filter.all) {
      return null;
    }

    // Written once and used for both the search and the screen, so the words
    // somebody can find it by are exactly the words they will then read.
    const exportTitle = 'Export everything';
    const exportNote =
        'Every record this organization holds, as one file. It is your data: '
        'you can keep it, read it, and take it somewhere else.';
    const inventoryTitle = 'What is held about people';
    const inventoryNote =
        'Which records hold personal details, what each one is for, how long '
        'it is kept, and what erasing it does.';

    final query = _search.text.trim().toLowerCase();
    final matches =
        query.isEmpty ||
        [
          _organizationDataHeading,
          exportTitle,
          exportNote,
          inventoryTitle,
          inventoryNote,
        ].any((text) => text.toLowerCase().contains(query));

    if (!matches) return null;

    final exportRefusal = answers['export_refusal'] as String?;
    final inventoryRefusal = answers['inventory_refusal'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 22, 4, 8),
          child: Text(
            _organizationDataHeading,
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
        Card(
          child: Column(
            children: [
              _dataRow(
                context,
                title: exportTitle,
                note: exportNote,
                refusal: exportRefusal,
                busy: _exportingEverything,
                buttonLabel: _exportingEverything
                    ? 'Preparing…'
                    : 'Export everything',
                icon: Icons.download_outlined,
                onPressed: _exportEverything,
              ),
              const Divider(height: 1),
              _dataRow(
                context,
                title: inventoryTitle,
                note: inventoryNote,
                refusal: inventoryRefusal,
                busy: false,
                buttonLabel: 'Open the record',
                icon: Icons.fact_check_outlined,
                onPressed: _openInventory,
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// The heading, in one place because the search above matches on it.
  static const String _organizationDataHeading = 'This organization’s data';

  /// One row of that section: what it is, and the one control that does it.
  ///
  /// A refused control stays on screen carrying the server's own sentence
  /// rather than disappearing (R8) — somebody who cannot export everything is
  /// missing a permission on their role, and that is a five-second fix for
  /// whoever administers them, if they are ever told.
  Widget _dataRow(
    BuildContext context, {
    required String title,
    required String note,
    required String? refusal,
    required bool busy,
    required String buttonLabel,
    required IconData icon,
    required VoidCallback onPressed,
  }) {
    final control = Refused(
      reason: refusal,
      child: OutlinedButton.icon(
        onPressed: (busy || refusal != null) ? null : onPressed,
        icon: Icon(icon, size: 18),
        label: Text(buttonLabel),
      ),
    );

    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(note, style: Theme.of(context).textTheme.bodySmall),
      ],
    );

    return Padding(
      padding: const EdgeInsets.all(16),
      child: LayoutBuilder(
        // The control drops under the text on a narrow window rather than
        // squeezing it — the same rule PanelHeader follows, because this has to
        // survive a phone (R7).
        builder: (context, constraints) => constraints.maxWidth < 520
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  text,
                  const SizedBox(height: 12),
                  Align(alignment: Alignment.centerLeft, child: control),
                ],
              )
            : Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: text),
                  const SizedBox(width: 16),
                  control,
                ],
              ),
      ),
    );
  }

  /// §9 portability: everything, as one JSON document.
  Future<void> _exportEverything() async {
    setState(() => _exportingEverything = true);

    final bytes = await widget.api.getBytes('/exports/bundle');

    if (!mounted) return;

    setState(() => _exportingEverything = false);

    if (bytes == null) {
      _snack(
        'Could not export. Nothing was changed, so it is safe to try again.',
        isError: true,
      );
      return;
    }

    // Named after the organization rather than "bundle.json", because this file
    // ends up in somebody's downloads folder beside a hundred others.
    final name = widget.session.me!.organization.name
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-|-$'), '');

    Files.download(
      '${name.isEmpty ? 'organization' : name}-everything.json',
      bytes,
    );
  }

  /// §9 inventory: the record of what is held about people.
  ///
  /// A side panel rather than a screen of its own — it is read, not worked in,
  /// and it belongs beside the settings it was opened from.
  Future<void> _openInventory() async {
    final response = await widget.api.get('/exports/inventory');

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.message, isError: true);
      return;
    }

    await showSidePanel<void>(
      context,
      builder: (_) => _InventoryPanel(inventory: response.body),
    );
  }
}

/// What this organization holds about people (§9).
///
/// Read-only, and deliberately not a summary: the value of an inventory is that
/// it is complete, so every holding the server lists is drawn, along with the
/// caveats it publishes about what it cannot see. Trimming it to the
/// interesting rows would turn the one document that has to be exhaustive into
/// somebody's impression of it.
class _InventoryPanel extends StatelessWidget {
  const _InventoryPanel({required this.inventory});

  final Map<String, dynamic> inventory;

  @override
  Widget build(BuildContext context) {
    final holdings = (inventory['holdings'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
    final caveats = (inventory['caveats'] as List<dynamic>? ?? [])
        .cast<String>();

    return SidePanel(
      title: 'What is held about people',
      width: 560,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
        // Downloadable because the people who ask for this — a regulator, a
        // customer's lawyer, whoever fills in a security questionnaire — need it
        // in a file rather than in a screenshot.
        FilledButton.icon(
          onPressed: () => Files.download(
            'personal-data-inventory.json',
            utf8.encode(const JsonEncoder.withIndent('  ').convert(inventory)),
          ),
          icon: const Icon(Icons.download_outlined, size: 18),
          label: const Text('Download'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Every place this organization keeps something about a person, what '
            'it is for, and what happens to it over time.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),
          for (final holding in holdings) ...[
            _holding(context, holding),
            const SizedBox(height: 18),
          ],
          if (caveats.isNotEmpty) ...[
            const Divider(),
            const SizedBox(height: 12),
            Text(
              'What this record cannot see',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 8),
            for (final caveat in caveats)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  caveat,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
          ],
        ],
      ),
    );
  }

  Widget _holding(BuildContext context, Map<String, dynamic> holding) {
    final fields = (holding['fields'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
    final rows = holding['rows'];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                holding['label'] as String? ?? '',
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            if (rows is int)
              Text(
                rows == 1 ? '1 record' : '$rows records',
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          holding['purpose'] as String? ?? '',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (holding['retention'] is String) ...[
          const SizedBox(height: 4),
          Text(
            holding['retention'] as String,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 6),
        for (final field in fields)
          Padding(
            padding: const EdgeInsets.only(left: 12, bottom: 2),
            child: Text(
              '${field['field']} — ${field['purpose']}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
  }
}

class _SettingRow extends StatelessWidget {
  const _SettingRow({
    required this.setting,
    required this.scopeType,
    required this.franchiseWord,
    required this.formats,
    required this.onChanged,
    required this.onReset,
    this.api,
  });

  final Map<String, dynamic> setting;

  /// For the editors that look something up (a place's address).
  final ApiClient? api;

  /// In force at this scope. Used by the shaped editors below, which show
  /// times, and to say so when the value in a row is one this build cannot
  /// draw.
  final DisplayFormats formats;

  /// Organization is the top of the cascade, so it inherits from nothing. The
  /// row has to know, or it offers to "reset to inherited" at a level with
  /// nothing above it.
  final String scopeType;

  /// The tenant's own word for a franchise — "Territory", "Branch", "Location".
  /// A row explaining which levels may set something has to use the word the
  /// switcher above it uses, or it is describing a different app (§2.1).
  final String franchiseWord;

  /// Returns once the write has been answered, so a control holding its own
  /// text can put itself back when the server refuses it.
  final Future<void> Function(String key, dynamic value) onChanged;
  final void Function(String key) onReset;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final key = setting['key'] as String;
    final type = setting['type'] as String;
    // Two separate questions, and the screen used to ask only the first.
    //
    //   editable_here — may this SCOPE hold a value for it (inheritance)
    //   may_edit      — may THIS PERSON set it here (permission)
    //
    // Rendering on the first alone meant somebody without `organizations.edit`
    // saw every control live, flicked a switch, watched it animate, and got a
    // 403 from a control that had already moved.
    final scopeAllows = setting['editable_here'] as bool? ?? false;
    final editable = setting['may_edit'] as bool? ?? scopeAllows;
    final source = setting['source'] as Map<String, dynamic>?;
    final inherited = source?['inherited'] as bool? ?? false;

    // Null for every setting this build understands, which is all of them
    // until the server's registry moves ahead of the client's.
    final unsupported = DisplayFormats.unsupportedNote(key, setting['value']);

    final pending = _notYetInEffect(setting);

    // Why the controls are dead, when they are.
    //
    // Separate from the provenance line below, which it used to BE: one chain
    // of ternaries printed either where the value came from or why you could
    // not change it. So the moment a row went read-only it stopped saying what
    // it had inherited and from where — on the screen whose entire purpose is
    // showing that. Worse, "EzBizi default" was tested first, so an
    // organization-only setting viewed from a franchise with nothing set
    // anywhere printed the platform default and gave no hint that this was the
    // wrong level to be looking at.
    // The server's own sentence, which is the same one the 403 would carry —
    // so a dead control and a refused save cannot explain themselves
    // differently. This used to print the permission key instead: "Read-only —
    // needs organizations.edit", a wire name naming something that appears
    // nowhere in the product, with nothing about who could grant it (R8).
    final locked = !scopeAllows
        ? _scopeSentence()
        : !editable
        ? setting['may_edit_reason'] as String? ??
              'Read-only — this one is not yours to change. Somebody who can '
                  'edit roles, or an administrator, can say why.'
        : null;

    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(setting['label'] as String, style: theme.textTheme.titleMedium),
        const SizedBox(height: 2),

        // Where the value came from — always, whatever else is true of
        // the row. The whole reason the API returns `source` is that an
        // admin who cannot see it changes the wrong level.
        //
        // The sentence itself now lives beside the sending-identity
        // card, which asks the same question about the same cascade and
        // would otherwise have grown a second wording of it.
        Text(
          provenanceSentence(source),
          style: theme.textTheme.bodyMedium?.copyWith(fontSize: 12),
        ),

        if (locked != null) ...[
          const SizedBox(height: 2),
          Text(
            locked,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],

        // Not the same thing as read-only, and it must not look like
        // it. A locked row is one you may not change. This is one you
        // MAY change and that will change nothing: the control is live,
        // the save is real, the value is stored, audited and inherited
        // exactly as any other, and no code reads it yet. Greying it
        // would say "not for you", which is a different lie — and the
        // sentence comes from the server, which is the only end that
        // knows what the setting is waiting for.
        if (pending != null) ...[
          const SizedBox(height: 4),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.schedule, size: 14, color: theme.colorScheme.tertiary),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  notInEffectSentence(pending),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.tertiary,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
            ],
          ),
        ],

        // The server sends `help` for every setting and nothing showed
        // it, so the reasoning behind each one lived only in the code.
        if ((setting['help'] as String?)?.isNotEmpty ?? false) ...[
          const SizedBox(height: 4),
          Text(setting['help'] as String, style: theme.textTheme.bodySmall),
        ],
        if (_limits(setting) case final limits?) ...[
          const SizedBox(height: 4),
          Text(limits, style: theme.textTheme.bodySmall),
        ],

        // A value the server accepts and this bundle cannot draw.
        //
        // Only reachable when the registry has gained an option since
        // this build was compiled — one deploy landing before the
        // other. Said out loud, and here, because the alternative is a
        // setting that saves, audits, and visibly does nothing, which
        // is the exact complaint this whole change answers. Somebody
        // looking at this row deserves to know it is the app that is
        // behind, not their choice that was ignored.
        if (unsupported != null) ...[
          const SizedBox(height: 4),
          Text(
            unsupported,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );

    final control = _control(context, key, type, editable);

    // A slot of the same width whether or not there is a button in it.
    // Without one, the controls down a card sat at different distances
    // from the right edge depending on which rows happened to be
    // overridden — a ragged column that read as a rendering fault.
    final reset = SizedBox(
      width: 48,
      child: editable && !inherited && source != null
          ? IconButton(
              icon: const Icon(Icons.settings_backup_restore, size: 18),
              // At organization scope there is nothing above to inherit
              // FROM — it is the top of the cascade. Clearing the value
              // there still does something useful, it just falls back to
              // the platform's default rather than to a parent, and the
              // label should say which.
              tooltip: scopeType == 'organization'
                  ? 'Reset to the EzBizi default'
                  : 'Reset to inherited',
              onPressed: () => onReset(key),
            )
          : null,
    );

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: LayoutBuilder(
        // On a phone the text, a 260px picker and the reset slot cannot share
        // one line: the Expanded text got what was left, about one letter, and
        // every label ran down the screen a character at a time. Below 520 the
        // control drops under the text, as _dataRow's does. A switch is narrow
        // enough to stay beside it.
        builder: (context, constraints) =>
            constraints.maxWidth < 520 && type != 'bool'
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  text,
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Flexible(child: control),
                      reset,
                    ],
                  ),
                ],
              )
            : Row(
                children: [
                  Expanded(child: text),
                  const SizedBox(width: 16),
                  control,
                  reset,
                ],
              ),
      ),
    );
  }

  /// Which levels may hold this setting, in the tenant's own vocabulary.
  ///
  /// Built from `allowed_scopes`, which the API has always sent and which this
  /// screen ignored in favour of the fixed words "Set at organization level
  /// only" — true of every restricted setting TODAY, and a plain falsehood the
  /// first time one is company-and-franchise. A sentence that happens to be
  /// right is not the same as one derived from the answer.
  String _scopeSentence() {
    final scopes = (setting['allowed_scopes'] as List<dynamic>? ?? [])
        .whereType<String>()
        .map(
          (scope) => switch (scope) {
            'organization' => 'the organization',
            'company' => 'a company',
            'franchise' => 'a ${franchiseWord.toLowerCase()}',
            _ => scope,
          },
        )
        .toList();

    if (scopes.isEmpty) return 'This cannot be set at this level.';

    final last = scopes.removeLast();

    return scopes.isEmpty
        ? 'Only $last can set this.'
        : 'Only ${scopes.join(', ')} or $last can set this.';
  }

  Widget _control(
    BuildContext context,
    String key,
    String type,
    bool editable,
  ) {
    final value = setting['value'];

    if (type == 'bool') {
      return Switch(
        value: value == true,
        onChanged: editable ? (v) => onChanged(key, v) : null,
      );
    }

    if (type == 'duration') {
      return _DurationControl(
        minutes: value is int ? value : int.tryParse('$value') ?? 0,
        editable: editable,
        onChanged: (minutes) => onChanged(key, minutes),
      );
    }

    // A company is an enum whose choices the server reads from the tree.
    if (type == 'enum' || type == 'company') {
      return _EnumControl(
        value: value,
        choices: _settingChoices(setting['options']),
        editable: editable,
        onChanged: (v) => onChanged(key, v),
      );
    }

    // Settings with a declared shape get an editor that knows it. The switch is
    // on the TYPE, never on the key — a second set of opening hours added to the
    // registry tomorrow lands here with nothing to change.
    if (type == 'hours') {
      return HoursButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        formats: formats,
        onChanged: (v) => onChanged(key, v),
      );
    }

    if (type == 'time_range') {
      return TimeRangeButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        formats: formats,
        help: setting['help'] as String?,
        onChanged: (v) => onChanged(key, v),
      );
    }

    // A place: an address search and a map (UI queue 104, design 7).
    if (type == 'location' && api != null) {
      return LocationButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        api: api!,
        onChanged: (v) => onChanged(key, v),
      );
    }

    if (type == 'list') {
      return ListButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        help: setting['help'] as String?,
        onChanged: (v) => onChanged(key, v),
      );
    }

    if (type == 'franchise_map') {
      return _FranchiseMapButton(
        label: setting['label'] as String,
        value: value,
        places: _settingChoices(setting['options']),
        editable: editable,
        onChanged: (v) => onChanged(key, v),
      );
    }

    if (type == 'subset' || type == 'roles') {
      return _SubsetButton(
        label: setting['label'] as String,
        value: value,
        choices: _settingChoices(setting['options']),
        editable: editable,
        onChanged: (v) => onChanged(key, v),
      );
    }

    // Anything still declared as plain `json` has no shape to draw, so it keeps
    // the text editor: the escape hatch for a structure that has not earned an
    // editor yet. `jobs.thumbtack_status_map` is the one that reaches it today.
    if (type == 'json') {
      // Structured values used to render in the same 140px box as a tax rate,
      // showing `{monday: {open: 08:00...` — a Dart Map's toString, which is not
      // JSON and which the server rejects the moment it is sent back. So the
      // control offered you a value it would refuse to accept.
      return _JsonButton(
        label: setting['label'] as String,
        value: value,
        editable: editable,
        onChanged: (v) => onChanged(key, v),
      );
    }

    // A sensitive setting comes back with `value: null` however it is set — the
    // API never hands the secret out, on any route. An empty box beside the
    // words "Set here" reads as "this is blank", which is the opposite of true,
    // so the field says what is actually the case and takes a replacement.
    final sensitive = setting['is_sensitive'] == true;

    // int / decimal / string. A string is often a sentence — AI Assistance's
    // welcome message showed as "Choose an optio" in the 150px a number
    // needs (UI audit, 2026-10-08) — so it gets the width of one, and on a
    // phone the Flexible around the control keeps it on the screen.
    return SizedBox(
      width: type == 'string' ? 320 : 150,
      child: _InlineField(
        // Keyed by value, so a save elsewhere or a scope switch rebuilds the
        // field instead of leaving the old text sitting in it.
        key: ValueKey('$key:${sensitive ? 'hidden' : value?.toString()}'),
        initial: sensitive ? '' : value?.toString() ?? '',
        hint: sensitive ? 'Hidden' : null,
        enabled: editable,
        numeric: type == 'int' || type == 'decimal',
        onCommitted: (v) => onChanged(key, v),
      ),
    );
  }
}

/// A text field that saves when you leave it, not only when you press Enter.
///
/// The old one committed on `onFieldSubmitted` alone. Everybody who typed a
/// value and clicked away — which is most people, most of the time — watched it
/// silently revert on the next load and concluded the screen was broken.
///
/// It is a StatefulWidget rather than a callback on the parent because it needs
/// a FocusNode, and because committing on every keystroke would fire a request
/// per character.
class _InlineField extends StatefulWidget {
  const _InlineField({
    super.key,
    required this.initial,
    required this.enabled,
    required this.numeric,
    required this.onCommitted,
    this.hint,
  });

  final String initial;

  /// What to show when the box is empty because the value is not ours to see.
  final String? hint;
  final bool enabled;
  final bool numeric;

  /// Completes when the write has been answered — see `_commit`.
  final Future<void> Function(String) onCommitted;

  @override
  State<_InlineField> createState() => _InlineFieldState();
}

class _InlineFieldState extends State<_InlineField> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial,
  );
  late final FocusNode _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus) _commit();
    });
  }

  @override
  void dispose() {
    _focus.dispose();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _commit() async {
    final typed = _controller.text.trim();

    // Unchanged means nothing to save. Without this, tabbing across a row of
    // settings would write every one of them and fill the audit log with
    // changes nobody made.
    if (typed == widget.initial.trim()) return;

    await widget.onCommitted(typed);

    // Then show what the SCREEN holds, which is not necessarily what was typed.
    //
    // Reaching this line at all means the stored value is still `initial`: a
    // write the server accepted with a different value splices a new row, the
    // row keys this field by that value, and this State is disposed before the
    // await returns. So still being here means one of three things —
    //
    //   - the server refused it, and the box was left sitting there showing a
    //     rejected number as though it had saved. Every other control reverts
    //     for free because it is drawn from the row; this is the one that keeps
    //     its own copy, and it was the one place where the "nothing on screen
    //     moves" promise in _update was quietly not kept;
    //   - it was accepted and normalised back to the same value — "8.50" for a
    //     rate already 8.5 — where the canonical spelling is what belongs in
    //     the box;
    //   - it is a sensitive setting, whose value never comes back, so the field
    //     empties and returns to saying "Hidden".
    if (mounted) _controller.text = widget.initial;
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _controller,
      focusNode: _focus,
      enabled: widget.enabled,
      // Numbers line up on the right; words read from the left.
      textAlign: widget.numeric ? TextAlign.right : TextAlign.start,
      decoration: InputDecoration(
        isDense: true,
        hintText: widget.hint,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 10,
          vertical: 10,
        ),
      ),
      keyboardType: widget.numeric
          ? const TextInputType.numberWithOptions(decimal: true)
          : TextInputType.text,
      onSubmitted: (_) => _commit(),
    );
  }
}

/// Structured settings — business hours, quiet hours — edited as real JSON.
///
/// A panel rather than an inline box: these are nested objects, and a 140px
/// field cannot show one, let alone let somebody correct it. The text is parsed
/// before it is sent, so a typo is caught here with the cursor still in the
/// field rather than as a 422 from the server.
class _JsonButton extends StatelessWidget {
  const _JsonButton({
    required this.label,
    required this.value,
    required this.editable,
    required this.onChanged,
  });

  final String label;
  final dynamic value;
  final bool editable;
  final ValueChanged<dynamic> onChanged;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      onPressed: editable ? () => _open(context) : null,
      icon: const Icon(Icons.data_object, size: 16),
      label: Text(_summary()),
    );
  }

  /// One line that says what is set, without pretending to show the whole thing.
  String _summary() {
    if (value == null) return 'Not set';
    if (value is Map) return '${(value as Map).length} entries';
    if (value is List) return '${(value as List).length} items';

    return 'Edit';
  }

  Future<void> _open(BuildContext context) async {
    final controller = TextEditingController(
      text: const JsonEncoder.withIndent('  ').convert(value ?? {}),
    );

    String? error;

    final saved = await showSidePanel<Object?>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: label,
          width: 520,
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () {
                try {
                  Navigator.of(context).pop(jsonDecode(controller.text));
                } on FormatException catch (e) {
                  // Caught here, where the cursor still is. A 422 arriving
                  // after the panel closed loses both the message and the
                  // half-typed value.
                  repaint(
                    () => error = 'That is not valid JSON — ${e.message}',
                  );
                }
              },
              child: const Text('Save'),
            ),
          ],
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: controller,
                minLines: 8,
                maxLines: 18,
                style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                decoration: const InputDecoration(border: OutlineInputBorder()),
              ),
              if (error != null) ...[
                const SizedBox(height: 8),
                ErrorNote(error!),
              ],
            ],
          ),
        ),
      ),
    );

    controller.dispose();

    if (saved != null) onChanged(saved);
  }
}

/// Some of another setting's list — which job statuses mean "earned".
///
/// Ticks rather than JSON: the choices are the organization's own words, so
/// nobody has to know that "Done and paid" is stored as `done`. A stored value
/// no longer in the list is dropped on save; the server's reader ignores it
/// already, so keeping it would only be a tick nobody can see.
class _SubsetButton extends StatelessWidget {
  const _SubsetButton({
    required this.label,
    required this.value,
    required this.choices,
    required this.editable,
    required this.onChanged,
  });

  final String label;
  final dynamic value;
  final List<_SettingChoice> choices;
  final bool editable;
  final ValueChanged<List<String>> onChanged;

  Set<String> get _chosen => {
    if (value is List)
      for (final v in value as List)
        if (choices.any((c) => '${c.value}' == '$v')) '$v',
  };

  @override
  Widget build(BuildContext context) {
    final n = _chosen.length;

    return OutlinedButton.icon(
      key: const ValueKey('subset-button'),
      onPressed: editable && choices.isNotEmpty ? () => _open(context) : null,
      icon: const Icon(Icons.checklist, size: 16),
      label: Text(n == 0 ? 'None chosen' : '$n of ${choices.length} chosen'),
    );
  }

  Future<void> _open(BuildContext context) async {
    final picked = _chosen;

    final saved = await showSidePanel<List<String>>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: label,
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              // In the list's own order, so the stored value reads the way
              // the statuses do.
              onPressed: () => Navigator.of(context).pop([
                for (final c in choices)
                  if (picked.contains('${c.value}')) '${c.value}',
              ]),
              child: const Text('Save'),
            ),
          ],
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final c in choices)
                CheckboxListTile(
                  key: ValueKey('subset:${c.value}'),
                  title: Text(c.label),
                  value: picked.contains('${c.value}'),
                  controlAffinity: ListTileControlAffinity.leading,
                  onChanged: (on) => repaint(
                    () => on == true
                        ? picked.add('${c.value}')
                        : picked.remove('${c.value}'),
                  ),
                ),
            ],
          ),
        ),
      ),
    );

    if (saved != null) onChanged(saved);
  }
}

/// Codes the business names, each pointing at one of its places — a phone
/// line's market code to the branch that takes it.
///
/// A value that is not one of the places (a branch NAME migrated from before
/// the type existed, or a place since deleted) is kept and shown as such, so
/// opening and saving does not silently drop a line.
class _FranchiseMapButton extends StatelessWidget {
  const _FranchiseMapButton({
    required this.label,
    required this.value,
    required this.places,
    required this.editable,
    required this.onChanged,
  });

  final String label;
  final dynamic value;
  final List<_SettingChoice> places;
  final bool editable;
  final ValueChanged<Map<String, String>> onChanged;

  // An empty PHP array arrives as `[]`, not `{}`.
  Map<String, String> get _map => value is Map
      ? {for (final e in (value as Map).entries) '${e.key}': '${e.value}'}
      : const {};

  @override
  Widget build(BuildContext context) {
    final n = _map.length;

    return OutlinedButton.icon(
      key: const ValueKey('franchise-map-button'),
      onPressed: editable ? () => _open(context) : null,
      icon: const Icon(Icons.alt_route, size: 16),
      label: Text(n == 0 ? 'None set' : '$n set'),
    );
  }

  Future<void> _open(BuildContext context) async {
    final rows = [
      for (final e in _map.entries)
        (code: TextEditingController(text: e.key), place: e.value as String?),
    ];

    final saved = await showSidePanel<Map<String, String>>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: label,
          width: 520,
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop({
                for (final r in rows)
                  if (r.code.text.trim().isNotEmpty && r.place != null)
                    r.code.text.trim(): r.place!,
              }),
              child: const Text('Save'),
            ),
          ],
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var i = 0; i < rows.length; i++)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      SizedBox(
                        width: 110,
                        child: TextField(
                          key: ValueKey('map-code:$i'),
                          controller: rows[i].code,
                          decoration: const InputDecoration(
                            isDense: true,
                            labelText: 'Code',
                            border: OutlineInputBorder(),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: SearchPickerField<String>(
                          key: ValueKey('map-place:$i'),
                          value: rows[i].place,
                          isDense: true,
                          border: const OutlineInputBorder(),
                          options: [
                            for (final p in places)
                              SearchOption(value: '${p.value}', label: p.label),
                            if (rows[i].place != null &&
                                !places.any(
                                  (p) => '${p.value}' == rows[i].place,
                                ))
                              SearchOption(
                                value: rows[i].place!,
                                label: '${rows[i].place} — not a place',
                              ),
                          ],
                          onChanged: (v) => repaint(
                            () => rows[i] = (code: rows[i].code, place: v),
                          ),
                        ),
                      ),
                      IconButton(
                        tooltip: 'Remove',
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () => repaint(() => rows.removeAt(i)),
                      ),
                    ],
                  ),
                ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  key: const ValueKey('map-add'),
                  onPressed: () => repaint(
                    () =>
                        rows.add((code: TextEditingController(), place: null)),
                  ),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add'),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    // ponytail: the code fields are not disposed — the panel is still
    // animating out when this resumes, and disposing then throws. A handful of
    // controllers per open is left to the GC.
    if (saved != null) onChanged(saved);
  }
}

/// "At least 5", "Between 1 and 10", or null when the server sent no bounds.
String? _limits(Map<String, dynamic> setting) {
  String n(Object v) =>
      v is num && v == v.roundToDouble() ? '${v.toInt()}' : '$v';
  final min = setting['min'];
  final max = setting['max'];

  if (min != null && max != null) return 'Between ${n(min)} and ${n(max)}.';
  if (min != null) return 'At least ${n(min)}.';
  if (max != null) return 'At most ${n(max)}.';

  return null;
}

/// One permitted value, with the words the selector prints.
class _SettingChoice {
  const _SettingChoice(this.value, this.label);

  final Object value;
  final String label;
}

/// Strings from older fixtures, `{value, label}` from the server today.
List<_SettingChoice> _settingChoices(Object? raw) {
  if (raw is! List) return const [];

  return [
    for (final item in raw)
      if (item is String)
        _SettingChoice(item, item)
      else if (item is Map)
        _SettingChoice(
          item['value'] as Object? ?? item['key'] as Object? ?? '',
          item['label'] as String? ?? '${item['value'] ?? item['key'] ?? ''}',
        ),
  ];
}

/// A closed list of answers, drawn as the same outlined menu every time.
///
/// DropdownButton has no border, so a row of them reads as floating labels.
/// DropdownMenu is the outlined box timezone already used; every enum uses
/// it so language, paper size and the rest sit in the same frame.
class _EnumControl extends StatelessWidget {
  const _EnumControl({
    required this.value,
    required this.choices,
    required this.editable,
    required this.onChanged,
  });

  final Object? value;
  final List<_SettingChoice> choices;
  final bool editable;
  final ValueChanged<Object> onChanged;

  @override
  Widget build(BuildContext context) {
    if (choices.isEmpty) {
      return Text(value?.toString() ?? '');
    }

    final matching = [
      for (final choice in choices)
        if (choice.value == value) choice,
    ];
    final selected = matching.isEmpty ? value : matching.first.value;
    final items = [
      if (value != null && matching.isEmpty)
        _SettingChoice(value as Object, '$value — not in the list'),
      ...choices,
    ];

    return SizedBox(
      width: 260,
      child: SearchPickerField<Object>(
        key: ValueKey(selected),
        value: selected is Object ? selected : null,
        border: const OutlineInputBorder(),
        isDense: true,
        options: [
          for (final choice in items)
            SearchOption(value: choice.value, label: choice.label),
        ],
        onChanged: editable
            ? (picked) {
                if (picked != null) onChanged(picked);
              }
            : null,
      ),
    );
  }
}

/// Minutes on the wire, minutes / hours / days on screen.
class _DurationControl extends StatefulWidget {
  const _DurationControl({
    required this.minutes,
    required this.editable,
    required this.onChanged,
  });

  final int minutes;
  final bool editable;
  final ValueChanged<int> onChanged;

  @override
  State<_DurationControl> createState() => _DurationControlState();
}

class _DurationControlState extends State<_DurationControl> {
  late final TextEditingController _amount;
  late String _unit;

  @override
  void initState() {
    super.initState();
    final split = _split(widget.minutes);
    _amount = TextEditingController(text: '${split.$1}');
    _unit = split.$2;
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  (int, String) _split(int minutes) {
    if (minutes >= 1440 && minutes % 1440 == 0) {
      return (minutes ~/ 1440, 'days');
    }
    if (minutes >= 60 && minutes % 60 == 0) {
      return (minutes ~/ 60, 'hours');
    }
    return (minutes, 'minutes');
  }

  int _toMinutes() {
    final amount = int.tryParse(_amount.text.trim()) ?? 0;
    final minutes = switch (_unit) {
      'days' => amount * 1440,
      'hours' => amount * 60,
      _ => amount,
    };
    return minutes.clamp(5, 30 * 24 * 60);
  }

  void _commit() {
    if (!widget.editable) return;
    widget.onChanged(_toMinutes());
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 88,
          child: TextField(
            controller: _amount,
            enabled: widget.editable,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.right,
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              contentPadding: EdgeInsets.symmetric(
                horizontal: 12,
                vertical: 10,
              ),
            ),
            onEditingComplete: _commit,
            onTapOutside: (_) => _commit(),
          ),
        ),
        const SizedBox(width: 8),
        SizedBox(
          width: 140,
          child: SearchPickerField<String>(
            key: ValueKey(_unit),
            value: _unit,
            border: const OutlineInputBorder(),
            isDense: true,
            options: const [
              SearchOption(value: 'minutes', label: 'Minutes'),
              SearchOption(value: 'hours', label: 'Hours'),
              SearchOption(value: 'days', label: 'Days'),
            ],
            onChanged: widget.editable
                ? (picked) {
                    if (picked == null) return;
                    setState(() => _unit = picked);
                    _commit();
                  }
                : null,
          ),
        ),
      ],
    );
  }
}
