# QR attendance dependencies

Served locally; no student QR data or camera frames are sent to an external service.

- qrcode-generator 1.4.4 by Kazuhiko Arase, MIT. [Source](https://github.com/kazuhikoarase/qrcode-generator).
  `qrcode.js` SHA-256: `18ae399f81182bc9de916e9c77b195df20cc58d6f2d55a62b085a299f1bf1780`.
- jsQR 1.4.0, Apache 2.0. [Source](https://github.com/cozmo/jsQR).
  `jsQR.js` SHA-256: `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859`.

Version-pinned files downloaded from their published npm distributions. Licenses
are retained alongside the source. Camera access uses the browser's permission
prompt and requires HTTPS or localhost; image upload and hardware-scanner input
are also available.
