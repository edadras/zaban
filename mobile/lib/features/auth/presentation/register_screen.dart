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
import 'package:zaban/features/auth/presentation/auth_controller.dart';
import 'package:zaban/features/auth/presentation/widgets/auth_field.dart';
import 'package:zaban/features/auth/presentation/widgets/brand_mark.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();

  bool _submitting = false;
  ApiException? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref.read(authControllerProvider.notifier).register(
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            passwordConfirmation: _confirm.text,
          );
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final fieldErrors = _error?.fieldErrors ?? const <String, List<String>>{};

    return ZabanScaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(vertical: Spacing.xxl),
          child: ResponsiveContent(
            maxWidth: 460,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const BrandMark(showWordmark: false),
                const SizedBox(height: Spacing.xl),
                GlassPanel(
                  padding: const EdgeInsets.all(Spacing.xl),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text('Create your account',
                            style: context.text.headlineSmall),
                        const SizedBox(height: Spacing.xs),
                        Text(
                          'We place you with a short adaptive test, then build '
                          'your course around what you actually know.',
                          style: context.text.bodyMedium,
                        ),
                        const SizedBox(height: Spacing.xl),
                        AuthField(
                          label: 'Name',
                          controller: _name,
                          textInputAction: TextInputAction.next,
                          autofillHints: const <String>[AutofillHints.name],
                          errorText: fieldErrors['name']?.first,
                          validator: (value) =>
                              (value == null || value.trim().length < 2)
                                  ? 'Tell us what to call you'
                                  : null,
                        ),
                        const SizedBox(height: Spacing.lg),
                        AuthField(
                          label: 'Email',
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          autofillHints: const <String>[AutofillHints.email],
                          errorText: fieldErrors['email']?.first,
                          validator: (value) =>
                              (value == null || !value.contains('@'))
                                  ? 'Enter a valid email address'
                                  : null,
                        ),
                        const SizedBox(height: Spacing.lg),
                        AuthField(
                          label: 'Password',
                          controller: _password,
                          obscure: true,
                          textInputAction: TextInputAction.next,
                          autofillHints: const <String>[
                            AutofillHints.newPassword,
                          ],
                          errorText: fieldErrors['password']?.first,
                          validator: (value) =>
                              (value == null || value.length < 8)
                                  ? 'Use at least 8 characters'
                                  : null,
                        ),
                        const SizedBox(height: Spacing.lg),
                        AuthField(
                          label: 'Confirm password',
                          controller: _confirm,
                          obscure: true,
                          textInputAction: TextInputAction.done,
                          onSubmitted: (_) => _submit(),
                          validator: (value) => value != _password.text
                              ? 'Passwords do not match'
                              : null,
                        ),
                        if (_error != null &&
                            _error!.kind != ApiErrorKind.validation) ...<Widget>[
                          const SizedBox(height: Spacing.lg),
                          Text(
                            _error!.message,
                            style: context.text.bodyMedium
                                ?.copyWith(color: context.colors.danger),
                          ),
                        ],
                        const SizedBox(height: Spacing.xl),
                        GlowButton(
                          label: 'Create account',
                          size: GlowButtonSize.large,
                          expand: true,
                          isLoading: _submitting,
                          onPressed: _submit,
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: Spacing.lg),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: <Widget>[
                    Text('Already have an account?',
                        style: context.text.bodyMedium),
                    TextButton(
                      onPressed: () => context.go(AppRoute.login.path),
                      child: const Text('Sign in'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
