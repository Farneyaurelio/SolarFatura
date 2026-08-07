<?php

declare(strict_types=1);

namespace SolarFatura;

final class CemigParser
{
    /** @return array<string, string|float|int|null> */
    public function parse(string $text): array
    {
        $clean = preg_replace('/\s+/', ' ', $text) ?? $text;
        $reference = $this->matchAll($clean, '/\b([A-Z]{3}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+[\d,]+/i');
        $result = [
            'customer_name' => $this->customerName($text),
            'utility_address' => $this->utilityAddress($text),
            'reference_month' => $reference[1] ?? null,
            'due_date' => $reference[2] ?? null,
            'installation_number' => $this->match($clean, '/UNIDADE CONSUMIDORA.*?(\d{1,3}(?:\.\d{3}){2}\.\d{3}-\d{2})/is'),
            'connection_type' => $this->match($clean, '/(Mono\S*sico|Bi\S*sico|Tri\S*sico)\s+\d{2}\/\d{2}/i'),
            'consumption_kwh' => $this->number($this->match($clean, '/Energia kWh\s+\S+\s+[\d.]+\s+[\d.]+\s+\d+\s+(\d+)/i')),
            'compensated_kwh' => $this->number($this->match($clean, '/Energia compensada GD I kWh\s+(\d+)/i')),
            'public_lighting' => $this->number($this->match($clean, '/Contrib Ilum Publica Municipal\s+([\d,.]+)/i')),
            'utility_total' => $this->number($this->match($clean, '/TOTAL\s+([\d,.]+)\s+[\d,.]+\s+[\d,.]+/i')),
            'generation_balance_kwh' => $this->number($this->match($clean, '/SALDO ATUAL DE GERA.{0,5}:\s*([\d,.]+) kWh/i')),
            'consumption_history' => $this->consumptionHistory($clean),
            'availability_amount' => null,
            'full_energy_rate' => null,
            'availability_label' => null,
        ];

        $energy = $this->matchAll($clean, '/Energia El.trica kWh\s+\d+\s+([\d,]+)\s+([\d,]+)/i');
        $availability = $this->matchAll($clean, '/Custo de Disponibilidade\s+([\d,]+)\s+([\d,]+)/i');
        if ($energy !== null) {
            $result['full_energy_rate'] = $this->number($energy[1]);
            $result['availability_amount'] = $this->number($energy[2]);
            $result['availability_label'] = 'Energia Elétrica';
        } elseif ($availability !== null) {
            $result['full_energy_rate'] = $this->number($availability[1]);
            $result['availability_amount'] = $this->number($availability[2]);
            $result['availability_label'] = 'Custo de Disponibilidade';
        }

        $result['warnings'] = $this->warnings($result);
        return $result;
    }

    private function match(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $matches) === 1 ? trim($matches[1]) : null;
    }

    private function customerName(string $text): ?string
    {
        if (preg_match('/^([A-Z][A-Z ]{4,})\s*\R[^\r\n]+\R[^\r\n]+\R[^\r\n]+\RCPF\s/m', $text, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }

    private function utilityAddress(string $text): ?string
    {
        if (preg_match('/^[A-Z][A-Z ]{4,}\s*\R([^\r\n]+)\R([^\r\n]+)\R([^\r\n]+)\RCPF\s/m', $text, $matches) === 1) {
            return trim("{$matches[1]}, {$matches[2]} - {$matches[3]}");
        }
        return null;
    }

    /** @return array<int, string>|null */
    private function matchAll(string $text, string $pattern): ?array
    {
        return preg_match($pattern, $text, $matches) === 1 ? $matches : null;
    }

    private function number(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) str_replace(',', '.', str_replace('.', '', $value));
    }

    /** @return array<int, array{month: string, kwh: int}> */
    private function consumptionHistory(string $text): array
    {
        preg_match_all('/\b([A-Z]{3}\/\d{2})\s+(\d+)\s+[\d,]+\s+\d+\b/', $text, $matches, PREG_SET_ORDER);
        $history = [];
        foreach ($matches as $match) {
            $history[] = ['month' => $match[1], 'kwh' => (int) $match[2]];
        }
        return array_slice($history, 0, 12);
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function warnings(array $data): array
    {
        $warnings = [];
        foreach (['reference_month', 'due_date', 'installation_number', 'consumption_kwh', 'compensated_kwh', 'public_lighting', 'availability_amount', 'full_energy_rate'] as $field) {
            if ($data[$field] === null) {
                $warnings[] = "Não foi possível encontrar: {$field}. Confira antes de gerar.";
            }
        }
        if (is_float($data['consumption_kwh']) && is_float($data['compensated_kwh']) && $data['compensated_kwh'] > $data['consumption_kwh']) {
            $warnings[] = 'kWh compensados maior que o consumo total; confira os valores.';
        }
        return $warnings;
    }
}
