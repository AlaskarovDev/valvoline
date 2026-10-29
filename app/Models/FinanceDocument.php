<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceDocument extends Model
{
    public const PREFIXES = ['cash_opening' => 'KA-', 'cash_receipt' => 'KM-', 'cash_transfer' => 'KT-', 'bank_opening' => 'BA-', 'bank_transfer' => 'BT-'];

    protected $table = 'finance_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float', 'to_amount' => 'float', 'rate' => 'float'];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'cash_opening' => __('Kassa açılış sənədi'), 'cash_receipt' => __('Kassa mədaxili (müştəri ödənişi)'), 'cash_transfer' => __('Kassa transfer sənədi'),
            'bank_opening' => __('Bank açılış sənədi'), 'bank_transfer' => __('Banklar arası transfer sənədi'),
        ];
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function toRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'to_cash_register_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
