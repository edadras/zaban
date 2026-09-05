import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/review/data/models/review_queue.dart';

class ReviewRepository {
  const ReviewRepository(this._client);

  final ApiClient _client;

  /// `GET /reviews/due` — most-forgotten first, as ordered by the engine.
  ///
  /// The total is carried in the envelope's `meta`, which is why this reads the
  /// envelope rather than just the payload.
  Future<ReviewQueue> due({int limit = 20}) async {
    final page = await _client.getPage(
      ApiEndpoints.reviewsDue,
      fromJson: ReviewItem.fromJson,
      query: <String, dynamic>{'limit': limit},
    );

    final total = page.envelope.meta?['total_due'];
    return ReviewQueue(
      items: page.items,
      dueCount: total is num ? total.toInt() : page.items.length,
    );
  }

  Future<int> dueCount() => _client.get(
        ApiEndpoints.reviewCounts,
        decode: (Object? data) {
          final value = (data as Map?)?['due'];
          return value is num ? value.toInt() : 0;
        },
      );
}

final reviewRepositoryProvider = Provider<ReviewRepository>(
  (ref) => ReviewRepository(ref.watch(apiClientProvider)),
);

/// Used by the navigation badge; cheap enough to refresh often.
final dueCountProvider = FutureProvider<int>(
  (ref) => ref.watch(reviewRepositoryProvider).dueCount(),
);
