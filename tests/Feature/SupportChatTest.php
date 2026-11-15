<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_uses_public_catalog_and_only_returns_valid_book_ids(): void
    {
        config([
            'services.ai.api_key' => 'test-key',
            'services.ai.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'services.ai.model' => 'gemini-4-hydrogen',
        ]);

        $author = Author::create(['name' => 'Test Author']);
        $book = Book::create([
            'book_code' => 'BK900001',
            'title' => 'Fantasy for Young Readers',
            'category' => 'Fantasy',
            'description' => 'A story for young readers.',
        ]);
        $book->authors()->attach($author);

        $deletedBook = Book::create([
            'book_code' => 'BK900002',
            'title' => 'Deleted Fantasy Book',
        ]);
        $deletedBook->delete();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'answer' => 'Mình gợi ý cuốn này.',
                            'book_ids' => [$book->id, $deletedBook->id, 999999],
                        ], JSON_UNESCAPED_UNICODE),
                    ]]],
                ]],
            ]),
        ]);

        $this->postJson('/support/chat', [
            'message' => 'Gợi ý sách fantasy',
            'history' => [['role' => 'user', 'content' => 'Mình thích truyện phiêu lưu.']],
        ])
            ->assertOk()
            ->assertJsonPath('answer', 'Mình gợi ý cuốn này.')
            ->assertExactJson([
                'answer' => 'Mình gợi ý cuốn này.',
                'books' => [['id' => $book->id, 'title' => 'Fantasy for Young Readers']],
            ]);

        Http::assertSent(function (Request $request): bool {
            $systemMessage = $request['systemInstruction']['parts'][0]['text'];

            return $request->hasHeader('x-goog-api-key', 'test-key')
                && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-4-hydrogen:generateContent'
                && str_contains($systemMessage, 'Fantasy for Young Readers')
                && ! str_contains($systemMessage, 'Deleted Fantasy Book')
                && $request['contents'][0] === ['role' => 'user', 'parts' => [['text' => 'Mình thích truyện phiêu lưu.']]]
                && $request['contents'][1] === ['role' => 'user', 'parts' => [['text' => 'Gợi ý sách fantasy']]];
        });
    }

    public function test_chat_reports_missing_ai_configuration_without_calling_provider(): void
    {
        config(['services.ai.api_key' => null]);
        Http::fake();

        $this->postJson('/support/chat', ['message' => 'Xin chào'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Trợ lý AI chưa được cấu hình. Vui lòng liên hệ quản trị viên.');

        Http::assertNothingSent();
    }

    public function test_chat_validates_message_and_context_limits_before_calling_provider(): void
    {
        config(['services.ai.api_key' => 'test-key']);
        Http::fake();

        $this->postJson('/support/chat', ['message' => str_repeat('x', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->postJson('/support/chat', [
            'message' => 'Xin chào',
            'history' => array_fill(0, 9, ['role' => 'user', 'content' => 'Xin chào']),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('history');

        Http::assertNothingSent();
    }

    public function test_provider_authentication_and_quota_errors_are_explained_without_exposing_provider_details(): void
    {
        config([
            'services.ai.api_key' => 'test-key',
            'services.ai.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'API_KEY_INVALID', 'message' => 'sensitive provider detail']], 400)
                ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'sensitive provider detail']], 429),
        ]);

        $this->postJson('/support/chat', ['message' => 'Xin chào'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Khóa Gemini API không hợp lệ. Hãy kiểm tra lại GEMINI_API_KEY.')
            ->assertDontSee('sensitive provider detail');

        $this->postJson('/support/chat', ['message' => 'Xin chào'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Gemini đã hết hạn mức miễn phí hoặc đang giới hạn yêu cầu. Hãy kiểm tra hạn mức trong Google AI Studio.')
            ->assertDontSee('sensitive provider detail');
    }
}
