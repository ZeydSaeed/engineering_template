// Load tests run only against this machine. Any other host is refused, so a
// typo or a copied command can never load a real server.
export function baseUrl() {
    const url = __ENV.BASE_URL || 'http://engineering-template.test';
    const allowed =
        /^http:\/\/([a-z0-9-]+\.test|localhost|127\.0\.0\.1)(:\d+)?$/;

    if (!allowed.test(url)) {
        throw new Error(
            `Refusing BASE_URL ${url}: load tests run only against local hosts (*.test, localhost, 127.0.0.1).`,
        );
    }

    return url;
}
