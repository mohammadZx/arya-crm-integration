<?php

namespace Arya\Portal;

/** Builds independent category payloads while preserving the WooCommerce charged total. */
class PurchasePayload {
    public static function groups($order, array $base) {
        $groups = [];
        foreach ($order->get_items() as $id => $item) {
            $category = (string) get_post_meta($item->get_product_id(), 'portal_category', true);
            $key = $category !== '' ? $category : '_unmanaged';
            if (!isset($groups[$key])) $groups[$key] = ['items' => [], 'subtotal' => 0.0, 'tax' => 0.0, 'physical' => 0.0];
            $source = null;
            foreach ($base['items'] ?? [] as $candidate) {
                if (($candidate['purchase_key'] ?? '') === 'woo-item-'.$id) { $source = $candidate; break; }
            }
            if ($source) $groups[$key]['items'][] = $source;
            $groups[$key]['subtotal'] += (float) $item->get_total();
            $groups[$key]['tax'] += (float) $item->get_total_tax();
            $product = $item->get_product();
            if ($product && !$product->is_virtual()) $groups[$key]['physical'] += (float) $item->get_total();
        }
        if (!$groups) return [];
        $weights = array_map(function ($group) { return $group['subtotal']; }, $groups);
        $shippingWeights = array_map(function ($group) { return $group['physical']; }, $groups);
        if (array_sum($shippingWeights) <= 0) $shippingWeights = $weights;
        $fees = array_fill_keys(array_keys($groups), []);
        $discounts = array_fill_keys(array_keys($groups), 0.0);
        $shippingTotals = array_fill_keys(array_keys($groups), 0.0);
        $taxTotals = array_map(function ($group) { return $group['tax']; }, $groups);
        foreach ($order->get_items('fee') as $id => $fee) {
            $shares = self::allocate((float) $fee->get_total(), $weights);
            $tax = self::allocate((float) $fee->get_total_tax(), $weights);
            foreach ($groups as $key => $group) {
                $taxTotals[$key] += $tax[$key];
                if ($shares[$key] < 0) $discounts[$key] += abs($shares[$key]);
                else if ($shares[$key] > 0) $fees[$key][] = self::fee('woo-fee-'.$id, $fee->get_name(), 'custom', $shares[$key]);
            }
        }
        $shippingNames = [];
        $shippingMethods = [];
        foreach ($order->get_items('shipping') as $id => $shipping) {
            $shippingNames[] = $shipping->get_name();
            $shippingMethods[] = $shipping->get_method_id().':'.$shipping->get_instance_id();
            $shares = self::allocate((float) $shipping->get_total(), $shippingWeights);
            $tax = self::allocate((float) $shipping->get_total_tax(), $shippingWeights);
            foreach ($groups as $key => $group) {
                $shippingTotals[$key] += $shares[$key];
                $taxTotals[$key] += $tax[$key];
                if ($shares[$key] > 0 || count($groups) === 1) $fees[$key][] = self::fee('woo-shipping-'.$id, $shipping->get_name(), 'shipping', $shares[$key]);
            }
        }
        $result = [];
        foreach ($groups as $key => $group) {
            if ($key === '_unmanaged' || !$group['items']) continue;
            if ($taxTotals[$key] > 0) $fees[$key][] = self::fee('woo-tax', 'مالیات سفارش', 'tax', $taxTotals[$key]);
            $payload = $base;
            $payload['items'] = $group['items'];
            $last = end($group['items']);
            $payload['course_id'] = $last['course_id'];
            $payload['course_code'] = $last['course_code'];
            $payload['category_id'] = $key;
            $payload['quantity'] = array_sum(array_column($group['items'], 'quantity'));
            $total = $group['subtotal'] - $discounts[$key] + array_sum(array_column($fees[$key], 'value'));
            $payload['purchase_version'] = 1;
            $payload['purchase_total'] = round($total, wc_get_price_decimals());
            $payload['purchase_pay_total'] = $payload['purchase_total'];
            $payload['purchase_discount'] = $discounts[$key];
            $payload['fees'] = $fees[$key];
            $payload['shipping_price'] = $shippingTotals[$key];
            $payload['shipping'] = [
                'name' => implode('، ', $shippingNames), 'external_method' => implode(',', $shippingMethods),
                'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
                'state' => $order->get_shipping_state() ?: $order->get_billing_state(),
                'address' => trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()).' '.($order->get_shipping_address_2() ?: $order->get_billing_address_2())),
                'postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                'recipient' => trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name()) ?: $base['name'],
                'phone' => $order->get_billing_phone(),
            ];
            // Legacy course/service paths keep their existing fields and installment contract.
            if (count($groups) > 1) {
                $payload['price'] = $total - $shippingTotals[$key];
                $payload['pay_price'] = $payload['price'];
                $payload['discount_total'] = -$discounts[$key];
            }
            $result[] = $payload;
        }
        return $result;
    }

    private static function fee($key, $name, $kind, $value) {
        return ['key' => $key, 'name' => $name, 'kind' => $kind, 'calculation' => 'fixed', 'value' => $value, 'item_keys' => []];
    }

    private static function allocate($amount, array $weights) {
        $precision = wc_get_price_decimals();
        $total = array_sum($weights);
        $remaining = round($amount, $precision);
        $result = [];
        $last = array_key_last($weights);
        foreach ($weights as $key => $weight) {
            $share = $key === $last ? $remaining : round($amount * ($total > 0 ? $weight / $total : 1 / count($weights)), $precision);
            $result[$key] = $share;
            $remaining = round($remaining - $share, $precision);
        }
        return $result;
    }
}
