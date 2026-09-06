/// The API surface this client depends on.
///
/// Paths are relative to `AppConfig.apiRoot` (`<base>/api/v1`) and mirror
/// `backend/routes/api.php` and `backend/routes/api/{billing,exam,speech}.php`.
/// Keeping them in one file makes the contract reviewable in a single diff.
class ApiEndpoints {
  const ApiEndpoints._();

  // ---------------------------------------------------------------- auth
  static const String register = '/auth/register';
  static const String login = '/auth/login';
  static const String logout = '/auth/logout';
  static const String me = '/auth/me';
  static const String forgotPassword = '/auth/forgot-password';

  /// Sanctum personal access tokens do not expire on their own, so the server
  /// deliberately has no refresh route: a 401 means the token was revoked and
  /// the only correct response is to sign in again. The constant stays so the
  /// interceptor has one place to point at if refresh is ever introduced.
  static const String refresh = '/auth/refresh';

  // ----------------------------------------------------------- placement
  static const String placementStart = '/placement/start';
  static String placementNext(int sessionId) => '/placement/$sessionId/next';
  static String placementSubmit(int sessionId) =>
      '/placement/$sessionId/submit';
  static String placementResult(int sessionId) => '/placement/$sessionId/result';

  // --------------------------------------------------- home / daily session
  /// The composed session. The server decides what is in it; the client only
  /// renders the activities it is handed.
  static const String sessionNext = '/session/next';
  static const String sessionStart = '/session/start';
  static String session(int id) => '/session/$id';
  static String sessionActivityComplete(int sessionId, int activityId) =>
      '/session/$sessionId/activities/$activityId/complete';
  static String sessionComplete(int id) => '/session/$id/complete';

  // ------------------------------------------------------ course / lesson
  static const String courses = '/courses';
  static String course(int id) => '/courses/$id';
  static String unit(int id) => '/units/$id';
  static String lesson(int id) => '/lessons/$id';
  static String exercise(int id) => '/exercises/$id';
  static String exerciseHint(int id) => '/exercises/$id/hint';
  static String exerciseSubmit(int id) => '/exercises/$id/submit';

  // --------------------------------------------------------------- review
  static const String reviewsDue = '/reviews/due';
  static const String reviewCounts = '/reviews/counts';

  // ------------------------------------------------------------- progress
  /// Feeds both the dashboard and the home screen — every counter the learner
  /// sees is computed here.
  static const String progressDashboard = '/progress/dashboard';
  static const String progressSkills = '/progress/skills';
  static const String progressHistory = '/progress/history';
  static const String progressTrend = '/progress/trend';

  // --------------------------------------------------------------- speech
  static const String speechAttempts = '/speech/attempts';
  static String speechAttempt(int id) => '/speech/attempts/$id';
  static String speechRecording(int id) => '/speech/attempts/$id/recording';
  static const String pronunciationProfile = '/speech/profile';
  static const String pronunciationDrills = '/speech/profile/drills';

  // ----------------------------------------------------------------- exam
  static const String examTypes = '/exams/types';
  static String examType(int id) => '/exams/types/$id';
  static const String examAttempts = '/exams/attempts';
  static String examAttempt(int id) => '/exams/attempts/$id';
  static String examNextTask(int attemptId) =>
      '/exams/attempts/$attemptId/next-task';
  static String examTaskResponse(int attemptId, int taskId) =>
      '/exams/attempts/$attemptId/tasks/$taskId/response';
  static String examFinish(int attemptId) => '/exams/attempts/$attemptId/finish';
  static String examResults(int attemptId) =>
      '/exams/attempts/$attemptId/results';
  static const String examProgress = '/exams/progress';

  // -------------------------------------------------------------- billing
  static const String plans = '/billing/plans';
  static String plan(String code) => '/billing/plans/$code';
  static const String subscription = '/billing/subscription';
  static const String checkout = '/billing/checkout';
  static const String cancelSubscription = '/billing/subscription/cancel';
  static const String resumeSubscription = '/billing/subscription/resume';
  static const String invoices = '/billing/invoices';

  // -------------------------------------------------------------- profile
  static const String profile = '/profile';
  static const String settings = '/profile/settings';
  static const String avatar = '/profile/avatar';
  static const String requestExport = '/profile/export';
  static const String requestDeletion = '/profile/delete';

  // ---------------------------------------------------------------- admin
  // Gated server-side on an admin, editor or reviewer role; the router hides
  // the screens from everyone else so the calls are never made.
  static const String adminCurriculumBooks = '/admin/curriculum/books';
  static String adminCurriculumLessons(int bookId) =>
      '/admin/curriculum/books/$bookId/lessons';
  static String adminCurriculumPublish(int bookId) =>
      '/admin/curriculum/books/$bookId/publish';
  static String adminCurriculumWithdraw(int bookId) =>
      '/admin/curriculum/books/$bookId/withdraw';
  static String adminCurriculumLesson(int lessonId) =>
      '/admin/curriculum/lessons/$lessonId';
  static const String adminIngestionSummary = '/admin/ingestion/summary';
  static const String adminReviewQueue = '/admin/content/queue';
  static const String adminAiOverview = '/admin/ai/overview';

  // ------------------------------------------------- awaiting the backend
  // These two features are built client-side against the shapes documented in
  // their models, but the routes do not exist on the server yet. Calls fail
  // with a normal 404 -> ApiException, which the screens render as an error
  // rather than crashing.
  static const String onboardingOptions = '/onboarding/options';
  static const String onboarding = '/onboarding';
  static const String conversationScenarios = '/conversation/scenarios';
  static const String conversationSessions = '/conversation/sessions';
  static String conversationSession(int id) => '/conversation/sessions/$id';
  static String conversationTurns(int sessionId) =>
      '/conversation/sessions/$sessionId/turns';
  static String conversationComplete(int sessionId) =>
      '/conversation/sessions/$sessionId/complete';
}
