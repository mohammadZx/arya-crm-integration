<?php

namespace Arya\Portal;

/**
 * VariationHelper Class
 * 
 * Helper class for managing product variations
 */
class VariationHelper {
    
    /**
     * Get course attribute name
     */
    private function get_course_attr_name() {
        return defined('COURSE_ATTR_NAME') ? COURSE_ATTR_NAME : 'انتخاب زمان برگذاری دوره';
    }
    
    /**
     * Get jdate function (fallback if not available)
     */
    private function jdate($format, $timestamp = '') {
        if (function_exists('jdate')) {
            return jdate($format, $timestamp);
        }
        // Fallback to regular date if jdate is not available
        return date($format, $timestamp ? $timestamp : time());
    }
    
    /**
     * Insert product variable
     */
    public function insert_product_variable($post, $params) {
        $term_name = $this->jdate('j F', strtotime($params['begin_date'])) . ' - ' . $params['day'] . ' - ' . $params['start_time'] . ' الی ' . $params['end_time'];
        $unit = null;
        
        switch ($params['course_duration_unit']) {
            case 'days':
                $unit = 'روز';
                break;
            case 'weeks':
                $unit = 'هفته';
                break;
            case 'months':
                $unit = 'ماه';
                break;
        }

        $settings = Settings::instance();
        $taxonomy = $this->get_course_attr_name();

        // Pre insert private
        $this->make_variation([
            'termname' => 'خصوصی (ساعتی)',
            'taxonomy' => $taxonomy,
            'typeTaxonomy' => 'نوع دوره',
            'post' => $post,
            'post_ID' => $post->ID,
            'begin_date' => null,
            'day' => null,
            'time' => null,
            'time_to' => null,
            'course_duration' => null,
            'course_duration_unit' => null,
            'start_time' => null,
            'end_time' => null,
            'course_code' => $settings->get_private_class_code(),
            'course_type' => null,
            'class_course_comment' => null,
            'has_presence' => 'yes',
            'has_online' => 'yes',
            'price' => $params['course']['private_price'],
            'sale_price' => false,
            'sale_price_date_from' => false,
            'sale_price_date_to' => false
        ]);

        $data = [
            'taxonomy' => $taxonomy,
            'typeTaxonomy' => 'نوع دوره',
            'post' => $post,
            'post_ID' => $post->ID,
            'begin_date' => $params['begin_date'],
            'day' => $params['day'],
            'time' => $params['time'],
            'time_to' => $params['time_to'],
            'course_duration' => $params['course_duration'],
            'course_duration_unit' => $unit,
            'start_time' => $params['start_time'],
            'end_time' => $params['end_time'],
            'course_code' => $params['course_code'],
            'course_type' => $params['course_type'] ?? null,
            'class_course_comment' => $params['comment'] ?? null,
            'has_presence' => isset($params['has_presence']) && $params['has_presence'] ? 'yes' : false,
            'has_online' => isset($params['has_online']) && $params['has_online'] ? 'yes' : false,
            'price' => $params['price'],
            'sale_price' => $params['sale_price'] ?? false,
            'sale_price_date_from' => $params['sale_price_date_from'] ?? false,
            'sale_price_date_to' => $params['sale_price_date_to'] ?? false
        ];

        if ($params['has_online'] && $params['has_presence'] && !isset($params['online_price'])) {
            $term_name .= ' (حضوری - آنلاین)';
            $data['termname'] = $term_name;
            return $this->make_variation($data);
        } elseif ($params['has_online'] && $params['has_presence'] && isset($params['online_price'])) {
            $term1 = $term_name . ' (حضوری)';
            $data['termname'] = $term1;
            $data['has_online'] = 'no';
            $this->make_variation($data);

            $term2 = $term_name . ' (آنلاین)';
            $data['termname'] = $term2;
            $data['price'] = $params['online_price'];
            $data['sale_price'] = $params['online_sale_price'] ?? false;
            $data['sale_price_date_from'] = $params['online_sale_price_date_from'] ?? false;
            $data['sale_price_date_to'] = $params['online_sale_price_date_to'] ?? false;
            $data['has_presence'] = 'no';
            $data['has_online'] = 'yes';
            return $this->make_variation($data);
        } elseif (!$params['has_online'] && $params['has_presence']) {
            $term_name .= ' (فقط حضوری)';
            $data['termname'] = $term_name;
            $data['has_online'] = 'no';
            return $this->make_variation($data);
        } elseif ($params['has_online'] && !$params['has_presence'] && !isset($params['online_price'])) {
            $term_name .= ' (فقط آنلاین)';
            $data['termname'] = $term_name;
            $data['has_presence'] = 'no';
            $data['has_online'] = 'yes';
            return $this->make_variation($data);
        } elseif ($params['has_online'] && !$params['has_presence'] && isset($params['online_price'])) {
            $term_name .= ' (فقط آنلاین)';
            $data['termname'] = $term_name;
            $data['price'] = $params['online_price'];
            $data['sale_price'] = $params['online_sale_price'] ?? false;
            $data['sale_price_date_from'] = $params['online_sale_price_date_from'] ?? false;
            $data['sale_price_date_to'] = $params['online_sale_price_date_to'] ?? false;
            $data['has_presence'] = 'no';
            $data['has_online'] = 'yes';
            return $this->make_variation($data);
        }

        return null;
    }
    
