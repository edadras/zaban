import 'package:flutter/widgets.dart';
import 'package:zaban/core/i18n/fa.dart';

/// The interface, in the learner's own language.
///
/// The catalogue is keyed by the English sentence rather than by an invented
/// identifier. That is a deliberate trade. Identifiers ("home.streak.title")
/// survive rewording and read well in a translation tool; source strings read
/// well in the widget, cannot drift from what is actually on screen, and — the
/// reason here — let an untranslated string fall back to something correct
/// instead of to "home.streak.title". An app that is half translated should
/// look half translated, not broken.
///
/// The English catalogue is therefore empty by construction: English is what
/// the widgets already say.
class Strings {
  const Strings(this.locale, this._catalogue);

  final Locale locale;
  final Map<String, String> _catalogue;

  static const List<Locale> supported = <Locale>[
    Locale('en'),
    Locale('fa'),
  ];

  static Strings of(BuildContext context) =>
      Localizations.of<Strings>(context, Strings) ??
      const Strings(Locale('en'), <String, String>{});

  /// The catalogue's line for [source], or [source] itself.
  String call(String source) => _catalogue[source] ?? source;

  static const LocalizationsDelegate<Strings> delegate = _StringsDelegate();
}

class _StringsDelegate extends LocalizationsDelegate<Strings> {
  const _StringsDelegate();

  @override
  bool isSupported(Locale locale) =>
      Strings.supported.any((Locale l) => l.languageCode == locale.languageCode);

  @override
  Future<Strings> load(Locale locale) async => Strings(
        locale,
        switch (locale.languageCode) {
          'fa' => faStrings,
          _ => const <String, String>{},
        },
      );

  @override
  bool shouldReload(_StringsDelegate old) => false;
}

extension StringsContext on BuildContext {
  /// The interface string for this English source sentence.
  String t(String source) => Strings.of(this)(source);
}
