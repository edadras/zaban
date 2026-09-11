{{flutter_js}}
{{flutter_build_config}}

// Prefer GPU CanvasKit. Only force the CPU rasterizer when WebGL is missing
// (broken drivers / some remote-desktop clients) — forcing CPU for everyone
// made the whole app feel laggy on ordinary browsers.
function _zabanWebGlOk() {
  try {
    var canvas = document.createElement("canvas");
    return !!(
      canvas.getContext("webgl2") ||
      canvas.getContext("webgl") ||
      canvas.getContext("experimental-webgl")
    );
  } catch (e) {
    return false;
  }
}

// Draw with the renderer bundled in this build, not the copy on Google's CDN.
//
// `flutter build web` ships CanvasKit into `canvaskit/` and then fetches it
// from gstatic.com anyway unless it is told otherwise. For learners in Iran
// that is not a detail: a network that cannot reach gstatic - and many cannot
// - gets a blank page and no error at all, because the renderer never arrives
// and there is nothing left to draw the error with. The local copy is already
// in the build, costs no extra download, and works behind the service worker.
var _zabanConfig = { canvasKitBaseUrl: "canvaskit/" };
if (!_zabanWebGlOk()) {
  _zabanConfig.canvasKitForceCpuOnly = true;
}

_flutter.loader.load({
  config: _zabanConfig,
});
