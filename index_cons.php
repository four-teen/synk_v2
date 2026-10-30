<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>synQ Systems — Under Construction </title>
  <meta name="description" content="synQ Systems is currently under construction." />
  <link rel="stylesheet" href="assets/css/brand.css?v=<?= (int)filemtime(__DIR__.'/assets/css/brand.css') ?>" />
  <style>
    :root { color-scheme: light dark; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(1200px 600px at 50% 0%, rgba(0, 123, 255, 0.18), transparent 60%),
                  radial-gradient(900px 500px at 20% 100%, rgba(255, 159, 64, 0.14), transparent 60%),
                  #0b1020;
      color: #e9eefc;
      padding: 24px;
      text-align: center;
    }
    .card {
      width: min(720px, 100%);
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.06);
      backdrop-filter: blur(10px);
      border-radius: 18px;
      padding: 42px 28px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    }
    .badge {
      display: inline-block;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.18);
      background: rgba(255,255,255,0.08);
      font-weight: 600;
      letter-spacing: 0.3px;
      margin-bottom: 18px;
    }
    h1 {
      margin: 0 0 10px 0;
      font-size: clamp(28px, 4vw, 44px);
      line-height: 1.15;
    }
    p {
      margin: 0;
      opacity: 0.9;
      font-size: 16px;
      line-height: 1.6;
    }
    .small {
      margin-top: 22px;
      font-size: 13px;
      opacity: 0.75;
    }
  </style>
</head>
<body>
  <main class="card" role="main" aria-label="synQ Systems Under Construction">
    <div class="badge">🚧 Under Construction</div>
    <h1><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span> Systems</h1>
    <p>We’re currently building something awesome. Please check back soon.</p>
    <div class="small"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span> - One Institution. One Connected Ecosystem.</div>
  </main>

</body>
</html>
