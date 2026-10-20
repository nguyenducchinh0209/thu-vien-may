@extends('layouts.library')

@section('content')
<div class="app-shell">
    @include('library.partials.sidebar')
    <button class="sidebar-backdrop" id="sidebar-backdrop" type="button" aria-label="Đóng menu" tabindex="-1"></button>

    <main class="main-area">
        @include('library.partials.topbar')
        <div id="app" class="page-content" tabindex="-1"></div>
        @include('library.partials.footer')
    </main>
</div>
@endsection
