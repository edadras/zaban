import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';

/// Wraps a tappable surface with the app's single press gesture: a small,
/// quick scale-down. Used by every interactive glass surface so touch feedback
/// is identical everywhere.
class PressScale extends StatefulWidget {
  const PressScale({
    required this.child,
    super.key,
    this.onTap,
    this.onLongPress,
    this.scale = 0.975,
    this.enableHover = true,
  });

  final Widget child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final double scale;
  final bool enableHover;

  @override
  State<PressScale> createState() => _PressScaleState();
}

class _PressScaleState extends State<PressScale> {
  bool _pressed = false;
  bool _hovered = false;

  bool get _enabled => widget.onTap != null || widget.onLongPress != null;

  @override
  Widget build(BuildContext context) {
    final motion = context.motion;
    final target = !_enabled
        ? 1.0
        : _pressed
            ? widget.scale
            : (_hovered && widget.enableHover ? 1.005 : 1.0);

    return MouseRegion(
      cursor: _enabled ? SystemMouseCursors.click : MouseCursor.defer,
      onEnter: (_) => _setHovered(true),
      onExit: (_) => _setHovered(false),
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        onLongPress: widget.onLongPress,
        onTapDown: _enabled ? (_) => _setPressed(true) : null,
        onTapUp: _enabled ? (_) => _setPressed(false) : null,
        onTapCancel: _enabled ? () => _setPressed(false) : null,
        child: AnimatedScale(
          scale: target,
          duration: motion.fast,
          curve: Curves.easeOut,
          child: widget.child,
        ),
      ),
    );
  }

  void _setPressed(bool value) {
    if (_pressed == value) return;
    setState(() => _pressed = value);
  }

  void _setHovered(bool value) {
    if (!widget.enableHover || _hovered == value) return;
    setState(() => _hovered = value);
  }
}
