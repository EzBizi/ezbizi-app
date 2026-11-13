import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'call_flow_chart_pane.dart';

/// Phone › Settings › Call flow — how a company answers a call, changed where
/// it is drawn, and what each of its lines then does.
///
/// ── One screen, where there were three ──────────────────────────
///
/// UI queue 51. Jose, 2026-10-05, of this screen: *"I feel we can also improve
/// this screen"*, and of General: *"Same for this one"*; he picked 1 and 4.
/// General set the company's answer as a form of full-width pickers with one
/// Save at the bottom; Call flow then drew that same answer again on every
/// line, seven of eight cards word for word; and the chart drew one line under
/// an entry of its own. Now the answer is drawn once, as the boxes a call
/// meets in order, and each box is changed where it is. The lines are rows
/// that say whether each one reaches anybody, and a row opens that line's
/// chart here, with a way back.
///
/// ── Why it still shows what the phone will do ───────────────────
///
/// General and Call flow were kept apart so that the report could never show
/// what somebody had just typed instead of what the renderer will do. That
/// holds here: a pick SAVES, and then the company's answer and every line's
/// trace are read again. A line's result is TwimlRenderer::describe()'s word,
/// never a second opinion worked out in Dart — that second opinion is what let
/// five lines look healthy while every call went to voicemail.
class CallFlowPane extends StatefulWidget {
  const CallFlowPane({
    super.key,
    required this.api,
    required this.session,
    this.onOpen,
  });

  final ApiClient api;
  final Session session;

  /// Take the reader to the settings section that edits a thing this screen
  /// only chooses — the menu itself, the people in a ring group.
  ///
  /// The TAB decides how: it owns the section list and the selection. Null
  /// where nothing can navigate, and then those links are not drawn.
  final void Function(String pane)? onOpen;

  @override
  State<CallFlowPane> createState() => _CallFlowPaneState();
}

