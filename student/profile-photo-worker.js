'use strict';
// Self-hosted inference. No photo or camera frames leave this browser for checks.
const assets = new URL('../assets/vendor/mediapipe/', self.location.href);
let detectorPromise;
async function detector() {
  if (!detectorPromise) detectorPromise = import(new URL('vision_bundle.mjs', assets)).then(async library => {
    const files = await library.FilesetResolver.forVisionTasks(new URL('wasm', assets).href);
    return library.FaceDetector.createFromOptions(files, {
      baseOptions: {modelAssetPath: new URL('face-detector.tflite', assets).href, delegate: 'CPU'},
      runningMode: 'IMAGE', minDetectionConfidence: .6
    });
  });
  return detectorPromise;
}
self.onmessage = async event => {
  const {id, image} = event.data;
  try {
    const model = await detector();
    if (!image) { self.postMessage({id, result: {ready: true}}); return; }
    const faces = model.detect(image).detections;
    let face = null;
    if (faces.length === 1) {
      const box = faces[0].boundingBox;
      face = {x: box.originX / image.width, y: box.originY / image.height,
        width: box.width / image.width, height: box.height / image.height,
        keypoints: faces[0].keypoints.map(point => ({x: point.x, y: point.y}))};
    }
    self.postMessage({id, result: {face_count: faces.length, face}});
  } catch (error) {
    self.postMessage({id, error: 'Photo checks could not load. Reopen the camera or try a supported browser. If this continues, ask your college office for help.'});
  } finally { if (image) image.close(); }
};
