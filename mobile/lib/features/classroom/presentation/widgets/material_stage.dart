import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:video_player/video_player.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';

/// Whatever the coach has put on screen — material or whiteboard.
class MaterialStage extends ConsumerWidget {
  const MaterialStage({
    required this.material,
    required this.stage,
    this.draftStroke,
    super.key,
  });

  final RoomMaterial? material;
  final RoomStage? stage;
  final RoomStroke? draftStroke;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = stage?.mode ?? 'material';

    if (mode == 'whiteboard') {
      final strokes = <RoomStroke>[
        ...?stage?.whiteboard?.strokes,
        if (draftStroke != null) draftStroke!,
      ];
      return GlassCard(
        eyebrow: context.t('On screen'),
        title: context.t('Whiteboard'),
        child: Padding(
          padding: const EdgeInsets.only(top: Spacing.md),
          child: AspectRatio(
            aspectRatio: 16 / 10,
            child: _WhiteboardView(strokes: strokes),
          ),
        ),
      );
    }

    final shared = material;
    if (shared == null) return const SizedBox.shrink();

    return GlassCard(
      eyebrow: context.t('On screen'),
      title: shared.title,
      trailing: IconButton(
        tooltip: context.t('Enlarge'),
        icon: const Icon(Icons.open_in_full_rounded),
        onPressed: () => openMediaLightbox(context, ref, shared, stage),
      ),
      child: Padding(
        padding: const EdgeInsets.only(top: Spacing.md),
        child: _StageBody(
          material: shared,
          stage: stage,
          onOpen: () => openMediaLightbox(context, ref, shared, stage),
        ),
      ),
    );
  }
}

class _StageBody extends ConsumerWidget {
  const _StageBody({
    required this.material,
    required this.stage,
    required this.onOpen,
  });

  final RoomMaterial material;
  final RoomStage? stage;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    switch (material.kind) {
      case 'text':
      case 'quiz':
        return GestureDetector(
          onTap: onOpen,
          child: SelectableText(
            material.body ?? '',
            style: TextStyle(height: 1.7, color: context.colors.textPrimary),
          ),
        );
      case 'lesson':
      case 'exercise':
        return Text(
          context.t('Your coach is teaching from a lesson in the course.'),
          style: TextStyle(color: context.colors.textSecondary),
        );
    }

    final assetId = material.mediaAssetId;
    if (assetId == null) return const SizedBox.shrink();

    return _Asset(
      kind: material.kind,
      assetId: assetId,
      page: stage?.page ?? 1,
      media: stage?.media,
      onOpen: onOpen,
    );
  }
}

Future<void> openMediaLightbox(
  BuildContext context,
  WidgetRef ref,
  RoomMaterial material,
  RoomStage? stage,
) async {
  final assetId = material.mediaAssetId;
  String? url;
  if (assetId != null) {
    url = await ref.read(classroomRepositoryProvider).mediaUrl(assetId);
  }

  if (!context.mounted) return;

  await Navigator.of(context).push(
    PageRouteBuilder<void>(
      opaque: true,
      barrierDismissible: true,
      pageBuilder: (BuildContext context, Animation<double> a, Animation<double> b) {
        return _MediaLightbox(
          material: material,
          stage: stage,
          url: url,
        );
      },
      transitionsBuilder: (context, animation, secondary, child) {
        return FadeTransition(opacity: animation, child: child);
      },
    ),
  );
}

/// Fullscreen pinch-zoom / landscape viewer — never nests another MaterialStage.
class _MediaLightbox extends StatefulWidget {
  const _MediaLightbox({
    required this.material,
    required this.stage,
    required this.url,
  });

  final RoomMaterial material;
  final RoomStage? stage;
  final String? url;

  @override
  State<_MediaLightbox> createState() => _MediaLightboxState();
}

