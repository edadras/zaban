import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/admin/data/models/admin_overview.dart';
import 'package:zaban/features/admin/data/models/billing_overview.dart';
import 'package:zaban/features/admin/data/models/curriculum_book.dart';
import 'package:zaban/features/subscription/data/models/manual_payment.dart';

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

  Future<BillingOverview> billingOverview({int days = 30}) => _client.get(
        ApiEndpoints.adminBillingOverview,
        query: <String, dynamic>{'days': days},
        decode: Decode.object(BillingOverview.fromJson),
      );

  Future<List<ManualPaymentSubmission>> manualPayments({String? status}) =>
      _client.get(
        ApiEndpoints.adminManualPayments,
        query: <String, dynamic>{
          if (status != null) 'status': status,
          'per_page': 50,
        },
        decode: (Object? data) {
          final rows = data is Map ? data['data'] : data;
          return Decode.list(ManualPaymentSubmission.fromJson)(rows);
        },
      );

  Future<ManualPaymentSubmission> approveManualPayment(
    int id, {
    String? note,
  }) =>
      _client.post(
        ApiEndpoints.adminManualPaymentApprove(id),
        body: <String, dynamic>{if (note != null) 'note': note},
        decode: Decode.object(ManualPaymentSubmission.fromJson),
      );

  Future<ManualPaymentSubmission> rejectManualPayment(
    int id, {
    String? note,
  }) =>
      _client.post(
        ApiEndpoints.adminManualPaymentReject(id),
        body: <String, dynamic>{if (note != null) 'note': note},
        decode: Decode.object(ManualPaymentSubmission.fromJson),
      );

  Future<List<AdminUserRow>> users({String? q}) => _client.get(
        ApiEndpoints.adminUsers,
        query: <String, dynamic>{
          if (q != null && q.isNotEmpty) 'q': q,
          'per_page': 50,
        },
        decode: (Object? data) {
          final rows = data is Map ? data['data'] : data;
          return Decode.list(AdminUserRow.fromJson)(rows);
        },
      );

  Future<Map<String, dynamic>> userDetail(int id) => _client.get(
        ApiEndpoints.adminUser(id),
        decode: Decode.map,
      );

  Future<void> updateUser(
    int id, {
    String? role,
    String? status,
  }) =>
      _client.patch(
        ApiEndpoints.adminUser(id),
        body: <String, dynamic>{
          if (role != null) 'role': role,
          if (status != null) 'status': status,
        },
        decode: Decode.none,
      );

  Future<Map<String, dynamic>> rialSettings() => _client.get(
        ApiEndpoints.adminRialSettings,
        decode: Decode.map,
      );

  Future<Map<String, dynamic>> updateRialSettings(Map<String, dynamic> body) =>
      _client.patch(
        ApiEndpoints.adminRialSettings,
        body: body,
        decode: Decode.map,
      );
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

final billingOverviewProvider = FutureProvider<BillingOverview>(
  (ref) => ref.watch(adminRepositoryProvider).billingOverview(),
);

final adminManualPaymentsProvider =
    FutureProvider<List<ManualPaymentSubmission>>(
  (ref) => ref.watch(adminRepositoryProvider).manualPayments(),
);

final adminUsersProvider = FutureProvider<List<AdminUserRow>>(
  (ref) => ref.watch(adminRepositoryProvider).users(),
);
