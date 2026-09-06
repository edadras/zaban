import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/i18n/fa.dart';
import 'package:zaban/core/i18n/strings.dart';

/// The interface in someone else's language.
///
/// Two things have to hold and only one of them is about words. The catalogue
/// has to answer, and the layout has to turn around: Persian is read
/// right-to-left, and an app that translates its text and keeps its arrows
/// pointing the wrong way is harder to use than one that stayed in English.
void main() {
  Future<void> pumpIn(WidgetTester tester, Locale locale, Widget child) async {
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
          home: child,
        ),
      );
    // The delegate loads its catalogue asynchronously, so the first frame is
    // painted before there is anything to look up.
    await tester.pump();
  }

  testWidgets('a translated string is shown in the chosen language',
      (WidgetTester tester) async {
    await pumpIn(
      tester,
      const Locale('fa'),
      Builder(builder: (BuildContext c) => Text(c.t('Continue'))),
    );

    expect(find.text('ادامه'), findsOneWidget);
    expect(find.text('Continue'), findsNothing);
  });

  /// The whole reason the catalogue is keyed by English rather than by an
  /// invented identifier: a gap shows the sentence, not "home.cta.continue".
  testWidgets('an untranslated string falls back to the English it was written in',
      (WidgetTester tester) async {
    await pumpIn(
      tester,
      const Locale('fa'),
      Builder(
        builder: (BuildContext c) => Text(c.t('A sentence nobody has translated')),
      ),
    );

    expect(find.text('A sentence nobody has translated'), findsOneWidget);
  });

  testWidgets('Persian lays the interface out right to left',
      (WidgetTester tester) async {
    await pumpIn(
      tester,
      const Locale('fa'),
      Builder(builder: (BuildContext c) => Text(c.t('Today'))),
    );

    expect(
      Directionality.of(tester.element(find.text('امروز'))),
      TextDirection.rtl,
    );
  });

  testWidgets('English is left to right and untranslated',
      (WidgetTester tester) async {
    await pumpIn(
      tester,
      const Locale('en'),
      Builder(builder: (BuildContext c) => Text(c.t('Today'))),
    );

    expect(find.text('Today'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('Today'))),
      TextDirection.ltr,
    );
  });

  test('the catalogue translates rather than echoes', () {
    // An entry whose Persian is identical to its English is either a mistake or
    // a word that genuinely does not change; the second is rare enough to name.
    const sameInBoth = <String>{'you@example.com'};

    final echoes = faStrings.entries
        .where((MapEntry<String, String> e) => e.key == e.value)
        .map((MapEntry<String, String> e) => e.key)
        .where((String k) => !sameInBoth.contains(k))
        .toList();

    expect(echoes, isEmpty, reason: 'untranslated entries in the Persian catalogue');
  });

  test('every entry carries Persian script', () {
    final latinOnly = faStrings.entries
        .where(
            (MapEntry<String, String> e) => !RegExp(r'[؀-ۿ]').hasMatch(e.value))
        .map((MapEntry<String, String> e) => '${e.key} -> ${e.value}')
        .toList();

    expect(latinOnly, <String>['you@example.com -> you@example.com']);
  });
}
