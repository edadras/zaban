import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// The learner's half of the live classroom.
///
/// Deliberately small. A learner sees their classes, joins a room, raises a
/// hand and answers what they are asked; enrolling, muting, sharing and
/// locking are the coach's, and there is no method here that could be made to
/// do them.
class ClassroomRepository {
  const ClassroomRepository(this._client);

  final ApiClient _client;

  Future<MyClasses> myClasses() => _client.get(
        ApiEndpoints.myClasses,
        decode: Decode.object(MyClasses.fromJson),
      );

  Future<List<AttendedClass>> history() => _client.get(
        ApiEndpoints.myClassHistory,
        decode: Decode.list(AttendedClass.fromJson),
      );

  Future<RoomState> room(int sessionId) => _client.get(
        ApiEndpoints.room(sessionId),
        decode: Decode.object(RoomState.fromJson),
      );

  Future<RoomJoin> join(int sessionId) => _client.post(
        ApiEndpoints.roomJoin(sessionId),
        decode: Decode.object(RoomJoin.fromJson),
      );

  Future<void> leave(int sessionId) => _client.post(
        ApiEndpoints.roomLeave(sessionId),
        decode: Decode.none,
      );

  /// A fresh room key, for a client whose token is ageing out mid-class.
  Future<RoomCredentials> refreshToken(int sessionId) => _client.post(
        ApiEndpoints.roomToken(sessionId),
        decode: Decode.object(RoomCredentials.fromJson),
      );

  Future<void> raiseHand(int sessionId, {required bool raised}) => _client.post(
        ApiEndpoints.roomHand(sessionId),
        body: <String, dynamic>{'raised': raised},
        decode: Decode.none,
      );

  /// Answering. A poll sends the option indexes it chose; an open question
  /// sends text. Whether it was right is the server's to decide.
  Future<Map<String, dynamic>> answer({
    required int sessionId,
    required int questionId,
    String? body,
    List<int>? selectedOptions,
  }) =>
      _client.post(
        ApiEndpoints.roomAnswer(sessionId, questionId),
        body: <String, dynamic>{
          if (body != null) 'body': body,
          if (selectedOptions != null) 'selected_options': selectedOptions,
        },
        decode: Decode.map,
      );

  Future<({List<AppNotification> items, int unread})> notifications({
    bool unreadOnly = false,
  }) async {
    final page = await _client.getPage(
      ApiEndpoints.notifications,
      fromJson: AppNotification.fromJson,
      query: <String, dynamic>{if (unreadOnly) 'unread': 1},
    );

    final unread = page.envelope.meta?['unread'];

    return (
      items: page.items,
      unread: unread is num ? unread.toInt() : 0,
    );
  }

  Future<void> markRead(String id) => _client.post(
        ApiEndpoints.notificationRead(id),
        decode: Decode.none,
      );

  Future<void> markAllRead() => _client.post(
        ApiEndpoints.notificationsReadAll,
        decode: Decode.none,
      );

  /// Resolve a material's asset to something playable. The link is signed and
  /// short-lived, so it is fetched when the material goes on screen rather
  /// than cached with the room.
  Future<String?> mediaUrl(int assetId) async {
    final asset = await _client.get(
      ApiEndpoints.media(assetId),
      decode: Decode.map,
    );

    return asset['url'] as String?;
  }
}

final classroomRepositoryProvider = Provider<ClassroomRepository>(
  (ref) => ClassroomRepository(ref.watch(apiClientProvider)),
);
