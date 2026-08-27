import 'dart:async';

import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

class TaskQuickSearch extends StatefulWidget {
  const TaskQuickSearch({
    super.key,
    required this.api,
    required this.onOpen,
    this.onUnavailable,
    this.debounce = const Duration(milliseconds: 300),
  });

  final TaskApi api;
  final ValueChanged<TaskQuickSearchResult> onOpen;
  final VoidCallback? onUnavailable;
  final Duration debounce;

  @override
  State<TaskQuickSearch> createState() => _TaskQuickSearchState();
}

class _TaskQuickSearchState extends State<TaskQuickSearch> {
  final _controller = TextEditingController();
  Timer? _timer;
  int _requestToken = 0;
  bool _loading = false;
  String? _error;
  List<TaskQuickSearchResult> _results = const [];

  @override
  void dispose() {
    _requestToken++;
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _changed(String value) {
    _timer?.cancel();
    final token = ++_requestToken;
    final query = value.trim();
    if (query.length < 2) {
      setState(() {
        _loading = false;
        _error = null;
        _results = const [];
      });
      return;
    }
    _timer = Timer(widget.debounce, () => _search(query, token));
  }

  Future<void> _search(String query, int token) async {
    if (!mounted || token != _requestToken) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    final result = await widget.api.quickSearch(query);
    if (!mounted || token != _requestToken) return;
    if (result is TaskApiUnavailable<List<TaskQuickSearchResult>>) {
      widget.onUnavailable?.call();
      return;
    }
    setState(() {
      _loading = false;
      if (result is TaskApiSuccess<List<TaskQuickSearchResult>>) {
        _results = result.data;
        _error = null;
      } else {
        _results = const [];
        _error = result.message;
      }
    });
  }

  void _open(TaskQuickSearchResult result) {
    _timer?.cancel();
    _requestToken++;
    _controller.clear();
    setState(() {
      _results = const [];
      _error = null;
    });
    widget.onOpen(result);
  }

  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Semantics(
        textField: true,
        label: 'Search all tasks',
        child: TextField(
          controller: _controller,
          decoration: InputDecoration(
            hintText: 'Search tasks',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: _loading
                ? const Padding(
                    padding: EdgeInsets.all(12),
                    child: SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : null,
          ),
          onChanged: _changed,
        ),
      ),
      if (_error != null)
        Padding(
          padding: const EdgeInsets.all(8),
          child: Text(
            _error!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ),
      for (final result in _results)
        ListTile(
          dense: true,
          title: Text(result.title),
          subtitle: Text('${result.number} · ${result.boardName}'),
          onTap: () => _open(result),
        ),
    ],
  );
}
