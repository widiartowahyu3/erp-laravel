<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code', $status ?? '404') - @yield('message', $message ?? 'Halaman tidak ditemukan.')</title>
    <link rel="stylesheet" href="/error.css">
</head>
<body>
    <div class="container">
        <div class="status">@yield('code', $status ?? '404')</div>
        <div class="message">@yield('message', $message ?? 'Halaman tidak ditemukan.')</div>
        <a href="/" class="btn">Kembali ke Beranda</a>
    </div>
</body>
</html>
