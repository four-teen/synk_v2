// Face and shoulder detection stays off the UI thread and uses only local assets.
'use strict';
const assetUrl = new URL('../vendor/mediapipe/', self.location.href);
let visionPromise;
let facePromise;
let posePromise;

function vision() {
  if (!visionPromise) visionPromise = import(new URL('vision_bundle.mjs', assetUrl)).then(async function (library) {
    return {library: library, files: await library.FilesetResolver.forVisionTasks(new URL('wasm', assetUrl).href)};
  });
  return visionPromise;
}

async function faceDetector() {
  if (!facePromise) facePromise = vision().then(function (setup) {
    return setup.library.FaceDetector.createFromOptions(setup.files, {
      baseOptions: {modelAssetPath: new URL('face-detector.tflite', assetUrl).href, delegate: 'CPU'},
      runningMode: 'IMAGE', minDetectionConfidence: .6
    });
  });
  return facePromise;
}

async function poseDetector() {
  if (!posePromise) posePromise = vision().then(function (setup) {
    return setup.library.PoseLandmarker.createFromOptions(setup.files, {
      baseOptions: {modelAssetPath: new URL('pose-landmarker.task', assetUrl).href, delegate: 'CPU'},
      runningMode: 'IMAGE', numPoses: 1, minPoseDetectionConfidence: .6, minPosePresenceConfidence: .6
    }).catch(function (error) { posePromise = null; throw error; });
  });
  return posePromise;
}

self.onmessage = async function (event) {
  const id = event.data.id;
  const image = event.data.image;
  try {
    const detector = await faceDetector();
    const detections = detector.detect(image).detections;
    if (detections.length !== 1) {
      self.postMessage({id: id, result: {kind: detections.length ? 'multiple' : 'none'}});
      return;
    }
    const box = detections[0].boundingBox;
    const face = {x: box.originX / image.width, y: box.originY / image.height, width: box.width / image.width, height: box.height / image.height};
    let shoulders = null;
    try {
      const pose = await poseDetector();
      const points = pose.detect(image).landmarks[0];
      const visible = point => point && point.visibility >= .6 && point.x >= 0 && point.x <= 1 && point.y >= 0 && point.y <= 1;
      // Match the body's nose to the detected face to avoid selecting another person's shoulders.
      if (points && visible(points[0]) && visible(points[11]) && visible(points[12])
          && points[0].x >= face.x - face.width * .2 && points[0].x <= face.x + face.width * 1.2
          && points[0].y >= face.y - face.height * .2 && points[0].y <= face.y + face.height * 1.2
          && Math.min(points[11].y, points[12].y) > face.y + face.height * .6) {
        shoulders = {left: {x: points[11].x, y: points[11].y}, right: {x: points[12].x, y: points[12].y}};
      }
    } catch (error) { /* Face-based framing remains available if shoulders cannot be detected. */ }
    self.postMessage({id: id, result: {kind: 'face', face: face, shoulders: shoulders}});
  } catch (error) {
    self.postMessage({id: id, error: 'Detection unavailable'});
  } finally {
    image.close();
  }
};
