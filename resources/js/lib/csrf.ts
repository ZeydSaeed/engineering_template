/**
 * Laravel's XSRF-TOKEN cookie (readable by design), for the X-XSRF-TOKEN
 * header of requests made with fetch rather than an Inertia visit.
 */
export function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
