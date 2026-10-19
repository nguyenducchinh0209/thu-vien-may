<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Đặt lại mật khẩu · Thư viện Mây</title>
    <style>
        body{font:16px system-ui,sans-serif;background:#f7f4ee;color:#25231f;margin:0;min-height:100vh;display:grid;place-items:center}.card{width:min(420px,calc(100% - 40px));background:white;padding:32px;border-radius:20px;box-shadow:0 16px 50px #29261f18}h1{font-size:26px;margin:0 0 8px}p{color:#6e6a61;line-height:1.5}.field{display:block;margin:18px 0}.field span{display:block;margin-bottom:6px;font-weight:600}.field input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #d9d5cc;border-radius:10px;font:inherit}.button{width:100%;padding:13px;border:0;border-radius:10px;background:#273f33;color:white;font:inherit;font-weight:700;cursor:pointer}.error{color:#aa3128;margin-top:6px}.status{color:#276544}
    </style>
</head>
<body>
<main class="card">
    <h1>Đặt lại mật khẩu</h1>
    <p>Chọn mật khẩu mới cho tài khoản của bạn.</p>
    @if (session('status'))<p class="status">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="field"><span>Email</span><input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">@error('email')<small class="error">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Mật khẩu mới</span><input type="password" name="password" required minlength="8" autocomplete="new-password">@error('password')<small class="error">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Nhập lại mật khẩu</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
        <button class="button" type="submit">Lưu mật khẩu mới</button>
    </form>
</main>
</body>
</html>
