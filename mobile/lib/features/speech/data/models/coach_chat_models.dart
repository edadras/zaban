/// Plain models for corrective coach chat (no freezed — kept light).
class CoachChatSession {
  const CoachChatSession({
    required this.id,
    required this.status,
    required this.turnCount,
    required this.messages,
  });

  final int id;
  final String status;
  final int turnCount;
  final List<CoachChatMessage> messages;

  bool get isActive => status == 'active';

  factory CoachChatSession.fromJson(Map<String, dynamic> json) {
    final raw = json['messages'];
    return CoachChatSession(
      id: (json['id'] as num).toInt(),
      status: json['status'] as String? ?? 'active',
      turnCount: (json['turn_count'] as num?)?.toInt() ?? 0,
      messages: raw is List
          ? raw
              .whereType<Map>()
              .map(
                (Map row) =>
                    CoachChatMessage.fromJson(Map<String, dynamic>.from(row)),
              )
              .toList()
          : const <CoachChatMessage>[],
    );
  }
}

class CoachChatMessage {
  const CoachChatMessage({
    required this.id,
    required this.position,
    required this.role,
    required this.text,
    this.textFa,
    this.correctedText,
    this.coaching,
    this.speechAttemptId,
  });

  final int id;
  final int position;

  /// learner | coach
  final String role;
  final String text;
  final String? textFa;
  final String? correctedText;
  final CoachTurnCoaching? coaching;
  final int? speechAttemptId;

  bool get isCoach => role == 'coach';
  bool get isLearner => role == 'learner';

  factory CoachChatMessage.fromJson(Map<String, dynamic> json) {
    final coachingRaw = json['coaching'];
    return CoachChatMessage(
      id: (json['id'] as num?)?.toInt() ?? 0,
      position: (json['position'] as num?)?.toInt() ?? 0,
      role: json['role'] as String? ?? 'coach',
      text: json['text'] as String? ?? '',
      textFa: json['text_fa'] as String?,
      correctedText: json['corrected_text'] as String?,
      coaching: coachingRaw is Map
          ? CoachTurnCoaching.fromJson(Map<String, dynamic>.from(coachingRaw))
          : null,
      speechAttemptId: (json['speech_attempt_id'] as num?)?.toInt(),
    );
  }
}

class CoachTurnCoaching {
  const CoachTurnCoaching({
    this.grammar = const <CoachBilingualNote>[],
    this.vocabulary = const <CoachVocabNote>[],
    this.pronunciation = const <CoachBilingualNote>[],
  });

  final List<CoachBilingualNote> grammar;
  final List<CoachVocabNote> vocabulary;
  final List<CoachBilingualNote> pronunciation;

  bool get isEmpty =>
      grammar.isEmpty && vocabulary.isEmpty && pronunciation.isEmpty;

  factory CoachTurnCoaching.fromJson(Map<String, dynamic> json) {
    List<Map<String, dynamic>> asMaps(Object? raw) {
      if (raw is! List) return const <Map<String, dynamic>>[];
      return raw
          .whereType<Map>()
          .map((Map row) => Map<String, dynamic>.from(row))
          .toList();
    }

    return CoachTurnCoaching(
      grammar: asMaps(json['grammar'])
          .map(CoachBilingualNote.fromJson)
          .toList(),
      vocabulary: asMaps(json['vocabulary']).map(CoachVocabNote.fromJson).toList(),
      pronunciation: asMaps(json['pronunciation'])
          .map(CoachBilingualNote.fromJson)
          .toList(),
    );
  }
}

class CoachBilingualNote {
  const CoachBilingualNote({required this.en, this.fa});

  final String en;
  final String? fa;

  factory CoachBilingualNote.fromJson(Map<String, dynamic> json) =>
      CoachBilingualNote(
        en: json['en'] as String? ?? '',
        fa: json['fa'] as String?,
      );
}

class CoachVocabNote {
  const CoachVocabNote({
    required this.word,
    required this.meaningEn,
    this.meaningFa,
    this.example,
  });

  final String word;
  final String meaningEn;
  final String? meaningFa;
  final String? example;

  factory CoachVocabNote.fromJson(Map<String, dynamic> json) => CoachVocabNote(
        word: json['word'] as String? ?? '',
        meaningEn: json['meaning_en'] as String? ?? '',
        meaningFa: json['meaning_fa'] as String?,
        example: json['example'] as String?,
      );
}
