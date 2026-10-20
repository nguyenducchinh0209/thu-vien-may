<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Membership;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class EpubReadingController extends Controller
{
    private const MAX_UPLOAD_KILOBYTES = 30720;

    private const MAX_ARCHIVE_ENTRIES = 500;

    private const MAX_UNCOMPRESSED_BYTES = 52428800;

    public function show(Request $request, Book $book)
    {
        abort_unless($book->epub_path && Storage::disk('local')->exists($book->epub_path), 404);

        $epub = $this->readChapters(Storage::disk('local')->path($book->epub_path));
        $isStaff = in_array($request->user()->role, ['Admin', 'Thủ thư'], true);
        $membership = $isStaff ? null : Membership::query()->where('user_id', $request->user()->id)->first();
        $isVip = $membership
            && $membership->tier === 'vip'
            && $membership->status === 'active'
            && $membership->expires_at?->isFuture();
        abort_unless($isStaff || $isVip || $epub['previewAvailable'], 403, 'Free preview is unavailable because this EPUB does not identify one chapter per XHTML document in its table of contents.');

        $chapters = $epub['chapters'];
        $chapterLimit = ($isStaff || $isVip) ? count($chapters) : min(3, count($chapters));
        $chapterNumber = $request->integer('chapter', 1);

        abort_unless($chapterNumber >= 1 && $chapterNumber <= $chapterLimit, 403);

        return view('reading.epub', [
            'book' => $book,
            'chapters' => array_slice($chapters, 0, $chapterLimit),
            'chapter' => $chapters[$chapterNumber - 1],
            'chapterNumber' => $chapterNumber,
            'chapterLimit' => $chapterLimit,
            'canReadFullBook' => $chapterLimit === count($chapters),
            'previewAvailable' => $epub['previewAvailable'],
        ]);
    }

    public function uploadForm(Book $book)
    {
        return view('reading.upload', ['book' => $book]);
    }

    public function upload(Request $request, Book $book)
    {
        $validated = $request->validate([
            'epub' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KILOBYTES],
        ]);

        $file = $validated['epub'];
        if (strtolower($file->getClientOriginalExtension()) !== 'epub') {
            throw ValidationException::withMessages(['epub' => 'Upload an EPUB file.']);
        }

        $temporaryPath = $file->getRealPath();
        $this->readChapters($temporaryPath);

        $storedPath = $file->storeAs('book-epubs/'.$book->id, Str::uuid().'.epub', 'local');
        abort_unless(is_string($storedPath), 500, 'The EPUB file could not be stored.');
        $previousPath = $book->epub_path;

        try {
            $book->forceFill(['epub_path' => $storedPath])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        if ($previousPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return redirect()->route('books.read', ['book' => $book, 'chapter' => 1]);
    }

    /** @return array{chapters: array<int, array{title: string, text: string}>, previewAvailable: bool} */
    private function readChapters(string $path): array
    {
        abort_unless(extension_loaded('zip') && class_exists(ZipArchive::class), 503, 'PHP zip extension is required to read EPUB files.');

        $archive = new ZipArchive;
        abort_unless($archive->open($path) === true, 422, 'The uploaded file is not a readable EPUB archive.');

        try {
            abort_if($archive->numFiles < 1 || $archive->numFiles > self::MAX_ARCHIVE_ENTRIES, 422, 'The EPUB archive has an invalid number of entries.');

            $totalSize = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                abort_unless($entry && $this->isSafeArchivePath($entry['name']), 422, 'The EPUB archive contains an unsafe path.');
                $totalSize += (int) ($entry['size'] ?? 0);
                abort_if($totalSize > self::MAX_UNCOMPRESSED_BYTES, 422, 'The EPUB archive is too large after decompression.');
            }

            $container = $this->readXmlEntry($archive, 'META-INF/container.xml');
            $containerXpath = new DOMXPath($container);
            $rootfilePath = $containerXpath->evaluate('string((//*[local-name()="rootfile"]/@full-path)[1])');
            $packagePath = $this->resolveArchivePath('', $rootfilePath);
            $package = $this->readXmlEntry($archive, $packagePath);
            $xpath = new DOMXPath($package);
            $manifest = [];
            $navigationPaths = [];

            foreach ($xpath->query('//*[local-name()="manifest"]/*[local-name()="item"]') as $item) {
                $id = $item->getAttribute('id');
                $href = $item->getAttribute('href');
                $mediaType = $item->getAttribute('media-type');
                if ($id === '' || $href === '') {
                    continue;
                }
                $itemPath = $this->resolveArchivePath(dirname($packagePath), $href);
                $manifest[$id] = ['path' => $itemPath, 'mediaType' => $mediaType, 'properties' => preg_split('/\s+/', trim($item->getAttribute('properties'))) ?: []];
                if ($mediaType === 'application/xhtml+xml' && in_array('nav', $manifest[$id]['properties'], true)) {
                    $navigationPaths[] = $itemPath;
                }
            }

            $chapters = [];
            $spinePaths = [];
            $seenChapters = [];
            $spineItems = $xpath->query('//*[local-name()="spine"]/*[local-name()="itemref"]');
            abort_if($spineItems->length > self::MAX_ARCHIVE_ENTRIES, 422, 'The EPUB spine contains too many chapters.');
            $allSpineItemsAreXhtml = true;
            $chapterBytes = 0;
            foreach ($spineItems as $item) {
                $id = $item->getAttribute('idref');
                if (! isset($manifest[$id]) || $manifest[$id]['mediaType'] !== 'application/xhtml+xml') {
                    $allSpineItemsAreXhtml = false;
                    continue;
                }
                $chapterPath = $manifest[$id]['path'];
                abort_if(isset($seenChapters[$id]), 422, 'The EPUB spine contains a repeated chapter.');
                $seenChapters[$id] = true;
                $spinePaths[] = $chapterPath;

                $contents = $archive->getFromName($chapterPath);
                $chapterBytes += is_string($contents) ? strlen($contents) : 0;
                abort_if($chapterBytes > self::MAX_UNCOMPRESSED_BYTES, 422, 'The EPUB chapters are too large to read.');
                abort_unless(is_string($contents) && ! preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $contents), 422, 'The EPUB chapter contains unsupported XML declarations.');
                $document = new DOMDocument;
                $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
                abort_unless($loaded, 422, 'An EPUB chapter could not be read.');

                $chapterXpath = new DOMXPath($document);
                $title = trim($chapterXpath->evaluate('string(//title[1])'));
                foreach ($chapterXpath->query('//script|//style|//head') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $body = $chapterXpath->query('//body')->item(0);
                $text = preg_replace('/[\t\r\n ]+/u', ' ', $body?->textContent ?? $document->textContent ?? '') ?? '';
                $text = trim($text);
                $chapters[] = [
                    'title' => $title !== '' ? $title : 'Chapter '.(count($chapters) + 1),
                    'text' => $text,
                ];
            }

            abort_if($chapters === [], 422, 'The EPUB does not contain readable chapters.');

            return [
                'chapters' => $chapters,
                'previewAvailable' => $allSpineItemsAreXhtml
                    && $this->hasOneDocumentPerChapterNavigation($archive, $xpath, $manifest, $navigationPaths, $spinePaths),
            ];
        } finally {
            $archive->close();
        }
    }

    private function hasOneDocumentPerChapterNavigation(ZipArchive $archive, DOMXPath $packageXpath, array $manifest, array $navigationPaths, array $spinePaths): bool
    {
        if ($spinePaths === [] || count(array_unique($spinePaths)) !== count($spinePaths)) {
            return false;
        }

        if (count($navigationPaths) > 1) {
            return false;
        }

        if ($navigationPaths !== []) {
            // EPUB 3 navigation is authoritative: an ambiguous TOC cannot be rescued by a coarse NCX.
            $navigationPath = $navigationPaths[0];
            $contents = $archive->getFromName($navigationPath);
            if (! is_string($contents) || strlen($contents) > self::MAX_UNCOMPRESSED_BYTES) {
                return false;
            }

            $document = $this->readXmlEntry($archive, $navigationPath);
            $navXpath = new DOMXPath($document);
            $tocNodes = $navXpath->query('//*[local-name()="nav" and contains(concat(" ", normalize-space(@*[namespace-uri()="http://www.idpf.org/2007/ops" and local-name()="type"]), " "), " toc ")]');
            if (! $tocNodes || $tocNodes->length !== 1) {
                return false;
            }
            $references = (new DOMXPath($document))->query('.//*[local-name()="a"]/@href', $tocNodes->item(0));

            return $this->navigationMatchesSpine($references, $navigationPath, $spinePaths);
        }

        $ncxId = $packageXpath->evaluate('string((//*[local-name()="spine"]/@toc)[1])');
        if ($ncxId === '' || ! isset($manifest[$ncxId]) || $manifest[$ncxId]['mediaType'] !== 'application/x-dtbncx+xml') {
            return false;
        }

        $navigationPath = $manifest[$ncxId]['path'];
        $contents = $archive->getFromName($navigationPath);
        if (! is_string($contents) || strlen($contents) > self::MAX_UNCOMPRESSED_BYTES) {
            return false;
        }
        $document = $this->readXmlEntry($archive, $navigationPath);
        $references = (new DOMXPath($document))->query('//*[local-name()="navPoint"]/*[local-name()="content"]/@src');

        return $this->navigationMatchesSpine($references, $navigationPath, $spinePaths);
    }

    private function navigationMatchesSpine(?\DOMNodeList $references, string $navigationPath, array $spinePaths): bool
    {
        if (! $references || $references->length !== count($spinePaths)) {
            return false;
        }

        $navigationChapterPaths = [];
        foreach ($references as $reference) {
            $href = $reference->nodeValue ?? '';
            $url = parse_url($href);
            if (! is_array($url) || ($url['path'] ?? '') === '' || isset($url['fragment']) || isset($url['query'])) {
                return false;
            }
            $navigationChapterPaths[] = $this->resolveArchivePath(dirname($navigationPath), $href);
        }

        return $navigationChapterPaths === $spinePaths;
    }

    private function readXmlEntry(ZipArchive $archive, string $path): DOMDocument
    {
        $contents = $archive->getFromName($path);
        abort_unless(is_string($contents) && ! preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $contents), 422, 'The EPUB package contains unsupported XML declarations.');

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        abort_unless($loaded, 422, 'The EPUB package metadata is invalid.');

        return $document;
    }

    private function resolveArchivePath(string $baseDirectory, string $href): string
    {
        $url = parse_url($href);
        abort_unless(is_array($url) && ! isset($url['scheme']) && ! isset($url['host']), 422, 'The EPUB references an external resource.');

        $path = rawurldecode($url['path'] ?? '');
        abort_unless($path !== '' && ! str_contains($path, '\\') && ! str_contains($path, "\0") && ! str_starts_with($path, '/'), 422, 'The EPUB contains an unsafe resource path.');

        $parts = $baseDirectory === '' || $baseDirectory === '.' ? [] : explode('/', $baseDirectory);
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                abort_unless($parts !== [], 422, 'The EPUB resource path escapes the archive.');
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        abort_unless($parts !== [], 422, 'The EPUB resource path is invalid.');

        return implode('/', $parts);
    }

    private function isSafeArchivePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/')
            && ! preg_match('/^[a-zA-Z]:/', $path)
            && ! in_array('..', explode('/', rtrim($path, '/')), true);
    }
}
