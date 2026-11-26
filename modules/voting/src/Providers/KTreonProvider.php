<?php

namespace KaevCMS\Modules\Voting\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use KaevCMS\Modules\Voting\Contracts\VotingProvider;
use KaevCMS\Modules\Voting\Data\VoteInvitation;
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
        if (! self::validProjectId($projectId) || ! self::validKey($key)) {
            return 'not_configured';
        }

        try {
            // Reading one event never creates a vote or changes the delivery cursor.
            $deadline = hrtime(true) + 10_000_000_000;
            $response = Http::withToken($key)->acceptJson()
                ->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 3])
                ->get(self::API.$projectId.'/vote-events', ['cursor' => '0', 'limit' => 1]);
            $stream = $response->toPsrResponse()->getBody();

            try {
                if ($response->status() !== 200) {
                    return match ($response->status()) {
                        401, 403 => 'unauthorized',
                        429 => 'rate_limited',
                        default => 'unavailable',
                    };
                }

                $page = $this->readJson($response, $deadline);

                return $this->validProbePage($page, $projectId) ? 'ok' : 'invalid_response';
            } finally {
                $stream->close();
            }
        } catch (\JsonException) {
            return 'invalid_response';
        } catch (Throwable) {
            // Do not log request/response data or exceptions that could contain credentials.
            return 'unavailable';
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
                    || ! is_string($data['expires_at'] ?? null)
                    || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|\+00:00)\z/D', $data['expires_at']) !== 1) {
                    return new VoteInvitation('invalid_response');
                }
                $expiresAt = CarbonImmutable::parse($data['expires_at'])->utc();
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

    private function readJson(Response $response, int|float $deadline): mixed
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
        if ($contentType !== 'application/json' || (int) $response->header('Content-Length') > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            if (hrtime(true) > $deadline || strlen($body) >= self::MAX_RESPONSE_BYTES) {
                return null;
            }
            $chunk = $stream->read(min(4096, self::MAX_RESPONSE_BYTES - strlen($body)));
            if ($chunk === '' && ! $stream->eof()) {
                return null;
            }
            $body .= $chunk;
        }

        return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    }

    private function validProbePage(mixed $page, string $projectId): bool
    {
        if (! is_array($page) || ! is_array($page['events'] ?? null)
            || ! array_is_list($page['events']) || count($page['events']) > 1
            || ! is_string($page['next_cursor'] ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $page['next_cursor']) !== 1
            || ! is_bool($page['has_more'] ?? null)) {
            return false;
        }
        if ($page['events'] === []) {
            return $page['next_cursor'] === '0' && $page['has_more'] === false;
        }

        $event = $page['events'][0];

        return is_array($event)
            && ($event['project_id'] ?? null) === $projectId
            && ($event['cursor'] ?? null) === $page['next_cursor']
            && $page['next_cursor'] !== '0'
            && in_array($event['status'] ?? null, ['accepted', 'pending', 'rejected', 'revoked'], true)
            && is_int($event['status_version'] ?? null) && $event['status_version'] > 0;
    }
}