class _CallFlowPaneState extends State<CallFlowPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  List<_Company> _companies = const [];
  List<_Named> _groups = const [];
  List<_Named> _schedules = const [];
  List<_Named> _menus = const [];
  List<_Named> _boxes = const [];
  List<_Line> _lines = const [];

  String? _companyId;

  /// The line whose chart is open in place of the list, or null.
  String? _openLine;

  final _fallback = TextEditingController();
  final _notice = TextEditingController();
  final _forwardTo = TextEditingController();

  /// Which typed value is being changed: `fallback`, `forward`, or null.
  String? _typing;

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  _Company? get _company =>
      _companies.where((c) => c.id == _companyId).firstOrNull;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_fallback, _notice, _forwardTo]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _companies.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/hours'),
      widget.api.get('/phone/flows'),
      widget.api.get('/phone/voicemail-boxes'),
      widget.api.get('/phone/call-flow'),
    ]);

    if (!mounted) return;

    final failed = results.where((r) => !r.ok).firstOrNull;

    setState(() {
      _loading = false;
      if (failed != null) {
        _error = failed.reason;
        return;
      }

      _companies = _Company.listFrom(results[0].body);
      _groups = _Named.listFrom(results[1].body);
      _schedules = _Named.listFrom(results[2].body);
      _menus = _Named.listFrom(results[3].body);
      _boxes = _Named.listFrom(results[4].body);
      _lines = _Line.listFrom(results[5].body);
      if (!_companies.any((c) => c.id == _companyId)) {
        _companyId = _companies.firstOrNull?.id;
      }
      _notice.text = _company?.recordingNotice ?? '';
    });
  }

  /// Save one change to the company's answer, then read everything again.
  ///
  /// The whole `default_*` set goes, with the one change applied, exactly as
  /// General's Save sent it: nothing here assumes the server leaves out what a
  /// partial body omits.
  Future<void> _save(Map<String, Object?> change) async {
    final company = _company;
    if (company == null || !_canEdit) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.patch('/phone/connections/${company.id}', {
      ...company.defaults,
      ...change,
    });

    if (!mounted) return;

    setState(() {
      _saving = false;
      _typing = null;
    });

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }

    await _load();
  }

  /// Open the picker for one box and save what is picked. "None" travels as
  /// an empty string, because the picker answers null for a dismissed dialog
  /// and a picked "Nobody" must not read as nothing picked.
  Future<void> _pick({
    required String title,
    required String field,
    required String? value,
    required List<_Named> items,
    required String none,
  }) async {
    final picked = await showSearchPicker<String>(
      context,
      title: title,
      value: value ?? '',
      options: [
        SearchOption(value: '', label: none),
        for (final item in items) SearchOption(value: item.id, label: item.name),
      ],
    );

    if (picked == null || picked == (value ?? '')) return;

    await _save({field: picked.isEmpty ? null : picked});
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null && _companies.isEmpty) {
      return ErrorRetry(_error!, onRetry: _load);
    }

    final open = _openLine == null
        ? null
        : _lines.where((l) => l.id == _openLine).firstOrNull;
    if (open != null) return _lineDetail(open);

    final company = _company;
    if (company == null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No phone account yet. Connect one under Connection, and how it '
          'answers a call is set here.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    final lines = _linesOf(company);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Call flow', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What happens when somebody rings ${company.name}.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),

        // A company picker, and only when there is more than one: a dropdown
        // with one entry is a decision nobody is making.
        if (_companies.length > 1)
          SizedBox(
            width: 360,
            child: SearchPickerField<String>(
              value: _companyId,
              label: 'Company',
              border: const OutlineInputBorder(),
              options: [
                for (final c in _companies)
                  SearchOption(value: c.id, label: c.name),
              ],
              onChanged: (id) => setState(() {
                _companyId = id;
                _typing = null;
                _notice.text = _company?.recordingNotice ?? '';
              }),
            ),
          ),
        if (_companies.length > 1) const SizedBox(height: 8),
        Text(
          'These apply to every line ${company.name} has, unless a line has '
          'its own.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),

        // The count first, and above everything: one silent line among eight
        // is easy to miss when each is a row like the others. Five production
        // lines once went to voicemail for months while a screen disclosed it
        // in grey. The red box below says why when it is the company's doing,
        // and carries the fix; this says how many, whatever the cause.
        if (lines.where((l) => !l.reaches).length case final broken
            when broken > 0) ...[
          _alarm(theme, broken),
          const SizedBox(height: 12),
        ],

        LayoutBuilder(
          builder: (context, constraints) => constraints.maxWidth >= 760
              ? _strip(theme, company, lines)
              : _stack(theme, company, lines),
        ),
        const SizedBox(height: 10),
        _aside(theme, company),

        if (!_canEdit) ...[
          const SizedBox(height: 8),
          Text(
            'You can see how calls are answered, but not change it.',
            style: theme.textTheme.bodySmall,
          ),
        ],

        // The lines straight under the answer they follow, and the switches
        // after them: the lines are what somebody scans, the switches what
        // they set once.
        const SizedBox(height: 20),
        _linesList(theme, company, lines),
        const SizedBox(height: 20),
        _recording(theme, company),
      ],
    );
  }

  Widget _alarm(ThemeData theme, int broken) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.phone_missed_outlined,
            color: theme.colorScheme.onErrorContainer,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              broken == 1
                  ? 'One line reaches nobody. Every call to it goes straight '
                        'to voicemail.'
                  : '$broken lines reach nobody. Every call to them goes '
                        'straight to voicemail.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onErrorContainer,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// The lines this company's answer applies to. Every line, until the trace
  /// says which company each belongs to.
  List<_Line> _linesOf(_Company company) {
    if (_lines.any((l) => l.connectionId == null)) return _lines;

    return [
      for (final line in _lines)
        if (line.connectionId == company.id) line,
    ];
  }

  // ── the boxes ────────────────────────────────────────────────────

  List<_Box> _boxesFor(_Company company, List<_Line> lines) {
    final schedule = _named(_schedules, company.hoursScheduleId);
    final menu = _named(_menus, company.flowId);
    final group = _named(_groups, company.ringGroupId);
    final box = _named(_boxes, company.voicemailBoxId);

    // Only lines that FOLLOW the company go silent because of it; a line with
    // a ring group of its own is not this box's doing. Counted only once the
    // trace says which is which.
    final known = lines.every((l) => l.knowsWhereFrom);
    final silent = lines
        .where((l) => !l.reaches && l.answerFrom == 'company')
        .length;

    // Nobody set, or a group set that the dial plan cannot ring: the server's
    // own judgement (`default_reaches`, DialPlan::batches, as a line's answer
    // step uses), so a group with nobody available reads as nobody too.
    final silentGroup = group == null || company.reaches == false;

    return [
      _Box(
        key: 'arrival',
        icon: Icons.call_received,
        label: 'A call comes in',
        value: lines.length == 1 ? '1 line' : '${lines.length} lines',
        detail: 'On any number below.',
      ),
      _Box(
        key: 'hours',
        icon: Icons.schedule_outlined,
        label: 'Hours',
        value: schedule?.name ?? 'Always open',
        detail: schedule == null
            ? 'No schedule, so a call is never treated as closed.'
            : 'When it says closed, the call takes the out-of-hours route.',
        onPick: () => _pick(
          title: 'Business hours',
          field: 'default_hours_schedule_id',
          value: company.hoursScheduleId,
          items: _schedules,
          none: 'Always open',
        ),
        link: ('Edit hours', 'hours'),
      ),
      _Box(
        key: 'menu',
        icon: Icons.dialpad_outlined,
        label: 'Menu',
        value: menu?.name ?? 'No menu',
        detail: menu == null
            ? 'Calls go straight to whoever rings.'
            : 'Plays first. A key sends the caller on.',
        onPick: () => _pick(
          title: 'IVR menu',
          field: 'default_flow_id',
          value: company.flowId,
          items: _menus,
          none: 'No menu — go straight to whoever answers',
        ),
        link: menu == null ? null : ('Edit the menu', 'flows'),
      ),
      _Box(
        key: 'answer',
        icon: silentGroup ? Icons.warning_amber_rounded : Icons.groups_outlined,
        label: 'Rings',
        value: group?.name ?? 'Nobody',
        bad: silentGroup,
        detail: !silentGroup
            ? 'Whoever is on duty in that group is dialled.'
            : known && silent > 0
            ? '${silent == 1 ? '1 line goes' : '$silent lines go'} straight '
                  'to voicemail.'
            : group != null
            ? 'Nobody in it can be rung, so calls go straight to voicemail.'
            : 'Every call goes straight to voicemail.',
        fix: silentGroup ? 'Pick who rings' : null,
        onPick: () => _pick(
          title: 'Who rings',
          field: 'default_ring_group_id',
          value: company.ringGroupId,
          items: _groups,
          none: 'Nobody — every call goes to voicemail',
        ),
        link: group == null ? null : ('Edit the group', 'ring-groups'),
      ),
      _Box(
        key: 'voicemail',
        icon: Icons.voicemail_outlined,
        label: 'Voicemail',
        value: box?.name ?? 'Built-in greeting',
        detail: 'Takes a message when nobody answers.',
        onPick: () => _pick(
          title: 'Voicemail greeting',
          field: 'default_voicemail_box_id',
          value: company.voicemailBoxId,
          items: _boxes,
          none: 'Built-in — “Please leave a message after the tone.”',
        ),
        link: ('Edit mailboxes', 'voicemail'),
      ),
    ];
  }

  static _Named? _named(List<_Named> items, String? id) =>
      id == null ? null : items.where((i) => i.id == id).firstOrNull;

  /// Desktop 1: left to right, in the order a call meets them.
  Widget _strip(ThemeData theme, _Company company, List<_Line> lines) {
    final boxes = _boxesFor(company, lines);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, box) in boxes.indexed) ...[
            if (i > 0)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Icon(
                  Icons.arrow_forward,
                  size: 16,
                  color: theme.colorScheme.outline,
                ),
              ),
            Expanded(child: _boxCard(theme, box)),
          ],
        ],
      ),
    );
  }

  /// Phone 4: the same boxes top to bottom, one row each.
  Widget _stack(ThemeData theme, _Company company, List<_Line> lines) {
    final boxes = _boxesFor(company, lines);

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (final (i, box) in boxes.indexed) ...[
            if (i > 0) const Divider(height: 1),
            _boxRow(theme, box),
          ],
        ],
      ),
    );
  }

  Widget _boxCard(ThemeData theme, _Box box) {
    final colours = box.bad ? ToneColours.of(context, Tone.bad) : null;
    final tappable = _canEdit && box.onPick != null && !_saving;

    return Material(
      key: Key('call-flow-box:${box.key}'),
      color: colours?.fill ?? theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: tappable ? box.onPick : null,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(box.icon, size: 14, color: colours?.ink ?? theme.colorScheme.onSurfaceVariant),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      box.label.toUpperCase(),
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: colours?.ink ?? theme.colorScheme.onSurfaceVariant,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.6,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                box.value,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: colours?.ink,
                  fontWeight: FontWeight.w800,
                ),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 2),
              Text(
                box.detail,
                style: theme.textTheme.bodySmall?.copyWith(color: colours?.ink),
              ),
              const Spacer(),
              const SizedBox(height: 8),
              _boxActions(theme, box, tappable),
            ],
          ),
        ),
      ),
    );
  }

  Widget _boxRow(ThemeData theme, _Box box) {
    final colours = box.bad ? ToneColours.of(context, Tone.bad) : null;
    final tappable = _canEdit && box.onPick != null && !_saving;

    return Material(
      key: Key('call-flow-box:${box.key}'),
      color: colours?.fill ?? Colors.transparent,
      child: InkWell(
        onTap: tappable ? box.onPick : null,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              Icon(box.icon, size: 18, color: colours?.ink ?? theme.colorScheme.onSurfaceVariant),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      box.label.toUpperCase(),
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: colours?.ink ?? theme.colorScheme.onSurfaceVariant,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    Text(
                      box.value,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: colours?.ink,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    if (box.bad)
                      Text(
                        box.detail,
                        style: theme.textTheme.bodySmall?.copyWith(color: colours?.ink),
                      ),
                  ],
                ),
              ),
              if (box.fix != null && _canEdit)
                FilledButton(
                  onPressed: tappable ? box.onPick : null,
                  child: const Text('Pick'),
                )
              else if (tappable)
                Icon(Icons.chevron_right, color: theme.colorScheme.outline),
            ],
          ),
        ),
      ),
    );
  }

  /// Change (or the fix, on a wrong box), and the way to the screen that
  /// edits the thing itself: a group with nobody available is mended there.
  Widget _boxActions(ThemeData theme, _Box box, bool tappable) {
    final link = box.link;
    final open = widget.onOpen;
    final fix = _canEdit ? box.fix : null;

    return Wrap(
      spacing: 4,
      runSpacing: 4,
      children: [
        if (fix != null)
          FilledButton(onPressed: tappable ? box.onPick : null, child: Text(fix))
        else if (tappable)
          TextButton(onPressed: box.onPick, child: const Text('Change')),
        if (link != null && open != null)
          TextButton(onPressed: () => open(link.$2), child: Text(link.$1)),
      ],
    );
  }

  /// Out of hours and the second number: both only matter on the way out of
  /// the boxes above, so they sit under them rather than among them.
  Widget _aside(ThemeData theme, _Company company) {
    const afterHours = {
      'voicemail': 'Take a voicemail',
      'forward': 'Forward the call',
      'closed': 'Say it is closed and hang up',
    };
    final noSchedule = company.hoursScheduleId == null;
    final fallback = company.fallbackNumber?.trim() ?? '';

    Future<void> pickAfterHours() async {
      final picked = await showSearchPicker<String>(
        context,
        title: 'Out of hours',
        value: company.afterHours,
        options: [
          for (final entry in afterHours.entries)
            SearchOption(value: entry.key, label: entry.value),
        ],
      );
      if (picked == null || picked == company.afterHours) return;
      if (picked == 'forward') {
        setState(() {
          _forwardTo.text = company.afterHoursNumber ?? '';
          _typing = 'forward';
        });
        return;
      }
      await _save({'default_after_hours': picked, 'default_after_hours_number': null});
    }

    return Wrap(
      spacing: 24,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('Out of hours: ', style: theme.textTheme.bodySmall),
            TextButton(
              key: const Key('call-flow-after-hours'),
              onPressed: _canEdit && !_saving ? pickAfterHours : null,
              child: Text(
                company.afterHours == 'forward' &&
                        (company.afterHoursNumber ?? '').isNotEmpty
                    ? 'Forward to ${formatPhoneNumber(company.afterHoursNumber!)}'
                    : afterHours[company.afterHours] ?? 'Take a voicemail',
              ),
            ),
            if (noSchedule)
              Text(
                'used once Hours has a schedule',
                style: theme.textTheme.bodySmall,
              ),
          ],
        ),
        if (_typing == 'forward')
          _typed(
            theme,
            controller: _forwardTo,
            label: 'Forward to',
            onSave: () => _save({
              'default_after_hours': 'forward',
              'default_after_hours_number': _forwardTo.text.trim(),
            }),
          )
        else if (_typing == 'fallback')
          _typed(
            theme,
            controller: _fallback,
            label: 'Also try',
            onSave: () => _save({'default_fallback_number': _fallback.text.trim()}),
          )
        else
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'If nobody picks up, also try: ',
                style: theme.textTheme.bodySmall,
              ),
              Text(
                fallback.isEmpty ? 'no other number' : formatPhoneNumber(fallback),
                style: theme.textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: theme.colorScheme.onSurface,
                ),
              ),
              if (_canEdit)
                TextButton(
                  key: const Key('call-flow-fallback'),
                  onPressed: _saving
                      ? null
                      : () => setState(() {
                          _fallback.text = fallback;
                          _typing = 'fallback';
                        }),
                  child: Text(fallback.isEmpty ? 'Add one' : 'Change'),
                ),
            ],
          ),
      ],
    );
  }

  Widget _typed(
    ThemeData theme, {
    required TextEditingController controller,
    required String label,
    required VoidCallback onSave,
  }) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 220,
          child: TextField(
            controller: controller,
            autofocus: true,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(
              labelText: label,
              hintText: '+17865550123',
              isDense: true,
              border: const OutlineInputBorder(),
            ),
            onSubmitted: (_) => onSave(),
          ),
        ),
        const SizedBox(width: 8),
        FilledButton(onPressed: _saving ? null : onSave, child: const Text('Save')),
        TextButton(
          onPressed: () => setState(() => _typing = null),
          child: const Text('Cancel'),
        ),
      ],
    );
  }

  // ── recording ────────────────────────────────────────────────────

  Widget _recording(ThemeData theme, _Company company) {
    final enabled = _canEdit && !_saving;

    Widget toggle(String key, String title, String note, bool value, String field) =>
        SwitchListTile(
          key: Key('call-flow-switch:$key'),
          contentPadding: EdgeInsets.zero,
          value: value,
          onChanged: enabled ? (v) => _save({field: v}) : null,
          title: Text(title),
          subtitle: Text(note, style: theme.textTheme.bodySmall),
        );

    final notice = company.recordingNotice ?? '';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Recording and transcripts', style: theme.textTheme.labelLarge),
        toggle(
          'record',
          'Record calls',
          'Callers hear the announcement before they connect, which is what '
              'gets their consent.',
          company.record,
          'default_record',
        ),
        if (company.record)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _notice,
                    enabled: enabled,
                    maxLines: 2,
                    onChanged: (_) => setState(() {}),
                    decoration: const InputDecoration(
                      labelText: 'What callers are told',
                      hintText: 'This call may be recorded.',
                      helperText: 'Left empty, callers hear the sentence above.',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                if (_notice.text.trim() != notice.trim()) ...[
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: enabled
                        ? () => _save({'default_recording_notice': _notice.text.trim()})
                        : null,
                    child: const Text('Save'),
                  ),
                ],
              ],
            ),
          ),
        toggle(
          'transcribe',
          'Write down calls',
          'Costs extra per minute, so it stays off until you turn it on.',
          company.transcribe,
          'default_transcribe',
        ),
        // ONE switch for every line (Jose: "we should be able to turn them all
        // on or all off at the same time, not per line"); a company value
        // beats a line's own, as record and live transcription always have.
        toggle(
          'voicemail-transcribe',
          'Write down voicemails',
          'Every line on this account. Costs extra per message, so it stays '
              'off until you turn it on.',
          company.voicemailTranscribe,
          'default_voicemail_transcribe',
        ),
      ],
    );
  }

  // ── the lines ────────────────────────────────────────────────────

  Widget _linesList(ThemeData theme, _Company company, List<_Line> lines) {
    final broken = lines.where((l) => !l.reaches).length;

    if (lines.isEmpty) {
      return Text(
        'No lines yet. Import or buy one on the Numbers screen and it is '
        'listed here.',
        style: theme.textTheme.bodySmall,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text.rich(
          TextSpan(
            children: [
              TextSpan(text: 'Lines', style: theme.textTheme.labelLarge),
              TextSpan(
                text: broken == 0
                    ? '  ${lines.length}'
                    : '  ${lines.length} · $broken reach nobody',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Card(
          margin: EdgeInsets.zero,
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              for (final (i, line) in lines.indexed) ...[
                if (i > 0) const Divider(height: 1),
                _lineRow(theme, company, line),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _lineRow(ThemeData theme, _Company company, _Line line) {
    final colours = ToneColours.of(context, line.reaches ? Tone.ok : Tone.bad);
    final narrow = MediaQuery.sizeOf(context).width < 600;

    return InkWell(
      key: ValueKey('flow:${line.id}'),
      onTap: () => setState(() => _openLine = line.id),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(line.heading, style: theme.textTheme.titleSmall),
                  if (line.answers(company.name) case final answers?)
                    Text(answers, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Icon(
              line.reaches ? Icons.check_circle_outline : Icons.warning_amber_rounded,
              size: 18,
              color: colours.ink,
            ),
            if (!narrow) ...[
              const SizedBox(width: 6),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 260),
                child: Text(
                  line.result,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: colours.ink,
                    fontWeight: FontWeight.w700,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
            Icon(Icons.chevron_right, color: theme.colorScheme.outline),
          ],
        ),
      ),
    );
  }

  /// A line, opened in place: its chart, with the way back to the list in the
  /// chart's own header, beside the picker that names the line.
  Widget _lineDetail(_Line line) {
    return CallFlowChartPane(
      api: widget.api,
      session: widget.session,
      lineId: line.id,
      leading: IconButton(
        tooltip: 'Back to Call flow',
        icon: const Icon(Icons.arrow_back),
        onPressed: () {
          setState(() => _openLine = null);
          _load();
        },
      ),
    );
  }
}

class _Box {
  const _Box({
    required this.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.detail,
    this.bad = false,
    this.fix,
    this.onPick,
    this.link,
  });

  final String key;
  final IconData icon;
  final String label;
  final String value;
  final String detail;
  final bool bad;

  /// The filled button a wrong box carries instead of "Change".
  final String? fix;

  final VoidCallback? onPick;

  /// The words and the settings section that edits the thing itself.
  final (String, String)? link;
}

class _Step {
  const _Step({
    required this.step,
    required this.title,
    required this.usable,
    this.from,
  });

  final String step;
  final String title;
  final bool usable;

  /// `company` or `line`: whose value this step uses. Null from a server
  /// that does not say yet, and then nothing here claims either.
  final String? from;
}

class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.steps,
    this.connectionId,
  });

  final String id;
  final String e164;
  final String label;
  final List<_Step> steps;
  final String? connectionId;

  String get heading => label.trim().isEmpty || label == e164
      ? formatPhoneNumber(e164)
      : '$label · ${formatPhoneNumber(e164)}';

  /// Whether a call to this line reaches a person at all.
  bool get reaches =>
      steps.every((s) => s.step != 'answer' || s.usable) &&
      steps.every((s) => s.step != 'arrival' || s.usable);

  /// The trace's own words for where a call ends: the first step that stops
  /// it, else who answers.
  String get result {
    final stopped = steps.where((s) => !s.usable).firstOrNull;
    if (stopped != null) return stopped.title;

    return steps.where((s) => s.step == 'answer').firstOrNull?.title ?? '';
  }

  bool get knowsWhereFrom => steps.any((s) => s.from != null);

  String? get answerFrom =>
      steps.where((s) => s.step == 'answer').firstOrNull?.from;

  /// "Follows A-1 Chimney", or what this line has of its own. Null until the
  /// trace says which values are whose.
  String? answers(String company) {
    if (!knowsWhereFrom) return null;

    // Arrival is every line's own by nature; only a setting can be the
    // company's or the line's.
    final own = [
      for (final s in steps)
        if (s.step != 'arrival' && s.from == 'line') s.title,
    ];

    return own.isEmpty ? 'Follows $company' : 'Its own: ${own.join(' · ')}';
  }

  static List<_Line> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Line(
          id: row['id'].toString(),
          e164: (row['e164'] ?? '') as String,
          label: (row['label'] ?? '') as String? ?? '',
          connectionId: row['connection_id']?.toString(),
          steps: [
            for (final step
                in ((row['steps'] as List?) ?? const [])
                    .whereType<Map<String, dynamic>>())
              _Step(
                step: (step['step'] ?? '') as String,
                title: (step['title'] ?? '') as String,
                usable: step['usable'] == true,
                from: step['from'] as String?,
              ),
          ],
        ),
    ];
  }
}

class _Named {
  const _Named(this.id, this.name);

  final String id;
  final String name;

  static List<_Named> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Named(
          row['id'].toString(),
          (row['name'] ?? row['label'] ?? '—').toString(),
        ),
    ];
  }
}

