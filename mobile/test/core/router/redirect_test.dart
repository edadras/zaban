import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zaban/core/router/app_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/storage/preferences_store.dart';
import 'package:zaban/features/auth/data/models/user.dart';
import 'package:zaban/features/auth/domain/auth_state.dart';
import 'package:zaban/features/auth/presentation/auth_controller.dart';

/// Where the app sends someone, and why.
///
/// This is the only piece of the client that decides what a person may see, and
/// every rule in it exists because of something that went wrong without it: a
/// signed-out user reaching the course, a signed-in user stuck on the login
/// screen, a learner skipping placement and being taught at the wrong level, a
/// learner finding the admin screens.
void main() {
  late SharedPreferences prefs;

  setUp(() async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    prefs = await SharedPreferences.getInstance();
  });

  /// A container whose auth state is fixed, so the redirect can be asked
  /// questions without a server.
  ProviderContainer containerFor(AuthState auth, {bool onboardingSeen = true}) {
    if (onboardingSeen) {
      prefs.setBool('zaban.onboarding_seen', true);
    }

    return ProviderContainer(
      overrides: <Override>[
        preferencesStoreProvider.overrideWithValue(PreferencesStore(prefs)),
        authControllerProvider.overrideWith(() => _FixedAuth(auth)),
      ],
    );
  }

  String? where(ProviderContainer c, String from) {
    final router = c.read(routerProvider);
    final redirect = router.configuration.topRedirect;

    return redirect(
      _NoContext(),
      GoRouterState(
        router.configuration,
        uri: Uri.parse(from),
        matchedLocation: from,
        fullPath: from,
        pathParameters: const <String, String>{},
        pageKey: const ValueKey<String>('test'),
      ),
    ) as String?;
  }

  User learner({String placement = 'completed', String role = 'learner'}) => User(
        id: 1,
        name: 'Sara',
        email: 'sara@example.com',
        role: role,
        learner: LearnerProfileSummary(placementStatus: placement),
      );

  test('while the stored token is still being read, everything waits on the splash',
      () {
    final c = containerFor(const AuthState.unknown());
    addTearDown(c.dispose);

    expect(where(c, AppRoute.home.path), AppRoute.splash.path);
    expect(where(c, AppRoute.splash.path), isNull);
  });

  test('a signed-out visitor is sent to sign in, and may stay on the public pages',
      () {
    final c = containerFor(const AuthState.unauthenticated());
    addTearDown(c.dispose);

    expect(where(c, AppRoute.home.path), AppRoute.login.path);
    expect(where(c, AppRoute.login.path), isNull);
    expect(where(c, AppRoute.register.path), isNull);
  });

  test('a signed-in learner is never left sitting on the login screen', () {
    final c = containerFor(AuthState.authenticated(learner()));
    addTearDown(c.dispose);

    expect(where(c, AppRoute.login.path), AppRoute.home.path);
  });

  /// Placement is the gate to the course: until the server says it is done,
  /// the learning routes are not meaningful, because there is no level to
  /// teach at.
  test('a learner who has not been placed is held at placement', () {
    final c = containerFor(AuthState.authenticated(learner(placement: 'not_started')));
    addTearDown(c.dispose);

    expect(where(c, AppRoute.home.path), AppRoute.placement.path);
    expect(where(c, AppRoute.placement.path), isNull);
    // Not everything is blocked: the plan and the profile stay reachable, so
    // nobody is trapped on a screen they cannot pay for or sign out of.
    expect(where(c, AppRoute.plans.path), isNull);
    expect(where(c, AppRoute.profile.path), isNull);
  });

  test('onboarding comes before placement, and only once', () {
    final c = containerFor(
      AuthState.authenticated(learner(placement: 'not_started')),
      onboardingSeen: false,
    );
    addTearDown(c.dispose);

    expect(where(c, AppRoute.home.path), AppRoute.onboarding.path);
  });

  test('a placed learner reaches the course', () {
    final c = containerFor(AuthState.authenticated(learner()));
    addTearDown(c.dispose);

    expect(where(c, AppRoute.home.path), isNull);
    expect(where(c, AppRoute.review.path), isNull);
  });
}

class _FixedAuth extends AuthController {
  _FixedAuth(this._state);

  final AuthState _state;

  @override
  AuthState build() => _state;
}

/// The redirect never touches its context; it reads providers.
class _NoContext extends BuildContext {
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}
