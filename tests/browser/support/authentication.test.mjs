import assert from 'node:assert/strict';
import test from 'node:test';

import { assertLoginResponseStatus } from './authentication.mjs';

test('reports login rate limiting explicitly', () => {
    assert.throws(
        () => assertLoginResponseStatus(429, 'Administrator login'),
        /rate limiter \(HTTP 429\)/,
    );
});

test('reports server-side login failures explicitly', () => {
    assert.throws(
        () => assertLoginResponseStatus(500, 'Player login'),
        /failed with HTTP 500/,
    );
});

test('allows redirects and successful login responses', () => {
    assert.doesNotThrow(() => assertLoginResponseStatus(302, 'Administrator login'));
    assert.doesNotThrow(() => assertLoginResponseStatus(200, 'Player login'));
});
