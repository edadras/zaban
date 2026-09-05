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

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _submitting = false;
  bool _obscure = true;
  ApiException? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref.read(authControllerProvider.notifier).login(
            email: _email.text.trim(),
            password: _password.text,
          );
      // The router redirect takes over from here.
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expired = ref.watch(authControllerProvider).sessionExpired;
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
                const BrandMark(),
                const SizedBox(height: Spacing.xxl),
                GlassPanel(
                  padding: const EdgeInsets.all(Spacing.xl),
                  child: Form(
                    key: _formKey,
                    child: AutofillGroup(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          Text('Welcome back', style: context.text.headlineSmall),
                          const SizedBox(height: Spacing.xs),
                          Text(
                            expired
                                ? 'Your session expired. Sign in to pick up where you left off.'
                                : 'Sign in to continue your course.',
                            style: context.text.bodyMedium,
                          ),
                          const SizedBox(height: Spacing.xl),
                          AuthField(
                            label: 'Email',
                            controller: _email,
                            hint: 'you@example.com',
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
                            obscure: _obscure,
                            textInputAction: TextInputAction.done,
                            autofillHints: const <String>[
                              AutofillHints.password,
                            ],
                            errorText: fieldErrors['password']?.first,
                            onSubmitted: (_) => _submit(),
                            validator: (value) =>
                                (value == null || value.isEmpty)
                                    ? 'Enter your password'
                                    : null,
                            suffix: IconButton(
                              icon: Icon(
                                _obscure
                                    ? Icons.visibility_outlined
                                    : Icons.visibility_off_outlined,
                                size: 18,
                              ),
                              onPressed: () =>
                                  setState(() => _obscure = !_obscure),
                            ),
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
                            label: 'Sign in',
                            size: GlowButtonSize.large,
                            expand: true,
                            isLoading: _submitting,
                            onPressed: _submit,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: Spacing.lg),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: <Widget>[
                    Text('New here?', style: context.text.bodyMedium),
                    TextButton(
                      onPressed: () => context.go(AppRoute.register.path),
                      child: const Text('Create an account'),
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
