<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $error ? 'Connection failed' : 'Store connected' }} · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; }
        .card { max-width: 440px; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; text-align: center; box-shadow: 0 10px 25px -10px rgba(15, 23, 42, .15); }
        .icon { width: 48px; height: 48px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; }
        .ok { background: #ecfdf5; color: #059669; }
        .fail { background: #fef2f2; color: #dc2626; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { font-size: 14px; line-height: 1.6; color: #475569; margin: 0 0 24px; }
        a { display: inline-block; background: #1e56ff; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        @if ($error)
            <div class="icon fail">!</div>
            <h1>{{ $platform }} could not be connected</h1>
            <p>{{ $error }}</p>
        @else
            <div class="icon ok">&#10003;</div>
            <h1>{{ $platform }} store connected</h1>
            <p>"{{ $storeName }}" is now connected to {{ config('app.name') }}. You can close this window.</p>
        @endif
        <a href="{{ $storesUrl }}" target="_top">Open Finovo Stores</a>
    </div>
</body>
</html>
