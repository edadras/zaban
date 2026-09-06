import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/storage/preferences_store.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/features/admin/presentation/book_lessons_screen.dart';
import 'package:zaban/features/admin/presentation/curriculum_screen.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/auth/domain/auth_state.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/auth/presentation/login_screen.dart';
import 'package:zaban/features/auth/presentation/register_screen.dart';
import 'package:zaban/features/auth/presentation/splash_screen.dart';
import 'package:zaban/features/conversation/presentation/conversation_screen.dart';
import 'package:zaban/features/conversation/presentation/scenarios_screen.dart';
import 'package:zaban/features/exam/presentation/exam_attempt_screen.dart';
import 'package:zaban/features/exam/presentation/exam_home_screen.dart';
import 'package:zaban/features/exam/presentation/exam_result_screen.dart';
import 'package:zaban/features/home/presentation/home_screen.dart';
import 'package:zaban/features/home/presentation/session/session_runner_screen.dart';
import 'package:zaban/features/lesson/presentation/lesson_screen.dart';
import 'package:zaban/features/onboarding/presentation/onboarding_screen.dart';
import 'package:zaban/features/placement/presentation/placement_intro_screen.dart';
import 'package:zaban/features/placement/presentation/placement_result_screen.dart';
import 'package:zaban/features/placement/presentation/placement_run_screen.dart';
import 'package:zaban/features/profile/presentation/profile_screen.dart';
import 'package:zaban/features/profile/presentation/settings_screen.dart';
import 'package:zaban/features/progress/presentation/progress_screen.dart';
import 'package:zaban/features/review/data/review_repository.dart';
import 'package:zaban/features/review/presentation/review_screen.dart';
import 'package:zaban/features/speech/presentation/speech_practice_screen.dart';
import 'package:zaban/features/subscription/presentation/plans_screen.dart';

final _rootNavigatorKey = GlobalKey<NavigatorState>(debugLabel: 'root');

/// Roles the server lets into `/api/v1/admin`. Kept in step with
/// `App\Http\Middleware\EnsureAdmin`; the client hides the screens so the
/// call is never made, the server refuses them so hiding is not the defence.
const Set<String> _staffRoles = <String>{'admin', 'editor', 'reviewer'};

bool _staff(Ref ref) => _staffRoles.contains(
      ref.read(authControllerProvider).user?.role,
    );

/// Routes that a signed-out user may see.
const Set<String> _publicPaths = <String>{'/login', '/register', '/splash'};

/// Routes that stay reachable while placement is still outstanding, so the
/// learner can read the intro, sign out or manage their plan.
const Set<String> _prePlacementPaths = <String>{
  '/onboarding',
  '/placement',
  '/placement/run',
  '/placement/result',
  '/plans',
  '/profile',
  '/profile/settings',
};

