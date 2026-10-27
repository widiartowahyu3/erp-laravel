<?php

declare(strict_types=1);

dataset('password_policy_matrix', [
    'too short (11 chars)' => ['Short#Pass1', 'user@example.com', false, 'Kata sandi minimal 12 karakter.'],
    'minimum length (12 chars)' => ['SecurePass#1', 'user@example.com', true, null],
    'valid standard length (16 chars)' => ['Valid#Secure1234', 'user@example.com', true, null],
    'maximum length (128 chars)' => [str_repeat('A', 60).str_repeat('a', 60).'12345678', 'user@example.com', true, null],
    'too long (129 chars)' => [str_repeat('A', 60).str_repeat('a', 60).'123456789', 'user@example.com', false, 'Kata sandi maksimal 128 karakter.'],
    'no uppercase' => ['lowercase#12345', 'user@example.com', false, 'Kata sandi harus mengandung setidaknya satu huruf besar.'],
    'no lowercase' => ['UPPERCASE#12345', 'user@example.com', false, 'Kata sandi harus mengandung setidaknya satu huruf kecil.'],
    'no numbers' => ['NoNumberPassword#', 'user@example.com', false, 'Kata sandi harus mengandung setidaknya satu angka.'],
    'local-part >= 4 contained exact case' => ['Pass#wahyu1234', 'wahyu@example.com', false, 'Kata sandi tidak boleh memuat bagian email pengguna.'],
    'local-part >= 4 contained mixed case' => ['Pass#Wahyu1234', 'wahyu@example.com', false, 'Kata sandi tidak boleh memuat bagian email pengguna.'],
    'local-part >= 4 not contained' => ['Valid#Pass1234', 'wahyu@example.com', true, null],
    'local-part < 4 contained (bob, length 3)' => ['Valid#bobPass123', 'bob@example.com', true, null],
    'local-part < 4 contained (a, length 1)' => ['Valid#Pass1234a', 'a@example.com', true, null],
]);