    /**
     * Make variation
     */
    public function make_variation($data = []) {
        $product = wc_get_product($data['post_ID']);
        
        if (!$product || !$product->is_type('variable')) {
            return null;
        }
        
        $typeTaxonomy = $data['typeTaxonomy'] ?? null;
        $taxonomy = $data['taxonomy'];
        $term_name = $data['termname'];
        
        $productAttributes = get_post_meta($product->get_id(), '_product_attributes', true);
        
        if (!is_array($productAttributes)) {
            $productAttributes = [];
        }
        
        if (!isset($productAttributes[sanitize_title($taxonomy)])) {
            $productAttributes[sanitize_title($taxonomy)] = [
                'name' => $taxonomy,
                'value' => null,
                'position' => 2,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 0
            ];
        }
        
        $values = $productAttributes[sanitize_title($taxonomy)]['value'];
        $values = $values ? explode('|', $values) : [];
        
        if (!in_array($term_name, $values)) {
            $values[] = $term_name;
        }
        
        $productAttributes[sanitize_title($taxonomy)]['value'] = implode('|', $values);

        if (!empty($data['course_type']) && $typeTaxonomy) {
            $productAttributes[sanitize_title($typeTaxonomy)] = [
                'name' => $typeTaxonomy,
                'value' => 'مقدماتی|پیشرفته|جامع (مقدماتی و پیشرفته)',
                'position' => 1,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 0
            ];
        }

        update_post_meta($product->get_id(), '_product_attributes', $productAttributes);

        $variation_post = [
            'post_title' => $product->get_name(),
            'post_name' => 'product-' . $product->get_id() . '-variation',
            'post_status' => 'publish',
            'post_parent' => $product->get_id(),
            'post_type' => 'product_variation',
            'guid' => $product->get_permalink()
        ];

        // Creating the product variation
        $variation_id = wp_insert_post($variation_post);
        
        update_post_meta($variation_id, 'attribute_' . sanitize_title($taxonomy), $term_name);
        
        if (!empty($data['course_type']) && $typeTaxonomy) {
            update_post_meta($variation_id, 'attribute_' . sanitize_title($typeTaxonomy), $data['course_type']);
        }

        update_post_meta($variation_id, 'class_course_date_start', $data['begin_date'] ?? '');
        update_post_meta($variation_id, 'days_in_week', $data['day'] ?? '');
        update_post_meta($variation_id, 'course_time', $data['time'] ?? '');
        update_post_meta($variation_id, 'course_time_to', $data['time_to'] ?? '');
        
        if (isset($data['time']) && isset($data['time_to'])) {
            update_post_meta($variation_id, 'course_time_compact', $data['time'] . ',' . $data['time_to']);
        }

        update_post_meta($variation_id, 'course_duration', $data['course_duration'] ?? '');
        update_post_meta($variation_id, 'course_duration_unit', $data['course_duration_unit'] ?? '');
        update_post_meta($variation_id, 'start_time', $data['start_time'] ?? '');
        update_post_meta($variation_id, 'end_time', $data['end_time'] ?? '');
        update_post_meta($variation_id, 'course_code', $data['course_code'] ?? '');
        update_post_meta($variation_id, 'has_online', $data['has_online'] ?? 'no');
        update_post_meta($variation_id, 'has_presence', $data['has_presence'] ?? 'no');
        update_post_meta($variation_id, 'class_course_comment', $data['class_course_comment'] ?? '');

        // Get an instance of the WC_Product_Variation object
        $variation = new \WC_Product_Variation($variation_id);
        $variation->set_virtual(true);

        // Prices
        if (empty($data['sale_price'])) {
            $variation->set_price(intval($data['price']));
        } else {
            $variation->set_price(intval($data['price']));
            $variation->set_sale_price(intval($data['sale_price']));
        }
        
        $variation->set_regular_price(intval($data['price']));

        if (!empty($data['sale_price_date_from']) && !empty($data['sale_price_date_to'])) {
            $variation->set_date_on_sale_from($data['sale_price_date_from']);
            $variation->set_date_on_sale_to($data['sale_price_date_to']);
        }

        $variation->set_manage_stock(false);
        $variation->set_weight('');
        $variation->save();

        return [
            'variation' => $variation,
            'variation_id' => $variation_id,
            'taxonomy' => $taxonomy,
            'term' => $term_name
        ];
    }
    
