import 'package:flutter/material.dart';
import 'package:just_audio/just_audio.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';

/// Plays one audio asset — the book's own recording for a listening block, or
/// the reference speaker for a repeat-after drill.
///
/// The [AudioPlayer] is created on first play rather than in `initState`: most
/// blocks are scrolled past without ever being played, and it keeps this widget
/// constructible in a widget test with no audio platform channel.
class AudioPlayerButton extends StatefulWidget {
  const AudioPlayerButton({
    required this.url,
    super.key,
    this.label = 'Play',
    this.size = 64,
    this.autoPlay = false,
    this.onPlayed,
  });

  final String url;
  final String label;
  final double size;
  final bool autoPlay;
  final VoidCallback? onPlayed;

  @override
  State<AudioPlayerButton> createState() => _AudioPlayerButtonState();
}

class _AudioPlayerButtonState extends State<AudioPlayerButton> {
  AudioPlayer? _player;
  bool _loading = false;
  bool _playing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.autoPlay) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _toggle());
    }
  }

  @override
  void dispose() {
    _player?.dispose();
    super.dispose();
  }

  Future<void> _toggle() async {
    if (_playing) {
      await _player?.pause();
      if (mounted) setState(() => _playing = false);
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final player = _player ??= AudioPlayer();
      if (player.audioSource == null) {
        await player.setUrl(widget.url);
        player.playerStateStream.listen((PlayerState state) {
          if (!mounted) return;
          if (state.processingState == ProcessingState.completed) {
            setState(() => _playing = false);
            player.seek(Duration.zero);
          }
        });
      }
      await player.seek(Duration.zero);
      if (mounted) {
        setState(() {
          _loading = false;
          _playing = true;
        });
      }
      widget.onPlayed?.call();
      await player.play();
      if (mounted) setState(() => _playing = false);
    } on Exception {
      if (mounted) {
        setState(() {
          _loading = false;
          _playing = false;
          _error = 'Audio unavailable';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Semantics(
          button: true,
          label: _playing ? 'Pause audio' : widget.label,
          child: PressScale(
            onTap: _toggle,
            child: AnimatedContainer(
              duration: context.motion.standard,
              height: widget.size,
              width: widget.size,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: _playing ? colors.accentGradient : null,
                color: _playing ? null : colors.glassFillStrong,
                border: Border.all(
                  color: _playing
                      ? Colors.transparent
                      : colors.accent.withValues(alpha: 0.45),
                ),
                boxShadow: _playing
                    ? ZabanShadows.glow(colors, intensity: 0.8)
                    : const <BoxShadow>[],
              ),
              child: _loading
                  ? Padding(
                      padding: const EdgeInsets.all(Spacing.lg),
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: colors.accent,
                      ),
                    )
                  : Icon(
                      _playing
                          ? Icons.pause_rounded
                          : Icons.play_arrow_rounded,
                      size: widget.size * 0.42,
                      color: _playing ? colors.textOnAccent : colors.accentSoft,
                    ),
            ),
          ),
        ),
        if (_error != null) ...<Widget>[
          const SizedBox(height: Spacing.sm),
          Text(
            _error!,
            style: context.text.bodySmall?.copyWith(color: colors.warning),
          ),
        ],
      ],
    );
  }
}
