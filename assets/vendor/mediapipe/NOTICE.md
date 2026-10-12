# Faculty photo detection assets

Self-hosted assets used by the opted-in College Dean and Professor faculty photo editors.

- MediaPipe Tasks Vision **1.1.0**, Apache-2.0:
  https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.1.0.tgz
- BlazeFace full-range face detector, float16, model version 1:
  https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_full_range/float16/1/blaze_face_full_range.tflite
- Pose Landmarker Lite, float16, model version 1:
  https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task
- License: https://github.com/google-ai-edge/mediapipe/blob/master/LICENSE

Deploy this directory at assets/vendor/mediapipe, including the `wasm` subdirectory.
Detection runs in the browser. Photos are not sent to an external detection service.
The uploader can adjust the crop manually if detection is unavailable or ambiguous.
