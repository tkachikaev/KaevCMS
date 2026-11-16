const localSocketExhaustion = 'net::ERR_NO_BUFFER_SPACE';

// Windows briefly runs out of local sockets during the long browser suite; the page itself is fine.
const withLocalNetworkRetry = async (page, navigate) => {
    let lastError = null;

    for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
            return await navigate();
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

export const gotoWithLocalNetworkRetry = (page, url, options = {}) => withLocalNetworkRetry(page, () => page.goto(url, options));

export const reloadWithLocalNetworkRetry = (page, options = {}) => withLocalNetworkRetry(page, () => page.reload(options));
