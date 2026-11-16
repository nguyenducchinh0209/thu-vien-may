<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d35">
    <title>{{ $book->title }} · {{ $chapter['title'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Spectral:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('library/epub-reader.css') }}?v={{ filemtime(public_path('library/epub-reader.css')) }}">
</head>
<body>
<a class="reader-skip" href="#reading">Đến nội dung đọc</a>
<header class="reader-header">
    <div class="reader-bar reader-shell">
        <a class="reader-brand" href="{{ url('/') }}">Thư viện Mây</a>
        <nav class="reader-links" aria-label="Điều hướng thư viện">
            <a href="{{ url('/') }}#book/{{ $book->id }}"><span aria-hidden="true">←</span> Chi tiết sách</a>
            @if (auth()->user()?->role === 'Độc giả')
                <a href="{{ url('/') }}#shelf">Kệ sách của tôi</a>
            @endif
        </nav>
    </div>
</header>

<div class="reader-layout reader-shell">
    <aside class="reader-sidebar" aria-label="Thông tin đọc sách">
        <details class="reader-contents" open>
            <summary>Mục lục</summary>
            <nav aria-label="Mục lục">
                <ol>
                    @foreach ($chapters as $index => $item)
                        <li>
                            <a href="{{ route('books.read', ['book' => $book, 'chapter' => $index + 1]) }}"
                               @if ($chapterNumber === $index + 1) aria-current="page" @endif>
                                <span class="reader-contents-number" aria-hidden="true">{{ $index + 1 }}</span>
                                <span>{{ $item['title'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </details>
        @unless ($canReadFullBook)
            <p class="reader-preview">
                Bạn đang đọc thử {{ $chapterLimit }} chương đầu.
                <a href="{{ url('/') }}#vip">Xem gói VIP</a>
            </p>
        @endunless
    </aside>

    <main class="reader-paper" id="reading" tabindex="-1">
        <header class="reader-title">
            <h1>{{ $book->title }}</h1>
            <h2>{{ $chapter['title'] }}</h2>
        </header>

        <article class="reader-prose" aria-label="{{ $chapter['title'] }}">
            @foreach ($chapter['paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </article>

        @if ($chapterLimit > 1)
            <nav class="reader-chapter-nav" aria-label="Điều hướng chương">
                @if ($chapterNumber > 1)
                    <a class="reader-previous" rel="prev" href="{{ route('books.read', ['book' => $book, 'chapter' => $chapterNumber - 1]) }}">
                        <span aria-hidden="true">←</span> Chương trước
                    </a>
                @endif
                <span class="reader-chapter-count">Chương {{ $chapterNumber }} / {{ $chapterLimit }}</span>
                @if ($chapterNumber < $chapterLimit)
                    <a class="reader-next" rel="next" href="{{ route('books.read', ['book' => $book, 'chapter' => $chapterNumber + 1]) }}">
                        Chương sau <span aria-hidden="true">→</span>
                    </a>
                @endif
            </nav>
        @endif
    </main>
</div>
</body>
</html>
