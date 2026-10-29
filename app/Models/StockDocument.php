<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDocument extends Model
{
    /** növ => [prefiks, stoka işarə (+1 / −1)] */
    public const TYPES = [
        'opening' => ['AC-', 1],
        'count_surplus' => ['SAR-', 1],
        'count_shortage' => ['SAS-', -1],
        'production_loss' => ['IIT-', -1],
        'consumption' => ['SRF-', -1],
    ];

    protected $table = 'stock_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'opening' => __('Açılış sənədi'),
            'count_surplus' => __('Sayım artığı sənədi'),
            'count_shortage' => __('Sayım əskiyi sənədi'),
            'production_loss' => __('İstehsal itkisi sənədi'),
            'consumption' => __('Sərf sənədi'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeHelp(): array
    {
        return [
            'opening' => __('Proqrama keçid zamanı anbardakı ilkin qalıq və onun mayası. Stoku artırır.'),
            'count_surplus' => __('Fiziki sayımda sistemdən artıq çıxan mal. Sayılan miqdarı yazın — fərq avtomatik hesablanır. Stoku artırır.'),
            'count_shortage' => __('Fiziki sayımda sistemdən əskik çıxan mal. Sayılan miqdarı yazın — fərq avtomatik hesablanır. Stoku azaldır.'),
            'production_loss' => __('Qablaşdırma / doldurma zamanı itən, dağılan mal. Stoku azaldır.'),
            'consumption' => __('Daxili istifadə, nümunə, reklam və s. üçün sərf olunan mal. Stoku azaldır.'),
        ];
    }

    public static function isCount(string $type): bool
    {
        return in_array($type, ['count_surplus', 'count_shortage'], true);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
