import 'package:flutter/material.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';

/// Quiet loading state: a small accent spinner and nothing else. No skeleton
/// shimmer — on a dark glass UI it reads as noise.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SizedBox(
            height: 26,
            width: 26,
            child: CircularProgressIndicator(
              strokeWidth: 2.2,
              color: context.colors.accent,
            ),
          ),
          if (message != null) ...<Widget>[
            const SizedBox(height: Spacing.lg),
            Text(message!, style: context.text.bodyMedium),
          ],
        ],
      ),
    );
  }
}

/// Renders a failure the way the backend framed it.
///
/// The client does not decide what is behind a paywall — it only recognises the
/// error kind the server returned and offers the matching action.
class ErrorView extends StatelessWidget {
  const ErrorView({
    required this.error,
    super.key,
    this.onRetry,
    this.onUpgrade,
    this.onSignIn,
    this.compact = false,
  });

  final Object error;
  final VoidCallback? onRetry;
  final VoidCallback? onUpgrade;
  final VoidCallback? onSignIn;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final api = error is ApiException ? error as ApiException : null;
    final kind = api?.kind ?? ApiErrorKind.unknown;

    final title = switch (kind) {
      ApiErrorKind.network => 'You are offline',
      ApiErrorKind.timeout => 'That took too long',
      ApiErrorKind.paywall => 'Included in a paid plan',
      ApiErrorKind.unauthorized => 'Please sign in again',
      ApiErrorKind.forbidden => 'Not available on your account',
      ApiErrorKind.notFound => 'Not found',
      ApiErrorKind.rateLimited => 'Slow down a moment',
      ApiErrorKind.server => 'The server had a problem',
      _ => 'Something went wrong',
    };

    final icon = switch (kind) {
      ApiErrorKind.network || ApiErrorKind.timeout => Icons.cloud_off_rounded,
      ApiErrorKind.paywall => Icons.workspace_premium_rounded,
      ApiErrorKind.unauthorized => Icons.lock_outline_rounded,
      _ => Icons.error_outline_rounded,
    };

    final message = api?.message ?? 'Please try again.';

    final actions = <Widget>[
      if (kind == ApiErrorKind.paywall && onUpgrade != null)
        GlowButton(
          label: context.t('See plans'),
          onPressed: onUpgrade,
          icon: Icons.workspace_premium_rounded,
        ),
      if (kind == ApiErrorKind.unauthorized && onSignIn != null)
        GlowButton(label: context.t('Sign in'), onPressed: onSignIn),
      if (onRetry != null && kind != ApiErrorKind.paywall)
        GlowButton(
          label: context.t('Try again'),
          onPressed: onRetry,
          variant: kind == ApiErrorKind.unauthorized
              ? GlowButtonVariant.ghost
              : GlowButtonVariant.primary,
          icon: Icons.refresh_rounded,
        ),
    ];

    final body = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            Icon(icon, color: colors.accent, size: 20),
            const SizedBox(width: Spacing.sm),
            Expanded(child: Text(title, style: context.text.titleLarge)),
          ],
        ),
        const SizedBox(height: Spacing.sm),
        Text(message, style: context.text.bodyMedium),
        if (actions.isNotEmpty) ...<Widget>[
          const SizedBox(height: Spacing.lg),
          Wrap(spacing: Spacing.md, runSpacing: Spacing.sm, children: actions),
        ],
      ],
    );

    if (compact) return body;

    return Center(
      child: Padding(
        padding: Spacing.screen,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: GlassPanel(child: body),
        ),
      ),
    );
  }
}

/// Used when a request succeeded but there is nothing to show.
class EmptyView extends StatelessWidget {
  const EmptyView({
    required this.title,
    super.key,
    this.message,
    this.icon = Icons.auto_awesome_rounded,
    this.action,
  });

  final String title;
  final String? message;
  final IconData icon;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Center(
      child: Padding(
        padding: Spacing.screen,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              height: 56,
              width: 56,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: colors.accentSurface,
                border: Border.all(
                  color: colors.accent.withValues(alpha: 0.35),
                ),
              ),
              child: Icon(icon, color: colors.accentSoft),
            ),
            const SizedBox(height: Spacing.lg),
            Text(title, style: context.text.titleLarge),
            if (message != null) ...<Widget>[
              const SizedBox(height: Spacing.sm),
              Text(
                message!,
                style: context.text.bodyMedium,
                textAlign: TextAlign.center,
              ),
            ],
            if (action != null) ...<Widget>[
              const SizedBox(height: Spacing.xl),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}