/// One company's phone account, and how it answers a call.
///
/// Reads the `default_*` fields off the connection. There is exactly one
/// connection per company — the table is unique on (organization, scope_type,
/// scope_id) — which is what makes this the company row rather than a table of
/// its own.
class _Company {
  const _Company({
    required this.id,
    required this.name,
    required this.afterHours,
    required this.record,
    required this.transcribe,
    required this.voicemailTranscribe,
    this.reaches,
    this.ringGroupId,
    this.hoursScheduleId,
    this.flowId,
    this.voicemailBoxId,
    this.afterHoursNumber,
    this.fallbackNumber,
    this.recordingNotice,
  });

  final String id;
  final String name;
  final String afterHours;
  final bool record;
  final bool transcribe;
  final bool voicemailTranscribe;

  /// Whether the company's own ring group can ring anybody. Null from a
  /// server that does not say, and then only "nobody set" is red.
  final bool? reaches;
  final String? ringGroupId;
  final String? hoursScheduleId;
  final String? flowId;
  final String? voicemailBoxId;
  final String? afterHoursNumber;
  final String? fallbackNumber;
  final String? recordingNotice;

  /// Everything a save sends, as it stands now.
  Map<String, Object?> get defaults => {
    'default_hours_schedule_id': hoursScheduleId,
    'default_flow_id': flowId,
    'default_ring_group_id': ringGroupId,
    'default_voicemail_box_id': voicemailBoxId,
    'default_after_hours': afterHours,
    'default_after_hours_number': afterHours == 'forward' ? afterHoursNumber : null,
    'default_fallback_number': fallbackNumber ?? '',
    'default_record': record,
    'default_recording_notice': recordingNotice ?? '',
    'default_transcribe': transcribe,
    'default_voicemail_transcribe': voicemailTranscribe,
  };

  static List<_Company> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Company(
          id: row['id'].toString(),
          // scope_name is what the place is CALLED. Without it three
          // connections all read "company" and there is no way to tell which
          // is which — which is exactly the bug that put this field on the
          // row in the first place.
          name: (row['scope_name'] ?? 'This company').toString(),
          afterHours: (row['default_after_hours'] ?? 'voicemail').toString(),
          record: row['default_record'] == true,
          transcribe: row['default_transcribe'] == true,
          voicemailTranscribe: row['default_voicemail_transcribe'] == true,
          reaches: row['default_reaches'] as bool?,
          ringGroupId: row['default_ring_group_id'] as String?,
          hoursScheduleId: row['default_hours_schedule_id'] as String?,
          flowId: row['default_flow_id'] as String?,
          voicemailBoxId: row['default_voicemail_box_id'] as String?,
          afterHoursNumber: row['default_after_hours_number'] as String?,
          fallbackNumber: row['default_fallback_number'] as String?,
          recordingNotice: row['default_recording_notice'] as String?,
        ),
    ];
  }
}
