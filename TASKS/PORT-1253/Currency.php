<?php

namespace App\Models;

use App\Classes\Compozite\Model;
use Illuminate\Support\Facades\App;
use NumberFormatter;
use App\Models\Order;
use App\Models\Rate;
use App\Models\Product;
use App\Models\DealerCountry;

/**
 * Валюта
 *
 * @property int $digital_code Цифровой код
 * @property string $letter_code Буквенный код
 * @property string $symbol_code Символ
 * @property string $name Название
 * @property-read string $date_create Дата добавления
 * @property-read string $date_update Дата обновления
 *
 * @property-read \App\Classes\Compozite\ModelStorage<Order> $orders Связь с моделью Order: один->многие
 * @property-read \App\Classes\Compozite\ModelStorage<Rate> $rates Связь с моделью Rate: один->многие
 * @property-read \App\Classes\Compozite\ModelStorage<Product> $products Связь с моделью Product: один->многие
 * @property-read \App\Classes\Compozite\ModelStorage<DealerCountry> $dealer_countries Связь с моделью DealerCountry: один->многие
 */

class Currency extends Model
{
    protected static $entityName = "currency";

    /**
     * ID валюты "рубль"
     */
    public const RUB = 5;

    /**
     * Ищет валюту по буквенному коду
     *
     * @param string $code
     * @return self|null
     */
    public static function getByLetterCode(string $code): ?self
    {
        $language = App::make(Language::class);
        return static::where(['letter_code', '=', $code])->translate($language)->first();
    }

    /**
     * Форматирует сумму в валюте
     *
     * @param int|float|string $sum
     * @return string
     */
    public function formatSum($sum, $basePriceTrigger = false): string
    {
        if ((!is_numeric($sum) && !is_string($sum)) || ($sum === '')) {
            return '';
        }

        $locale = App::currentLocale();
        $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $fmt->setTextAttribute(NumberFormatter::CURRENCY_CODE, $this->symbol_code);
        $formatted = $fmt->formatCurrency((float)$sum, $this->letter_code);
        if ($basePriceTrigger) {
            $result = '<b>' . str_ireplace($this->letter_code, $this->symbol_code, $formatted) . '</b>';
        } else {
            $result = str_ireplace($this->letter_code, $this->symbol_code, $formatted);
        }

        return $result;
    }

    /**
     * Форматирует сумму в валюте (без символа валюты)
     *
     * @param int|float|string $sum
     * @return string
     */
    public function formatSumSymbless($sum): string
    {
        if ((!is_numeric($sum) && !is_string($sum)) || ($sum === '')) {
            return '';
        }

        $locale = App::currentLocale();
        $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $fmt->setTextAttribute(NumberFormatter::CURRENCY_CODE, $this->symbol_code);
        $formatted = $fmt->formatCurrency((float)$sum, $this->letter_code);
        return str_ireplace($this->symbol_code, '', str_ireplace($this->letter_code, '', $formatted));
    }
}
