<?php

namespace KaevCMS\Modules\Voting\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use KaevCMS\Modules\Voting\Contracts\VotingProvider;
use KaevCMS\Modules\Voting\Data\VoteEventPage;
use KaevCMS\Modules\Voting\Data\VoteInvitation;
use KaevCMS\Modules\Voting\Services\VoteEventDecoder;
use Throwable;

final class KTreonProvider implements VotingProvider
{
    private const API = 'https://ktreon.com/api/v1/projects/';

    private const MAX_RESPONSE_BYTES = 16384;

    public function code(): string
    {
        return 'ktreon';
    }

    public function name(): string
    {
        return 'KTreon';
    }

    public static function validProjectId(string $projectId): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $projectId) === 1
            && $projectId !== '00000000-0000-0000-0000-000000000000';
    }

    public static function validKey(#[\SensitiveParameter] string $key): bool
    {
        return preg_match('/\Akti_[A-Za-z0-9_-]{43}\z/D', $key) === 1;
    }

    public function check(string $projectId, #[\SensitiveParameter] string $key): string
    {
        // Same strict reader as synchronization, without writing the cursor or events.
        return $this->readEvents($projectId, $key, '0', 1)->result;
    }

    public function readEvents(string $projectId, #[\SensitiveParameter] string $key, string $cursor, int $limit = 100): VoteEventPage
    {
        if (! self::validProjectId($projectId) || ! self::validKey($key)
            || ! VoteEventDecoder::validCursor($cursor) || $limit < 1 || $limit > 100) {
            return new VoteEventPage('not_configured');
        }

        try {
            $deadline = hrtime(true) + 10_000_000_000;
            $response = Http::withToken($key)->acceptJson()
                ->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 3])
                ->get(self::API.$projectId.'/vote-events', ['cursor' => $cursor, 'limit' => $limit]);

            try {
                if ($response->status() === 429) {
                    $retry = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

                    return new VoteEventPage('rate_limited', retryAfter: max(60, min(3600, $retry === false ? 60 : $retry)));
                }
                if ($response->status() !== 200) {
                    return new VoteEventPage(in_array($response->status(), [401, 403], true) ? 'unauthorized' : 'unavailable');
                }

                return (new VoteEventDecoder)->decode($this->readJson($response, $deadline, 131072, false), $projectId, $cursor, $limit);
            } finally {
                $response->toPsrResponse()->getBody()->close();
            }
        } catch (\JsonException) {
            return new VoteEventPage('invalid_response');
        } catch (Throwable) {
            return new VoteEventPage('unavailable');
        }
    }

    public function invite(string $projectId, #[\SensitiveParameter] string $key, string $requestId, string $playerRef): VoteInvitation
    {
        if (! self::validProjectId($projectId) || ! self::validKey($key)
            || ! self::validProjectId($requestId) || preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $playerRef) !== 1) {
            return new VoteInvitation('not_configured');
        }

        try {
            $deadline = hrtime(true) + 10_000_000_000;
            $response = Http::withToken($key)->acceptJson()->asJson()
                ->withHeaders(['Idempotency-Key' => $requestId])
                ->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 3])
                ->post(self::API.$projectId.'/vote-intents', ['player_ref' => $playerRef]);

            try {
                if ($response->status() === 429) {
                    $retry = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

                    return new VoteInvitation('rate_limited', retryAfter: max(60, min(3600, $retry === false ? 60 : $retry)));
                }
                if ($response->status() === 409) {
                    $error = $this->readJson($response, $deadline);

                    return new VoteInvitation(is_array($error) && ($error['error'] ?? null) === 'intent_unavailable' ? 'expired' : 'unavailable');
                }
                if (! in_array($response->status(), [200, 201], true)) {
                    return new VoteInvitation(in_array($response->status(), [401, 403], true) ? 'unauthorized' : 'unavailable');
                }

                $data = $this->readJson($response, $deadline);
                if (! is_array($data) || ! is_string($data['intent_id'] ?? null) || ! self::validProjectId($data['intent_id'])
                    || ! is_string($data['vote_url'] ?? null)
                    || preg_match('#\Ahttps://ktreon\.com/vote/kvi_[A-Za-z0-9_-]{43}\z#D', $data['vote_url']) !== 1
                    || ($expiry = VoteEventDecoder::utcDate($data['expires_at'] ?? null)) === null) {
                    return new VoteInvitation('invalid_response');
                }
                $expiresAt = CarbonImmutable::parse($expiry, 'UTC');
                if ($expiresAt->isPast() || $expiresAt->greaterThan(CarbonImmutable::now('UTC')->addMinutes(11))) {
                    return new VoteInvitation('invalid_response');
                }

                return new VoteInvitation('ok', $data['intent_id'], $data['vote_url'], $expiresAt);
            } finally {
                $response->toPsrResponse()->getBody()->close();
            }
        } catch (\JsonException) {
            return new VoteInvitation('invalid_response');
        } catch (Throwable) {
            // An uncertain POST must be retried with the original idempotency key.
            return new VoteInvitation('unavailable');
        }
    }

    private function readJson(Response $response, int|float $deadline, int $maxBytes = self::MAX_RESPONSE_BYTES, bool $associative = true): mixed
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
        if ($contentType !== 'application/json' || (int) $response->header('Content-Length') > $maxBytes) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        do {
            if (hrtime(true) > $deadline || strlen($body) >= $maxBytes) {
                return null;
            }
            $chunk = $stream->read(min(4096, $maxBytes - strlen($body)));
            $body .= $chunk;
            $atEnd = $stream->eof();
            if ($chunk === '' && ! $atEnd) {
                return null;
            }
        } while (! $atEnd);

        return json_decode($body, $associative, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
