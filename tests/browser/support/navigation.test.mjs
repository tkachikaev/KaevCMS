import assert from 'node:assert/strict';
import test from 'node:test';

import { gotoWithLocalNetworkRetry } from './navigation.mjs';

test('retries only transient Windows local socket exhaustion', async () => {
    let attempts = 0;
    const waits = [];
    const expectedResponse = { status: 200 };
    const page = {
        goto: async () => {
            attempts += 1;
            if (attempts < 3) {
                throw new Error('page.goto: net::ERR_NO_BUFFER_SPACE');
            }

            return expectedResponse;
        },
        waitForTimeout: async (milliseconds) => {
            waits.push(milliseconds);
        },
    };

    const response = await gotoWithLocalNetworkRetry(page, '/admin/settings');

    assert.equal(response, expectedResponse);
    assert.equal(attempts, 3);
    assert.deepEqual(waits, [750, 1500]);
});

test('does not retry application or unrelated browser failures', async () => {
    let attempts = 0;
    const page = {
        goto: async () => {
            attempts += 1;
            throw new Error('page.goto: net::ERR_HTTP_RESPONSE_CODE_FAILURE');
        },
        waitForTimeout: async () => {
            throw new Error('waitForTimeout must not be called for unrelated failures');
        },
    };

    await assert.rejects(
        gotoWithLocalNetworkRetry(page, '/admin/settings'),
        /ERR_HTTP_RESPONSE_CODE_FAILURE/,
    );
    assert.equal(attempts, 1);
});
