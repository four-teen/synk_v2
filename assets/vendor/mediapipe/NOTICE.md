# Photo detection assets

Self-hosted assets used by Student camera capture and the opted-in College Dean and Professor faculty photo editors.

- MediaPipe Tasks Vision **1.1.0**, Apache-2.0:
  https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.1.0.tgz
- BlazeFace full-range face detector, float16, model version 1:
  https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_full_range/float16/1/blaze_face_full_range.tflite
- Pose Landmarker Lite, float16, model version 1:
  https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task
- License: https://github.com/google-ai-edge/mediapipe/blob/master/LICENSE

Deploy this directory at assets/vendor/mediapipe, including the `wasm` subdirectory.
Detection runs in the browser. Photos are not sent to an external detection service.
Faculty editors allow a manual crop when detection is unavailable or ambiguous.
Student capture requires the photo checks to load before saving. These checks do
not establish identity, liveness, or protection against virtual camera feeds.
