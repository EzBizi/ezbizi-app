import 'package:ezbizi_client/core/address.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses and builds a canonical Tasks task address', () {
    const intent = TaskAddressIntent(boardId: 'board-1', taskId: 'task-9');

    expect(Address.taskPath(intent), '/tasks/boards/board-1/tasks/task-9');
    expect(Address.taskIntent('/tasks/boards/board-1/tasks/task-9'), intent);
  });

  test('does not turn malformed Tasks paths into an intent', () {
    expect(Address.taskIntent('/tasks/boards/board-1'), isNull);
    expect(Address.taskIntent('/tasks/boards/board-1/tasks'), isNull);
    expect(Address.taskIntent('/tasks/boards//tasks/task-9'), isNull);
    expect(
      Address.taskIntent('/tasks/boards/board-1/tasks/task-9/extra'),
      isNull,
    );
  });
}
