<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Security\Redactor;

it('redacts secret assignments headers private keys and known token shapes', function (): void {
    $content = <<<'TEXT'
password='super-secret'
Authorization: Bearer abcdefghijklmnopqrstuvwxyz
api_key=sk-abcdefghijklmnopqrstuvwxyz123456
ghp_abcdefghijklmnopqrstuvwxyz123456
-----BEGIN PRIVATE KEY-----
secret-body
-----END PRIVATE KEY-----
TEXT;

    $redacted = (new Redactor())->redact($content);

    expect($redacted)->not->toContain('super-secret');
    expect($redacted)->not->toContain('abcdefghijklmnopqrstuvwxyz');
    expect($redacted)->not->toContain('secret-body');
    expect($redacted)->toContain('[REDACTED]');
});
