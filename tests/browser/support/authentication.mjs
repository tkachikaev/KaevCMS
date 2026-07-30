export const assertLoginResponseStatus = (status, label) => {
    if (status === 429) {
        throw new Error(`${label} was blocked by the login rate limiter (HTTP 429). Check the browser test limiter overrides instead of increasing Playwright timeouts.`);
    }

    if (status >= 500) {
        throw new Error(`${label} failed with HTTP ${status}. Check the Laravel log before investigating page locators.`);
    }
};

export const submitLogin = async ({ page, postPath, submit, label }) => {
    const responsePromise = page.waitForResponse((response) => {
        const request = response.request();

        return request.method() === 'POST'
            && new URL(response.url()).pathname === postPath;
    });

    await submit();
    const response = await responsePromise;
    assertLoginResponseStatus(response.status(), label);

    return response;
};
