<?php

namespace KaevCMS\Modules\Voting\Services;

use KaevCMS\Modules\Voting\Data\VoteEvent;
use KaevCMS\Modules\Voting\Data\VoteEventPage;
use KaevCMS\Modules\Voting\Providers\KTreonProvider;
use stdClass;

final class VoteEventDecoder
{
    public static function validCursor(string $cursor): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $cursor) === 1
            && self::compareCursor($cursor, '9223372036854775807') <= 0;
    }

    /** Compare canonical nonnegative decimal strings without float or integer conversion. */
    public static function compareCursor(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }

    public function decode(mixed $page, string $projectId, string $cursor, int $limit): VoteEventPage
    {
        $invalid = new VoteEventPage('invalid_response');
        if (! $page instanceof stdClass) {
            return $invalid;
        }
        $page = get_object_vars($page);
        if (! self::validCursor($cursor) || $limit < 1 || $limit > 100
            || ! is_array($page['events'] ?? null) || ! array_is_list($page['events'])
            || count($page['events']) > $limit || ! is_string($page['next_cursor'] ?? null)
            || ! self::validCursor($page['next_cursor']) || ! is_bool($page['has_more'] ?? null)
            || ($page['has_more'] && count($page['events']) !== $limit)) {
            return $invalid;
        }

        $last = $cursor;
        $events = [];
        $seen = [];
        $votes = [];
        foreach ($page['events'] as $row) {
            if (! $row instanceof stdClass) {
                return $invalid;
            }
            $row = get_object_vars($row);
            if (($row['project_id'] ?? null) !== $projectId
                || ! is_string($row['cursor'] ?? null) || ! self::validCursor($row['cursor'])
                || self::compareCursor($row['cursor'], $last) <= 0
                || ! is_string($row['event_id'] ?? null) || ! KTreonProvider::validProjectId($row['event_id'])
                || isset($seen[$row['event_id']])
                || ! is_string($row['vote_id'] ?? null) || ! KTreonProvider::validProjectId($row['vote_id'])
                || ! is_string($row['player_ref'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $row['player_ref']) !== 1
                || ! is_string($row['status'] ?? null) || ! in_array($row['status'], ['pending', 'accepted', 'rejected', 'revoked'], true)
                || ! is_int($row['status_version'] ?? null) || $row['status_version'] < 1) {
                return $invalid;
            }
            $voted = $this->utcDate($row['voted_at'] ?? null);
            $changed = $this->utcDate($row['status_changed_at'] ?? null);
            if ($voted === null || $changed === null || strcmp($changed, $voted) < 0) {
                return $invalid;
            }
            $previous = $votes[$row['vote_id']] ?? null;
            if ($previous !== null && ($previous->statusVersion >= $row['status_version']
                || $previous->playerRef !== $row['player_ref'] || $previous->votedAt !== $voted)) {
                return $invalid;
            }
            $last = $row['cursor'];
            $seen[$row['event_id']] = true;
            $event = new VoteEvent($last, $row['event_id'], $row['vote_id'], $projectId, $row['player_ref'], $row['status'], $voted, $changed, $row['status_version']);
            $events[] = $event;
            $votes[$event->voteId] = $event;
        }

        if ($page['next_cursor'] !== $last) {
            return $invalid;
        }

        return new VoteEventPage('ok', $events, $last, $page['has_more']);
    }

    private function utcDate(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,7}))?(?:Z|\+00:00)\z/D', $value, $parts) !== 1
            || (int) $parts[1] < 1000 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }

        // Database DATETIME(6) stores UTC microseconds, not the application time zone.
        return $parts[1].'-'.$parts[2].'-'.$parts[3].' '.$parts[4].':'.$parts[5].':'.$parts[6].'.'.str_pad(substr($parts[7] ?? '', 0, 6), 6, '0');
    }
}