    /**
     * Reorder product variable
     */
    public function reorder_product_variable($variation) {
        $postProduct = get_post($variation);
        $taxonomy = $this->get_course_attr_name();
        $postKeySort = [];
        $sortedAttribute = [];
        
        $posts = get_posts([
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'post_parent' => $postProduct->post_parent,
        ]);

        foreach ($posts as $post) {
            $dateStart = get_post_meta($post->ID, 'class_course_date_start', true);
            if ($dateStart) {
                $postKeySort[] = ['date' => $dateStart, 'pid' => $post->ID];
            }
        }

        usort($postKeySort, function ($dt1, $dt2) {
            return strcmp($dt1['date'], $dt2['date']);
        });

        $menuOrder = 0;
        foreach ($postKeySort as $dateKey => $pid) {
            wp_update_post([
                'ID' => $pid['pid'],
                'menu_order' => $menuOrder
            ]);

            $dateStart = get_post_meta($pid['pid'], 'attribute_' . sanitize_title($taxonomy), true);
            if ($dateStart) {
                $sortedAttribute[] = $dateStart;
            }
            $menuOrder += 1;
        }

        // Sort the product attribute
        $productAttributes = get_post_meta($postProduct->post_parent, '_product_attributes', true);
        $values = $productAttributes[sanitize_title($taxonomy)]['value'] ?? '';
        $values = array_map('trim', explode('|', $values));

        $taxonomy_name = $this->get_course_attr_name();
        
        if (in_array($taxonomy_name, $values)) {
            if (($key = array_search($taxonomy_name, $sortedAttribute)) !== false) {
                unset($sortedAttribute[$key]);
            }
            array_unshift($sortedAttribute, $taxonomy_name);
        }

        if (in_array('عمومی', $values)) {
            if (($key = array_search('عمومی', $sortedAttribute)) !== false) {
                unset($sortedAttribute[$key]);
            }
            $sortedAttribute[] = 'عمومی';
        }
        
        if (in_array('خصوصی', $values)) {
            if (($key = array_search('خصوصی', $sortedAttribute)) !== false) {
                unset($sortedAttribute[$key]);
            }
            $sortedAttribute[] = 'خصوصی';
        }
        
        if (in_array('خصوصی (ساعتی)', $values)) {
            if (($key = array_search('خصوصی (ساعتی)', $sortedAttribute)) !== false) {
                unset($sortedAttribute[$key]);
            }
            $sortedAttribute[] = 'خصوصی (ساعتی)';
        }

        $productAttributes[sanitize_title($taxonomy)]['value'] = implode('|', $sortedAttribute);
        update_post_meta($postProduct->post_parent, '_product_attributes', $productAttributes);

        return ['status' => true, 'message' => 'ok', 'data' => [$values, $productAttributes, $posts]];
    }
}

