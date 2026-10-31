<?php

namespace Tests\Unit;

use App\Enums\BroadcastType;
use App\Enums\StoryType;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\MediaFile;
use App\Models\Story;
use App\Services\Waha\DTO\ConnectionSnapshot;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaClientTest extends TestCase
{
    private function snapshot(array $overrides = []): ConnectionSnapshot
    {
        return new ConnectionSnapshot(
            baseUrl: $overrides['baseUrl'] ?? 'http://waha.test',
            apiKey: $overrides['apiKey'] ?? 'sekrit-key',
            basicUsername: $overrides['basicUsername'] ?? 'proxyuser',
            basicPassword: $overrides['basicPassword'] ?? 'proxypass',
            session: $overrides['session'] ?? 'default',
            revision: $overrides['revision'] ?? 1,
            capabilities: $overrides['capabilities'] ?? [],
        );
    }

    private function story(StoryType $type, ?MediaFile $media = null): Story
    {
        $story = new Story;
        $story->forceFill([
            'type' => $type,
            'text' => 'Hello status',
            'background_color' => '#38b42f',
            'font' => 2,
        ]);
        if ($media !== null) {
            $story->media_file_id = 1;
            $story->setRelation('mediaFile', $media);
        }

        return $story;
    }

    private function media(string $mime, string $originalName = 'media.jpg'): MediaFile
    {
        $file = new MediaFile;
        $file->forceFill([
            'mime_type' => $mime,
            'original_name' => $originalName,
        ]);

        return $file;
    }

    public function test_exact_requests_for_story_and_broadcast_endpoints(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'waha.test/api/default/status/text' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/waha/send-result-webjs.json')), true), 200),
            'waha.test/api/default/status/image' => Http::response(['id' => 'image-id'], 200),
            'waha.test/api/default/status/video' => Http::response(['id' => 'video-id'], 200),
            'waha.test/api/default/status/delete' => Http::response(['success' => true], 200),
            'waha.test/api/sendText' => Http::response(['id' => 'text-msg-id'], 200),
            'waha.test/api/sendImage' => Http::response(['id' => 'image-msg-id'], 200),
            'waha.test/api/contacts/all*' => Http::response([['chatId' => '6281@c.us']], 200),
            'waha.test/api/default/chats' => Http::response([['id' => '6281@c.us']], 200),
        ]);

        $client = new WahaClient;
        $connection = $this->snapshot();

        // --- Text story: exact payload per D2.
        $result = $client->sendStory($connection, $this->story(StoryType::Text), null);
        $this->assertSame('false_6281234567891@c.us_3EB0844D5C1F6060173767', $result->messageId);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://waha.test/api/default/status/text'
                && $request->method() === 'POST'
                && $request['text'] === 'Hello status'
                && $request['backgroundColor'] === '#38b42f'
                && $request['font'] === 2
                && ! array_key_exists('caption', $request->data())
                && $request->hasHeader('X-Api-Key', 'sekrit-key')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('proxyuser:proxypass'));
        });

        // --- Image story: file object with mimetype + url, caption from text.
        $imageStory = $this->story(StoryType::Image, $this->media('image/jpeg', 'photo.jpg'));
        $result = $client->sendStory($connection, $imageStory, 'http://app.test/storage/stories/2026/10/x.jpg');
        $this->assertSame('image-id', $result->messageId);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://waha.test/api/default/status/image'
                && $request->method() === 'POST'
                && $request->data()['file']['mimetype'] === 'image/jpeg'
                && $request->data()['file']['url'] === 'http://app.test/storage/stories/2026/10/x.jpg'
                && $request['caption'] === 'Hello status'
                && ! array_key_exists('text', $request->data());
        });

        // --- Video story: filename included and `convert` sent explicitly.
        $videoStory = $this->story(StoryType::Video, $this->media('video/mp4', 'clip.mp4'));
        $videoStory->text = null;
        $result = $client->sendStory($connection, $videoStory, 'http://app.test/storage/stories/2026/10/x.mp4');
        $this->assertSame('video-id', $result->messageId);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'http://waha.test/api/default/status/video'
                && $request->method() === 'POST'
                && $data['file']['mimetype'] === 'video/mp4'
                && $data['file']['filename'] === 'clip.mp4'
                && $data['file']['url'] === 'http://app.test/storage/stories/2026/10/x.mp4'
                && array_key_exists('convert', $data)
                && $data['convert'] === false
                && ! array_key_exists('caption', $data);
        });

        // --- Delete uses the key id as documented.
        $client->deleteStatus($connection, '3EB0A8B2C9D7E1F3');
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/default/status/delete'
            && $request->method() === 'POST'
            && $request['id'] === '3EB0A8B2C9D7E1F3');

        // --- Broadcast text and image.
        $broadcast = new Broadcast;
        $broadcast->forceFill(['type' => BroadcastType::Text, 'template_text' => 'Hi {name}']);

        $recipient = new BroadcastRecipient;
        $recipient->forceFill([
            'chat_id' => '6281234567891@c.us',
            'rendered_text' => 'Hi Andi',
        ]);

        $result = $client->sendBroadcast($connection, $recipient, $broadcast, null);
        $this->assertSame('text-msg-id', $result->messageId);

        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendText'
            && $request->method() === 'POST'
            && $request['session'] === 'default'
            && $request['chatId'] === '6281234567891@c.us'
            && $request['text'] === 'Hi Andi');

        $imageBroadcast = new Broadcast;
        $imageBroadcast->forceFill(['type' => BroadcastType::Image, 'template_text' => 'Hi {name}']);
        $imageBroadcast->setRelation('mediaFile', $this->media('image/png', 'promo.png'));

        $result = $client->sendBroadcast($connection, $recipient, $imageBroadcast, 'http://app.test/storage/stories/2026/10/x.png');
        $this->assertSame('image-msg-id', $result->messageId);

        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendImage'
            && $request->method() === 'POST'
            && $request['session'] === 'default'
            && $request['chatId'] === '6281234567891@c.us'
            && $request->data()['file']['mimetype'] === 'image/png'
            && $request->data()['file']['url'] === 'http://app.test/storage/stories/2026/10/x.png'
            && $request['caption'] === 'Hi Andi');

        // --- Contacts use the query param; chats use the session path.
        $client->listContacts($connection);
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/contacts/all?session=default'
            && $request->method() === 'GET');

        $client->listChats($connection);
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/default/chats'
            && $request->method() === 'GET');
    }

    public function test_accepts_top_level_id_or_key_id(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('*')
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/waha/send-result-webjs.json')), true), 200)
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/waha/send-result-key-id.json')), true), 200)
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/waha/send-result-object-id.json')), true), 200);

        $client = new WahaClient;
        $connection = $this->snapshot();

        // Engine returning a string top-level id (webjs shape).
        $result = $client->sendStory($connection, $this->story(StoryType::Text), null);
        $this->assertNotNull($result->messageId);
        $this->assertNull($result->keyId);

        // Engine returning only key.id.
        $result = $client->sendStory($connection, $this->story(StoryType::Text), null);
        $this->assertSame('3EB0A8B2C9D7E1F2', $result->keyId);
        $this->assertNull($result->messageId);

        // Engine returning an object id: serialized form + key id both kept.
        $result = $client->sendStory($connection, $this->story(StoryType::Text), null);
        $this->assertSame('true_status@broadcast_3EB0A8B2C9D7E1F3', $result->messageId);
        $this->assertSame('3EB0A8B2C9D7E1F3', $result->keyId);
    }

    public function test_two_xx_without_id_is_unknown(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/waha/send-result-no-id.json')), true), 200),
        ]);

        try {
            (new WahaClient)->sendStory($this->snapshot(), $this->story(StoryType::Text), null);
            $this->fail('Expected WahaRequestException for 2xx without id.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('unknown', $exception->outcome);
            $this->assertFalse($exception->retryable);
            $this->assertSame(200, $exception->httpStatus);
            $this->assertStringNotContainsString('sekrit-key', $exception->safeMessage);
        }
    }

    public function test_timeout_is_never_retried(): void
    {
        Http::preventStrayRequests();

        // The next stub response would succeed with an id, so if the client
        // retried, no exception would surface. One attempt only.
        Http::fakeSequence('*')
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push(['id' => 'would-prove-retry'], 200);

        try {
            (new WahaClient)->sendStory($this->snapshot(), $this->story(StoryType::Text), null);
            $this->fail('Expected WahaRequestException on timeout.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('unknown', $exception->outcome);
            $this->assertFalse($exception->retryable);
            $this->assertSame('network_timeout', $exception->errorCode);
            $this->assertStringNotContainsString('sekrit-key', $exception->safeMessage);
        }
    }

    public function test_ambiguous_five_xx_is_unknown_and_not_retried(): void
    {
        Http::preventStrayRequests();

        // 5xx after the send may have been processed: unknown outcome, one
        // recorded request, and a second stub success that must stay unused.
        Http::fakeSequence('*')
            ->push(['error' => 'internal'], 500)
            ->push(['id' => 'would-prove-retry'], 200);

        try {
            (new WahaClient)->sendBroadcast(
                $this->snapshot(),
                (new BroadcastRecipient)->forceFill(['chat_id' => '6281@c.us', 'rendered_text' => 'x']),
                (new Broadcast)->forceFill(['type' => BroadcastType::Text, 'template_text' => 'x']),
                null,
            );
            $this->fail('Expected WahaRequestException on 5xx.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('unknown', $exception->outcome);
            $this->assertFalse($exception->retryable);
            $this->assertSame(500, $exception->httpStatus);
        }
        Http::assertSentCount(1);
    }

    public function test_forbidden_is_not_assumed_missing_session(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'access denied'], 403)]);

        try {
            (new WahaClient)->sendStory($this->snapshot(), $this->story(StoryType::Text), null);
            $this->fail('Expected WahaRequestException on 403.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('forbidden', $exception->errorCode);
            $this->assertNotSame('not_found', $exception->errorCode);
            $this->assertSame('rejected', $exception->outcome);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_rate_limited_send_is_rejected_but_safe_to_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'too many requests'], 429, ['Retry-After' => '120'])]);

        try {
            (new WahaClient)->sendStory($this->snapshot(), $this->story(StoryType::Text), null);
            $this->fail('Expected WahaRequestException on 429.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('rejected', $exception->outcome);
            $this->assertTrue($exception->retryable);
            $this->assertSame(120, $exception->retryAfterSeconds);
        }
        Http::assertSentCount(1);
    }

    public function test_unsupported_delete_is_blocked(): void
    {
        Http::preventStrayRequests();

        $connection = $this->snapshot(['capabilities' => [
            'delete_status' => ['state' => 'unsupported', 'source' => 'admin'],
        ]]);

        try {
            (new WahaClient)->deleteStatus($connection, '3EB0A8B2C9D7E1F3');
            $this->fail('Expected delete to be blocked by capability.');
        } catch (WahaRequestException $exception) {
            $this->assertSame('capability_unsupported', $exception->errorCode);
            $this->assertSame('rejected', $exception->outcome);
        }

        Http::assertNothingSent();

        // Unknown capability (default) does not block: documented behavior.
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        (new WahaClient)->deleteStatus($this->snapshot(), '3EB0A8B2C9D7E1F3');
        Http::assertSentCount(1);
    }
}
