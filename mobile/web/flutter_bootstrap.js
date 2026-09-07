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

var _zabanConfig = {};
if (!_zabanWebGlOk()) {
  _zabanConfig.canvasKitForceCpuOnly = true;
}

_flutter.loader.load({
  config: _zabanConfig,
});