final routerProvider = Provider<GoRouter>((ref) {
  // GoRouter needs a Listenable; bridging Riverpod through a counter keeps the
  // redirect logic pure and re-runs it on every auth change.
  final refresh = ValueNotifier<int>(0);
  ref.onDispose(refresh.dispose);
  ref.listen<AuthState>(
    authControllerProvider,
    (AuthState? previous, AuthState next) => refresh.value++,
  );

  final router = GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: AppRoute.splash.path,
    refreshListenable: refresh,
    debugLogDiagnostics: false,
    redirect: (BuildContext context, GoRouterState state) {
      final auth = ref.read(authControllerProvider);
      final location = state.matchedLocation;

      // Boot: hold on the splash until the stored token has been checked.
      if (!auth.isResolved) {
        return location == AppRoute.splash.path ? null : AppRoute.splash.path;
      }

      if (!auth.isAuthenticated) {
        return _publicPaths.contains(location) ? null : AppRoute.login.path;
      }

      // Signed in: never leave the user sitting on an auth screen.
      final learner = auth.user?.learner;
      final onboardingSeen = ref.read(preferencesStoreProvider).onboardingSeen;
      final needsPlacement = learner?.needsPlacement ?? true;

      String destinationForSignedIn() {
        if (!onboardingSeen) return AppRoute.onboarding.path;
        if (needsPlacement) return AppRoute.placement.path;
        return AppRoute.home.path;
      }

      if (_publicPaths.contains(location)) {
        return destinationForSignedIn();
      }

      // Placement is the gate to the course: the server decides when it is
      // done, and until then the learning routes are not meaningful.
      if (needsPlacement && !_prePlacementPaths.contains(location)) {
        return onboardingSeen
            ? AppRoute.placement.path
            : AppRoute.onboarding.path;
      }

      return null;
    },
    routes: <RouteBase>[
      GoRoute(
        path: AppRoute.splash.path,
        name: AppRoute.splash.name,
        builder: (_, __) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoute.login.path,
        name: AppRoute.login.name,
        builder: (_, __) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoute.register.path,
        name: AppRoute.register.name,
        builder: (_, __) => const RegisterScreen(),
      ),
      GoRoute(
        path: AppRoute.onboarding.path,
        name: AppRoute.onboarding.name,
        builder: (_, __) => const OnboardingScreen(),
      ),
      GoRoute(
        path: AppRoute.placement.path,
        name: AppRoute.placement.name,
        builder: (_, __) => const PlacementIntroScreen(),
        routes: <RouteBase>[
          GoRoute(
            path: 'run',
            name: AppRoute.placementRun.name,
            builder: (_, __) => const PlacementRunScreen(),
          ),
          GoRoute(
            path: 'result',
            name: AppRoute.placementResult.name,
            builder: (_, __) => const PlacementResultScreen(),
          ),
        ],
      ),

      // Full-screen flows: they own the whole viewport, so they sit outside the
      // navigation shell rather than inside a tab.
      GoRoute(
        path: AppRoute.session.path,
        name: AppRoute.session.name,
        builder: (_, __) => const SessionRunnerScreen(),
      ),
      GoRoute(
        path: AppRoute.lesson.path,
        name: AppRoute.lesson.name,
        builder: (BuildContext context, GoRouterState state) => LessonScreen(
          lessonId: int.parse(state.pathParameters['lessonId']!),
        ),
      ),
      GoRoute(
        path: AppRoute.speech.path,
        name: AppRoute.speech.name,
        builder: (_, __) => const SpeechPracticeScreen(),
      ),
      GoRoute(
        path: AppRoute.plans.path,
        name: AppRoute.plans.name,
        builder: (_, __) => const PlansScreen(),
      ),
      GoRoute(
        path: AppRoute.exam.path,
        name: AppRoute.exam.name,
        builder: (_, __) => const ExamHomeScreen(),
      ),
      GoRoute(
        path: AppRoute.examAttempt.path,
        name: AppRoute.examAttempt.name,
        builder: (BuildContext context, GoRouterState state) =>
            ExamAttemptScreen(
          attemptId: int.parse(state.pathParameters['attemptId']!),
        ),
      ),
      GoRoute(
        path: AppRoute.examResult.path,
        name: AppRoute.examResult.name,
        builder: (BuildContext context, GoRouterState state) =>
            ExamResultScreen(
          attemptId: int.parse(state.pathParameters['attemptId']!),
        ),
      ),
      GoRoute(
        path: AppRoute.settings.path,
        name: AppRoute.settings.name,
        builder: (_, __) => const SettingsScreen(),
      ),

      // Admin. Outside the tab shell because it is not part of learning, and
      // redirected away from anyone whose account does not carry a staff role
      // - the server refuses them anyway, and a 403 is a worse way to find out.
      GoRoute(
        path: AppRoute.adminCurriculum.path,
        name: AppRoute.adminCurriculum.name,
        redirect: (BuildContext context, GoRouterState state) =>
            _staff(ref) ? null : AppRoute.home.path,
        builder: (_, __) => const AdminCurriculumScreen(),
      ),
      GoRoute(
        path: AppRoute.adminBook.path,
        name: AppRoute.adminBook.name,
        redirect: (BuildContext context, GoRouterState state) =>
            _staff(ref) ? null : AppRoute.home.path,
        builder: (BuildContext context, GoRouterState state) =>
            AdminBookLessonsScreen(
          bookId: int.parse(state.pathParameters['bookId']!),
        ),
      ),

      // The tabbed part of the app. IndexedStack keeps each tab's scroll
      // position and in-flight requests alive when switching.
      StatefulShellRoute.indexedStack(
        builder: (
          BuildContext context,
          GoRouterState state,
          StatefulNavigationShell shell,
        ) =>
            _AppShell(shell: shell),
        branches: <StatefulShellBranch>[
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppRoute.home.path,
                name: AppRoute.home.name,
                builder: (_, __) => const HomeScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppRoute.review.path,
                name: AppRoute.review.name,
                builder: (_, __) => const ReviewScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppRoute.conversation.path,
                name: AppRoute.conversation.name,
                builder: (_, __) => const ScenariosScreen(),
                routes: <RouteBase>[
                  GoRoute(
                    path: ':sessionId',
                    name: AppRoute.conversationSession.name,
                    parentNavigatorKey: _rootNavigatorKey,
                    builder: (BuildContext context, GoRouterState state) =>
                        ConversationScreen(
                      sessionId: int.parse(state.pathParameters['sessionId']!),
                    ),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppRoute.progress.path,
                name: AppRoute.progress.name,
                builder: (_, __) => const ProgressScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: <RouteBase>[
              GoRoute(
                path: AppRoute.profile.path,
                name: AppRoute.profile.name,
                builder: (_, __) => const ProfileScreen(),
              ),
            ],
          ),
        ],
      ),
    ],
    errorBuilder: (BuildContext context, GoRouterState state) => ZabanScaffold(
      title: 'Lost',
      body: Center(
        child: Text('No screen for ${state.uri}'),
      ),
    ),
  );

  ref.onDispose(router.dispose);
  return router;
});

/// Wires the shell's branch index to the glass navigation.
class _AppShell extends ConsumerWidget {
  const _AppShell({required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // The badge is whatever the server said is due; the client never counts it.
    final dueReviews = ref.watch(dueCountProvider).valueOrNull;

    return AdaptiveNavigationShell(
      currentIndex: shell.currentIndex,
      onSelected: (int index) => shell.goBranch(
        index,
        // Tapping the active tab returns it to its first screen.
        initialLocation: index == shell.currentIndex,
      ),
      destinations: <ShellDestination>[
        const ShellDestination(
          label: 'Today',
          icon: Icons.bolt_outlined,
          selectedIcon: Icons.bolt_rounded,
          route: '/home',
        ),
        ShellDestination(
          label: 'Review',
          icon: Icons.replay_outlined,
          selectedIcon: Icons.replay_rounded,
          route: '/review',
          badgeCount: dueReviews,
        ),
        const ShellDestination(
          label: 'Talk',
          icon: Icons.forum_outlined,
          selectedIcon: Icons.forum_rounded,
          route: '/conversation',
        ),
        const ShellDestination(
          label: 'Progress',
          icon: Icons.insights_outlined,
          selectedIcon: Icons.insights_rounded,
          route: '/progress',
        ),
        const ShellDestination(
          label: 'You',
          icon: Icons.person_outline_rounded,
          selectedIcon: Icons.person_rounded,
          route: '/profile',
        ),
      ],
      child: shell,
    );
  }
}
