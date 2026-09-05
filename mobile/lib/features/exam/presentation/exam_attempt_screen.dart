import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/exam/data/models/exam_models.dart';
import 'package:zaban/features/exam/presentation/exam_controller.dart';
import 'package:zaban/features/exam/presentation/widgets/exam_timer.dart';
import 'package:zaban/features/lesson/data/models/attempt_result.dart';
import 'package:zaban/features/lesson/data/models/exercise.dart';
import 'package:zaban/features/lesson/presentation/exercises/exercise_renderer.dart';
import 'package:zaban/features/lesson/presentation/widgets/exercise_shell.dart';
import 'package:zaban/features/speech/data/recorder_service.dart';
import 'package:zaban/features/speech/data/speech_repository.dart';

/// One task at a time, under the engine's clock.
///
/// Nothing is marked in front of the learner: objective answers are keyed
/// server-side and productive work is scored when the attempt is finished,
/// which is what makes this exam practice rather than a lesson.
class ExamAttemptScreen extends ConsumerStatefulWidget {
  const ExamAttemptScreen({required this.attemptId, super.key});

  final int attemptId;

  @override
  ConsumerState<ExamAttemptScreen> createState() => _ExamAttemptScreenState();
}

class _ExamAttemptScreenState extends ConsumerState<ExamAttemptScreen> {
  final Map<int, Object> _answers = <int, Object>{};
  final TextEditingController _writing = TextEditingController();
  final List<int> _speechAttemptIds = <int>[];

  Stopwatch _taskTimer = Stopwatch()..start();
  int? _currentTaskId;
  bool _recording = false;

  @override
  void dispose() {
    _writing.dispose();
    super.dispose();
  }

  void _resetFor(int taskId) {
    _currentTaskId = taskId;
    _answers.clear();
    _speechAttemptIds.clear();
    _writing.clear();
    _taskTimer = Stopwatch()..start();
  }

  Future<void> _submit(ExamTaskEnvelope envelope) async {
    final controller =
        ref.read(examControllerProvider(widget.attemptId).notifier);

    await controller.submit(
      answers: envelope.kind == 'objective' ? Map<int, Object>.from(_answers) : null,
      text: envelope.kind == 'writing' ? _writing.text.trim() : null,
      speechAttemptIds:
          envelope.kind == 'speaking' ? List<int>.from(_speechAttemptIds) : null,
      secondsUsed: _taskTimer.elapsed.inSeconds,
    );
  }

  /// Records one spoken answer and uploads it; the exam only needs the id of
  /// the stored attempt, and scoring happens with the rest of the section.
  Future<void> _toggleRecording() async {
    final recorder = ref.read(recorderServiceProvider);

    if (_recording) {
      setState(() => _recording = false);
      final recording = await recorder.stop();
      if (recording == null || recording.isEmpty) return;

      try {
        final attempt = await ref.read(speechRepositoryProvider).upload(
              recording: recording,
            );
        setState(() => _speechAttemptIds.add(attempt.id));
      } on ApiException catch (error) {
        if (!mounted) return;
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
      return;
    }

    try {
      await recorder.start();
      setState(() => _recording = true);
    } on Exception catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('$error')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final provider = examControllerProvider(widget.attemptId);
    final async = ref.watch(provider);

    ref.listen<Object?>(examErrorProvider, (Object? _, Object? error) {
      if (error == null) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            error is ApiException ? error.message : 'Something went wrong.',
          ),
        ),
      );
      ref.read(examErrorProvider.notifier).state = null;
    });

    ref.listen<AsyncValue<ExamRunState>>(provider, (_, AsyncValue<ExamRunState> next) {
      final state = next.valueOrNull;
      if (state?.isFinished ?? false) {
        context.pushReplacement(
          AppRoute.examResult.examResultPath(widget.attemptId),
        );
      }
    });

    return ZabanScaffold(
      ambient: false,
      title: async.valueOrNull?.task.section?.name ?? 'Exam',
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: () => Navigator.of(context).maybePop(),
      ),
      body: async.when(
        loading: () => const LoadingView(message: 'Preparing your paper…'),
        error: (Object error, StackTrace _) => ErrorView(
          error: error,
          onRetry: () => ref.invalidate(provider),
          onUpgrade: () => context.push(AppRoute.plans.path),
        ),
        data: (ExamRunState state) {
          if (state.isFinished || state.task.complete) {
            return const LoadingView(message: 'Marking your paper…');
          }

          final envelope = state.task;
          final task = envelope.task;
          if (task == null) {
            return const EmptyView(
              title: 'This sitting has no tasks',
              icon: Icons.error_outline_rounded,
            );
          }

          if (_currentTaskId != task.id) {
            // A new task: clear the previous answers before the frame builds.
            WidgetsBinding.instance.addPostFrameCallback(
              (_) => setState(() => _resetFor(task.id)),
            );
          }

          return Column(
            children: <Widget>[
              _TaskHeader(
                envelope: envelope,
                onExpired: () => _submit(envelope),
              ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.only(bottom: Spacing.huge),
                  children: <Widget>[
                    ResponsiveContent(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          _TaskBrief(task: task),
                          const SizedBox(height: Spacing.lg),
                          if (envelope.kind == 'objective')
                            _ObjectiveTask(
                              exercises: envelope.exercises,
                              answers: _answers,
                              onAnswer: (int id, Object value) =>
                                  setState(() => _answers[id] = value),
                            )
                          else if (envelope.kind == 'writing')
                            _WritingTask(controller: _writing)
                          else
                            _SpeakingTask(
                              recording: _recording,
                              recordings: _speechAttemptIds.length,
                              onToggle: _toggleRecording,
                            ),
                          const SizedBox(height: Spacing.xl),
                          GlowButton(
                            label: 'Submit and continue',
                            size: GlowButtonSize.large,
                            expand: true,
                            isLoading: state.submitting,
                            onPressed: () => _submit(envelope),
                          ),
                          const SizedBox(height: Spacing.sm),
                          Text(
                            switch (envelope.kind) {
                              'objective' =>
                                '${_answers.length} of ${envelope.exercises.length} answered',
                              'speaking' =>
                                '${_speechAttemptIds.length} recording(s) attached',
                              _ => 'Your work is submitted as written',
                            },
                            textAlign: TextAlign.center,
                            style: context.text.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _TaskHeader extends StatelessWidget {
  const _TaskHeader({required this.envelope, required this.onExpired});

  final ExamTaskEnvelope envelope;
  final VoidCallback onExpired;

  @override
  Widget build(BuildContext context) {
    final timing = envelope.timing;
    final progress = envelope.progress;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: Spacing.lg),
      child: ResponsiveContent(
        padding: EdgeInsets.zero,
        child: Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    envelope.section?.name.toUpperCase() ?? 'SECTION',
                    style: context.text.labelSmall,
                  ),
                  Text(
                    progress == null
                        ? 'Task'
                        : '${progress.tasksRemainingInSection} task(s) left '
                            'in this section',
                    style: context.text.bodyMedium,
                  ),
                ],
              ),
            ),
            // The clock only appears when the engine says timing is enforced;
            // an untimed practice run must not pretend otherwise.
            if (timing != null && timing.enforced)
              ExamTimer(
                duration: Duration(seconds: timing.sectionRemainingSeconds),
                onExpired: onExpired,
              ),
          ],
        ),
      ),
    );
  }
}

