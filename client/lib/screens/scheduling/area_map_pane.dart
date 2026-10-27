import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/map_attribution.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'area_delete_panel.dart';
import 'availability_suggestion.dart' show mapTileUrl;

/// Scheduling › Settings › Service areas: every area drawn on one map, and a
/// draw tool (UI queue 125, Jose 2026-10-21: desktop 1, phone 4).
///
/// design: docs/previews/service-areas-map-layouts.html #1 (#4 at phone width)
///
/// Server (Scheduling, efbd6e645 + d69673ebf): `GET /scheduling/areas-map`
/// for the shapes, `GET /scheduling/areas/{id}/map` for one area's ZIP dots,
/// `POST …/polygons/preview` for what a drawing would change, `PUT
/// …/polygons` to save it. Points are `[lat, lng]` on the wire.
///
/// Nothing is saved while drawing: every change asks the preview (after half
/// a second of quiet) and the review on the right says what Save would add,
/// take from another area (only the ones ticked, and only where `may_take`),
/// drop, and keep. Typed ZIPs are never removed by a drawing.
class AreaMapPane extends StatefulWidget {
  const AreaMapPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  /// Below this width the map is to look at; drawing is on a bigger screen.
  static const double phoneBelow = 760;

  @override
  State<AreaMapPane> createState() => _AreaMapPaneState();
}

enum _Tool { draw, pan, erase }

class _AreaMapPaneState extends State<AreaMapPane> {
  bool _loading = true;
  String? _error;

  /// The branch whose areas are drawn; null is the whole company.
  String? _branch;
  List<Map<String, dynamic>> _areas = [];
  int _request = 0;

  String? _selectedId;
  List<Map<String, dynamic>> _zips = [];

  /// The selected area's shapes as saved, for Cancel.
  List<List<LatLng>> _saved = [];

  bool _editing = false;
  _Tool _tool = _Tool.draw;

  /// The closed shapes being edited, and the one still being drawn.
  List<List<LatLng>> _shapes = [];
  List<LatLng> _open = [];

  Timer? _debounce;
  int _previewRequest = 0;
  Map<String, dynamic>? _preview;
  final Set<String> _take = {};
  bool _saving = false;
  String? _paneError;

  bool _autoDrawing = false;
  String? _autoError;

  final _hex = TextEditingController();

  /// Recommended area (Workiz's): a circle round a centre ZIP. [_circle] is
  /// the centre and radius the editor was last loaded from, saved with Save.
  final _centreZip = TextEditingController();
  final _radius = TextEditingController();
  bool _drawingCircle = false;
  String? _circleError;
  ({String? zip, int? miles})? _circle;
  bool _savingColour = false;
  String? _colourError;

  final _map = MapController();
  bool _mapReady = false;

  Me? get _me => widget.session.me;
  bool get _mayEdit => _me?.can('scheduling.edit') ?? false;
  bool get _mayDelete => _me?.can('scheduling.delete') ?? false;

  @override
  void initState() {
    super.initState();
    final inView = _me?.franchisesInView ?? const [];
    _branch = inView.length == 1 ? inView.first.id : null;
    if (widget.visible) _load();
  }

  @override
  void didUpdateWidget(covariant AreaMapPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _hex.dispose();
    _centreZip.dispose();
    _radius.dispose();
    super.dispose();
  }

  // ── Loading ─────────────────────────────────────────────────────