class _MediaLightboxState extends State<_MediaLightbox> {
  @override
  void initState() {
    super.initState();
    SystemChrome.setPreferredOrientations(const <DeviceOrientation>[
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
  }

  @override
  void dispose() {
    SystemChrome.setPreferredOrientations(const <DeviceOrientation>[
      DeviceOrientation.portraitUp,
    ]);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final url = widget.url;
    final kind = widget.material.kind;

    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Stack(
          children: <Widget>[
            Positioned.fill(child: _lightboxBody(context, kind, url)),
            PositionedDirectional(
              top: 8,
              end: 8,
              child: IconButton.filled(
                style: IconButton.styleFrom(backgroundColor: Colors.white24),
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close_rounded, color: Colors.white),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _lightboxBody(BuildContext context, String kind, String? url) {
    if (kind == 'text' || kind == 'quiz') {
      return InteractiveViewer(
        minScale: 0.8,
        maxScale: 4,
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(Spacing.lg),
            child: Text(
              widget.material.body ?? '',
              style: const TextStyle(color: Colors.white, height: 1.7, fontSize: 18),
            ),
          ),
        ),
      );
    }

    if (url == null || url.isEmpty) {
      return Center(
        child: Text(
          context.t('This material could not be opened.'),
          style: const TextStyle(color: Colors.white70),
        ),
      );
    }

    switch (kind) {
      case 'image':
        return InteractiveViewer(
          minScale: 0.5,
          maxScale: 5,
          child: Center(
            child: Image.network(url, fit: BoxFit.contain),
          ),
        );
      case 'video':
        return Center(
          child: _FollowVideo(
            url: url,
            clock: widget.stage?.media,
            expanded: true,
          ),
        );
      case 'pdf':
        return _PdfFollow(url: url, page: widget.stage?.page ?? 1, dark: true, expanded: true);
      case 'audio':
        return Center(child: _FollowAudio(url: url, clock: widget.stage?.media));
      default:
        return const SizedBox.shrink();
    }
  }
}

class _Asset extends ConsumerWidget {
  const _Asset({
    required this.kind,
    required this.assetId,
    required this.page,
    required this.media,
    required this.onOpen,
  });

  final String kind;
  final int assetId;
  final int page;
  final RoomMediaClock? media;
  final VoidCallback onOpen;

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
            return GestureDetector(
              onTap: onOpen,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(Radii.sm),
                child: Image.network(url, fit: BoxFit.contain),
              ),
            );
          case 'audio':
            return _FollowAudio(url: url, clock: media);
          case 'video':
            return GestureDetector(
              onTap: onOpen,
              child: _FollowVideo(url: url, clock: media),
            );
          case 'pdf':
            return GestureDetector(
              onTap: onOpen,
              child: _PdfFollow(url: url, page: page),
            );
          default:
            return Text(
              context.t('Open the file your coach shared from the class page.'),
              style: TextStyle(color: context.colors.textSecondary),
            );
        }
      },
    );
  }
}

class _PdfFollow extends StatefulWidget {
  const _PdfFollow({
    required this.url,
    required this.page,
    this.dark = false,
    this.expanded = false,
  });

  final String url;
  final int page;
  final bool dark;
  final bool expanded;

  @override
  State<_PdfFollow> createState() => _PdfFollowState();
}

class _PdfFollowState extends State<_PdfFollow> {
  final PdfViewerController _controller = PdfViewerController();
  int _lastPage = 1;

  @override
  void initState() {
    super.initState();
    _lastPage = widget.page;
  }