class _TaskBrief extends StatelessWidget {
  const _TaskBrief({required this.task});

  final ExamTaskDetail task;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (task.type?.name != null)
            Text(task.type!.name!.toUpperCase(), style: context.text.labelSmall),
          const SizedBox(height: Spacing.xs),
          Text(task.title, style: context.text.headlineSmall),
          if (task.instructions != null) ...<Widget>[
            const SizedBox(height: Spacing.sm),
            Text(
              task.instructions!,
              style: context.text.bodyLarge?.copyWith(height: 1.6),
            ),
          ],
        ],
      ),
    );
  }
}

class _ObjectiveTask extends StatelessWidget {
  const _ObjectiveTask({
    required this.exercises,
    required this.answers,
    required this.onAnswer,
  });

  final List<Exercise> exercises;
  final Map<int, Object> answers;
  final void Function(int exerciseId, Object value) onAnswer;

  @override
  Widget build(BuildContext context) {
    // Answers are saved locally and posted with the whole task: showing a
    // verdict per item would not be exam practice.
    return ExerciseChrome(
      submitLabel: 'Save answer',
      hideFeedback: true,
      child: Column(
        children: <Widget>[
          for (final Exercise exercise in exercises)
            Padding(
              padding: const EdgeInsets.only(bottom: Spacing.lg),
              child: ExerciseRenderer(
                key: ValueKey<int>(exercise.id),
                exercise: exercise,
                onSubmit: (ExerciseResponse response) =>
                    onAnswer(exercise.id, response.value),
                onContinue: () {},
              ),
            ),
        ],
      ),
    );
  }
}

class _WritingTask extends StatelessWidget {
  const _WritingTask({required this.controller});

  final TextEditingController controller;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text('YOUR ANSWER', style: context.text.labelSmall),
          const SizedBox(height: Spacing.sm),
          TextField(
            controller: controller,
            minLines: 10,
            maxLines: null,
            style: context.text.bodyLarge,
            cursorColor: context.colors.accent,
            decoration: const InputDecoration(
              hintText: 'Write your response here',
            ),
          ),
        ],
      ),
    );
  }
}

class _SpeakingTask extends StatelessWidget {
  const _SpeakingTask({
    required this.recording,
    required this.recordings,
    required this.onToggle,
  });

  final bool recording;
  final int recordings;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return GlassPanel(
      child: Column(
        children: <Widget>[
          Text(
            recording
                ? 'Recording — tap to stop'
                : 'Record your answer, then submit',
            style: context.text.bodyMedium,
          ),
          const SizedBox(height: Spacing.lg),
          GlowButton(
            label: recording ? 'Stop recording' : 'Record',
            icon: recording ? Icons.stop_rounded : Icons.mic_rounded,
            size: GlowButtonSize.large,
            onPressed: onToggle,
          ),
          if (recordings > 0) ...<Widget>[
            const SizedBox(height: Spacing.md),
            Text(
              '$recordings recording(s) ready to submit',
              style: context.text.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}
