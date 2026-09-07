import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/glass_card.dart';
import 'package:zaban/core/widgets/glow_button.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// What the coach just asked this learner.
///
/// The correct answer is not in the payload, so this widget could not reveal
/// it even by mistake; what comes back after answering is the server's verdict.
class QuestionCard extends StatefulWidget {
  const QuestionCard({
    required this.question,
    required this.onAnswer,
    required this.busy,
    required this.answered,
    this.wasCorrect,
    super.key,
  });

  final RoomQuestion question;
  final Future<void> Function({String? body, List<int>? selectedOptions})
      onAnswer;
  final bool busy;
  final bool answered;
  final bool? wasCorrect;

  @override
  State<QuestionCard> createState() => _QuestionCardState();
}

class _QuestionCardState extends State<QuestionCard> {
  final TextEditingController _text = TextEditingController();
  final Set<int> _chosen = <int>{};

  @override
  void didUpdateWidget(QuestionCard old) {
    super.didUpdateWidget(old);

    // A new question is a blank sheet, not the last one's leftovers.
    if (old.question.id != widget.question.id) {
      _text.clear();
      _chosen.clear();
    }
  }

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final options = widget.question.options ?? const <String>[];

    return GlassCard(
      accent: true,
      eyebrow: context.t('Your coach asks'),
      title: widget.question.prompt ?? '',
      child: Padding(
        padding: const EdgeInsets.only(top: Spacing.md),
        child: widget.answered
            ? _verdict(context)
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  if (options.isNotEmpty)
                    for (int i = 0; i < options.length; i++)
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        value: _chosen.contains(i),
                        title: Text(options[i]),
                        onChanged: widget.busy
                            ? null
                            : (bool? on) => setState(() {
                                  on ?? false ? _chosen.add(i) : _chosen.remove(i);
                                }),
                      )
                  else
                    TextField(
                      controller: _text,
                      minLines: 2,
                      maxLines: 5,
                      enabled: !widget.busy,
                      decoration: InputDecoration(
                        hintText: context.t('Type your answer'),
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  const SizedBox(height: Spacing.md),
                  GlowButton(
                    label: context.t('Send'),
                    isLoading: widget.busy,
                    expand: true,
                    onPressed: widget.busy ? null : _send,
                  ),
                ],
              ),
      ),
    );
  }

  Widget _verdict(BuildContext context) {
    final colors = context.colors;
    final correct = widget.wasCorrect;

    if (correct == null) {
      return Row(
        children: <Widget>[
          Icon(Icons.check_circle_outline_rounded, color: colors.success),
          const SizedBox(width: Spacing.sm),
          Expanded(child: Text(context.t('Your answer is with your coach.'))),
        ],
      );
    }

    return Row(
      children: <Widget>[
        Icon(
          correct ? Icons.check_circle_rounded : Icons.cancel_rounded,
          color: correct ? colors.success : colors.danger,
        ),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: Text(
            correct ? context.t('Correct') : context.t('Not this time'),
          ),
        ),
      ],
    );
  }

  void _send() {
    final options = widget.question.options ?? const <String>[];

    if (options.isNotEmpty) {
      if (_chosen.isEmpty) return;
      widget.onAnswer(selectedOptions: _chosen.toList()..sort());
      return;
    }

    final body = _text.text.trim();
    if (body.isEmpty) return;

    widget.onAnswer(body: body);
  }
}
