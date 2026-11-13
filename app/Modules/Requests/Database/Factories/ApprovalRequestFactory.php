<?php

namespace App\Modules\Requests\Database\Factories;

use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requests in each state, consistent with the table's CHECK constraints.
 *
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Staff sign in with a second factor (RequireSecondFactor).
            'requester_id' => User::factory()->withTwoFactor(),
            'title' => fake()->sentence(4),
            'details' => fake()->paragraph(),
            'amount' => fake()->optional()->randomFloat(2, 10, 5000),
            'status' => RequestStatus::Draft,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => RequestStatus::Submitted,
            'submitted_at' => now()->subHour(),
        ]);
    }

    public function decided(RequestStatus $outcome, ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'status' => $outcome,
            'submitted_at' => now()->subHours(2),
            'decided_by' => $by ?? User::factory()->withTwoFactor(),
            'decided_at' => now()->subHour(),
            'decision_note' => $outcome === RequestStatus::Rejected ? 'Not within this year\'s budget.' : null,
        ]);
    }
}
