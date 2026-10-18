@extends('layouts.library')

@section('content')
<div class="app-shell">
    @include('library.partials.sidebar')

    <main class="main-area">
        @include('library.partials.topbar')
        <div id="app" class="page-content" tabindex="-1"></div>
        @include('library.partials.footer')
    </main>
</div>
@endsection
