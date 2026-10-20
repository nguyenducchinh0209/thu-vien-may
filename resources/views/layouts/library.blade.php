<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#173d35">
    <meta name="description" content="Thư viện Mây — không gian đọc sách và quản lý mượn trả thân thiện.">
    <title>{{ config('app.name') }} — Không gian đọc của bạn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    @foreach (['styles.css', 'operations.css', 'professional.css', 'search-community.css', 'account-features.css', 'sidebar-responsive.css'] as $stylesheet)
        <link rel="stylesheet" href="{{ asset('library/' . $stylesheet) }}?v={{ filemtime(public_path('library/' . $stylesheet)) }}">
    @endforeach
    @stack('styles')
</head>
<body>
    @php
        $libraryUser = auth()->user()?->only(['id', 'name', 'email', 'role']);
        $libraryAuthUrls = [
            'login' => route('auth.login'),
            'register' => route('auth.register'),
            'logout' => route('auth.logout'),
        ];
    @endphp
    <script>
        window.libraryUser = {{ Illuminate\Support\Js::from($libraryUser) }};
        window.libraryAuthUrls = {{ Illuminate\Support\Js::from($libraryAuthUrls) }};
        window.libraryFeatureConfig = {
            capabilities: {
                membership: true,
                plans: true,
                payments: true,
                reservationWorkflow: true,
                gender: true,
                chat: false,
            },
        };
    </script>
    @yield('content')
    @include('library.partials.dialogs')

    {{-- Thứ tự script giữ nguyên để các phần mở rộng giao diện hoạt động đúng. --}}
    @foreach (['app.js', 'operations.js', 'professional.js', 'feature-api.js', 'account-features.js', 'auth-admin.js'] as $script)
        <script src="{{ asset('library/' . $script) }}?v={{ filemtime(public_path('library/' . $script)) }}" defer></script>
    @endforeach
    @stack('scripts')
</body>
</html>
