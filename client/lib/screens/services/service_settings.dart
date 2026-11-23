import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../inventory/inventory_place_fields.dart';
import '../inventory/item_pane.dart';
import '../inventory_screen.dart';

/// Settings chip: groups and labels for this view.
class ServiceSettings extends StatefulWidget {
  const ServiceSettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<ServiceSettings> createState() => _ServiceSettingsState();
}

class _ServiceSettingsState extends State<ServiceSettings> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _groups = [];
  List<Map<String, dynamic>> _labels = [];

  @override
  void initState() {
    super.initState();
    _load();
    // A group or label added or removed by another admin: re-read quietly.
    _live = LiveChanges.of(widget.api).subscribe(
      {'service_taxonomy'},
      (_) {
        if (!_loading) return _load(quiet: true);
      },
      active: () => widget.visible && LiveChanges.visible(this)(),
    );
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant ServiceSettings oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  /// [quiet]: a live re-read; a failure keeps the lists on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _groups.isEmpty && _labels.isEmpty;
        _error = null;
      });
    }

    final groups = await widget.api.get('/services/groups');
    final labels = await widget.api.get('/services/labels');
    if (!mounted || (quiet && !(groups.ok && labels.ok))) return;

    if (!groups.ok) {
      setState(() {
        _loading = false;
        _error = groups.message;
      });
      return;
    }
    if (!labels.ok) {
      setState(() {
        _loading = false;
        _error = labels.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _groups = inventoryRows(groups.data);
      _labels = inventoryRows(labels.data);
    });
  }

  @override
  Widget build(BuildContext context) {
    return InventorySplitScaffold(
      hasPane: false,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: const PanelHeader(
        note: 'Groups and labels you can assign to a service.',
      ),
      list: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          _TaxonomyCard(
            key: const ValueKey('tour-services-groups'),
            api: widget.api,
            session: widget.session,
            title: 'Groups',
            path: '/services/groups',
            rows: _groups,
            addNoun: 'group',
            onChanged: _load,
          ),
          const SizedBox(height: 16),
          _TaxonomyCard(
            key: const ValueKey('tour-services-labels'),
            api: widget.api,
            session: widget.session,
            title: 'Labels',
            path: '/services/labels',
            rows: _labels,
            addNoun: 'label',
            onChanged: _load,
          ),
        ],
      ),
      pane: ({required showBack}) => const SizedBox.shrink(),
    );
  }
}

class _TaxonomyCard extends StatefulWidget {
  const _TaxonomyCard({
    super.key,
    required this.api,
    required this.session,
    required this.title,
    required this.path,
    required this.rows,
    required this.addNoun,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;
  final String title;
  final String path;
  final List<Map<String, dynamic>> rows;
  final String addNoun;
  final VoidCallback onChanged;

  @override
  State<_TaxonomyCard> createState() => _TaxonomyCardState();
}

class _TaxonomyCardState extends State<_TaxonomyCard> {
  final _name = TextEditingController();
  String? _companyId;
  String? _franchiseId;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _canCreate => _me.can('services.create');
  bool get _canDelete => _me.can('services.delete');
  bool get _atOrganization => _me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    final place = inventoryPlaceFromScope(_me);
    _companyId = place.companyId;
    _franchiseId = place.franchiseId;
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _add() async {
    if (_name.text.trim().isEmpty) return;
    if (_companyId == null && !_atOrganization) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(widget.path, {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'name': _name.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    _name.clear();
    setState(() => _busy = false);
    widget.onChanged();
  }

  Future<void> _delete(String id) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('${widget.path}/$id');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() => _busy = false);
    widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final nowhere = (_me.companies.isEmpty && _me.franchises.isEmpty)
        ? _me.nowhereToAct('add a ${widget.addNoun}')
        : null;
    final refused = _canCreate ? nowhere : null;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            if (widget.rows.isEmpty)
              Text('None yet.', style: Theme.of(context).textTheme.bodyMedium)
            else
              for (final row in widget.rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(row['name'] as String? ?? ''),
                  subtitle: Text(
                    [
                      inventoryOwnerLabel(row, _me.organization.name),
                      inventoryPlaceLabel(row),
                    ].join(' · '),
                  ),
                  trailing: _canDelete
                      ? IconButton(
                          tooltip: 'Delete',
                          onPressed: _busy
                              ? null
                              : () => _delete(row['id'] as String),
                          icon: const Icon(Icons.delete_outline),
                        )
                      : null,
                ),
            if (_canCreate) ...[
              const SizedBox(height: 12),
              InventoryPlaceFields(
                me: _me,
                companyId: _companyId,
                franchiseId: _franchiseId,
                enabled: !_busy,
                onCompanyChanged: (v) => setState(() {
                  _companyId = v;
                  _franchiseId = null;
                }),
                onFranchiseChanged: (v) => setState(() => _franchiseId = v),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _name,
                enabled: !_busy,
                decoration: InputDecoration(labelText: 'New ${widget.addNoun}'),
                onChanged: (_) => setState(() {}),
              ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                ErrorNote(_error!),
              ],
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerLeft,
                child: Refused(
                  reason: refused,
                  child: FilledButton(
                    onPressed:
                        refused == null &&
                            !_busy &&
                            _name.text.trim().isNotEmpty &&
                            (_companyId != null || _atOrganization)
                        ? _add
                        : null,
                    child: Text('Add ${widget.addNoun}'),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
