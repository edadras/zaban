<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use Illuminate\Database\Seeder;

/**
 * The commercial catalogue: one free tier plus the four paid intervals.
 *
 * Entitlements are the contract the backend enforces (EntitlementService), so
 * every plan states every feature explicitly - an absent row means "no access",
 * and leaving one out by accident would be indistinguishable from denying it.
 *
 * Amounts are minor units: kuruş for TRY, cents for USD.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $definition) {
            $plan = Plan::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'interval' => $definition['interval'],
                    'interval_count' => $definition['interval_count'],
                    'trial_days' => $definition['trial_days'],
                    'position' => $definition['position'],
                    'is_active' => true,
                    'is_public' => true,
                ],
            );

            foreach ($definition['prices'] as $currency => $amount) {
                PlanPrice::updateOrCreate(
                    ['plan_id' => $plan->id, 'currency' => $currency, 'country_code' => null, 'gateway' => null],
                    ['amount' => $amount, 'is_active' => true],
                );
            }

            foreach ($definition['entitlements'] as $feature => $rule) {
                PlanEntitlement::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature' => $feature],
                    [
                        'is_enabled' => $rule[0],
                        'limit_value' => $rule[1],
                        'limit_period' => $rule[2],
                    ],
                );
            }
        }
    }

    /**
     * Entitlement tuples are [enabled, limit, period]; a null limit is
     * unlimited and needs no period.
     */
    private function catalogue(): array
    {
        return [
            [
                'code' => 'free',
                'name' => 'Free',
                'description' => 'Daily practice with a capped tutor allowance. No card required.',
                'interval' => 'monthly',
                'interval_count' => 1,
                'trial_days' => 0,
                'position' => 0,
                'prices' => ['TRY' => 0, 'USD' => 0],
                'entitlements' => [
                    'ai_messages' => [true, 15, 'day'],
                    'speech_minutes' => [true, 10, 'month'],
                    'generated_media' => [true, 3, 'month'],
                    'exam_prep' => [false, 0, 'month'],
                    'premium_tutor' => [false, 0, 'month'],
                ],
            ],
            [
                'code' => 'monthly',
                'name' => 'Premium Monthly',
                'description' => 'Full course access, unlimited lessons and a generous daily tutor allowance.',
                'interval' => 'monthly',
                'interval_count' => 1,
                'trial_days' => 7,
                'position' => 1,
                'prices' => ['TRY' => 24900, 'USD' => 999],
                'entitlements' => [
                    'ai_messages' => [true, 200, 'day'],
                    'speech_minutes' => [true, 240, 'month'],
                    'generated_media' => [true, 50, 'month'],
                    'exam_prep' => [true, 20, 'month'],
                    'premium_tutor' => [false, 0, 'month'],
                ],
            ],
            [
                'code' => 'quarterly',
                'name' => 'Premium 3 Months',
                'description' => 'Three months up front, a wider daily allowance and exam preparation.',
                'interval' => 'quarterly',
                'interval_count' => 1,
                'trial_days' => 7,
                'position' => 2,
                'prices' => ['TRY' => 64900, 'USD' => 2699],
                'entitlements' => [
                    'ai_messages' => [true, 300, 'day'],
                    'speech_minutes' => [true, 360, 'month'],
                    'generated_media' => [true, 80, 'month'],
                    'exam_prep' => [true, 40, 'month'],
                    'premium_tutor' => [true, 2, 'month'],
                ],
            ],
            [
                'code' => 'annual',
                'name' => 'Premium Annual',
                'description' => 'A year of everything, including unlimited exam preparation.',
                'interval' => 'annual',
                'interval_count' => 1,
                'trial_days' => 14,
                'position' => 3,
                'prices' => ['TRY' => 199900, 'USD' => 7999],
                'entitlements' => [
                    'ai_messages' => [true, 500, 'day'],
                    'speech_minutes' => [true, 600, 'month'],
                    'generated_media' => [true, 150, 'month'],
                    'exam_prep' => [true, null, null],
                    'premium_tutor' => [true, 4, 'month'],
                ],
            ],
            [
                'code' => 'lifetime',
                'name' => 'Lifetime',
                'description' => 'One payment, permanent access. Fair-use monthly ceilings on the metered features.',
                'interval' => 'lifetime',
                'interval_count' => 1,
                'trial_days' => 0,
                'position' => 4,
                'prices' => ['TRY' => 499900, 'USD' => 19999],
                'entitlements' => [
                    'ai_messages' => [true, null, null],
                    'speech_minutes' => [true, 1200, 'month'],
                    'generated_media' => [true, 300, 'month'],
                    'exam_prep' => [true, null, null],
                    'premium_tutor' => [true, 8, 'month'],
                ],
            ],
        ];
    }
}
