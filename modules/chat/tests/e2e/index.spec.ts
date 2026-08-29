import { expect, test } from '@e2e/fixtures';

test.describe('Chat index page', () => {
    test('responds successfully when navigating to root', async ({ page }) => {
        const response = await page.goto('/');

        expect(response, 'Expected a navigation response').toBeTruthy();
        expect(
            response?.ok(),
            'Expected a successful status code',
        ).toBeTruthy();
    });

    test('provides a concise copyable prompt for an external agent', async ({
        page,
        credentials,
        loginAs,
    }) => {
        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await loginAs(credentials.user);
        await page.goto('/chat?conversation_id=not-in-prompt');

        await page.getByTestId('webmcp-trigger').click();

        const prompt = page.getByTestId('webmcp-agent-prompt');
        const expectedChatUrl = new URL('/chat', page.url()).href;
        await expect(prompt).toBeVisible();
        await expect(prompt).toContainText(expectedChatUrl);
        await expect(prompt).not.toContainText('not-in-prompt');
        await expect(prompt).toContainText("page's WebMCP tools");

        const copyButton = page.getByTestId('webmcp-copy-prompt');
        await copyButton.click();
        await expect(copyButton).toContainText('Copied');

        const copiedPrompt = await page.evaluate(() =>
            navigator.clipboard.readText(),
        );
        expect(copiedPrompt).toContain(expectedChatUrl);
        expect(copiedPrompt).not.toContain('not-in-prompt');
        expect(copiedPrompt).toContain("page's WebMCP tools");
    });
});
