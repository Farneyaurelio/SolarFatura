<?php

declare(strict_types=1);

namespace SolarFatura;

final class Calculator
{
    /** @param array<string, mixed> $data @return array<string, float> */
    public function calculate(array $data): array
    {
        $consumption = (float) $data['consumption_kwh'];
        $compensated = (float) $data['compensated_kwh'];
        $rate = (float) $data['full_energy_rate'];
        $discount = (float) $data['discount_percent'];
        $availability = (float) $data['availability_amount'];
        $lighting = (float) $data['public_lighting'];
        $adjustment = (float) $data['adjustment_amount'];
        $bonus = (float) $data['bonus_amount'];

        $withoutSolar = $consumption * $rate + $lighting + $adjustment;
        $solarEnergy = $compensated * $rate * (1 - $discount / 100);
        $amountDue = $solarEnergy + $availability + $lighting + $adjustment - $bonus;

        return [
            'without_solar' => round($withoutSolar, 2),
            'solar_energy' => round($solarEnergy, 2),
            'amount_due' => round($amountDue, 2),
            'savings' => round($withoutSolar - $amountDue, 2),
            'savings_percent' => $withoutSolar > 0 ? round((($withoutSolar - $amountDue) / $withoutSolar) * 100, 2) : 0.0,
        ];
    }
}
