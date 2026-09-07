import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';

/// Whatever the coach has put on screen.
///
/// Text and a corpus lesson are rendered here; a file is resolved to a signed
/// link when it goes up rather than when the room loads, because those links
/// are short-lived by design.
class MaterialStage extends ConsumerWidget {
  const MaterialStage({required this.material, super.key});

  final RoomMaterial material;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return GlassCard(
      eyebrow: context.t('On screen'),
      title: material.title,
      child: Padding(
        padding: const EdgeInsets.only(top: Spacing.md),
        child: _body(context, ref),
      ),
    );
  }

  Widget _body(BuildContext context, WidgetRef ref) {
    switch (material.kind) {
      case 'text':
      case 'quiz':
        return SelectableText(
          material.body ?? '',
          style: TextStyle(height: 1.7, color: context.colors.textPrimary),
        );

      case 'lesson':
      case 'exercise':
        return Text(
          context.t('Your coach is teaching from a lesson in the course.'),
          style: TextStyle(color: context.colors.textSecondary),
        );
    }

    final assetId = material.mediaAssetId;
    if (assetId == null) {
      return const SizedBox.shrink();
    }

    return _Asset(kind: material.kind, assetId: assetId);
  }
}

class _Asset extends ConsumerWidget {
  const _Asset({required this.kind, required this.assetId});

  final String kind;
  final int assetId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(_mediaUrlProvider(assetId));

    return async.when(
      loading: () => const Center(
        child: Padding(
          padding: EdgeInsets.all(Spacing.lg),
          child: CircularProgressIndicator(),
        ),
      ),
      error: (Object error, StackTrace _) => Text(
        context.t('This material could not be opened.'),
        style: TextStyle(color: context.colors.danger),
      ),
      data: (String? url) {
        if (url == null) return const SizedBox.shrink();

        switch (kind) {
          case 'image':
            return ClipRRect(
              borderRadius: BorderRadius.circular(Radii.sm),
              child: Image.network(url, fit: BoxFit.contain),
            );
          case 'audio':
            return AudioPlayerButton(url: url);
          case 'video':
            return _Video(url: url);
          default:
            // A PDF: shown as a link rather than rendered, because a viewer is
            // a platform component and the class does not wait for it.
            return Text(
              context.t('Open the file your coach shared from the class page.'),
              style: TextStyle(color: context.colors.textSecondary),
            );
        }
      },
    );
  }
}

class _Video extends StatefulWidget {
  const _Video({required this.url});

  final String url;

  @override
  State<_Video> createState() => _VideoState();
}

class _VideoState extends State<_Video> {
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
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(Spacing.lg),
          child: CircularProgressIndicator(),
        ),
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
        IconButton(
          icon: Icon(
            _controller.value.isPlaying
                ? Icons.pause_rounded
                : Icons.play_arrow_rounded,
          ),
          onPressed: () => setState(() {
            _controller.value.isPlaying
                ? _controller.pause()
                : _controller.play();
          }),
        ),
      ],
    );
  }
}

/// Keyed by asset so switching material does not re-fetch the previous link.
final _mediaUrlProvider =
    FutureProvider.autoDispose.family<String?, int>((ref, int assetId) {
  return ref.watch(classroomRepositoryProvider).mediaUrl(assetId);
});
