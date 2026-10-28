<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    public const KINDS = [
        'simple_discount', 'qty_threshold_discount', 'amount_threshold_discount', 'tiered_discount',
        'invoice_bottom_discount', 'qty_gift', 'amount_gift', 'max_discount_cap', 'target_price',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $table = 'campaigns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tiers' => 'array', 'product_filter' => 'array', 'excluded_products' => 'array',
            'customer_filter' => 'array', 'customer_excludes' => 'array',
            'begin_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime',
            'is_active' => 'boolean', 'discount_rate' => 'float', 'threshold_value' => 'float',
            'gift_qty' => 'float', 'gift_divisor' => 'float', 'vat_multiplier' => 'float', 'estimated_budget' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            'simple_discount' => __('Sadə endirim'),
            'qty_threshold_discount' => __('Miqdar həddi ilə endirim'),
            'amount_threshold_discount' => __('Məbləğ həddi ilə endirim'),
            'tiered_discount' => __('Pilləli endirim'),
            'invoice_bottom_discount' => __('Qaimə altı endirim'),
            'qty_gift' => __('Miqdara görə hədiyyə'),
            'amount_gift' => __('Məbləğə görə hədiyyə'),
            'max_discount_cap' => __('Maksimal endirim tavanı'),
            'target_price' => __('Hədəf qiymət'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['pending' => __('Təsdiq gözləyir'), 'approved' => __('Təsdiqlənib'), 'rejected' => __('Rədd edilib'), 'cancelled' => __('Ləğv edilib')];
    }

    public function isGift(): bool
    {
        return in_array($this->kind, ['qty_gift', 'amount_gift'], true);
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
