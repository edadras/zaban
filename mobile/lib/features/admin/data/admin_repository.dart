import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/admin/data/models/admin_overview.dart';
import 'package:zaban/features/admin/data/models/curriculum_book.dart';

/// The admin half of the API. Reachable only by an admin, editor or reviewer;
/// the server enforces that and the router hides it.
class AdminRepository {
  const AdminRepository(this._client);

  final ApiClient _client;

  Future<List<CurriculumBook>> books() => _client.get(
        ApiEndpoints.adminCurriculumBooks,
        decode: Decode.list(CurriculumBook.fromJson),
      );

  Future<List<CurriculumLesson>> lessons(int bookId, {int perPage = 200}) =>
      _client.get(
        ApiEndpoints.adminCurriculumLessons(bookId),
        query: <String, dynamic>{'per_page': perPage},
        // The route paginates, so the rows sit under the paginator's own key.
        decode: (Object? data) {
          final rows = data is Map ? data['data'] : data;
          return Decode.list(CurriculumLesson.fromJson)(rows);
        },
      );

  Future<void> setLessonStatus(int lessonId, {required bool published}) =>
      _client.patch(
        ApiEndpoints.adminCurriculumLesson(lessonId),
        body: <String, dynamic>{'status': published ? 'published' : 'draft'},
        decode: Decode.none,
      );

  /// Publishes every lesson of the book that clears the bar. Returns how many
  /// went out and how many were held back, so the caller can say so.
  Future<({int published, int heldBack})> publishBook(int bookId) async {
    final result = await _client.post(
      ApiEndpoints.adminCurriculumPublish(bookId),
      decode: Decode.map,
    );

    return (
      published: (result['published_now'] as num?)?.toInt() ?? 0,
      heldBack: (result['held_back'] as num?)?.toInt() ?? 0,
    );
  }

  Future<IngestionSummary> ingestionSummary() => _client.get(
        ApiEndpoints.adminIngestionSummary,
        decode: Decode.object(IngestionSummary.fromJson),
      );

  Future<AiOverview> aiOverview({int days = 30}) => _client.get(
        ApiEndpoints.adminAiOverview,
        query: <String, dynamic>{'days': days},
        decode: Decode.object(AiOverview.fromJson),
      );

  Future<List<ReviewItem>> reviewQueue({int perPage = 25}) => _client.get(
        ApiEndpoints.adminReviewQueue,
        query: <String, dynamic>{'per_page': perPage},
        decode: (Object? data) {
          final rows = data is Map ? data['data'] : data;
          return Decode.list(ReviewItem.fromJson)(rows);
        },
      );

  Future<int> withdrawBook(int bookId) async {
    final result = await _client.post(
      ApiEndpoints.adminCurriculumWithdraw(bookId),
      decode: Decode.map,
    );

    return (result['withdrawn'] as num?)?.toInt() ?? 0;
  }
}

final adminRepositoryProvider = Provider<AdminRepository>(
  (ref) => AdminRepository(ref.watch(apiClientProvider)),
);

final curriculumBooksProvider = FutureProvider<List<CurriculumBook>>(
  (ref) => ref.watch(adminRepositoryProvider).books(),
);

final ingestionSummaryProvider = FutureProvider<IngestionSummary>(
  (ref) => ref.watch(adminRepositoryProvider).ingestionSummary(),
);

final aiOverviewProvider = FutureProvider<AiOverview>(
  (ref) => ref.watch(adminRepositoryProvider).aiOverview(),
);

final reviewQueueProvider = FutureProvider<List<ReviewItem>>(
  (ref) => ref.watch(adminRepositoryProvider).reviewQueue(),
);

final curriculumLessonsProvider =
    FutureProvider.family<List<CurriculumLesson>, int>(
  (ref, bookId) => ref.watch(adminRepositoryProvider).lessons(bookId),
);
