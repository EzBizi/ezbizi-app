import '../core/phone_numbers.dart';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/data_transfer.dart';
import '../widgets/error_note.dart';
import '../widgets/franchise_field.dart';
import '../widgets/refused.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';
import 'customers/customer_detail_panel.dart';

/// Customers (plan §5.2).
///
/// The owning-franchise tag is on every row, deliberately. Its whole job is to
/// stop somebody acting on another franchise's customer without realising, so it
/// has to be visible BEFORE they act — not buried in a detail pane they may
/// never open.
///
/// It names the COMPANY as well as the franchise, for the same reason it exists
/// at all. A franchise name does not identify a franchise: two companies in one
/// organization may each have a Florida, and a row tagged "Florida" answers
/// nothing about which one — which is the failure the tag was put there to
/// prevent, quietly restored.
///
/// Said on every row rather than only in the organization view, because the
/// view is not what decides how wide the list is. A SEARCH escapes the view
/// scope on purpose (CustomersController::index — a customer nobody can find is
/// a customer somebody duplicates), so a franchise view searching for "Philip"
/// gets results from across the organization. A rule that showed the company
/// only in the organization view would drop it exactly there.
///
/// Importing and exporting customers is HERE rather than on a tab of its own
/// (§12). It used to be one "Import & export" destination listing every
/// resource, which meant somebody who wanted their customer list in a
/// spreadsheet had to leave the customers. See [DataTransfer].
class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _customers = [];
  int _total = 0;
  // Page one only, until asked. The header used to claim a total the list did
  // not render, with no next-page control and nothing saying records were
  // hidden — which reads as data loss rather than as pagination.
  int _page = 1;
  int _lastPage = 1;

  // A failed NEXT page is a different thing from a failed FIRST page, and
  // collapsing them meant one bad request blanked every row already on screen
  // and dropped the reader back to the top of a list they were halfway down.
  String? _pageError;

  // One request at a time, whichever page it is for.
  //
  // This used to guard the append alone, which was enough while a page-one
  // refetch covered the list with a spinner — the "Load more" control went with
  // it, so nothing could ask for page four mid-refresh. Now that a refresh
  // leaves the rows on screen that control stays reachable, and page one
  // REPLACES the list where page four APPENDS to it: whichever answer lands
  // second wins, and the other page's rows are either lost or shown twice.
  bool _fetching = false;

  /// Which request is the current one.
  ///
  /// A page-one load must be allowed to SUPERSEDE another page-one load rather
  /// than be dropped. Refusing it outright — which a plain in-flight guard does
  /// — loses the newer intent permanently: clearing the search box while the
  /// search is still in flight left the box empty and the list still filtered,
  /// with nothing scheduled to correct it.
  ///
  /// So every load takes a ticket, and a response that is no longer holding the
  /// current one is discarded. That also fixes the out-of-order case, where the
  /// slower of two answers lands last and wins.
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    // A second tap while the first request is in flight appends the same page
    // twice, so the list grows with duplicate rows.
    // Appends only. Two appends at once duplicate rows, and an append landing
    // during a page-one refresh appends to a list that is being replaced. A
    // page-one load is never refused — it supersedes instead, via _request.
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. A search,
      // a cleared search and the refetch after creating a customer all come
      // through here with rows already on screen, and those rows stay until the
      // new ones arrive. A later page never blanks anything either way.
      _loading = page == 1 && _customers.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/customers', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    });

    // Superseded while this was in flight — a newer load owns the screen now,
    // and applying this answer would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        // A first-page failure takes over the screen. Any rows still in hand
        // are from before whatever the write did, and a refusal to re-read them
        // is not something to whisper — the retry has to be the obvious next
        // thing. A later page failing leaves page one intact, so that one
        // reports beside the rows rather than over them.
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _customers = page == 1 ? rows : [..._customers, ...rows];

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _customers.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    // Two gates, two remedies, and this screen tested neither out loud: the
    // button was disabled on reach alone, silently, and `customers.create` was
    // never consulted at all. See jobs_screen for the same pair.
    final refused =
        me.refusal('customers.create', 'add a customer') ??
        (me.franchises.isEmpty
            ? me.nowhereToAct(
                'add a customer',
                // Why a customer needs one at all, which is the part somebody
                // reasonably expects not to be true: customers are shared
                // organization-wide (§5.2) and a reader can be forgiven for
                // assuming a shared record needs no place.
                note: 'A ${label.toLowerCase()} has to be accountable for the '
                    'relationship.',
              )
            : null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Customers',
          note: (_loading || _error != null)
              ? 'Shared across the organization — the $label tag shows who is accountable.'
              : _customers.length < _total
              ? 'Showing ${_customers.length} of $_total shared across the '
                    'organization. The $label tag shows who is accountable.'
              : '$_total shared across the organization. The $label tag shows who is '
                    'accountable for each relationship.',
          // A Wrap rather than a Row: on a narrow window — and on a phone, which
          // is coming — three controls side by side either overflow or squeeze
          // the labels out. They fall onto a second line instead, in the same
          // order.
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Transfer sits before the primary action rather than after it,
              // so "Add customer" stays the last and most prominent thing in
              // the header.
              DataTransfer(
                api: widget.api,
                session: widget.session,
                resource: 'customers',
                // An import writes rows this list is showing, so it has to be
                // read again — otherwise the count in the note above is a
                // number the screen has already been told is wrong.
                onImported: _load,
              ),
              Refused(
                // The server decides in the end. This decides what is OFFERED,
                // and a control that cannot be used stays on screen saying why
                // rather than going dark or disappearing.
                reason: refused,
                child: FilledButton.icon(
                  onPressed: refused == null ? _create : null,
                  icon: const Icon(Icons.person_add_outlined, size: 18),
                  label: const Text('Add customer'),
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
          child: TextField(
            controller: _search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name, email, phone or number',
              suffixIcon: _search.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.clear),
                      tooltip: 'Clear the search',
                      onPressed: () {
                        _search.clear();
                        _load();
                      },
                    ),
            ),
            onSubmitted: (_) => _load(),
            onChanged: (_) => setState(() {}),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _customers.isEmpty
                ? Center(
                    child: Text(
                      _search.text.trim().isEmpty
                          ? 'No customers yet.'
                          // "No customers yet" after a search reads as data loss.
                          : 'Nothing matches "${_search.text.trim()}".',
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _customers.length + (_page < _lastPage ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => i == _customers.length
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (_pageError != null) ...[
                                  ErrorNote(_pageError!),
                                  const SizedBox(height: 8),
                                ],
                                TextButton(
                                  onPressed: _fetching
                                      ? null
                                      : () => _load(page: _page + 1),
                                  child: Text(
                                    _fetching
                                        ? 'Loading…'
                                        : _pageError != null
                                        ? 'Try again'
                                        : 'Load more (${_total - _customers.length} left)',
                                  ),
                                ),
                              ],
                            ),
                          )
                        : _row(context, _customers[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> customer) {
    final owner = customer['owning_franchise'] as Map<String, dynamic>;
    final jobs = customer['jobs_count'] as int? ?? 0;

    // "EzBizi · Florida", or just the franchise on a server that does not send
    // the company yet — the older shape reads as it always did rather than as
    // a row with something missing from it.
    final company = (owner['company'] as Map<String, dynamic>?)?['name'];
    final franchise = owner['name'] as String? ?? 'Unassigned';
    final ownerLabel = company is String && company.isNotEmpty
        ? '$company · $franchise'
        : franchise;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        onTap: () => _open(customer),
        title: Row(
          children: [
            Flexible(
              child: Text(
                customer['name'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            // §5.2: visible in the list, not only on the detail screen.
            //
            // Flexible because the label now carries two names, and a Row hands
            // a non-flex child unbounded width: an organization with a company
            // called something long would otherwise push the row off the right
            // edge of a phone rather than shortening the tag.
            Flexible(child: Tag(ownerLabel)),
            if (customer['is_active'] == false)
              const Tag('Inactive', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              customer['number'],
              if (customer['phone'] != null)
                formatPhoneNumber(customer['phone'] as String?),
              if (customer['email'] != null) customer['email'],
              '$jobs ${jobs == 1 ? 'job' : 'jobs'}',
            ].join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      ),
    );
  }

  Future<void> _create() async {
    final created = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) =>
          _NewCustomerPanel(api: widget.api, session: widget.session),
    );

    if (created == null || !mounted) return;

    final duplicates = created['possible_duplicates'] as List? ?? [];

    if (duplicates.isNotEmpty) {
      // Reported, never merged (§5.2). Automatic merging of customer records is
      // unrecoverable when it is wrong, so this is a notice for a human.
      await showSidePanel<void>(
        context,
        builder: (context) => SidePanel(
          title: 'Possible duplicate',
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Got it'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Saved. These existing customers look similar — nothing has been '
                'merged, and nothing will be automatically.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
              ...duplicates.map((d) {
                final dup = d as Map<String, dynamic>;
                final owner = dup['owning_franchise'] as Map<String, dynamic>;
                return ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('${dup['name']} (${dup['number']})'),
                  subtitle: Text(
                    '${dup['phone'] != null ? formatPhoneNumber(dup['phone'] as String?) : (dup['email'] ?? '')} · ${owner['name'] ?? ''}',
                  ),
                );
              }),
            ],
          ),
        ),
      );
    }

    if (mounted) _load();
  }

  Future<void> _open(Map<String, dynamic> customer) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => CustomerDetailPanel(
        api: widget.api,
        session: widget.session,
        id: customer['id'] as String,
      ),
    );

    if (changed == true && mounted) _load();
  }
}

