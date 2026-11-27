import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/connect_account.dart';
import '../../widgets/list_search.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../email/consent_window.dart';
import 'invoice_common.dart';

/// A transaction amount as people read a statement: Plaid's positive is money
/// out, so it shows with a minus; money in shows with a plus.
String bankAmount(num cents) =>
    cents > 0 ? '−${invoiceMoney(cents)}' : '+${invoiceMoney(-cents)}';

/// Financials → Bank (Jose, 2026-11-24): the banks this view connected through
/// Plaid, each account with its balances, and every transaction, searchable by
/// merchant or category and filtered by account and money in or out.
/// Connecting is Plaid's own page in a window of its own (ConnectAccount);
/// a bank that wants the person to sign in again shows Reconnect.
class FinancialBankTab extends StatefulWidget {
  const FinancialBankTab({
    super.key,
    required this.api,
    required this.me,
    this.openConsent = openConsentWindow,
  });

  final ApiClient api;
  final Me me;

  /// Injected so a test can hand in a window that never opens one.
  final OpenConsentWindow openConsent;

  @override
  State<FinancialBankTab> createState() => _FinancialBankTabState();
}

class _FinancialBankTabState extends State<FinancialBankTab> {
  Map? _data;
  String? _error;
  List<Map> _txns = const [];
  int _page = 1;
  int _lastPage = 1;
  bool _loadingTxns = false;
  String? _txnError;
  final _search = TextEditingController();
  Timer? _debounce;
  Timer? _watch;
  int _txnSeq = 0;
  String? _accountId;
  String _direction = 'all';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _debounce?.cancel();
    _watch?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    await _loadBanks();
    await _loadTxns(reset: true);
  }

  Future<void> _loadBanks() async {
    final r = await widget.api.get('/financials/bank');
    if (!mounted) return;
    setState(() {
      _data = r.ok ? r.body['data'] as Map : null;
      _error = r.ok ? null : r.message;
    });
  }

  /// The first page ([reset]) or the next one. Only the newest request may
  /// land: an older answer (other filters) is dropped, and the page number
  /// moves only when its page actually arrived.
  Future<void> _loadTxns({bool reset = false}) async {
    final page = reset ? 1 : _page + 1;
    final seq = ++_txnSeq;
    setState(() => _loadingTxns = true);
    final r = await widget.api.get('/financials/bank/transactions', {
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      'account_id': ?_accountId,
      if (_direction == 'in' || _direction == 'out') 'direction': _direction,
      if (_direction == 'unmatched') 'unmatched': '1',
      'page': '$page',
    });
    if (!mounted || seq != _txnSeq) return;
    setState(() {
      _loadingTxns = false;
      _txnError = r.ok ? null : r.message;
      if (r.ok) {
        final rows = (r.body['data'] as List).cast<Map>();
        _page = page;
        _txns = reset ? rows : [..._txns, ...rows];
        _lastPage = (r.body['meta']?['last_page'] as num?)?.toInt() ?? 1;
      } else if (reset) {
        _txns = const [];   // never the last filter's rows under a new filter
      }
    });
  }

  /// Update mode: Plaid's page for this bank in a window of its own, then the
  /// tab re-reads until the bank shows connected (five minutes at most).
  Future<void> _reconnect(Map bank) async {
    final window = widget.openConsent();
    final r = await widget.api.post(
      '/financials/bank/${bank['id']}/reconnect',
      const {},
    );
    if (!mounted) return;
    final data = r.ok ? r.body['data'] : null;
    final url = data is Map ? data['authorization_url'] : null;
    if (url is! String) {
      window?.close();
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(content: Text(r.reason)),
      );
      return;
    }
    window?.goTo(url);
    var asks = 100;
    var busy = false;
    _watch?.cancel();
    _watch = Timer.periodic(const Duration(seconds: 3), (t) async {
      if (--asks <= 0 || !mounted) return t.cancel();
      if (busy) return;   // the last ask has not answered yet
      busy = true;
      await _loadBanks();   // the bank's status only; transactions stay put
      busy = false;
      final now = (_data?['banks'] as List? ?? const [])
          .cast<Map>()
          .where((b) => b['id'] == bank['id']);
      if (now.isNotEmpty && now.first['status'] == 'connected') {
        t.cancel();
        if (mounted) await _loadTxns(reset: true);
      }
    });
  }

  /// What this money in was for: the recorded payments and open invoices
  /// that fit, and the current match with a way to undo it.
  Future<void> _openMatch(Map t) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => _MatchPanel(
        api: widget.api,
        t: t,
        canEdit: widget.me.can('financials.edit'),
      ),
    );
    if (changed == true) await _loadTxns(reset: true);
  }

  Future<void> _refresh(Map bank) async {
    final r = await widget.api.post(
      '/financials/bank/${bank['id']}/refresh',
      const {},
    );
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(
        content: Text(
          r.ok
              ? 'Reading ${bank['name']} now. New transactions appear in a minute.'
              : r.reason,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data;
    final banks = (data?['banks'] as List? ?? const []).cast<Map>();
    final accounts = [
      for (final b in banks) ...(b['accounts'] as List? ?? const []).cast<Map>(),
    ];
    final canConnect = data?['can_connect'] == true;
    final connect = canConnect && data?['ready'] == true
        ? ConnectAccount(
            key: const Key('financials-bank-connect'),
            api: widget.api,
            label: 'a bank',
            startPath: '/social/plaid/start',
            onConnected: _load,
            openConsent: widget.openConsent,
          )
        : null;

    return ListView(
      key: const ValueKey('tour-financials-bank'),
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 12,
          runSpacing: 8,
          children: [
            Text('Bank', style: theme.textTheme.titleMedium),
            if (connect != null && banks.isNotEmpty) connect,
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null)
          Text(_error!, style: TextStyle(color: theme.colorScheme.error))
        else if (data == null)
          const Center(child: CircularProgressIndicator())
        else if (banks.isEmpty)
          _Empty(connect: connect, ready: data['ready'] == true)
        else ...[
          for (final b in banks)
            _BankCard(
              bank: b,
              canEdit: canConnect,
              onReconnect: () => _reconnect(b),
              onRefresh: () => _refresh(b),
            ),
          const SizedBox(height: 16),
          Text('Transactions', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              SizedBox(
                width: 260,
                child: ListSearch(
                  key: const Key('financials-bank-search'),
                  controller: _search,
                  hint: 'Merchant or category',
                  onChanged: (_) {
                    _debounce?.cancel();
                    _debounce = Timer(
                      const Duration(milliseconds: 350),
                      () => _loadTxns(reset: true),
                    );
                  },
                ),
              ),
              SizedBox(
                width: 220,
                child: SearchPickerField<String?>(
                  key: const Key('financials-bank-account'),
                  isDense: true,
                  value: _accountId,
                  options: [
                    const SearchOption(value: null, label: 'All accounts'),
                    for (final a in accounts)
                      SearchOption(
                        value: a['id'] as String,
                        label: _accountLabel(a),
                      ),
                  ],
                  onChanged: (v) {
                    setState(() => _accountId = v);
                    _loadTxns(reset: true);
                  },
                ),
              ),
              SizedBox(
                width: 160,
                child: SearchPickerField<String>(
                  key: const Key('financials-bank-direction'),
                  isDense: true,
                  value: _direction,
                  options: const [
                    SearchOption(value: 'all', label: 'In and out'),
                    SearchOption(value: 'out', label: 'Money out'),
                    SearchOption(value: 'in', label: 'Money in'),
                    SearchOption(value: 'unmatched', label: 'Money in, not matched'),
                  ],
                  onChanged: (v) {
                    setState(() => _direction = v ?? 'all');
                    _loadTxns(reset: true);
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // A failed next page keeps the rows already shown and its button.
          if (_txnError != null)
            Text(_txnError!, style: TextStyle(color: theme.colorScheme.error)),
          if (_txns.isEmpty && !_loadingTxns && _txnError == null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                _search.text.isEmpty && _accountId == null && _direction == 'all'
                    ? 'No transactions yet. A newly connected bank can take a few minutes to send its history.'
                    : 'No transactions match.',
                textAlign: TextAlign.center,
              ),
            )
          else if (_txns.isNotEmpty)
            Card(
              child: Column(
                children: [
                  for (final t in _txns)
                    _TxnRow(
                      t: t,
                      onTap: (t['amount_cents'] as num? ?? 0) < 0 ? () => _openMatch(t) : null,
                    ),
                  if (_page < _lastPage)
                    TextButton(
                      key: const Key('financials-bank-more'),
                      onPressed: _loadingTxns
                          ? null
                          : () => _loadTxns(),
                      child: const Text('Show more'),
                    ),
                ],
              ),
            ),
        ],
      ],
    );
  }
}

String _accountLabel(Map a) =>
    a['mask'] == null ? '${a['name']}' : '${a['name']} ••${a['mask']}';

class _Empty extends StatelessWidget {
  const _Empty({required this.connect, required this.ready});

  final Widget? connect;
  final bool ready;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Icon(Icons.account_balance_outlined, size: 40, color: theme.colorScheme.primary),
            const SizedBox(height: 12),
            Text('No bank connected', style: theme.textTheme.titleMedium),
            const SizedBox(height: 6),
            Text(
              ready
                  ? 'Connect your business bank and card accounts to see balances and every '
                        'transaction here. EzBizi only reads them; it never moves money.'
                  : 'Bank connections are not set up on this installation yet.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
            if (connect != null) ...[const SizedBox(height: 16), connect!],
          ],
        ),
      ),
    );
  }
}

