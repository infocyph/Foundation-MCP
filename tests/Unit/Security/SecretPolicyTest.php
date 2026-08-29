<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Security\SecretPolicy;

it('hard denies secret-bearing files while allowing env examples', function (): void {
    $policy = new SecretPolicy();

    expect($policy->denied('.env'))->toBeTrue();
    expect($policy->denied('.env.production'))->toBeTrue();
    expect($policy->denied('.env.example'))->toBeFalse();
    expect($policy->denied('server.pem'))->toBeTrue();
    expect($policy->denied('id_ed25519'))->toBeTrue();
    expect($policy->denied('credentials.json'))->toBeTrue();
    expect($policy->denied('config/app.php'))->toBeFalse();
});
