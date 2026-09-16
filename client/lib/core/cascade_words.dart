/// The three sentences the cascade is allowed to say, in one place.
///
/// `Set here` · `Inherited from {name}` · `EzBizi default`.
///
/// ── Why this file exists, and why it did not until today ─────────
///
/// These words lived in `settings_screen.dart` because that screen was the only
/// thing that said them. When the sending-identity panel needed them it took
/// them from there rather than writing its own, and the comment above them has
/// carried the reason ever since: a card saying "inherited" differently from
/// the rows beside it is the confusion that screen exists to prevent.
///
/// On 2026-09-15 the mail-server card arrived on the same screen with its own
/// copy — honestly reasoned ("reaching into another screen's helpers would be
/// worse than a small duplication"), and identical today. Identical today is
/// the whole problem: two copies of a user-facing sentence do not drift on the
/// day they are written, they drift on the day somebody improves one of them,
/// and both are drawn ON THE SAME SCREEN where the difference is visible in one
/// glance.
///
/// So the words move out rather than either caller reaching into the other.
///
/// ── What is deliberately NOT here ────────────────────────────────
///
/// `sourceIsOwn` and `notInEffectSentence` stayed in `settings_screen.dart`.
/// They have one caller each and moving them would be churn: this file exists
/// because two things say these three sentences, not because a shared file is
/// tidier.
///
/// ── The PRIMITIVE, not the payload ───────────────────────────────
///
/// It takes a name and a flag rather than a map, because the two callers
/// receive different shapes for the same fact — the settings rows carry a
/// nested `source` object, the mail-server card a flat `source_name` — and
/// picking one would have forced the other to reshape a response to borrow a
/// sentence. Each caller unpacks its own answer; only the WORDS are shared.
library;

/// What a value's provenance is called, in the cascade's own words.
///
/// [sourceName] is null when nothing is set anywhere in this organization.
/// That is a different fact from inheriting a value somebody here chose, and
/// the third sentence is not a rounding of the second: "EzBizi default" means
/// nobody has answered the question, "Inherited from Everdoors" means somebody
/// did, above you.
String provenanceWords(String? sourceName, {required bool inherited}) {
  if (sourceName == null) return 'EzBizi default';

  return inherited ? 'Inherited from $sourceName' : 'Set here';
}
