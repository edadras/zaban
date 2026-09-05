import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';

/// Text field used across auth and profile forms. The label sits above the box
/// rather than floating inside it, which survives long translations and RTL
/// interface languages better.
class AuthField extends StatelessWidget {
  const AuthField({
    required this.label,
    required this.controller,
    super.key,
    this.hint,
    this.obscure = false,
    this.keyboardType,
    this.textInputAction,
    this.validator,
    this.errorText,
    this.autofillHints,
    this.enabled = true,
    this.onSubmitted,
    this.prefixIcon,
    this.suffix,
  });

  final String label;
  final TextEditingController controller;
  final String? hint;
  final bool obscure;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final String? Function(String?)? validator;
  final String? errorText;
  final Iterable<String>? autofillHints;
  final bool enabled;
  final ValueChanged<String>? onSubmitted;
  final IconData? prefixIcon;
  final Widget? suffix;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.only(bottom: Spacing.sm, left: Spacing.xs),
          child: Text(label.toUpperCase(), style: context.text.labelSmall),
        ),
        TextFormField(
          controller: controller,
          obscureText: obscure,
          enabled: enabled,
          keyboardType: keyboardType,
          textInputAction: textInputAction,
          autofillHints: autofillHints,
          validator: validator,
          onFieldSubmitted: onSubmitted,
          style: context.text.bodyLarge,
          cursorColor: context.colors.accent,
          inputFormatters: keyboardType == TextInputType.emailAddress
              ? <TextInputFormatter>[FilteringTextInputFormatter.deny(' ')]
              : null,
          decoration: InputDecoration(
            hintText: hint,
            errorText: errorText,
            prefixIcon: prefixIcon == null ? null : Icon(prefixIcon, size: 18),
            suffixIcon: suffix,
          ),
        ),
      ],
    );
  }
}
