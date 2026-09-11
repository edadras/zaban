import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/conversation/data/models/scene_models.dart';
import 'package:zaban/features/conversation/data/scene_repository.dart';
import 'package:zaban/features/conversation/presentation/scenes_screen.dart';

/// The scene itself.
///
/// The stage is a page the backend serves rather than a renderer written in
/// Dart: one WebGL implementation to maintain instead of three, and the same
/// scene runs in the browser build, on a phone and on a coach's screen without
/// diverging. The link it is opened with is signed, short-lived and scoped to
/// this one run, so nothing here holds a credential.
class ScenePlayerScreen extends ConsumerStatefulWidget {
  const ScenePlayerScreen({
    required this.sessionId,
    this.launch,
    super.key,
  });

  final int sessionId;

  /// Handed over by the screen that started the run. Absent when the player is
  /// reached by its URL directly, in which case a fresh link is fetched.
  final SceneLaunch? launch;

  @override
  ConsumerState<ScenePlayerScreen> createState() => _ScenePlayerScreenState();
}

class _ScenePlayerScreenState extends ConsumerState<ScenePlayerScreen> {
  WebViewController? _controller;
  String? _title;
  Object? _error;
  bool _loading = true;

  /// Backstop for a page-finished callback that never comes.
  Timer? _cover;

  @override
  void initState() {
    super.initState();
    _title = widget.launch?.title;
    unawaited(_open());
  }

  Future<void> _open() async {
    try {
      String? url = widget.launch?.url;

      if (url == null) {
        // Reached without a link - deep link, or a rebuild after the old one
        // expired. Asking for the run again mints a fresh one.
        final SceneRun run = await ref.read(sceneRepositoryProvider).run(widget.sessionId);
        url = run.playerUrl;
      }

      if (url == null || url.isEmpty) {
        throw StateError('This scene has no player link.');
      }

      final WebViewController controller = WebViewController();

      /*
       * The web implementation renders an iframe and leaves several of these
       * unimplemented - the browser already decides scripting and painting for
       * an iframe, so there is nothing for them to do. Each is best-effort: a
       * platform that has no use for one must not stop the scene opening.
       */
      await bestEffort(() => controller.setJavaScriptMode(JavaScriptMode.unrestricted));
      await bestEffort(() => controller.setBackgroundColor(const Color(0xFF0E1119)));
      final bool tellsUsWhenItLoads = await bestEffort(() => controller.setNavigationDelegate(
        NavigationDelegate(
          onPageFinished: (_) => _loaded(),
          onWebResourceError: (WebResourceError error) {
            // A sub-resource failing is not the page failing; only a failure of
            // the document itself is worth replacing the scene with an error
            // screen. The flag is absent on some platforms, and a failure we
            // cannot place is treated as the page's.
            if (!mounted || (error.isForMainFrame ?? true) == false) return;
            setState(() {
              _error = error.description;
              _loading = false;
            });
          },
        ),
      ));
      await controller.loadRequest(Uri.parse(url));

      if (!mounted) return;
      setState(() => _controller = controller);

      /*
       * Take the cover off even when nothing says the page has loaded.
       *
       * On the web this is an iframe and the delegate above is not implemented
       * at all, so `onPageFinished` never arrives. The spinner sat on top of a
       * scene that was already running underneath it: playing, asking for
       * lines, marking them - and completely hidden. A loading state that
       * cannot end is worse than one that ends slightly early, because what it
       * hides is the working product.
       */
      if (!tellsUsWhenItLoads) {
        _loaded();
      } else {
        _cover = Timer(const Duration(seconds: 12), _loaded);
      }
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  /// The scene is on screen; stop covering it.
  void _loaded() {
    _cover?.cancel();
    _cover = null;
    if (mounted && _loading) setState(() => _loading = false);
  }

  /// Leaving early still closes the run, so the debrief and the progress it
  /// earns are written rather than left hanging as an open session.
  Future<void> _close() async {
    try {
      await ref.read(sceneRepositoryProvider).finish(widget.sessionId);
    } catch (_) {
      // Closing is a courtesy; failing to close must not trap the learner in
      // the scene.
    }
  }

  @override
  void dispose() {
    _cover?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      onPopInvokedWithResult: (bool didPop, Object? _) {
        if (didPop) unawaited(_close());
      },
      child: Scaffold(
        backgroundColor: const Color(0xFF0E1119),
        appBar: AppBar(
          title: Text(_title ?? context.t('Scene')),
          backgroundColor: const Color(0xFF0E1119),
        ),
        body: _body(),
      ),
    );
  }

  Widget _body() {
    if (_error != null) {
      return ErrorView(
        error: _error!,
        onRetry: () {
          _cover?.cancel();
          setState(() {
            _error = null;
            _loading = true;
          });
          unawaited(_open());
        },
      );
    }

    final WebViewController? controller = _controller;
    if (controller == null) {
      return const LoadingView();
    }

    return Stack(
      children: <Widget>[
        WebViewWidget(controller: controller),
        if (_loading)
          const Positioned.fill(child: ColoredBox(color: Color(0xFF0E1119), child: LoadingView())),
        if (kIsWeb)
          Positioned(
            left: Spacing.md,
            right: Spacing.md,
            bottom: Spacing.md,
            child: Text(
              context.t('Sound needs a tap on Play the first time.'),
              textAlign: TextAlign.center,
              style: context.text.labelSmall,
            ),
          ),
      ],
    );
  }
}

/// Runs a platform call that some web-view implementations do not provide.
///
/// Returns whether the call was actually honoured, which is the part that
/// matters: a callback that will never fire is a different thing from one that
/// has not fired yet, and treating them the same is how the scene ended up
/// under a spinner that could not end. Only "this platform has no such thing"
/// is absorbed - a real failure is still thrown.
Future<bool> bestEffort(Future<void> Function() call) async {
  try {
    await call();
    return true;
  } on UnimplementedError {
    // Nothing to configure on this platform.
    return false;
  } on MissingPluginException {
    // Same, reported differently.
    return false;
  }
}
