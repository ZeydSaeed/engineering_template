<?php

use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->post('/_test/rule', function () {
        throw new DomainRuleViolation('The period is closed.', field: 'period', context: ['closed_at' => '2026-01-01']);
    });
});

it('renders a JSON validation-style error for API clients', function () {
    $this->postJson('/_test/rule')
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'The period is closed.',
            'errors' => ['period' => ['The period is closed.']],
            'context' => ['closed_at' => '2026-01-01'],
        ]);
});

it('sends browser and Inertia requests back with the error on the field', function () {
    $this->from('/form')
        ->post('/_test/rule', [], ['X-Inertia' => 'true'])
        ->assertRedirect('/form')
        ->assertSessionHasErrors(['period' => 'The period is closed.']);
});

it('is never reported as a fault', function () {
    Exceptions::fake();

    $this->postJson('/_test/rule');

    Exceptions::assertNotReported(DomainRuleViolation::class);
});
