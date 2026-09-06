/// Display-only mapping from the backend's logit ability scale onto 0..1.
///
/// This is *not* a mastery calculation: mastery, CEFR levels and confidence all
/// arrive from the server already decided. This exists solely because a radar
/// chart needs a fraction of a radius, and it is deliberately the only place in
/// the app that touches an ability number.
///
/// The bounds match `cefr_levels.ability_min/max` for A1 → C2 in
/// `ReferenceDataSeeder`; anything outside is clamped rather than extrapolated.
class SkillScale {
  const SkillScale._();

  static const double minAbility = -2.5;
  static const double maxAbility = 2.5;

  static double normalise(double ability) {
    if (ability.isNaN) return 0;
    const span = maxAbility - minAbility;
    return ((ability - minAbility) / span).clamp(0.0, 1.0);
  }
}