  Future<void> _load() async {
    final ask = ++_request;
    setState(() {
      _loading = _areas.isEmpty;
      _error = null;
    });
    final response = await widget.api.get('/scheduling/areas-map', {
      'franchise_id': ?_branch,
    });
    if (!mounted || ask != _request) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _areas = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      if (_selectedId != null &&
          !_areas.any((a) => a['area_id'] == _selectedId)) {
        _selectedId = null;
        _zips = [];
      }
    });
    _fit();
  }

  Future<void> _select(String id) async {
    if (_editing) return;
    setState(() {
      _selectedId = id;
      _zips = [];
      _paneError = null;
      _colourError = null;
      _hex.text =
          (_areas.where((a) => a['area_id'] == id).firstOrNull?['color']
              as String?) ??
          '';
    });
    final response = await widget.api.get('/scheduling/areas/$id/map');
    if (!mounted || _selectedId != id) return;
    setState(() {
      if (!response.ok) {
        _paneError = response.reason;
        return;
      }
      final data = response.body['data'] as Map? ?? const {};
      _zips = (data['zips'] as List? ?? const []).cast<Map<String, dynamic>>();
      _saved = _shapesOf(data['polygons']);
    });
    _fit();
  }

  static List<List<LatLng>> _shapesOf(Object? polygons) => [
    for (final p in (polygons as List? ?? const []))
      [
        for (final pt in (p as List))
          LatLng(
            ((pt as List)[0] as num).toDouble(),
            (pt[1] as num).toDouble(),
          ),
      ],
  ];

  static List<List<List<double>>> _wire(List<List<LatLng>> shapes) => [
    for (final s in shapes)
      [
        for (final p in s) [p.latitude, p.longitude],
      ],
  ];

  Map<String, dynamic>? get _selected =>
      _areas.where((a) => a['area_id'] == _selectedId).firstOrNull;

  // ── Colours: per branch in the company view, per area in a branch ──

  /// The area's own colour when it has one (data, `#RRGGBB`); otherwise the
  /// branch or position colour it always had.
  Color _colour(BuildContext context, Map<String, dynamic> area) {
    final own = areaColourOf(area['color']);
    if (own != null) return own;
    if (_branch == null) {
      final branches = {
        for (final a in _areas) a['franchise_id'] as String?,
      }.toList();
      return PersonColours.hue(
        context,
        branches.indexOf(area['franchise_id'] as String?),
      ).ink;
    }
    return PersonColours.hue(context, _areas.indexOf(area)).ink;
  }

  // ── An area's own colour (PATCH …/areas/{id} {color}) ──────────

  Future<void> _setColour(String? hex) async {
    final id = _selectedId;
    if (id == null) return;
    setState(() {
      _colourError = null;
      _savingColour = true;
    });
    final response = await widget.api.patch('/scheduling/areas/$id', {
      'color': hex,
    });
    if (!mounted) return;
    setState(() {
      _savingColour = false;
      if (!response.ok) {
        _colourError = response.reason;
        return;
      }
      _areas = [
        for (final a in _areas)
          if (a['area_id'] == id) {...a, 'color': hex} else a,
      ];
      _hex.text = hex ?? '';
    });
  }

  void _submitHex(String raw) {
    final v = raw.trim();
    final hex = v.startsWith('#') ? v : '#$v';
    if (!RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(hex)) {
      setState(() => _colourError = 'A colour is # and six hex digits.');
      return;
    }
    _setColour(hex.toUpperCase());
  }

  Widget _colourPicker(BuildContext context) {
    final theme = Theme.of(context);
    final current = (_selected?['color'] as String?)?.toUpperCase();
    return Column(
      key: const ValueKey('area-colour'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('Colour', style: theme.textTheme.labelMedium),
            for (final hex in areaSwatches)
              Tooltip(
                message: hex,
                child: InkWell(
                  key: ValueKey('area-colour-$hex'),
                  customBorder: const CircleBorder(),
                  onTap: _savingColour ? null : () => _setColour(hex),
                  child: Container(
                    width: 22,
                    height: 22,
                    decoration: BoxDecoration(
                      color: areaColourOf(hex),
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: current == hex
                            ? theme.colorScheme.onSurface
                            : theme.colorScheme.outlineVariant,
                        width: current == hex ? 2.5 : 1,
                      ),
                    ),
                  ),
                ),
              ),
            SizedBox(
              width: 96,
              child: TextField(
                key: const ValueKey('area-colour-hex'),
                controller: _hex,
                enabled: !_savingColour,
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: '#RRGGBB',
                ),
                style: theme.textTheme.bodySmall,
                onSubmitted: _submitHex,
              ),
            ),
            TextButton(
              key: const ValueKey('area-colour-default'),
              onPressed: _savingColour || current == null
                  ? null
                  : () => _setColour(null),
              child: const Text('Default'),
            ),
          ],
        ),
        if (_colourError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: ErrorNote(_colourError!),
          ),
      ],
    );
  }

  // ── Auto draw (GET …/outline): loads the editor, saves nothing ───

  Future<void> _autoDraw() async {
    final id = _selectedId;
    if (id == null || !_editing) return;
    setState(() {
      _autoDrawing = true;
      _autoError = null;
    });
    final response = await widget.api.get('/scheduling/areas/$id/outline');
    if (!mounted || _selectedId != id || !_editing) return;
    var loaded = false;
    setState(() {
      _autoDrawing = false;
      if (!response.ok) {
        // 422: "This area needs at least 3 ZIP codes on the map …"
        _autoError = response.reason;
        return;
      }
      final body = response.body;
      final data = body['data'] is Map ? body['data'] as Map : body;
      final shape = _shapesOf([data['polygon'] ?? const []]).first;
      if (shape.length < 3) {
        _autoError = 'No outline came back for this area.';
        return;
      }
      // Replaces only what is being edited; Save or Cancel still decides.
      _shapes = [shape];
      _circle = null;
      _open = [];
      _tool = _Tool.draw;
      loaded = true;
    });
    if (loaded) {
      _changed();
      _fit();
    }
  }

  // ── Recommended (GET …/outline?mode=recommended): a circle ───────

  Future<void> _drawCircle() async {
    final id = _selectedId;
    if (id == null || !_editing) return;
    final zip = _centreZip.text.trim();
    final miles = int.tryParse(_radius.text.trim());
    setState(() {
      _drawingCircle = true;
      _circleError = null;
    });
    // Typed values override the stored ones, so a circle can be tried first.
    final response = await widget.api.get('/scheduling/areas/$id/outline', {
      'mode': 'recommended',
      if (zip.isNotEmpty) 'center_zip': zip,
      if (miles != null) 'radius_miles': '$miles',
    });
    if (!mounted || _selectedId != id || !_editing) return;
    var loaded = false;
    setState(() {
      _drawingCircle = false;
      if (!response.ok) {
        // 422: "Pick a centre ZIP code we know and a radius in miles."
        _circleError = response.reason;
        return;
      }
      final body = response.body;
      final data = body['data'] is Map ? body['data'] as Map : body;
      final shape = _shapesOf([data['polygon'] ?? const []]).first;
      if (shape.length < 3) {
        _circleError = 'No circle came back for this area.';
        return;
      }
      _shapes = [shape];
      _open = [];
      _tool = _Tool.draw;
      _circle = (zip: zip.isEmpty ? null : zip, miles: miles);
      loaded = true;
    });
    if (loaded) {
      _changed();
      _fit();
    }
  }

  Widget _recommended(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      key: const ValueKey('area-recommended'),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Recommended area', style: theme.textTheme.labelMedium),
          const SizedBox(height: 6),
          TextField(
            key: const ValueKey('area-recommended-zip'),
            controller: _centreZip,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              isDense: true,
              labelText: 'Centre ZIP',
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('area-recommended-miles'),
            controller: _radius,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              isDense: true,
              labelText: 'Radius (miles)',
            ),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.tonal(
              key: const ValueKey('area-recommended-draw'),
              onPressed: _drawingCircle ? null : _drawCircle,
              child: Text(_drawingCircle ? 'Drawing…' : 'Draw circle'),
            ),
          ),
          if (_circleError != null)
            Padding(
              key: const ValueKey('area-recommended-error'),
              padding: const EdgeInsets.only(top: 6),
              child: ErrorNote(_circleError!),
            ),
          const SizedBox(height: 6),
          Text(
            'Or Auto draw from ZIPs, or draw by hand on the map.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  // ── The camera ──────────────────────────────────────────────────

  void _fit() {
    if (!_mapReady || !mounted) return;
    final points = <LatLng>[
      if (_selectedId != null) ...[
        for (final s in _editing ? _shapes : _saved) ...s,
        for (final z in _zips)
          if (z['latitude'] is num && z['longitude'] is num)
            LatLng(
              (z['latitude'] as num).toDouble(),
              (z['longitude'] as num).toDouble(),
            ),
      ],
    ];
    if (points.isEmpty) {
      for (final a in _areas) {
        for (final s in _shapesOf(a['polygons'])) {
          points.addAll(s);
        }
      }
    }
    if (points.isEmpty) return;
    if (points.length == 1) {
      _map.move(points.single, 11);
      return;
    }
    _map.fitCamera(
      CameraFit.coordinates(
        coordinates: points,
        padding: const EdgeInsets.all(40),
        maxZoom: 13,
      ),
    );
  }

  // ── Drawing ─────────────────────────────────────────────────────

  void _startEditing() {
    _prefillCircle();
    _startEditingState();
  }

  /// The stored centre and radius, from the area detail, into the fields.
  Future<void> _prefillCircle() async {
    final id = _selectedId;
    if (id == null) return;
    final response = await widget.api.get('/scheduling/areas/$id', const {});
    if (!mounted || _selectedId != id || !response.ok) return;
    final data = response.body['data'];
    if (data is! Map) return;
    if (_centreZip.text.isEmpty && data['center_zip'] is String) {
      _centreZip.text = data['center_zip'] as String;
    }
    if (_radius.text.isEmpty && data['radius_miles'] is num) {
      _radius.text = '${(data['radius_miles'] as num).toInt()}';
    }
  }

  void _startEditingState() => setState(() {
    _editing = true;
    _circle = null;
    _circleError = null;
    _centreZip.clear();
    _radius.clear();
    _tool = _Tool.draw;
    _shapes = [
      for (final s in _saved) [...s],
    ];
    _open = [];
    _preview = null;
    _take.clear();
    _paneError = null;
    _autoError = null;
  });

  void _cancel() {
    _debounce?.cancel();
    _previewRequest++;
    setState(() {
      _editing = false;
      _shapes = [];
      _open = [];
      _preview = null;
      _take.clear();
      _paneError = null;
      _autoError = null;
      _circle = null;
      _circleError = null;
    });
  }

  void _tapMap(LatLng at) {
    if (!_editing || _tool != _Tool.draw) return;
    setState(() => _open = [..._open, at]);
  }

  void _closeShape() {
    if (_open.length < 3) return;
    setState(() {
      _shapes = [..._shapes, _open];
      _open = [];
    });
    _changed();
  }

  /// [shape] -1 is the open one.
  void _moveVertex(int shape, int i, Offset delta) {
    final camera = _map.camera;
    final from = shape < 0 ? _open[i] : _shapes[shape][i];
    final to = camera.screenOffsetToLatLng(
      camera.latLngToScreenOffset(from) + delta,
    );
    setState(() {
      if (shape < 0) {
        _open = [..._open]..[i] = to;
      } else {
        _shapes = [..._shapes]..[shape] = ([..._shapes[shape]]..[i] = to);
      }
    });
  }

  void _tapVertex(int shape, int i) {
    if (_tool == _Tool.erase) {
      setState(() {
        if (shape < 0) {
          _open = [..._open]..removeAt(i);
        } else {
          final left = [..._shapes[shape]]..removeAt(i);
          _shapes = [..._shapes];
          // A shape needs three corners; fewer is no shape at all.
          if (left.length < 3) {
            _shapes.removeAt(shape);
          } else {
            _shapes[shape] = left;
          }
        }
      });
      if (shape >= 0) _changed();
      return;
    }
    // Tapping the first corner of the open shape closes it.
    if (_tool == _Tool.draw && shape < 0 && i == 0) _closeShape();
  }

  /// A closed shape changed: ask the preview once the drawing settles.
  void _changed() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), _runPreview);
  }

  Future<void> _runPreview() async {
    final id = _selectedId;
    if (id == null || !_editing) return;
    final ask = ++_previewRequest;
    final response = await widget.api.post(
      '/scheduling/areas/$id/polygons/preview',
      {'polygons': _wire(_shapes)},
    );
    if (!mounted || ask != _previewRequest) return;
    setState(() {
      if (!response.ok) {
        _paneError = response.reason;
        return;
      }
      _paneError = null;
      _preview = response.body['data'] as Map<String, dynamic>?;
      final takeable = {
        for (final c in _conflicts)
          if (c['may_take'] == true) c['zip'] as String,
      };
      _take.retainAll(takeable);
    });
  }

  List<Map<String, dynamic>> get _conflicts =>
      (_preview?['conflicts'] as List? ?? const [])
          .cast<Map<String, dynamic>>();

  List<String> _list(String key) => [
    for (final z in (_preview?[key] as List? ?? const [])) '$z',
  ];

  Future<void> _save({bool clear = false}) async {
    final id = _selectedId;
    if (id == null) return;
    _debounce?.cancel();
    if (!clear && _open.length >= 3) _closeShape();
    setState(() {
      _saving = true;
      _paneError = null;
    });
    final response = await widget.api.put('/scheduling/areas/$id/polygons', {
      'polygons': clear ? const [] : _wire(_shapes),
      if (!clear) 'take': _take.toList(),
    });
    if (!mounted) return;
    if (!response.ok) {
      // The server's own sentence: "62711 is in East, which you cannot
      // change." It names the ZIP and the area to go and look at.
      setState(() {
        _saving = false;
        _paneError = response.reason;
      });
      return;
    }
    final circle = _circle;
    if (!clear && circle != null) {
      // The centre and radius the drawing came from, kept with the area.
      final kept = await widget.api.patch('/scheduling/areas/$id', {
        'center_zip': circle.zip,
        'radius_miles': circle.miles,
      });
      if (!mounted) return;
      if (!kept.ok) {
        setState(() {
          _saving = false;
          _paneError = kept.reason;
        });
        return;
      }
    }
    final data = response.body['data'] as Map? ?? const {};
    int count(String k) => (data[k] as List? ?? const []).length;
    final line = clear
        ? 'Drawing cleared: ${count('removed')} removed'
        : 'Saved: ${count('added')} added, ${count('moved')} moved, '
              '${count('removed')} removed';
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text(line)));
    setState(() {
      _saving = false;
      _editing = false;
      _preview = null;
      _take.clear();
      _shapes = [];
      _open = [];
      _circle = null;
    });
    await _load();
    if (mounted) await _select(id);
  }

  Future<void> _delete() async {
    final area = _selected;
    if (area == null) return;
    final name = area['name'] as String? ?? '';
    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => AreaDeletePanel(
        api: widget.api,
        areaId: area['area_id'] as String,
        areaName: name,
        zipCount: (area['zip_count'] as num?)?.toInt() ?? _zips.length,
        siblings: [
          for (final a in _areas)
            if (a['area_id'] != area['area_id'] &&
                a['franchise_id'] == area['franchise_id'])
              {'id': a['area_id'], 'name': a['name']},
        ],
      ),
    );
    if (result == null || !mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text(deleteResultLine(name, result))));
    setState(() {
      _selectedId = null;
      _zips = [];
      _saved = [];
    });
    await _load();
  }

  // ── Building ────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, box) => box.maxWidth < AreaMapPane.phoneBelow
            ? _phone(context)
            : _desktop(context),
      ),
    );
  }

  Widget _phone(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      key: const ValueKey('area-map-phone'),
      padding: const EdgeInsets.all(16),
      children: [
        if (_branchPicker() case final picker?) ...[
          picker,
          const SizedBox(height: 12),
        ],
        SizedBox(height: 300, child: _mapView(context, tools: false)),
        const SizedBox(height: 8),
        Text(
          'Drawing is on a computer or tablet.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        if (_mayEdit && _selected != null) ...[
          const SizedBox(height: 8),
          _colourPicker(context),
        ],
        const SizedBox(height: 8),
        ..._areaRows(context),
      ],
    );
  }

  Widget _desktop(BuildContext context) {
    final theme = Theme.of(context);
    final area = _selected;
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 260,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_branchPicker() case final picker?) ...[
                  picker,
                  const SizedBox(height: 12),
                ],
                Expanded(
                  child: ListView(
                    key: const ValueKey('area-map-list'),
                    children: _areaRows(context),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (area != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${area['name']} · ${_zipLabel(area)}',
                            style: theme.textTheme.titleSmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if (_mayEdit && !_editing)
                          OutlinedButton.icon(
                            key: const ValueKey('area-map-edit'),
                            onPressed: _startEditing,
                            icon: const Icon(Icons.draw_outlined, size: 18),
                            label: Text(
                              _saved.isEmpty ? 'Draw area' : 'Edit drawing',
                            ),
                          ),
                        if (_mayDelete && !_editing) ...[
                          const SizedBox(width: 8),
                          TextButton.icon(
                            key: const ValueKey('area-map-delete'),
                            onPressed: _delete,
                            icon: const Icon(Icons.delete_outline, size: 18),
                            label: const Text('Delete area'),
                            style: TextButton.styleFrom(
                              foregroundColor: theme.colorScheme.error,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                if (area != null && _mayEdit)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _colourPicker(context),
                  ),
                if (_paneError != null && !_editing)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: ErrorNote(_paneError!),
                  ),
                Expanded(child: _mapView(context, tools: _editing)),
              ],
            ),
          ),
          if (_editing) ...[
            const SizedBox(width: 16),
            SizedBox(width: 320, child: _review(context)),
          ],
        ],
      ),
    );
  }

  String _zipLabel(Map<String, dynamic> area) {
    final n = (area['zip_count'] as num?)?.toInt() ?? 0;
    return '$n ${n == 1 ? 'ZIP' : 'ZIPs'}';
  }

  Widget? _branchPicker() {
    final me = _me;
    if (me == null || me.franchisesInView.length < 2) return null;
    return SearchPickerField<String?>(
      key: const ValueKey('area-map-branch'),
      value: _branch,
      label: me.organization.franchiseLabel,
      options: [
        const SearchOption<String?>(value: null, label: 'All branches'),
        for (final b in branchOptionsInView(me))
          SearchOption<String?>(value: b.id, label: b.label),
      ],
      onChanged: _editing
          ? null
          : (v) {
              setState(() {
                _branch = v;
                _selectedId = null;
                _zips = [];
                _saved = [];
              });
              _load();
            },
    );
  }

  List<Widget> _areaRows(BuildContext context) {
    final theme = Theme.of(context);
    if (_areas.isEmpty) {
      return [
        Padding(
          padding: const EdgeInsets.all(8),
          child: Text(
            'No service areas here yet. Add one in Availability.',
            style: theme.textTheme.bodySmall,
          ),
        ),
      ];
    }
    return [
      for (final a in _areas)
        Material(
          color: a['area_id'] == _selectedId
              ? theme.colorScheme.primaryContainer
              : theme.colorScheme.surface,
          borderRadius: BorderRadius.circular(10),
          child: InkWell(
            key: ValueKey('area-map-row-${a['area_id']}'),
            borderRadius: BorderRadius.circular(10),
            onTap: _editing ? null : () => _select(a['area_id'] as String),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
              child: Row(
                children: [
                  Container(
                    key: ValueKey('area-map-dot-${a['area_id']}'),
                    width: 12,
                    height: 12,
                    decoration: BoxDecoration(
                      color: _colour(context, a),
                      borderRadius: BorderRadius.circular(3),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          a['name'] as String? ?? '',
                          style: theme.textTheme.bodyMedium,
                          overflow: TextOverflow.ellipsis,
                        ),
                        if (_branch == null && a['franchise_name'] != null)
                          Text(
                            a['franchise_name'] as String,
                            style: theme.textTheme.bodySmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                      ],
                    ),
                  ),
                  Text(_zipLabel(a), style: theme.textTheme.bodySmall),
                ],
              ),
            ),
          ),
        ),
    ];
  }

  Widget _mapView(BuildContext context, {required bool tools}) {
    final scheme = Theme.of(context).colorScheme;
    // The shape being edited wears the area's own colour when it has one.
    final own = areaColourOf(_selected?['color']);
    final drawing = own ?? ToneColours.of(context, Tone.caution).ink;
    final drawingOn = tools && _tool == _Tool.draw;

    var flags = InteractiveFlag.all & ~InteractiveFlag.rotate;
    if (drawingOn || (tools && _tool == _Tool.erase)) {
      // Drags move corners and taps add them; the hand tool moves the map.
      // Double-tap zoom stays: flutter_map 8.3.2 with it off (a zero
      // double-tap delay) never delivered a tap to onTap in the widget tests.
      flags &= ~InteractiveFlag.drag;
    }

    final vertices = <(int, int, LatLng)>[
      if (tools) ...[
        for (final (s, shape) in _shapes.indexed)
          for (final (i, p) in shape.indexed) (s, i, p),
        for (final (i, p) in _open.indexed) (-1, i, p),
      ],
    ];

    return ClipRRect(
      key: const ValueKey('area-map'),
      borderRadius: BorderRadius.circular(10),
      child: Stack(
        children: [
          FutureBuilder<String?>(
            future: mapTileUrl(widget.api, Theme.of(context).brightness),
            builder: (context, tiles) => FlutterMap(
              mapController: _map,
              options: MapOptions(
                backgroundColor: scheme.surfaceContainerHighest,
                initialCenter: const LatLng(39.5, -98.35),
                initialZoom: 4,
                interactionOptions: InteractionOptions(flags: flags),
                onTap: (_, at) => _tapMap(at),
                onMapReady: () {
                  _mapReady = true;
                  _fit();
                },
              ),
              children: [
                if (tiles.data != null)
                  TileLayer(
                    urlTemplate: tiles.data,
                    userAgentPackageName: 'com.ezbizi.app',
                  ),
                PolygonLayer(
                  polygons: [
                    for (final a in _areas)
                      if (!(_editing && a['area_id'] == _selectedId))
                        for (final s in _shapesOf(a['polygons']))
                          Polygon(
                            points: s,
                            color: _colour(context, a).withValues(alpha: 0.16),
                            borderColor: _colour(context, a),
                            borderStrokeWidth: a['area_id'] == _selectedId
                                ? 3
                                : 2,
                          ),
                    if (_editing)
                      for (final s in _shapes)
                        Polygon(
                          points: s,
                          color: drawing.withValues(alpha: 0.10),
                          borderColor: drawing,
                          borderStrokeWidth: 2,
                          pattern: StrokePattern.dashed(segments: const [8, 5]),
                        ),
                  ],
                ),
                if (_editing && _open.length > 1)
                  PolylineLayer(
                    polylines: [
                      Polyline(
                        points: _open,
                        color: drawing,
                        strokeWidth: 2,
                        pattern: StrokePattern.dashed(segments: const [8, 5]),
                      ),
                    ],
                  ),
                MarkerLayer(
                  markers: [
                    for (final z in _zips)
                      if (z['latitude'] is num && z['longitude'] is num)
                        Marker(
                          point: LatLng(
                            (z['latitude'] as num).toDouble(),
                            (z['longitude'] as num).toDouble(),
                          ),
                          width: 10,
                          height: 10,
                          child: Tooltip(
                            message: [z['zip'], ?z['city']].join(' · '),
                            child: Container(
                              key: ValueKey('area-map-zip-${z['zip']}'),
                              decoration: BoxDecoration(
                                color: scheme.onSurfaceVariant,
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color: scheme.surface,
                                  width: 1.5,
                                ),
                              ),
                            ),
                          ),
                        ),
                  ],
                ),
                if (tools)
                  MarkerLayer(
                    markers: [
                      for (final (s, i, p) in vertices)
                        Marker(
                          point: p,
                          width: 18,
                          height: 18,
                          child: GestureDetector(
                            key: ValueKey('area-map-vertex-$s-$i'),
                            behavior: HitTestBehavior.opaque,
                            onTap: () => _tapVertex(s, i),
                            onPanUpdate: _tool == _Tool.draw
                                ? (d) => _moveVertex(s, i, d.delta)
                                : null,
                            onPanEnd: _tool == _Tool.draw && s >= 0
                                ? (_) => _changed()
                                : null,
                            child: Container(
                              decoration: BoxDecoration(
                                color: scheme.surface,
                                shape: BoxShape.circle,
                                border: Border.all(color: drawing, width: 2.5),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                if (tiles.data != null) MapAttributionLine(api: widget.api),
              ],
            ),
          ),
          if (tools) Positioned(left: 10, top: 10, child: _toolbar(context)),
        ],
      ),
    );
  }

  Widget _toolbar(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    Widget tool(_Tool t, IconData icon, String tip) => IconButton(
      key: ValueKey('area-map-tool-${t.name}'),
      tooltip: tip,
      isSelected: _tool == t,
      visualDensity: VisualDensity.compact,
      style: IconButton.styleFrom(
        backgroundColor: _tool == t ? scheme.primaryContainer : scheme.surface,
        foregroundColor: _tool == t
            ? scheme.onPrimaryContainer
            : scheme.onSurface,
      ),
      onPressed: () => setState(() => _tool = t),
      icon: Icon(icon, size: 18),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        tool(
          _Tool.draw,
          Icons.edit_outlined,
          'Draw: tap to add corners, drag to move them',
        ),
        const SizedBox(height: 4),
        tool(_Tool.pan, Icons.pan_tool_outlined, 'Move the map'),
        const SizedBox(height: 4),
        tool(_Tool.erase, Icons.backspace_outlined, 'Remove a corner'),
        const SizedBox(height: 8),
        FilledButton.tonal(
          key: const ValueKey('area-map-close-shape'),
          onPressed: _open.length >= 3 ? _closeShape : null,
          child: const Text('Close shape'),
        ),
        const SizedBox(height: 4),
        FilledButton.tonal(
          key: const ValueKey('area-map-auto-draw'),
          onPressed: _autoDrawing ? null : _autoDraw,
          child: Text(_autoDrawing ? 'Drawing…' : 'Auto draw from ZIPs'),
        ),
        if (_autoError != null)
          Container(
            key: const ValueKey('area-map-auto-error'),
            constraints: const BoxConstraints(maxWidth: 260),
            padding: const EdgeInsets.only(top: 6),
            child: ErrorNote(_autoError!),
          ),
      ],
    );
  }

  Widget _review(BuildContext context) {
    final theme = Theme.of(context);
    final name = _selected?['name'] as String? ?? '';
    final preview = _preview;
    final adds = _list('add');
    final remove = _list('remove');
    final outside = _list('outside');
    final conflicts = _conflicts;

    Widget heading(String text) => Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 6),
      child: Text(text, style: theme.textTheme.labelMedium),
    );
    Widget chips(List<String> zips, Tone tone, String key) => Wrap(
      key: ValueKey('area-review-$key'),
      spacing: 6,
      runSpacing: 6,
      children: [for (final z in zips) _ZipChip(zip: z, tone: tone)],
    );

    return Material(
      key: const ValueKey('area-review'),
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 0),
            child: Text('$name · review', style: theme.textTheme.titleSmall),
          ),
          _recommended(context),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
              children: [
                if (preview == null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text(
                      'Tap the map to add corners, then close the shape. '
                      'What it covers shows here before anything is saved.',
                      style: theme.textTheme.bodySmall,
                    ),
                  )
                else ...[
                  heading('Adds · ${adds.length}'),
                  if (adds.isEmpty)
                    Text('Nothing new.', style: theme.textTheme.bodySmall)
                  else
                    chips(adds, Tone.ok, 'add'),
                  if (conflicts.isNotEmpty) ...[
                    heading('Belong to another area · ${conflicts.length}'),
                    for (final c in conflicts) _conflictRow(context, c),
                  ],
                  if (remove.isNotEmpty) ...[
                    heading('Would drop out · ${remove.length}'),
                    chips(remove, Tone.bad, 'remove'),
                  ],
                  if (outside.isNotEmpty) ...[
                    heading(
                      'Typed outside the drawing, kept · ${outside.length}',
                    ),
                    chips(outside, Tone.neutral, 'outside'),
                  ],
                ],
                if (_paneError != null) ...[
                  const SizedBox(height: 12),
                  ErrorNote(_paneError!),
                ],
              ],
            ),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                TextButton(
                  key: const ValueKey('area-map-clear'),
                  onPressed: _saving ? null : () => _save(clear: true),
                  child: const Text('Clear drawing'),
                ),
                TextButton(
                  key: const ValueKey('area-map-cancel'),
                  onPressed: _saving ? null : _cancel,
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  key: const ValueKey('area-map-save'),
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Save'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _conflictRow(BuildContext context, Map<String, dynamic> c) {
    final theme = Theme.of(context);
    final zip = c['zip'] as String;
    final owner = c['area_name'] as String? ?? 'another area';
    final mayTake = c['may_take'] == true;
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          _ZipChip(zip: zip, tone: Tone.caution),
          const SizedBox(width: 8),
          Expanded(
            child: mayTake
                ? Text('in $owner', style: theme.textTheme.bodySmall)
                : Text(
                    "Belongs to $owner, which you can't change",
                    key: ValueKey('area-review-locked-$zip'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
          ),
          if (mayTake) ...[
            Checkbox(
              key: ValueKey('area-review-take-$zip'),
              value: _take.contains(zip),
              onChanged: (v) => setState(() {
                if (v == true) {
                  _take.add(zip);
                } else {
                  _take.remove(zip);
                }
              }),
            ),
            Text('Take it', style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }
}

class _ZipChip extends StatelessWidget {
  const _ZipChip({required this.zip, required this.tone});

  final String zip;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    final c = ToneColours.of(context, tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        zip,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(color: c.ink),
      ),
    );
  }
}

/// Ten swatches for an area's own colour. Area colours are data the user
/// picks (stored as `#RRGGBB` on the area), not chrome.
const areaSwatches = [
  '#E5484D',
  '#F76B15',
  '#F5C518',
  '#46A758',
  '#12A594',
  '#00AEEF',
  '#3E63DD',
  '#8E4EC6',
  '#D6409F',
  '#8D8D86',
];

/// `#RRGGBB` to a colour; anything else is null, so the caller falls back.
Color? areaColourOf(Object? hex) {
  if (hex is! String || !RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(hex)) {
    return null;
  }
  return Color(int.parse(hex.substring(1), radix: 16) | 0xFF000000);
}
