import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/features/home/data/home_repository.dart';
import 'package:zaban/features/home/data/models/home_snapshot.dart';

/// Today's dashboard payload. Invalidate it after anything that changes learner
/// state (finishing a session, completing reviews, changing the daily target).
final homeSnapshotProvider = FutureProvider<HomeSnapshot>(
  (ref) => ref.watch(homeRepositoryProvider).snapshot(),
);
