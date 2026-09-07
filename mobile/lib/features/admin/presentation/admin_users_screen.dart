import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:zaban/core/error/api_exception.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/router/routes.dart';
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/core/widgets/app_scaffold.dart';
import 'package:zaban/core/widgets/glass_panel.dart';
import 'package:zaban/core/widgets/responsive.dart';
import 'package:zaban/core/widgets/state_views.dart';
import 'package:zaban/features/admin/data/admin_repository.dart';
import 'package:zaban/features/admin/data/models/billing_overview.dart';

class AdminUsersScreen extends ConsumerStatefulWidget {
  const AdminUsersScreen({super.key});

  @override
  ConsumerState<AdminUsersScreen> createState() => _AdminUsersScreenState();
}

class _AdminUsersScreenState extends ConsumerState<AdminUsersScreen> {
  final TextEditingController _search = TextEditingController();
  List<AdminUserRow>? _rows;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({String? q}) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final rows = await ref.read(adminRepositoryProvider).users(q: q);
      if (!mounted) return;
      setState(() {
        _rows = rows;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  Future<void> _toggleSuspend(AdminUserRow user) async {
    try {
      await ref.read(adminRepositoryProvider).updateUser(
            user.id,
            status: user.status == 'suspended' ? 'active' : 'suspended',
          );
      await _load(q: _search.text.trim());
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(error.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return ZabanScaffold(
      title: context.t('Users'),
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => context.go(AppRoute.admin.path),
      ),
      body: ResponsiveContent(
        child: Column(
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.only(top: Spacing.lg),
              child: TextField(
                controller: _search,
                decoration: InputDecoration(
                  hintText: context.t('Search name or email'),
                  prefixIcon: const Icon(Icons.search_rounded),
                  suffixIcon: IconButton(
                    icon: const Icon(Icons.refresh_rounded),
                    onPressed: () => _load(q: _search.text.trim()),
                  ),
                ),
                onSubmitted: (String value) => _load(q: value.trim()),
              ),
            ),
            const SizedBox(height: Spacing.md),
            Expanded(
              child: _loading
                  ? const LoadingView()
                  : _error != null
                      ? ErrorView(
                          error: _error!,
                          onRetry: () => _load(q: _search.text.trim()),
                        )
                      : (_rows == null || _rows!.isEmpty)
                          ? EmptyView(
                              title: context.t('No users found'),
                              message: context.t('Try another search.'),
                            )
                          : ListView.separated(
                              itemCount: _rows!.length,
                              separatorBuilder: (_, __) =>
                                  const SizedBox(height: Spacing.sm),
                              itemBuilder: (BuildContext context, int i) {
                                final u = _rows![i];
                                return GlassPanel.compact(
                                  child: ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    title: Text(u.name),
                                    subtitle: Text(
                                      '${u.email}\n${u.role} · ${u.status}'
                                      '${u.cefr == null ? '' : ' · ${u.cefr}'}',
                                    ),
                                    isThreeLine: true,
                                    trailing: const Icon(Icons.chevron_right_rounded),
                                    onTap: () => context.go(
                                      AppRoute.admin.adminUserPath(u.id),
                                    ),
                                    onLongPress: () => _toggleSuspend(u),
                                  ),
                                );
                              },
                            ),
            ),
          ],
        ),
      ),
    );
  }
}
