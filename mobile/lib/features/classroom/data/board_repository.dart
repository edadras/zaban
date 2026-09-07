import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zaban/core/network/api_client.dart';
import 'package:zaban/core/network/api_endpoints.dart';
import 'package:zaban/core/network/network_providers.dart';
import 'package:zaban/features/classroom/data/models/board_models.dart';

/// The class's board and its homework, from the learner's side.
///
/// Uploads go as multipart because a question about a page of a book is a
/// photograph of that page, and that is the common case rather than the
/// exception.
class BoardRepository {
  const BoardRepository(this._client);

  final ApiClient _client;

  // ---------------------------------------------------------------- board

  Future<List<ClassThread>> threads(int groupId, {String? query}) => _client.get(
        ApiEndpoints.threads(groupId),
        query: <String, dynamic>{if (query != null && query.isNotEmpty) 'q': query},
        decode: Decode.list(ClassThread.fromJson),
      );

  Future<ThreadView> thread(int threadId) => _client.get(
        ApiEndpoints.thread(threadId),
        decode: Decode.object(ThreadView.fromJson),
      );

  Future<ClassThread> ask(
    int groupId, {
    required String title,
    String? body,
    List<File> files = const <File>[],
  }) async {
    if (files.isEmpty) {
      return _client.post(
        ApiEndpoints.threads(groupId),
        body: <String, dynamic>{'title': title, if (body != null) 'body': body},
        decode: Decode.object(ClassThread.fromJson),
      );
    }

    return _client.upload(
      ApiEndpoints.threads(groupId),
      form: await _form(<String, dynamic>{'title': title, 'body': body}, files),
      decode: Decode.object(ClassThread.fromJson),
    );
  }

  Future<ClassThreadReply> reply(
    int threadId, {
    required String body,
    List<File> files = const <File>[],
  }) async {
    if (files.isEmpty) {
      return _client.post(
        ApiEndpoints.threadReplies(threadId),
        body: <String, dynamic>{'body': body},
        decode: Decode.object(ClassThreadReply.fromJson),
      );
    }

    return _client.upload(
      ApiEndpoints.threadReplies(threadId),
      form: await _form(<String, dynamic>{'body': body}, files),
      decode: Decode.object(ClassThreadReply.fromJson),
    );
  }

  /// The person who asked marking the answer that answered it.
  Future<void> accept(int threadId, int replyId) => _client.post(
        ApiEndpoints.threadAccept(threadId, replyId),
        decode: Decode.none,
      );

  /// One person, one vote; pressing it again takes it back.
  Future<Map<String, dynamic>> helpful(int threadId, int replyId) => _client.post(
        ApiEndpoints.threadHelpful(threadId, replyId),
        decode: Decode.map,
      );

  // ------------------------------------------------------------- homework

  Future<List<HomeworkEntry>> myHomework() => _client.get(
        ApiEndpoints.myHomework,
        decode: Decode.list(HomeworkEntry.fromJson),
      );

  Future<AssignmentView> assignment(int assignmentId) => _client.get(
        ApiEndpoints.homework(assignmentId),
        decode: Decode.object(AssignmentView.fromJson),
      );

  /// Hand it in.
  ///
  /// A spoken piece is uploaded to the speech endpoint first and passed in by
  /// id, so there is one upload path for audio in the whole app and consent
  /// and retention are enforced in one place.
  Future<HomeworkSubmission> submit(
    int assignmentId, {
    String? body,
    Map<int, List<int>> selectedOptions = const <int, List<int>>{},
    int? speechAttemptId,
    int? writingAttemptId,
    List<File> files = const <File>[],
  }) async {
    if (files.isEmpty) {
      return _client.post(
        ApiEndpoints.homeworkSubmit(assignmentId),
        body: <String, dynamic>{
          if (body != null) 'body': body,
          if (speechAttemptId != null) 'speech_attempt_id': speechAttemptId,
          if (writingAttemptId != null) 'writing_attempt_id': writingAttemptId,
          if (selectedOptions.isNotEmpty)
            'responses': <String, dynamic>{
              for (final MapEntry<int, List<int>> e in selectedOptions.entries)
                '${e.key}': <String, dynamic>{'selected_options': e.value},
            },
        },
        decode: Decode.object(HomeworkSubmission.fromJson),
      );
    }

    // Multipart cannot nest, so the same shape is spelled out in the field
    // names Laravel unpacks it from.
    final fields = <String, dynamic>{
      if (body != null) 'body': body,
      if (speechAttemptId != null) 'speech_attempt_id': speechAttemptId,
      if (writingAttemptId != null) 'writing_attempt_id': writingAttemptId,
      for (final MapEntry<int, List<int>> e in selectedOptions.entries)
        'responses[${e.key}][selected_options]': e.value,
    };

    return _client.upload(
      ApiEndpoints.homeworkSubmit(assignmentId),
      form: await _form(fields, files),
      decode: Decode.object(HomeworkSubmission.fromJson),
    );
  }

  // ------------------------------------------------------------- private

  Future<FormData> _form(Map<String, dynamic> fields, List<File> files) async {
    final form = FormData();

    fields.forEach((String key, dynamic value) {
      if (value == null) return;

      if (value is List) {
        for (final dynamic item in value) {
          form.fields.add(MapEntry<String, String>('$key[]', '$item'));
        }
        return;
      }

      form.fields.add(MapEntry<String, String>(key, '$value'));
    });

    for (final File file in files) {
      form.files.add(MapEntry<String, MultipartFile>(
        'files[]',
        await MultipartFile.fromFile(file.path),
      ));
    }

    return form;
  }
}

final boardRepositoryProvider = Provider<BoardRepository>(
  (ref) => BoardRepository(ref.watch(apiClientProvider)),
);
