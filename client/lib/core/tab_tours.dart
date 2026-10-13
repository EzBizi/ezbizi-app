/// Each tab's walkthrough (UI queue 81; Jose: "as u recommend" — 1 and 4).
///
/// Data, not code: a tab's owner adds or rewrites its steps here and the
/// engine in `widgets/tab_help.dart` does the rest. A step points at the
/// widget carrying `ValueKey<String>(anchor)`. A step whose widget is not on
/// screen (a role without it, a module switched off) is skipped, so a tour
/// never points at nothing.
///
/// Raise [Tour.version] when steps are added, and mark the new ones with
/// [TourStep.since]: somebody who saw the old version is offered only those,
/// as "What's new here".
class TourStep {
  const TourStep({
    required this.anchor,
    required this.title,
    required this.body,
    this.since = 1,
  });

  final String anchor;
  final String title;

  /// One or two sentences: what the part is for, in the person's words.
  final String body;

  /// The [Tour.version] that added this step.
  final int since;
}

class Tour {
  const Tour({required this.version, required this.steps});

  final int version;
  final List<TourStep> steps;
}

/// By module key, as `module_screens.dart` keys its screens.
const Map<String, Tour> tabTours = {
  'tasks': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tasks-boards',
        title: 'Your boards',
        body:
            'My board is yours alone. The franchise and company boards in '
            'this view sit beside it, each with how many open tasks are yours.',
      ),
      TourStep(
        anchor: 'tasks-view',
        title: 'Table or Kanban',
        body:
            'The same tasks, as a table by group or as a column for each '
            'status.',
      ),
      TourStep(
        anchor: 'tasks-search',
        title: 'Find a task',
        body:
            'Searches the task, who owns it, its status and what it is about.',
      ),
      TourStep(
        anchor: 'tasks-groups',
        title: 'Groups and tasks',
        body:
            'Click a group to fold it, and Add task at its foot to add one '
            'there. Click a status to change it, or a task to open it.',
      ),
    ],
  ),
};
