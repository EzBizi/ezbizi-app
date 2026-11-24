import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/connect_account.dart';
import '../../widgets/list_search.dart';
import '../../widgets/search_picker.dart';
import '../email/consent_window.dart';
import 'financial_cells.dart';

String _money(num cents) =>
    formatCell(const {'type': 'currency', 'options': {}}, cents / 100);

/// A transaction amount as people read a statement: Plaid's positive is money
/// out, so it shows with a minus; money in shows with a plus.
String bankAmount(num cents) =>
    cents > 0 ? '−${_money(cents)}' : '+${_money(-cents)}';

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
    final r = await widget.api.get('/financials/bank');
    if (!mounted) return;
    setState(() {
      _data = r.ok ? r.body['data'] as Map : null;
      _error = r.ok ? null : r.message;
    });
    await _loadTxns(reset: true);
  }

  Future<void> _loadTxns({bool reset = false}) async {
    if (reset) _page = 1;
    setState(() => _loadingTxns = true);
    final r = await widget.api.get('/financials/bank/transactions', {
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      'account_id': ?_accountId,
      if (_direction != 'all') 'direction': _direction,
      'page': '$_page',
    });
    if (!mounted) return;
    setState(() {
      _loadingTxns = false;
      _txnError = r.ok ? null : r.message;
      if (r.ok) {
        final rows = (r.body['data'] as List).cast<Map>();
        _txns = reset ? rows : [..._txns, ...rows];
        _lastPage = (r.body['meta']?['last_page'] as num?)?.toInt() ?? 1;
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
    _watch?.cancel();
    _watch = Timer.periodic(const Duration(seconds: 3), (t) async {
      if (--asks <= 0 || !mounted) return t.cancel();
      await _load();
      final now = (_data?['banks'] as List? ?? const [])
          .cast<Map>()
          .where((b) => b['id'] == bank['id']);
      if (now.isNotEmpty && now.first['status'] == 'connected') t.cancel();
    });
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
          if (_txnError != null)
            Text(_txnError!, style: TextStyle(color: theme.colorScheme.error))
          else if (_txns.isEmpty && !_loadingTxns)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                _search.text.isEmpty && _accountId == null && _direction == 'all'
                    ? 'No transactions yet. A newly connected bank can take a few minutes to send its history.'
                    : 'No transactions match.',
                textAlign: TextAlign.center,
              ),
            )
          else
            Card(
              child: Column(
                children: [
                  for (final t in _txns) _TxnRow(t: t),
                  if (_page < _lastPage)
                    TextButton(
                      key: const Key('financials-bank-more'),
                      onPressed: _loadingTxns
                          ? null
                          : () {
                              _page += 1;
                              _loadTxns();
                            },
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
                  [a['subtype'] ?? a['type'], if (a['available_cents'] != null) 'available ${_money(a['available_cents'] as num)}']
                      .whereType<Object>()
                      .join(' · '),
                ),
                trailing: Text(
                  a['current_cents'] == null ? '—' : _money(a['current_cents'] as num),
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
  const _TxnRow({required this.t});

  final Map t;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final cents = t['amount_cents'] as num? ?? 0;
    final account = t['account'] as Map?;
    final category = '${t['category'] ?? ''}'.replaceAll('_', ' ').toLowerCase();
    return ListTile(
      dense: true,
      title: Text('${t['merchant_name'] ?? t['name']}', overflow: TextOverflow.ellipsis),
      subtitle: Text(
        [
          t['date'],
          if (account != null) _accountLabel(account),
          if (category.isNotEmpty) category,
          if (t['pending'] == true) 'pending',
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
