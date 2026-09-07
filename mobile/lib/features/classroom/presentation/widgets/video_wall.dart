import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:zaban/core/theme/theme_context.dart';
import 'package:zaban/core/theme/tokens/dimension_tokens.dart';
import 'package:zaban/features/classroom/data/models/classroom_models.dart';

/// Everybody's face, side by side.
///
/// The column count follows the number of tiles rather than a fixed grid, so
/// two people fill the screen and twelve stay legible. Names come from the
/// API's roster and not from the media server, because the media server only
/// knows the identity `u{id}` and a class needs to see who that is.
class VideoWall extends StatelessWidget {
  const VideoWall({
    required this.media,
    required this.roster,
    super.key,
  });

  final lk.Room media;
  final List<RoomParticipant> roster;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: media,
      builder: (BuildContext context, Widget? _) {
        final local = media.localParticipant;

        final people = <lk.Participant>[
          if (local != null) local,
          ...media.remoteParticipants.values,
        ];

        if (people.isEmpty) {
          return const SizedBox.shrink();
        }

        final columns = people.length <= 1
            ? 1
            : people.length <= 4
                ? 2
                : people.length <= 9
                    ? 3
                    : 4;

        return GridView.count(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisCount: columns,
          childAspectRatio: 4 / 3,
          mainAxisSpacing: Spacing.sm,
          crossAxisSpacing: Spacing.sm,
          children: <Widget>[
            for (final lk.Participant person in people)
              _Tile(
                person: person,
                isLocal: identical(person, local),
                seat: _seatFor(person),
              ),
          ],
        );
      },
    );
  }

  RoomParticipant? _seatFor(lk.Participant person) => roster
      .firstWhereOrNull((RoomParticipant p) => p.identity == person.identity);
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.person,
    required this.isLocal,
    required this.seat,
  });

  final lk.Participant person;
  final bool isLocal;
  final RoomParticipant? seat;

  @override
  Widget build(BuildContext context) {
    final colors = context.colors;
    final video = _video();

    return ClipRRect(
      borderRadius: BorderRadius.circular(Radii.md),
      child: ColoredBox(
        color: colors.canvasRaised,
        child: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            if (video != null)
              lk.VideoTrackRenderer(
                video,
                fit: lk.VideoViewFit.cover,
                mirrorMode: isLocal
                    ? lk.VideoViewMirrorMode.mirror
                    : lk.VideoViewMirrorMode.off,
              )
            else
              Center(
                child: Icon(
                  Icons.person_rounded,
                  size: 40,
                  color: colors.textTertiary,
                ),
              ),

            // The two things a class actually reads off a tile: who, and
            // whether they can be heard.
            Positioned(
              left: Spacing.xs,
              right: Spacing.xs,
              bottom: Spacing.xs,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Flexible(
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: Spacing.sm,
                        vertical: 2,
                      ),
                      decoration: BoxDecoration(
                        color: colors.scrim,
                        borderRadius: BorderRadius.circular(Radii.sm),
                      ),
                      child: Text(
                        seat?.name ?? person.identity,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 11,
                          color: colors.textOnAccent,
                        ),
                      ),
                    ),
                  ),
                  if (seat?.canPublishAudio == false)
                    Padding(
                      padding: const EdgeInsets.only(left: Spacing.xs),
                      child: Icon(
                        Icons.mic_off_rounded,
                        size: 14,
                        color: colors.textOnAccent,
                      ),
                    ),
                ],
              ),
            ),

            if (seat?.handRaised ?? false)
              Positioned(
                top: Spacing.xs,
                right: Spacing.xs,
                child: Icon(
                  Icons.back_hand_rounded,
                  size: 18,
                  color: colors.warning,
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// The first video this person is actually sending.
  ///
  /// `videoTrackPublications` is typed against the base track on a plain
  /// `Participant` — the local and remote subclasses narrow it — so the cast
  /// is done here rather than in the signature.
  lk.VideoTrack? _video() {
    final publication = person.videoTrackPublications.firstWhereOrNull(
      (lk.TrackPublication<lk.Track> p) => p.track != null && !p.muted,
    );

    final track = publication?.track;

    return track is lk.VideoTrack ? track : null;
  }
}
