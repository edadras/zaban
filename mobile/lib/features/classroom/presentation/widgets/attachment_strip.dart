import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';
import 'package:zaban/features/lesson/presentation/widgets/audio_player_button.dart';

/// Pictures and recordings hanging off a post.
///
/// An image is shown, because half of what a learner is stuck on is a
/// photograph of a page and a link to it is not an answer. Anything heavier is
/// named rather than played inline: a board should load on a phone on a bus.
class AttachmentStrip extends StatelessWidget {
  const AttachmentStrip({required this.attachments, super.key});

  final List<ClassAttachment> attachments;

  @override
  Widget build(BuildContext context) {
    if (attachments.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: Spacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (final ClassAttachment attachment in attachments)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.sm),
              child: _One(attachment: attachment),
            ),
        ],
      ),
    );
  }
}

class _One extends ConsumerWidget {
  const _One({required this.attachment});

  final ClassAttachment attachment;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final assetId = attachment.mediaAssetId;

    if (assetId == null) return const SizedBox.shrink();

    if (!attachment.isImage && !attachment.isAudio) {
      return _Chip(
        icon: attachment.isVideo
            ? Icons.movie_outlined
            : Icons.attach_file_rounded,
        label: attachment.caption ?? attachment.mime ?? '',
      );
    }

    return ref.watch(_urlProvider(assetId)).maybeWhen(
          orElse: () => _Chip(
            icon: Icons.hourglass_empty_rounded,
            label: context.t('Loading'),
          ),
          data: (String? url) {
            if (url == null) return const SizedBox.shrink();

            if (attachment.isAudio) return AudioPlayerButton(url: url);

            return ClipRRect(
              borderRadius: BorderRadius.circular(Radii.sm),
              child: Image.network(url, fit: BoxFit.contain),
            );
          },
        );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(icon, size: 16, color: context.colors.textSecondary),
        const SizedBox(width: Spacing.xs),
        Flexible(
          child: Text(
            label,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 12, color: context.colors.textSecondary),
          ),
        ),
      ],
    );
  }
}

/// Media links are signed and short-lived, so they are fetched when the post
/// comes on screen rather than carried with the list.
final _urlProvider = FutureProvider.autoDispose.family<String?, int>(
  (ref, int assetId) => ref.watch(classroomRepositoryProvider).mediaUrl(assetId),
);
