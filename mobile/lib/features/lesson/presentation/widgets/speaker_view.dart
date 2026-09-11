import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/lesson/data/models/speaker_ref.dart';

/// The person whose line you are about to repeat.
///
/// The figure is drawn by a page the backend serves rather than a renderer
/// written in Dart - one WebGL implementation instead of three, and the same
/// face in a lesson, in an exam and in a scene. The link is signed, short-lived
/// and scoped to this one line, so nothing here holds a credential.
///
/// Why a face at all: for pronunciation, seeing the mouth is part of the
/// exercise. It is the one place in the product where a moving figure teaches
/// something a waveform cannot.
class SpeakerView extends StatefulWidget {
  const SpeakerView({
    required this.speaker,
    this.height = 220,
    super.key,
  });

  final SpeakerRef speaker;
  final double height;

  @override
  State<SpeakerView> createState() => _SpeakerViewState();
}

class _SpeakerViewState extends State<SpeakerView> {
  WebViewController? _controller;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _open();
  }

  Future<void> _open() async {
    try {
      final WebViewController controller = WebViewController();

      /*
       * Each of these is best-effort. The web implementation renders an iframe
       * and leaves several unimplemented, and a platform with no use for one
       * must not stop the figure appearing - the same lesson the scene player
       * learned when a spinner it could never lift covered a working scene.
       */
      await _best(() => controller.setJavaScriptMode(JavaScriptMode.unrestricted));
      await _best(() => controller.setBackgroundColor(const Color(0xFF10131A)));
      await controller.loadRequest(Uri.parse(widget.speaker.url));

      if (!mounted) return;
      setState(() => _controller = controller);
    } catch (_) {
      // A figure that will not load is not a lesson that cannot be done. The
      // block keeps its audio button and its lines; the face is the extra.
      if (mounted) setState(() => _failed = true);
    }
  }

  Future<bool> _best(Future<void> Function() call) async {
    try {
      await call();

      return true;
    } on UnimplementedError {
      return false;
    } on MissingPluginException {
      return false;
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) return const SizedBox.shrink();

    final WebViewController? controller = _controller;

    return ClipRRect(
      borderRadius: Radii.cardRadius,
      child: SizedBox(
        height: widget.height,
        child: controller == null
            ? const ColoredBox(color: Color(0xFF10131A))
            : WebViewWidget(controller: controller),
      ),
    );
  }
}

/// Whether this platform can show the figure at all.
///
/// `webview_flutter` has no desktop implementation, and a lesson on a coach's
/// laptop should show its audio button rather than an exception.
bool get speakerIsShowable {
  if (kIsWeb) return true;

  return defaultTargetPlatform == TargetPlatform.android ||
      defaultTargetPlatform == TargetPlatform.iOS;
}
