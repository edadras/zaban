/// Every navigable location in the app.
///
/// Paths double as web URLs, so they are readable and shareable; ids are path
/// parameters rather than query strings.
enum AppRoute {
  splash('/splash', 'splash'),
  login('/login', 'login'),
  register('/register', 'register'),
  onboarding('/onboarding', 'onboarding'),

  placement('/placement', 'placement'),
  placementRun('/placement/run', 'placementRun'),
  placementResult('/placement/result', 'placementResult'),

  home('/home', 'home'),
  session('/session', 'session'),
  lesson('/lesson/:lessonId', 'lesson'),

  review('/review', 'review'),
  conversation('/conversation', 'conversation'),
  conversationSession('/conversation/:sessionId', 'conversationSession'),

  progress('/progress', 'progress'),
  speech('/speech', 'speech'),

  exam('/exam', 'exam'),
  examAttempt('/exam/attempt/:attemptId', 'examAttempt'),
  examResult('/exam/result/:attemptId', 'examResult'),

  plans('/plans', 'plans'),

  profile('/profile', 'profile'),
  settings('/profile/settings', 'settings');

  const AppRoute(this.path, this.name);

  final String path;
  final String name;

  String lessonPath(int lessonId) => '/lesson/$lessonId';
  String conversationPath(int sessionId) => '/conversation/$sessionId';
  String examAttemptPath(int attemptId) => '/exam/attempt/$attemptId';
  String examResultPath(int attemptId) => '/exam/result/$attemptId';
}