  @override
  void didUpdateWidget(covariant _PdfFollow oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.page != widget.page && _controller.isReady) {
      _lastPage = widget.page;
      _controller.goToPage(pageNumber: widget.page, duration: Duration.zero);
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = widget.expanded ? MediaQuery.sizeOf(context).height * 0.85 : 420.0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text(
          context.t('Page {page}').replaceAll('{page}', '${widget.page}'),
          style: TextStyle(
            fontWeight: FontWeight.w600,
            color: widget.dark ? Colors.white : context.colors.textPrimary,
          ),
        ),
        const SizedBox(height: Spacing.sm),
        SizedBox(
          height: height,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(Radii.sm),
            child: PdfViewer.uri(
              Uri.parse(widget.url),
              controller: _controller,
              initialPageNumber: widget.page,
              params: PdfViewerParams(
                backgroundColor: widget.dark ? Colors.black : Colors.white,
                onViewerReady: (document, controller) {
                  if (widget.page != _lastPage || widget.page != 1) {
                    controller.goToPage(
                      pageNumber: widget.page,
                      duration: Duration.zero,
                    );
                  }
                },
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _FollowVideo extends StatefulWidget {
  const _FollowVideo({
    required this.url,
    required this.clock,
    this.expanded = false,
  });

  final String url;
  final RoomMediaClock? clock;
  final bool expanded;

  @override
  State<_FollowVideo> createState() => _FollowVideoState();
}

class _FollowVideoState extends State<_FollowVideo> {
  late final VideoPlayerController _controller;
  bool _ready = false;
  bool _applying = false;
  Timer? _followTimer;

  @override
  void initState() {
    super.initState();
    _controller = VideoPlayerController.networkUrl(Uri.parse(widget.url))
      ..initialize().then((_) {
        if (!mounted) return;
        setState(() => _ready = true);
        _applyClock();
        _armFollowTimer();
      });
  }

  @override
  void didUpdateWidget(covariant _FollowVideo oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.clock != widget.clock) {
      _applyClock();
      _armFollowTimer();
    }
  }

  void _armFollowTimer() {
    _followTimer?.cancel();
    _followTimer = null;
    if (widget.clock?.playing != true) return;
    // Keep extrapolating between LiveKit ticks so drift never builds up.
    _followTimer = Timer.periodic(const Duration(milliseconds: 200), (_) {
      if (mounted) _applyClock();
    });
  }

  int _effectiveMs(RoomMediaClock clock) {
    var ms = clock.positionMs;
    if (clock.playing && clock.updatedAt != null) {
      ms += DateTime.now().toUtc().difference(clock.updatedAt!.toUtc()).inMilliseconds;
      if (ms < 0) ms = 0;
    }
    return ms;
  }

  Future<void> _applyClock() async {
    if (!_ready || _applying) return;
    final clock = widget.clock;
    if (clock == null) return;
    _applying = true;
    try {
      final target = Duration(milliseconds: _effectiveMs(clock));
      final drift = (_controller.value.position - target).abs();

      if (!clock.playing) {
        // Freeze: pause first, seek only for a real scrub — not micro-corrections.
        if (_controller.value.isPlaying) await _controller.pause();
        if (drift > const Duration(milliseconds: 500)) {
          await _controller.seekTo(target);
        }
        if (mounted) setState(() {});
        return;
      }

      // Playing: correct when more than ~200ms off.
      if (drift > const Duration(milliseconds: 200)) {
        await _controller.seekTo(target);
      }
      if (!_controller.value.isPlaying) await _controller.play();
      if (mounted) setState(() {});
    } finally {
      _applying = false;
    }
  }

  @override
  void dispose() {
    _followTimer?.cancel();
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

    final ratio = _controller.value.aspectRatio == 0
        ? 16 / 9
        : _controller.value.aspectRatio;

    final player = AspectRatio(
      aspectRatio: ratio,
      child: VideoPlayer(_controller),
    );

    if (widget.expanded) {
      return InteractiveViewer(
        minScale: 0.8,
        maxScale: 3,
        child: Center(child: player),
      );
    }

    return Column(
      children: <Widget>[
        ClipRRect(
          borderRadius: BorderRadius.circular(Radii.sm),
          child: player,
        ),
        const SizedBox(height: Spacing.sm),
        Text(
          context.t('Playback follows your coach'),
          style: TextStyle(fontSize: 12, color: context.colors.textSecondary),
        ),
      ],
    );
  }
}

class _FollowAudio extends StatelessWidget {
  const _FollowAudio({required this.url, required this.clock});

  final String url;
  final RoomMediaClock? clock;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        AudioPlayerButton(url: url),
        const SizedBox(height: Spacing.sm),
        Text(
          (clock?.playing ?? false)
              ? context.t('Coach is playing audio')
              : context.t('Coach paused the audio'),
          style: TextStyle(fontSize: 12, color: context.colors.textSecondary),
        ),
      ],
    );
  }
}

class _WhiteboardView extends StatelessWidget {
  const _WhiteboardView({required this.strokes});

  final List<RoomStroke> strokes;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(Radii.sm),
        border: Border.all(color: context.colors.outline),
      ),
      child: CustomPaint(
        painter: _StrokePainter(strokes),
        child: const SizedBox.expand(),
      ),
    );
  }
}

class _StrokePainter extends CustomPainter {
  _StrokePainter(this.strokes);

  final List<RoomStroke> strokes;

  @override
  void paint(Canvas canvas, Size size) {
    for (final RoomStroke stroke in strokes) {
      if (stroke.points.length < 2) continue;
      final paint = Paint()
        ..color = _parseColor(stroke.color)
        ..strokeWidth = stroke.width
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round;
      final path = Path()
        ..moveTo(
          stroke.points.first[0] * size.width,
          stroke.points.first[1] * size.height,
        );
      for (var i = 1; i < stroke.points.length; i++) {
        path.lineTo(
          stroke.points[i][0] * size.width,
          stroke.points[i][1] * size.height,
        );
      }
      canvas.drawPath(path, paint);
    }
  }

  Color _parseColor(String hex) {
    final cleaned = hex.replaceFirst('#', '');
    if (cleaned.length != 6) return const Color(0xFF111827);
    return Color(int.parse('FF$cleaned', radix: 16));
  }

  @override
  bool shouldRepaint(covariant _StrokePainter oldDelegate) =>
      !listEquals(oldDelegate.strokes, strokes);
}

final _mediaUrlProvider =
    FutureProvider.autoDispose.family<String?, int>((ref, int assetId) {
  return ref.watch(classroomRepositoryProvider).mediaUrl(assetId);
});
