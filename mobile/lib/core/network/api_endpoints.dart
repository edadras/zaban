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
  static const String speechCoachChat = '/speech/coach-chat';
  static String speechCoachChatSession(int id) => '/speech/coach-chat/$id';
  static String speechCoachChatRespond(int id) =>
      '/speech/coach-chat/$id/respond';
  static String speechCoachChatFinish(int id) =>
      '/speech/coach-chat/$id/finish';

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
  static const String manualPayInstructions = '/billing/manual/instructions';
  static const String manualPaySubmissions = '/billing/manual/submissions';
  static String manualPaySubmission(int id) =>
      '/billing/manual/submissions/$id';
  static String manualPayReceipt(int id) =>
      '/billing/manual/submissions/$id/receipt';

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
  static const String adminUsers = '/admin/users';
  static String adminUser(int id) => '/admin/users/$id';
  static const String adminBillingOverview = '/admin/billing/overview';
  static const String adminManualPayments = '/admin/billing/manual-payments';
  static String adminManualPaymentApprove(int id) =>
      '/admin/billing/manual-payments/$id/approve';
  static String adminManualPaymentReject(int id) =>
      '/admin/billing/manual-payments/$id/reject';
  static String adminManualPaymentReceipt(int id) =>
      '/admin/billing/manual-payments/$id/receipt';
  static const String adminRialSettings = '/admin/billing/rial-settings';

  // -------------------------------------------------------- conversation
  static const String conversationScenarios = '/conversation/scenarios';
  static const String conversationStart = '/conversation/start';
  static String conversationSession(int id) => '/conversation/$id';
  static String conversationRespond(int sessionId) =>
      '/conversation/$sessionId/respond';
  static String conversationFinish(int sessionId) =>
      '/conversation/$sessionId/finish';

  // ------------------------------------------------------- acted scenes
  /// Conversation practice with a room around it. Starting a run answers with
  /// the whole scene plus a signed link to the page that draws it.
  static const String scenes = '/conversation/scenes';
  static const String sceneStart = '/conversation/scenes/start';
  static String scene(int id) => '/conversation/scenes/$id';
  static String sceneSession(int id) => '/conversation/scene-sessions/$id';
  static String sceneFinish(int id) =>
      '/conversation/scene-sessions/$id/finish';

  // ----------------------------------------------------------- onboarding
  static const String onboardingOptions = '/onboarding/options';
  static const String onboarding = '/onboarding';

  // ------------------------------------------------------ the live classroom
  /// The learner's side. `routes/api/classroom.php`; the coach's and the
  /// administrator's side is the web panel rather than this client.
  static const String myClasses = '/my/classes';
  static const String myClassHistory = '/my/classes/history';

  static String room(int sessionId) => '/class-sessions/$sessionId/room';
  static String roomJoin(int sessionId) => '/class-sessions/$sessionId/room/join';
  static String roomLeave(int sessionId) =>
      '/class-sessions/$sessionId/room/leave';
  static String roomToken(int sessionId) =>
      '/class-sessions/$sessionId/room/token';
  static String roomHand(int sessionId) => '/class-sessions/$sessionId/room/hand';
  static String roomChat(int sessionId) => '/class-sessions/$sessionId/room/chat';
  static String classRecording(int sessionId) =>
      '/class-sessions/$sessionId/recording';
  static String roomAnswer(int sessionId, int questionId) =>
      '/class-sessions/$sessionId/room/questions/$questionId/answer';

  /// A short-lived signed playback URL for one asset. Materials reference
  /// media by id, never by path, so storage cannot be enumerated.
  static String media(int id) => '/media/$id';

  // -------------------------------------------------------- the class board
  static String threads(int groupId) => '/classes/$groupId/threads';
  static String thread(int threadId) => '/threads/$threadId';
  static String threadReplies(int threadId) => '/threads/$threadId/replies';
  static String threadAccept(int threadId, int replyId) =>
      '/threads/$threadId/replies/$replyId/accept';
  static String threadHelpful(int threadId, int replyId) =>
      '/threads/$threadId/replies/$replyId/helpful';

  // ------------------------------------------------------------- homework
  static String classHomework(int groupId) => '/classes/$groupId/homework';
  static String homework(int assignmentId) => '/homework/$assignmentId';
  static String homeworkSubmit(int assignmentId) =>
      '/homework/$assignmentId/submit';
  static const String myHomework = '/my/homework';

  // --------------------------------------------------------------- the bell
  static const String notifications = '/notifications';
  static const String notificationsReadAll = '/notifications/read-all';
  static String notificationRead(String id) => '/notifications/$id/read';
}
