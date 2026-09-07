import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/classroom/data/board_repository.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';

/// One class's board.
final classBoardProvider =
    FutureProvider.autoDispose.family<List<ClassThread>, int>(
  (ref, int groupId) => ref.watch(boardRepositoryProvider).threads(groupId),
);

/// One thread and everything under it.
final threadProvider = FutureProvider.autoDispose.family<ThreadView, int>(
  (ref, int threadId) => ref.watch(boardRepositoryProvider).thread(threadId),
);

/// Everything this learner has been set, across all their classes.
final myHomeworkProvider = FutureProvider<List<HomeworkEntry>>(
  (ref) => ref.watch(boardRepositoryProvider).myHomework(),
);

final assignmentProvider =
    FutureProvider.autoDispose.family<AssignmentView, int>(
  (ref, int id) => ref.watch(boardRepositoryProvider).assignment(id),
);
