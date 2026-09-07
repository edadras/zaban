import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/classroom/data/classroom_repository.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// The learner's classes. Invalidate after joining or leaving a room so the
/// "join" button on the list matches the door the server is holding open.
final myClassesProvider = FutureProvider<MyClasses>(
  (ref) => ref.watch(classroomRepositoryProvider).myClasses(),
);

final classHistoryProvider = FutureProvider<List<AttendedClass>>(
  (ref) => ref.watch(classroomRepositoryProvider).history(),
);

/// The bell. Read on the classes screen and on the home screen's badge.
final notificationsProvider =
    FutureProvider<({List<AppNotification> items, int unread})>(
  (ref) => ref.watch(classroomRepositoryProvider).notifications(),
);
