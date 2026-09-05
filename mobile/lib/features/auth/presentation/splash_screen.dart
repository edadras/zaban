import 'package:flutter/material.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/features/auth/presentation/widgets/brand_mark.dart';

/// Shown only while the stored session is being resolved. It has no logic of
/// its own — the router moves on as soon as auth state settles.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ZabanScaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const BrandMark(showWordmark: false, size: 64),
            const SizedBox(height: Spacing.xl),
            SizedBox(
              height: 18,
              width: 18,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: context.colors.accent,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
