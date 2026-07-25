const localSocketExhaustion = 'net::ERR_NO_BUFFER_SPACE';

export const gotoWithLocalNetworkRetry = async (page, url, options = {}) => {
    let lastError = null;

    for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
            return await page.goto(url, options);
        } catch (error) {
            lastError = error;
            const message = error instanceof Error ? error.message : String(error);
            const shouldRetry = message.includes(localSocketExhaustion) && attempt < 3;

            if (!shouldRetry) {
                throw error;
            }

            await page.waitForTimeout(attempt * 750);
        }
    }

    throw lastError;
};
