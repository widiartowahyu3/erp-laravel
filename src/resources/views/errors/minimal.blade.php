<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code', $status ?? '404') - @yield('message', $message ?? 'Halaman tidak ditemukan.')</title>
    <style>
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            text-align: center;
            padding: 2rem;
            max-width: 28rem;
        }
        .status {
            font-size: 4rem;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        .message {
            font-size: 1.25rem;
            color: #334155;
            margin-bottom: 1.5rem;
        }
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: #4f46e5;
            color: #ffffff;
            border-radius: 0.375rem;
            text-decoration: none;
            font-weight: 500;
        }
        .btn:hover {
            background-color: #4338ca;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="status">@yield('code', $status ?? '404')</div>
        <div class="message">@yield('message', $message ?? 'Halaman tidak ditemukan.')</div>
        <a href="/" class="btn">Kembali ke Beranda</a>
    </div>
</body>
</html>