class _NewCustomerPanel extends StatefulWidget {
  const _NewCustomerPanel({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewCustomerPanel> createState() => _NewCustomerPanelState();
}

class _NewCustomerPanelState extends State<_NewCustomerPanel> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();

  String? _franchiseId;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // The current view, not every franchise this person can ever reach.
    // Standing in A-1 Chimney must not offer Everdoors' Connecticut.
    final reachable = widget.session.me!.franchisesInView;
    if (reachable.length == 1) _franchiseId = reachable.first.id;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _line1.dispose();
    _line2.dispose();
    _city.dispose();
    _state.dispose();
    _postal.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/customers', {
      'owning_franchise_id': _franchiseId,
      'name': _name.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': toE164(_phone.text),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
      if (_line1.text.trim().isNotEmpty) 'address_line1': _line1.text.trim(),
      if (_line2.text.trim().isNotEmpty) 'address_line2': _line2.text.trim(),
      if (_city.text.trim().isNotEmpty) 'city': _city.text.trim(),
      if (_state.text.trim().isNotEmpty)
        'state': _state.text.trim().toUpperCase(),
      if (_postal.text.trim().isNotEmpty) 'postal_code': _postal.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(response.body);
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    return SidePanel(
      title: 'Add customer',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || _franchiseId == null) ? null : _submit,
          child: const Text('Create'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FranchiseField(
            me: me,
            value: _franchiseId,
            label: 'Owning $label',
            helperText:
                'Accountable for the relationship, and counts them '
                'in their numbers. Changing it later is a separate, audited step.',
            enabled: !_busy,
            onChanged: (v) => setState(() => _franchiseId = v),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
            autofocus: true,
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _phone,
            decoration: const InputDecoration(labelText: 'Phone'),
            keyboardType: TextInputType.phone,
            inputFormatters: const [PhoneNumberInputFormatter()],
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
            keyboardType: TextInputType.emailAddress,
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _line1,
            decoration: const InputDecoration(labelText: 'Street address'),
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _line2,
            decoration: const InputDecoration(
              labelText: 'Apartment, suite, etc.',
            ),
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _city,
            decoration: const InputDecoration(labelText: 'City'),
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _state,
                  decoration: const InputDecoration(
                    labelText: 'State',
                    helperText: 'Two letters.',
                  ),
                  textCapitalization: TextCapitalization.characters,
                  enabled: !_busy,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  controller: _postal,
                  decoration: const InputDecoration(labelText: 'ZIP'),
                  enabled: !_busy,
                ),
              ),
            ],
          ),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
