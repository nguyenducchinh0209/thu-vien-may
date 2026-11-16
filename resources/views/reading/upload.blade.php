<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload EPUB · {{ $book->title }}</title>
    <style>
        body { max-width: 44rem; margin: 3rem auto; padding: 0 1rem; color: #172033; font: 1rem/1.5 system-ui, sans-serif; }
        form { display: grid; gap: 1rem; padding: 1.5rem; border: 1px solid #d7deea; border-radius: .75rem; }
        button { width: fit-content; padding: .65rem 1rem; cursor: pointer; }
        .error { color: #a11; }
    </style>
</head>
<body>
    <p><a href="{{ url('/') }}#catalog">Back to books</a></p>
    <h1>Upload EPUB</h1>
    <p>{{ $book->title }}</p>

    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('staff.books.epub.store', $book) }}" enctype="multipart/form-data">
        @csrf
        <label for="epub">EPUB file (maximum 30 MB)</label>
        <input id="epub" name="epub" type="file" accept=".epub,application/epub+zip" required>
        <button type="submit">Upload book</button>
    </form>
</body>
</html>
