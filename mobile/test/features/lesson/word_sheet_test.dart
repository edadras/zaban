import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/i18n/strings.dart';
import 'package:zaban/core/theme/app_theme.dart';
import 'package:zaban/core/theme/tokens/glass_tokens.dart';
import 'package:zaban/core/theme/tokens/motion_tokens.dart';
import 'package:zaban/features/lesson/data/models/lesson_block.dart';
import 'package:zaban/features/lesson/presentation/widgets/word_sheet.dart';

/// Tapping a taught word mid-paragraph.
///
/// The sheet answers in the reader's own language when the corpus has been
/// translated into it, and falls back to the book's English gloss when it has
/// not. Getting that the wrong way round at A1 shows a beginner an explanation
/// written in the language they are trying to learn.
void main() {
  const ReadingTerm translated = ReadingTerm(
    term: 'harbour',
    start: 0,
    end: 7,
    gloss: 'a place on the coast where ships shelter',
    meanings: <String, String>{'fa': 'بندرگاه'},
  );

  const ReadingTerm untranslated = ReadingTerm(
    term: 'quay',
    start: 0,
    end: 4,
    gloss: 'a platform beside water where ships load',
  );

  Future<void> openSheet(
    WidgetTester tester,
    Locale locale,
    ReadingTerm term,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: locale,
        supportedLocales: Strings.supported,
        localizationsDelegates: const <LocalizationsDelegate<Object>>[
          Strings.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: AppTheme.dark(
          glass: ZabanGlass.flat(),
          motion: ZabanMotion.reduced(),
        ),
        home: Builder(
          builder: (BuildContext context) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () => showWordSheet(context, term),
                child: const Text('open'),
              ),
            ),
          ),
        ),
      ),
    );
    // The catalogue loads asynchronously, so the first frame has nothing yet.
    await tester.pump();
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
  }

  testWidgets('a Persian reader is shown the meaning in Persian',
      (WidgetTester tester) async {
    await openSheet(tester, const Locale('fa'), translated);

    expect(find.text('harbour'), findsOneWidget);
    expect(find.text('بندرگاه'), findsOneWidget);
  });

  /// The gloss is not dropped once there is a meaning: it is the book's own
  /// wording and stays available to a reader who can use it.
  testWidgets('the English gloss is kept beside the meaning',
      (WidgetTester tester) async {
    await openSheet(tester, const Locale('fa'), translated);

    expect(
      find.text('a place on the coast where ships shelter'),
      findsOneWidget,
    );
  });

  testWidgets('an English reader is shown the gloss and not the Persian',
      (WidgetTester tester) async {
    await openSheet(tester, const Locale('en'), translated);

    expect(find.text('بندرگاه'), findsNothing);
    expect(
      find.text('a place on the coast where ships shelter'),
      findsOneWidget,
    );
  });

  testWidgets('a word nobody has translated still shows its gloss',
      (WidgetTester tester) async {
    await openSheet(tester, const Locale('fa'), untranslated);

    expect(find.text('quay'), findsOneWidget);
    expect(
      find.text('a platform beside water where ships load'),
      findsOneWidget,
    );
  });

  /// The gloss is English inside a right-to-left sheet. Left to itself it is
  /// laid out from the right and its full stop jumps to the front.
  testWidgets('the English gloss reads left to right inside a Persian sheet',
      (WidgetTester tester) async {
    await openSheet(tester, const Locale('fa'), translated);

    final Text gloss = tester.widget<Text>(
      find.text('a place on the coast where ships shelter'),
    );

    expect(gloss.textDirection, TextDirection.ltr);
  });
}
