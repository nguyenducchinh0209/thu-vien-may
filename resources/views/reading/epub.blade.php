<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $book->title }} · {{ $chapter['title'] }}</title>
    <style>
        body { margin: 0; color: #202633; font: 1rem/1.75 Georgia, serif; }
        header, main, nav { max-width: 58rem; margin: 0 auto; padding: 1rem 1.25rem; }
        header { border-bottom: 1px solid #d7deea; font-family: system-ui, sans-serif; }
        nav { display: flex; flex-wrap: wrap; gap: .5rem 1rem; font-family: system-ui, sans-serif; }
        nav a[aria-current="page"] { font-weight: 700; }
        main { white-space: pre-wrap; overflow-wrap: anywhere; }
        .chapter-nav { display: flex; justify-content: space-between; max-width: 58rem; margin: 1rem auto 3rem; padding: 0 1.25rem; font-family: system-ui, sans-serif; }
    </style>
</head>
<body>
    <header>
        <a href="{{ url('/') }}#book/{{ $book->id }}">Back to book</a>
        <h1>{{ $book->title }}</h1>
        @unless ($canReadFullBook)
            <p>Free preview: chapters 1–{{ $chapterLimit }}. Borrow the physical copy to read the full book, or activate VIP.</p>
        @endunless
        @unless ($previewAvailable)
            <p>Free preview is unavailable for this EPUB because its table of contents does not map one chapter to each separate XHTML document.</p>
        @endunless
    </header>

    <nav aria-label="Table of contents">
        @foreach ($chapters as $index => $item)
            <a href="{{ route('books.read', ['book' => $book, 'chapter' => $index + 1]) }}" @if ($chapterNumber === $index + 1) aria-current="page" @endif>
                {{ $index + 1 }}. {{ $item['title'] }}
            </a>
        @endforeach
    </nav>

    <main>
        <h2>{{ $chapter['title'] }}</h2>
        <article>{{ $chapter['text'] }}</article>
    </main>

    <div class="chapter-nav">
        @if ($chapterNumber > 1)
            <a href="{{ route('books.read', ['book' => $book, 'chapter' => $chapterNumber - 1]) }}">Previous chapter</a>
        @else
            <span></span>
        @endif
        @if ($chapterNumber < $chapterLimit)
            <a href="{{ route('books.read', ['book' => $book, 'chapter' => $chapterNumber + 1]) }}">Next chapter</a>
        @endif
    </div>
</body>
</html>
