import 'package:freezed_annotation/freezed_annotation.dart';

part 'onboarding_options.freezed.dart';
part 'onboarding_options.g.dart';

/// The choices offered during onboarding, supplied by the backend so the list
/// of interface languages and goals is not hard-coded in the app.
@freezed
abstract class OnboardingOptions with _$OnboardingOptions {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory OnboardingOptions({
    @Default(<LanguageOption>[]) List<LanguageOption> interfaceLanguages,
    @Default(<LanguageOption>[]) List<LanguageOption> targetLanguages,
    @Default(<GoalOption>[]) List<GoalOption> goals,
    @Default(<int>[5, 10, 15, 20, 30, 45]) List<int> dailyTargets,
    @Default(15) int defaultDailyTarget,
  }) = _OnboardingOptions;

  factory OnboardingOptions.fromJson(Map<String, dynamic> json) =>
      _$OnboardingOptionsFromJson(json);
}

@freezed
abstract class LanguageOption with _$LanguageOption {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory LanguageOption({
    required String code,
    required String name,
    String? nativeName,
    @Default('ltr') String direction,
  }) = _LanguageOption;

  factory LanguageOption.fromJson(Map<String, dynamic> json) =>
      _$LanguageOptionFromJson(json);
}

@freezed
abstract class GoalOption with _$GoalOption {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory GoalOption({
    required String code,
    required String label,
    String? description,
  }) = _GoalOption;

  factory GoalOption.fromJson(Map<String, dynamic> json) =>
      _$GoalOptionFromJson(json);
}
