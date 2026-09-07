import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// Watching a class again.
///
/// For the learner who was there and wants a second pass, and for the one who
/// missed it entirely — the server lets both in, and refuses anybody who was
/// not on the roll.
class ClassRecordingScreen extends ConsumerWidget {
  const ClassRecordingScreen({required this.sessionId, super.key});

  final int sessionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(classRecordingProvider(sessionId));

    return ZabanScaffold(
      title: context.t('Class recording'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(classRecordingProvider(sessionId)),
        ),
        data: (ClassRecording recording) {
          final url = recording.url;

          if (url == null || !recording.isReady) {
            return EmptyView(
              title: context.t('Not ready yet'),
              message: context.t(
                'When your coach finishes recording, the class appears here.',
              ),
              icon: Icons.videocam_off_outlined,
            );
          }

          return ListView(
            padding: const EdgeInsets.only(
              top: Spacing.lg,
              bottom: Spacing.huge,
            ),
            children: <Widget>[
              ResponsiveContent(
                maxWidth: Breakpoints.wideContentMaxWidth,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    _Player(url: url),
                    const SizedBox(height: Spacing.md),
                    if (recording.duration != null)
                      Text(
                        '${recording.duration!.inMinutes} ${context.t('min')}',
                        style: TextStyle(color: context.colors.textSecondary),
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Player extends StatefulWidget {
  const _Player({required this.url});

  final String url;

  @override
  State<_Player> createState() => _PlayerState();
}

class _PlayerState extends State<_Player> {
  late final VideoPlayerController _controller;
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    _controller = VideoPlayerController.networkUrl(Uri.parse(widget.url))
      ..initialize().then((_) {
        if (mounted) setState(() => _ready = true);
      });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return const AspectRatio(
        aspectRatio: 16 / 9,
        child: Center(child: CircularProgressIndicator()),
      );
    }

    return Column(
      children: <Widget>[
        ClipRRect(
          borderRadius: BorderRadius.circular(Radii.sm),
          child: AspectRatio(
            aspectRatio: _controller.value.aspectRatio,
            child: VideoPlayer(_controller),
          ),
        ),
        VideoProgressIndicator(_controller, allowScrubbing: true),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            IconButton(
              icon: const Icon(Icons.replay_10_rounded),
              onPressed: () => _controller.seekTo(
                _controller.value.position - const Duration(seconds: 10),
              ),
            ),
            IconButton(
              iconSize: 40,
              icon: Icon(
                _controller.value.isPlaying
                    ? Icons.pause_circle_filled_rounded
                    : Icons.play_circle_fill_rounded,
              ),
              onPressed: () => setState(() {
                _controller.value.isPlaying
                    ? _controller.pause()
                    : _controller.play();
              }),
            ),
            IconButton(
              icon: const Icon(Icons.forward_10_rounded),
              onPressed: () => _controller.seekTo(
                _controller.value.position + const Duration(seconds: 10),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// The link is signed and short-lived, so it is fetched when the screen opens
/// rather than carried around with the class list.
final classRecordingProvider =
    FutureProvider.autoDispose.family<ClassRecording, int>((ref, int sessionId) {
  return ref.watch(classroomRepositoryProvider).recording(sessionId);
});
