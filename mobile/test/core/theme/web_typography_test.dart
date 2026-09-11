import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:zaban/core/theme/tokens/typography_tokens.dart';

/// The web build has to carry its own letters.
///
/// This exists because of a fault no widget test could have caught and no
/// amount of looking at a phone would have shown: on the web, Flutter renders
/// through CanvasKit, CanvasKit carries no fonts of its own, and the theme
/// asked for the platform UI font - which on the web is nothing at all. The
/// engine quietly downloads Roboto from fonts.gstatic.com to cover for that.
///
/// On a network that cannot reach Google, the download fails and every string
/// in the product renders as blank space. The app drew its cards, its fields
/// and its buttons perfectly, and did not say a single word. For an app
/// written for learners in Iran that is not an edge case.
///
/// So: one family is bundled, and it sits at the end of both fallback lists,
/// reached only where nothing else exists. These tests are here to stop it
/// being tidied away by someone who sees an unused font on a phone.
void main() {
  group('the bundled fallback family', () {
    test('is the last resort for interface text', () {
      expect(
        ZabanTypography.fontFamilyFallback.last,
        ZabanTypography.bundledFamily,
        reason: 'the bundled face must be reachable when no platform font is',
      );
    });

    test('is the last resort for reading text too', () {
      expect(
        ZabanTypography.readingFamilyFallback.last,
        ZabanTypography.bundledFamily,
        reason: 'prose in the bundled sans beats prose nobody can see',
      );
    });

    test('never displaces a real platform font', () {
      // It is last, so SF Pro, Roboto and the rest are all preferred. A phone
      // renders exactly as it did before this font was added.
      expect(ZabanTypography.fontFamilyFallback.first, 'SF Pro Display');
      expect(
        ZabanTypography.fontFamilyFallback.indexOf(ZabanTypography.bundledFamily),
        ZabanTypography.fontFamilyFallback.length - 1,
      );
    });

    test('is still the default family choice for the platform to make', () {
      // Null means "whatever this platform uses"; the fallback list is what
      // rescues the one platform that has no answer.
      expect(ZabanTypography.fontFamily, isNull);
    });
  });

  group('the font is actually shipped', () {
    final File pubspec = File('pubspec.yaml');

    test('pubspec declares the family', () {
      final String text = pubspec.readAsStringSync();
      expect(text, contains('family: ${ZabanTypography.bundledFamily}'));
    });

    test('every declared weight exists on disk', () {
      final String text = pubspec.readAsStringSync();
      final Iterable<RegExpMatch> assets =
          RegExp(r'asset: (assets/fonts/[^\s]+\.ttf)').allMatches(text);

      expect(assets, isNotEmpty, reason: 'no font assets are declared at all');
      for (final RegExpMatch match in assets) {
        final String path = match.group(1)!;
        expect(
          File(path).existsSync(),
          isTrue,
          reason: '$path is declared in pubspec.yaml but not in the repository',
        );
      }
    });

    test('the licence travels with it', () {
      // SIL OFL requires the licence to be distributed with the font.
      expect(
        File('assets/fonts/Vazirmatn-OFL.txt').existsSync(),
        isTrue,
        reason: 'the bundled font must ship its licence',
      );
    });
  });

  group('the web build draws with its own renderer', () {
    test('the bootstrap points CanvasKit at the local copy', () {
      // The other half of the same failure: without this, the renderer itself
      // is fetched from gstatic.com and a blocked network gets a blank page
      // with no error, because nothing is loaded that could draw one.
      final String bootstrap = File('web/flutter_bootstrap.js').readAsStringSync();
      expect(
        bootstrap,
        contains('canvasKitBaseUrl'),
        reason: 'the web build must not depend on Google\'s CDN to render',
      );
      expect(bootstrap, contains('canvaskit/'));
    });
  });
}
