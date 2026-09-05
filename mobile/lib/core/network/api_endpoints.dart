/// The API surface this client depends on.
///
/// Paths are relative to `AppConfig.apiRoot` (`<base>/api/v1`). Keeping them in
/// one file makes the contract with the Laravel backend reviewable in a single
/// diff.
class ApiEndpoints {
  const ApiEndpoints._();

  // ---------------------------------------------------------------- auth
  static const String register = '/auth/register';
  static const String login = '/auth/login';
  static const String logout = '/auth/logout';
  static const String refresh = '/auth/refresh';
  static const String me = '/me';

  // ---------------------------------------------------------- onboarding
  static const String onboardingOptions = '/onboarding/options';
  static const String onboarding = '/onboarding';

  // ----------------------------------------------------------- placement
  static const String placementStart = '/placement/start';
  static String placementNext(int sessionId) => '/placement/$sessionId/next';
  static String placementRespond(int sessionId) =>
      '/placement/$sessionId/respond';
  static String placementComplete(int sessionId) =>
      '/placement/$sessionId/complete';
  static String placementResult(int sessionId) => '/placement/$sessionId/result';

  // --------------------------------------------------- home / daily session
  static const String home = '/home';

  /// The composed session. The server decides what is in it; the client only
  /// renders the activities it is handed.
  static const String sessionNext = '/session/next';
  static String session(int id) => '/session/$id';
  static String sessionActivityComplete(int sessionId, int activityId) =>
      '/session/$sessionId/activities/$activityId/complete';
  static String sessionComplete(int id) => '/session/$id/complete';

  // --------------------------------------------------------------- lesson
  static String lesson(int id) => '/lessons/$id';
  static String exercise(int id) => '/exercises/$id';
  static String exerciseAttempt(int id) => '/exercises/$id/attempt';

  // --------------------------------------------------------------- speech
  static const String speechAttempts = '/speech/attempts';
  static String speechAttempt(int id) => '/speech/attempts/$id';

  // --------------------------------------------------------- conversation
  static const String conversationScenarios = '/conversation/scenarios';
  static const String conversationSessions = '/conversation/sessions';
  static String conversationTurns(int sessionId) =>
      '/conversation/sessions/$sessionId/turns';
  static String conversationComplete(int sessionId) =>
      '/conversation/sessions/$sessionId/complete';

  // --------------------------------------------------------------- review
  static const String reviewQueue = '/review/queue';
  static const String reviewSession = '/review/session';

  // ------------------------------------------------------------- progress
  static const String progressDashboard = '/progress/dashboard';
  static const String progressHistory = '/progress/history';

  // ----------------------------------------------------------------- exam
  static const String examTypes = '/exams/types';
  static const String examAttempts = '/exams/attempts';
  static String examAttempt(int id) => '/exams/attempts/$id';
  static String examSectionSubmit(int attemptId, int sectionId) =>
      '/exams/attempts/$attemptId/sections/$sectionId/submit';
  static String examResult(int attemptId) => '/exams/attempts/$attemptId/result';

  // --------------------------------------------------------- subscription
  static const String plans = '/subscription/plans';
  static const String subscription = '/subscription';
  static const String checkout = '/subscription/checkout';
  static const String cancelSubscription = '/subscription/cancel';

  // -------------------------------------------------------------- profile
  static const String profile = '/profile';
  static const String settings = '/settings';
}
