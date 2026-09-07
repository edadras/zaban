import 'package:flutter/material.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// English coaching line with optional Persian underneath when UI locale is fa.
class BilingualText extends StatelessWidget {
  const BilingualText({
    required this.english,
    super.key,
    this.persian,
    this.style,
    this.persianStyle,
    this.textAlign,
  });

  final String english;
  final String? persian;
  final TextStyle? style;
  final TextStyle? persianStyle;
  final TextAlign? textAlign;

  bool _showFa(BuildContext context) =>
      Strings.of(context).locale.languageCode == 'fa' &&
      persian != null &&
      persian!.trim().isNotEmpty;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(english, style: style ?? context.text.bodyMedium, textAlign: textAlign),
        if (_showFa(context)) ...<Widget>[
          const SizedBox(height: 2),
          Text(
            persian!,
            textAlign: textAlign,
            textDirection: TextDirection.rtl,
            style: (persianStyle ?? context.text.bodySmall)?.copyWith(
              color: colors.textSecondary,
              height: 1.45,
            ),
          ),
        ],
      ],
    );
  }
}
