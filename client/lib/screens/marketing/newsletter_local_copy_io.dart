/// Off the web the composer's local copy lives for the running session.
final Map<String, String> _memory = {};

String? read(String key) => _memory[key];

void write(String key, String? value) {
  if (value == null) {
    _memory.remove(key);
  } else {
    _memory[key] = value;
  }
}
