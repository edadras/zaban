import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/section_header.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/curriculum_book.dart';
import 'package:zaban/features/admin/presentation/widgets/coverage_bar.dart';

/// The curriculum, book by book, and the button that lets a book out.
///
/// Every lesson imports as a draft — the pipeline reads scanned pages and some
/// of what it produces is a heading the scanner invented. This is where a
/// person looks at what a book actually carries and decides.
///
/// The publish button never releases everything. The server publishes only the
/// lessons that teach something and hold an activity, and returns how many it
/// held back; that number is shown, because it is the one an editor acts on.
class AdminCurriculumScreen extends ConsumerWidget {
  const AdminCurriculumScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(curriculumBooksProvider);

    return ZabanScaffold(
      title: 'Curriculum',
      body: async.when(
        loading: () => const LoadingView(),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(curriculumBooksProvider),
        ),
        data: (List<CurriculumBook> books) {
          if (books.isEmpty) {
            return const EmptyView(
              title: 'No books imported',
              message: 'Run the content import before publishing anything.',
            );
          }

          return RefreshIndicator(
            color: context.colors.accent,
            backgroundColor: context.colors.surface,
            onRefresh: () async {
              ref.invalidate(curriculumBooksProvider);
              await ref.read(curriculumBooksProvider.future);
            },
            child: ResponsiveContent(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: Spacing.lg),
                children: <Widget>[
                  _Totals(books: books),
                  const SizedBox(height: Spacing.lg),
                  const SectionHeader(
                    title: 'Books',
                    eyebrow: 'draft until someone releases them',
                  ),
                  const SizedBox(height: Spacing.md),
                  for (final CurriculumBook book in books) ...<Widget>[
                    _BookCard(book: book),
                    const SizedBox(height: Spacing.md),
                  ],
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// The corpus in one line, because "how much is out?" is the first question.
class _Totals extends StatelessWidget {
  const _Totals({required this.books});

  final List<CurriculumBook> books;

  @override
  Widget build(BuildContext context) {
    int sum(int Function(CurriculumBook) f) =>
        books.fold(0, (int total, CurriculumBook b) => total + f(b));

    final lessons = sum((CurriculumBook b) => b.lessons);
    final published = sum((CurriculumBook b) => b.published);
    final ready = sum((CurriculumBook b) => b.ready);

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('$published of $lessons lessons published',
              style: context.text.titleMedium),
          const SizedBox(height: Spacing.xs),
          Text(
            ready > published
                ? '${ready - published} more are ready to go out.'
                : 'Everything that clears the bar is out.',
            style: context.text.bodyMedium
                ?.copyWith(color: context.colors.textSecondary),
          ),
          const SizedBox(height: Spacing.md),
          CoverageBar(
            label: 'Something a learner can do',
            count: sum((CurriculumBook b) => b.coverage.activity),
            total: lessons,
          ),
          const SizedBox(height: Spacing.sm),
          CoverageBar(
            label: 'Can be asked a choice question',
            count: sum((CurriculumBook b) => b.coverage.recognition),
            total: lessons,
            color: context.colors.info,
          ),
          const SizedBox(height: Spacing.sm),
          CoverageBar(
            label: 'Has audio',
            count: sum((CurriculumBook b) => b.coverage.audio),
            total: lessons,
            color: context.colors.success,
          ),
          const SizedBox(height: Spacing.sm),
          CoverageBar(
            label: 'Has artwork',
            count: sum((CurriculumBook b) => b.coverage.artwork),
            total: lessons,
            color: context.colors.warning,
          ),
        ],
      ),
    );
  }
}

class _BookCard extends ConsumerStatefulWidget {
  const _BookCard({required this.book});

  final CurriculumBook book;

  @override
  ConsumerState<_BookCard> createState() => _BookCardState();
}

class _BookCardState extends ConsumerState<_BookCard> {
  bool _busy = false;

  Future<void> _run(Future<String> Function() action) async {
    setState(() => _busy = true);
    try {
      final message = await action();
      if (!mounted) {
        return;
      }
      ref.invalidate(curriculumBooksProvider);
      ref.invalidate(curriculumLessonsProvider(widget.book.id));
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
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
    final book = widget.book;
    final colors = context.colors;

    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(book.title, style: context.text.titleSmall),
              ),
              Text(
                '${book.published} / ${book.lessons} out',
                style: context.text.labelMedium?.copyWith(
                  color: book.published == 0
                      ? colors.textTertiary
                      : colors.success,
                ),
              ),
            ],
          ),
          const SizedBox(height: Spacing.md),
          CoverageBar(
            label: 'Ready to publish',
            count: book.ready,
            total: book.lessons,
          ),
          const SizedBox(height: Spacing.sm),
          CoverageBar(
            label: 'Audio',
            count: book.coverage.audio,
            total: book.lessons,
            color: colors.success,
          ),
          const SizedBox(height: Spacing.sm),
          CoverageBar(
            label: 'Artwork',
            count: book.coverage.artwork,
            total: book.lessons,
            color: colors.warning,
          ),
          const SizedBox(height: Spacing.lg),
          Wrap(
            spacing: Spacing.sm,
            runSpacing: Spacing.sm,
            children: <Widget>[
              GlowButton(
                label: book.awaitingRelease > 0
                    ? 'Publish ${book.awaitingRelease}'
                    : 'Publish',
                size: GlowButtonSize.small,
                isLoading: _busy,
                onPressed: _busy || book.awaitingRelease == 0
                    ? null
                    : () => _run(() async {
                          final result = await ref
                              .read(adminRepositoryProvider)
                              .publishBook(book.id);

                          return result.heldBack == 0
                              ? '${result.published} lessons published.'
                              : '${result.published} published, '
                                  '${result.heldBack} held back — they have '
                                  'nothing for a learner to do.';
                        }),
              ),
              GlowButton(
                label: 'Withdraw',
                variant: GlowButtonVariant.ghost,
                size: GlowButtonSize.small,
                onPressed: _busy || book.published == 0
                    ? null
                    : () => _run(() async {
                          final count = await ref
                              .read(adminRepositoryProvider)
                              .withdrawBook(book.id);

                          return '$count lessons withdrawn.';
                        }),
              ),
              GlowButton(
                label: 'Lessons',
                variant: GlowButtonVariant.ghost,
                size: GlowButtonSize.small,
                onPressed: () =>
                    context.go(AppRoute.adminBook.bookPath(book.id)),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
