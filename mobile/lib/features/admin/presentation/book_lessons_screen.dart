import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/curriculum_book.dart';

/// One book's lessons, each with what it carries and a switch to let it out.
///
/// A lesson that cannot be published says why, in the same words the server
/// refuses it with. That is the point of the screen: not to hide the gap, but
/// to point at it so someone can go and fix the page.
class AdminBookLessonsScreen extends ConsumerWidget {
  const AdminBookLessonsScreen({required this.bookId, super.key});

  final int bookId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(curriculumLessonsProvider(bookId));

    return ZabanScaffold(
      title: 'Lessons',
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(curriculumLessonsProvider(bookId)),
        ),
        data: (List<CurriculumLesson> lessons) {
          if (lessons.isEmpty) {
            return const EmptyView(
              title: 'No lessons',
              message: 'This book imported no lessons.',
            );
          }

          return ResponsiveContent(
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
              itemCount: lessons.length,
              separatorBuilder: (_, __) => const SizedBox(height: Spacing.sm),
              itemBuilder: (BuildContext context, int index) =>
                  _LessonRow(bookId: bookId, lesson: lessons[index]),
            ),
          );
        },
      ),
    );
  }
}

class _LessonRow extends ConsumerStatefulWidget {
  const _LessonRow({required this.bookId, required this.lesson});

  final int bookId;
  final CurriculumLesson lesson;

  @override
  ConsumerState<_LessonRow> createState() => _LessonRowState();
}

class _LessonRowState extends ConsumerState<_LessonRow> {
  bool _busy = false;

  Future<void> _toggle(bool published) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(adminRepositoryProvider)
          .setLessonStatus(widget.lesson.id, published: published);
      if (!mounted) {
        return;
      }
      ref
        ..invalidate(curriculumLessonsProvider(widget.bookId))
        ..invalidate(curriculumBooksProvider);
    } catch (error) {
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('$error')));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final lesson = widget.lesson;
    final colors = context.colors;
    final blocked = lesson.blockedBecause;

    return GlassPanel.compact(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  lesson.unitNumber == null
                      ? lesson.title
                      : '${lesson.unitNumber}. ${lesson.title}',
                  style: context.text.bodyLarge,
                ),
                const SizedBox(height: Spacing.xs),
                Wrap(
                  spacing: Spacing.sm,
                  runSpacing: Spacing.xs,
                  children: <Widget>[
                    _Carries(label: 'activity', on: lesson.hasActivity),
                    _Carries(label: 'choice item', on: lesson.hasRecognitionItem),
                    _Carries(label: 'audio', on: lesson.hasAudio),
                    _Carries(label: 'artwork', on: lesson.hasArtwork),
                  ],
                ),
                if (blocked != null) ...<Widget>[
                  const SizedBox(height: Spacing.xs),
                  Text(
                    'Cannot publish: it $blocked.',
                    style: context.text.bodySmall
                        ?.copyWith(color: colors.warning),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: Spacing.md),
          Switch(
            value: lesson.isPublished,
            onChanged: _busy || (!lesson.publishable && !lesson.isPublished)
                ? null
                : (bool value) => _toggle(value),
          ),
        ],
      ),
    );
  }
}

/// One thing a lesson does or does not carry.
class _Carries extends StatelessWidget {
  const _Carries({required this.label, required this.on});

  final String label;
  final bool on;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(
          on ? Icons.check_circle_rounded : Icons.remove_circle_outline_rounded,
          size: 13,
          color: on ? colors.success : colors.textTertiary,
        ),
        const SizedBox(width: 4),
        Text(
          label,
          style: context.text.labelSmall?.copyWith(
            color: on ? colors.textSecondary : colors.textTertiary,
          ),
        ),
      ],
    );
  }
}
