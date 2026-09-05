import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/shadow_tokens.dart';
import 'package:zaban/core/widgets/press_scale.dart';

/// The microphone control: a red light that breathes with the input level.
///
/// The halo is driven by the actual amplitude rather than a canned animation,
/// so the learner can see that the app is hearing them.
class RecordButton extends StatelessWidget {
  const RecordButton({
    required this.recording,
    required this.onPressed,
    super.key,
    this.level = 0,
    this.busy = false,
    this.size = 96,
  });

  final bool recording;
  final VoidCallback? onPressed;
  final double level;
  final bool busy;
  final double size;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final halo = recording ? (0.6 + level * 0.9) : 0.0;

    return Semantics(
      button: true,
      label: recording ? 'Stop recording' : 'Start recording',
      child: PressScale(
        onTap: busy ? null : onPressed,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 140),
          curve: Curves.easeOut,
          height: size,
          width: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: busy ? null : colors.accentGradient,
            color: busy ? colors.glassFillStrong : null,
            boxShadow: <BoxShadow>[
              ...ZabanShadows.glow(colors, intensity: recording ? halo : 0.5),
            ],
          ),
          child: busy
              ? Padding(
                  padding: EdgeInsets.all(size * 0.32),
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: colors.accent,
                  ),
                )
              : Icon(
                  recording ? Icons.stop_rounded : Icons.mic_rounded,
                  size: size * 0.38,
                  color: colors.textOnAccent,
                ),
        ),
      ),
    );
  }
}