class _BankCard extends StatelessWidget {
  const _BankCard({
    required this.bank,
    required this.canEdit,
    required this.onReconnect,
    required this.onRefresh,
  });

  final Map bank;
  final bool canEdit;
  final VoidCallback onReconnect;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final broken = bank['status'] != 'connected';
    final accounts = (bank['accounts'] as List? ?? const []).cast<Map>();
    final reading = bank['history'] == 'NOT_READY' ||
        bank['history'] == 'INITIAL_UPDATE_COMPLETE';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 4,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.account_balance_outlined, size: 20),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        '${bank['name']}',
                        style: theme.textTheme.titleSmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
                if (canEdit)
                  broken
                      ? FilledButton.icon(
                          key: Key('financials-bank-reconnect-${bank['id']}'),
                          onPressed: onReconnect,
                          icon: const Icon(Icons.link, size: 18),
                          label: const Text('Reconnect'),
                        )
                      : TextButton.icon(
                          key: Key('financials-bank-refresh-${bank['id']}'),
                          onPressed: onRefresh,
                          icon: const Icon(Icons.refresh, size: 18),
                          label: const Text('Refresh'),
                        ),
              ],
            ),
            if (broken && bank['status_reason'] != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  '${bank['status_reason']}',
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
                ),
              ),
            if (reading)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  'Still reading up to two years of history from the bank.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            const SizedBox(height: 8),
            for (final a in accounts)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(_accountLabel(a), overflow: TextOverflow.ellipsis),
                subtitle: Text(
                  [a['subtype'] ?? a['type'], if (a['available_cents'] != null) 'available ${invoiceMoney(a['available_cents'] as num)}']
                      .whereType<Object>()
                      .join(' · '),
                ),
                trailing: Text(
                  a['current_cents'] == null ? '—' : invoiceMoney(a['current_cents'] as num),
                  style: theme.textTheme.titleSmall,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _TxnRow extends StatelessWidget {
  const _TxnRow({required this.t, this.onTap});

  final Map t;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final cents = t['amount_cents'] as num? ?? 0;
    final account = t['account'] as Map?;
    final category = '${t['category'] ?? ''}'.replaceAll('_', ' ').toLowerCase();
    return ListTile(
      dense: true,
      onTap: onTap,
      title: Text('${t['merchant_name'] ?? t['name']}', overflow: TextOverflow.ellipsis),
      subtitle: Text(
        [
          t['date'],
          if (account != null) _accountLabel(account),
          if (category.isNotEmpty) category,
          if (t['pending'] == true) 'pending',
          if (t['match'] != null) '→ ${t['match']['label']}',
        ].join(' · '),
        overflow: TextOverflow.ellipsis,
      ),
      trailing: Text(
        bankAmount(cents),
        style: theme.textTheme.bodyMedium?.copyWith(
          color: cents < 0 ? EzBiziTheme.clockIn(context) : null,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _MatchPanel extends StatefulWidget {
  const _MatchPanel({required this.api, required this.t, required this.canEdit});

  final ApiClient api;
  final Map t;
  final bool canEdit;

  @override
  State<_MatchPanel> createState() => _MatchPanelState();
}

class _MatchPanelState extends State<_MatchPanel> {
  Map? _match;
  List<Map> _candidates = const [];
  String? _error;
  bool _loading = true;
  bool _changed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/financials/bank/transactions/${widget.t['id']}/candidates');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = r.ok ? null : r.message;
      if (r.ok) {
        final data = r.body['data'] as Map;
        _match = data['match'] as Map?;
        _candidates = (data['candidates'] as List).cast<Map>();
      }
    });
  }

  Future<void> _choose(Map c) async {
    final r = await widget.api.post('/financials/bank/transactions/${widget.t['id']}/match', {'type': c['type'], 'id': c['id']});
    if (!mounted) return;
    if (!r.ok) return setState(() => _error = r.reason);
    _changed = true;
    await _load();
  }

  Future<void> _undo() async {
    final r = await widget.api.delete('/financials/bank/transactions/${widget.t['id']}/match');
    if (!mounted) return;
    if (!r.ok) return setState(() => _error = r.reason);
    _changed = true;
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SidePanel(
        title: 'What was this for?',
        subtitle: '${widget.t['merchant_name'] ?? widget.t['name']} · ${widget.t['date']} · ${bankAmount(widget.t['amount_cents'] as num? ?? 0)}',
        onClose: () => Navigator.of(context).pop(_changed),
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_error != null)
                    Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
                  if (_match != null) ...[
                    Text('Matched to', style: theme.textTheme.labelLarge),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text('${_match!['label']}'),
                      subtitle: Text(_match!['by'] == 'auto' ? 'Matched by EzBizi' : 'Matched by a person'),
                      trailing: widget.canEdit
                          ? TextButton(
                              key: const Key('financials-bank-unmatch'),
                              onPressed: _undo,
                              child: const Text('Unmatch'),
                            )
                          : null,
                    ),
                    const Divider(),
                  ],
                  Text(
                    _candidates.isEmpty
                        ? 'Nothing in EzBizi fits this deposit: no recorded payment for this amount within a week, and no open invoice for it.'
                        : 'Payments and open invoices for this amount',
                    style: theme.textTheme.bodySmall,
                  ),
                  for (final c in _candidates)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text('${c['label']}'),
                      subtitle: Text([c['detail'], c['date']].whereType<Object>().where((x) => '$x'.isNotEmpty).join(' · ')),
                      trailing: widget.canEdit
                          ? FilledButton.tonal(
                              key: Key('financials-bank-match-${c['id']}'),
                              onPressed: () => _choose(c),
                              child: const Text('Match'),
                            )
                          : null,
                    ),
                ],
              ),
    );
  }
}
