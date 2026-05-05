<?php

namespace App\Support\Shop;

class ShopPromo
{
    /**
     * @return array{valid: bool, discount: float, description: ?string, message?: string}
     */
    public static function compute(string $code, float $subtotal): array
    {
        $code = strtoupper(trim($code));
        $promos = config('shop.promo_codes', []);
        if ($code === '' || ! isset($promos[$code])) {
            return ['valid' => false, 'discount' => 0.0, 'description' => null, 'message' => 'Invalid or expired promo code'];
        }

        $rule = $promos[$code];
        $description = $rule['description'] ?? null;
        $discount = 0.0;

        if (($rule['type'] ?? '') === 'fixed') {
            $discount = min((float) ($rule['amount'] ?? 0), max(0.0, $subtotal));
        } elseif (($rule['type'] ?? '') === 'percent') {
            $pct = (float) ($rule['percent'] ?? 0);
            $discount = round($subtotal * ($pct / 100.0), 2);
            if (! empty($rule['max_tzs'])) {
                $discount = min($discount, (float) $rule['max_tzs']);
            }
            $discount = min($discount, max(0.0, $subtotal));
        }

        return [
            'valid' => true,
            'discount' => $discount,
            'description' => $description,
        ];
    }
}
