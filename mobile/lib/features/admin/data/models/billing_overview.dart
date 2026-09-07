/// Admin billing dashboard payload from `GET /admin/billing/overview`.
class BillingOverview {
  const BillingOverview({
    required this.windowDays,
    required this.usersTotal,
    required this.learners,
    required this.active7d,
    required this.newInWindow,
    required this.activeSubscriptions,
    required this.byPlan,
    required this.irrRevenue,
    required this.irrDisplay,
    required this.tryDisplay,
    required this.usdDisplay,
    required this.succeededCount,
    required this.pendingReview,
    required this.pendingTransfer,
    required this.approvedInWindow,
    required this.daily,
    required this.sessionsInWindow,
    required this.exerciseAttemptsInWindow,
  });

  final int windowDays;
  final int usersTotal;
  final int learners;
  final int active7d;
  final int newInWindow;
  final int activeSubscriptions;
  final List<PlanCount> byPlan;
  final int irrRevenue;
  final String irrDisplay;
  final String tryDisplay;
  final String usdDisplay;
  final int succeededCount;
  final int pendingReview;
  final int pendingTransfer;
  final int approvedInWindow;
  final List<DailyRevenue> daily;
  final int sessionsInWindow;
  final int exerciseAttemptsInWindow;

  factory BillingOverview.fromJson(Map<String, dynamic> json) {
    final users = json['users'] as Map? ?? const {};
    final subs = json['subscriptions'] as Map? ?? const {};
    final revenue = json['revenue'] as Map? ?? const {};
    final manual = json['manual_payments'] as Map? ?? const {};
    final learning = json['learning'] as Map? ?? const {};
    return BillingOverview(
      windowDays: (json['window_days'] as num?)?.toInt() ?? 30,
      usersTotal: (users['total'] as num?)?.toInt() ?? 0,
      learners: (users['learners'] as num?)?.toInt() ?? 0,
      active7d: (users['active_7d'] as num?)?.toInt() ?? 0,
      newInWindow: (users['new_in_window'] as num?)?.toInt() ?? 0,
      activeSubscriptions: (subs['active'] as num?)?.toInt() ?? 0,
      byPlan: ((subs['by_plan'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => PlanCount.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      irrRevenue: (revenue['irr'] as num?)?.toInt() ?? 0,
      irrDisplay: (revenue['irr_display'] as String?) ?? '0 IRR',
      tryDisplay: (revenue['try_display'] as String?) ?? '0 TRY',
      usdDisplay: (revenue['usd_display'] as String?) ?? '0 USD',
      succeededCount: (revenue['succeeded_count'] as num?)?.toInt() ?? 0,
      pendingReview: (manual['pending_review'] as num?)?.toInt() ?? 0,
      pendingTransfer: (manual['pending_transfer'] as num?)?.toInt() ?? 0,
      approvedInWindow: (manual['approved_in_window'] as num?)?.toInt() ?? 0,
      daily: ((json['daily'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => DailyRevenue.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      sessionsInWindow: (learning['sessions_in_window'] as num?)?.toInt() ?? 0,
      exerciseAttemptsInWindow:
          (learning['exercise_attempts_in_window'] as num?)?.toInt() ?? 0,
    );
  }
}

class PlanCount {
  const PlanCount({required this.code, required this.name, required this.count});

  final String code;
  final String name;
  final int count;

  factory PlanCount.fromJson(Map<String, dynamic> json) => PlanCount(
        code: (json['code'] as String?) ?? '',
        name: (json['name'] as String?) ?? '',
        count: (json['count'] as num?)?.toInt() ?? 0,
      );
}

class DailyRevenue {
  const DailyRevenue({
    required this.day,
    required this.currency,
    required this.total,
    required this.count,
  });

  final String day;
  final String currency;
  final int total;
  final int count;

  factory DailyRevenue.fromJson(Map<String, dynamic> json) => DailyRevenue(
        day: '${json['day'] ?? ''}',
        currency: (json['currency'] as String?) ?? '',
        total: (json['total'] as num?)?.toInt() ?? 0,
        count: (json['count'] as num?)?.toInt() ?? 0,
      );
}

class AdminUserRow {
  const AdminUserRow({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.status,
    this.cefr,
    this.streakDays,
    this.lastActiveAt,
  });

  final int id;
  final String name;
  final String email;
  final String role;
  final String status;
  final String? cefr;
  final int? streakDays;
  final String? lastActiveAt;

  factory AdminUserRow.fromJson(Map<String, dynamic> json) => AdminUserRow(
        id: (json['id'] as num).toInt(),
        name: (json['name'] as String?) ?? '',
        email: (json['email'] as String?) ?? '',
        role: (json['role'] as String?) ?? 'learner',
        status: (json['status'] as String?) ?? 'active',
        cefr: json['cefr'] as String?,
        streakDays: (json['streak_days'] as num?)?.toInt(),
        lastActiveAt: json['last_active_at'] as String?,
      );
}
