<?php

/*
| The deployment templates must agree with each other and with the
| application (ADR-0021): each timeout layer gives up before the layer above
| it, and each upload limit fits in the next one out.
*/

function templateValue(string $file, string $pattern): float
{
    $text = (string) file_get_contents(base_path($file));

    expect(preg_match($pattern, $text, $match))->toBe(1, "{$pattern} not found in {$file}");

    return (float) $match[1];
}

it('nests the timeouts: edge > nginx > php-fpm > php > outbound calls', function () {
    $edge = 100;
    $nginx = templateValue('deploy/nginx/app.conf', '/fastcgi_read_timeout\s+(\d+)s;/');
    $fpm = templateValue('deploy/php/app-pool.conf', '/^request_terminate_timeout\s*=\s*(\d+)s/m');
    $php = templateValue('deploy/php/app-php.ini', '/^max_execution_time\s*=\s*(\d+)/m');

    expect($edge)->toBeGreaterThan($nginx)
        ->and($nginx)->toBeGreaterThan($fpm)
        ->and($fpm)->toBeGreaterThan($php);

    foreach ((array) config('platform.http.services') as $name => $service) {
        $retries = (int) ($service['retries'] ?? 2);
        $worst = ((int) ($service['timeout'] ?? 10)) * ($retries + 1);

        expect($worst)->toBeLessThan($php, "outbound service {$name}: timeout x attempts");
    }
});

it('nests the upload limits: nginx > post_max_size > upload_max_filesize >= the application limit', function () {
    $nginx = templateValue('deploy/nginx/app.conf', '/client_max_body_size\s+(\d+)m;/') * 1024;
    $post = templateValue('deploy/php/app-php.ini', '/^post_max_size\s*=\s*(\d+)M/m') * 1024;
    $upload = templateValue('deploy/php/app-php.ini', '/^upload_max_filesize\s*=\s*(\d+)M/m') * 1024;
    $app = (int) config('platform.files.max_kb');

    expect($nginx)->toBeGreaterThan($post)
        ->and($post)->toBeGreaterThan($upload)
        ->and($upload)->toBeGreaterThanOrEqual($app);
});

it('runs only the front controller and keeps probes local', function () {
    $nginx = (string) file_get_contents(base_path('deploy/nginx/app.conf'));

    expect($nginx)->toContain('location ~ \.php$ {'."\n".'        return 404;')
        ->toContain('location ~ ^/(up|health/ready|metrics)$ {'."\n".'        allow 127.0.0.1;')
        ->toContain('ssl_reject_handshake on;')
        ->toContain('server_tokens off;');
});

it('gives the files Nginx serves the headers PHP gives pages', function () {
    $nginx = (string) file_get_contents(base_path('deploy/nginx/app.conf'));
    $hsts = 'max-age='.config('platform.security.hsts_max_age').'; includeSubDomains';

    preg_match_all('#\n    location (/build/ |~ \^/\(favicon[^{]+)\{(.*?)\n    \}#s', $nginx, $blocks);

    expect($blocks[2])->toHaveCount(2);

    foreach ($blocks[2] as $block) {
        expect($block)->toContain('X-Content-Type-Options "nosniff" always')
            ->toContain('Cross-Origin-Resource-Policy "same-origin" always')
            ->toContain('Permissions-Policy')
            ->toContain("Strict-Transport-Security \"{$hsts}\" always");
    }

    // Every file at the top of public/ besides index.php is covered.
    foreach (glob(public_path('*.*')) ?: [] as $file) {
        if (basename($file) !== 'index.php') {
            expect(str_contains($nginx, str_replace('.', '\.', basename($file))))->toBeTrue(basename($file).' is not in the Nginx static files location');
        }
    }
});

it('uses only directives the Nginx of the supported Ubuntu LTS knows', function () {
    $nginx = (string) file_get_contents(base_path('deploy/nginx/app.conf'));

    // `http2 on;` needs Nginx 1.25.1; Ubuntu 24.04 ships 1.24 (Phase 8).
    expect($nginx)->not->toMatch('/^\s*http2\s+on\s*;/m')
        ->and($nginx)->toContain('listen 443 ssl http2;');
});
