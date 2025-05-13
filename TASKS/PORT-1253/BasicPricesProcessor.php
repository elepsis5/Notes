<?php

namespace App\Classes\Pricing;

use App\Classes\Common\Traits\StrictMatching;
use App\Classes\Pricing\Contracts\ProcessPrices;
use App\Classes\Pricing\DTO\FullPricePosition;
use App\Classes\Pricing\DTO\PartialPricePosition;
use App\Models\Currency;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Support\Facades\App;

/**
 * Основа для других обработчиков цен
 * Используется, если нет нужных условий для более конкретных расчётов
 */
class BasicPricesProcessor implements ProcessPrices
{
    /**
     * Все валюты
     * 
     * @var array<int, Currency>
     */
    protected array $currencies;
    /**
     * Валюта
     */
    protected Currency $currency;

    use StrictMatching;
    
    public function __construct()
    {
        $this->currency = App::make(Currency::class);
        $this->currencies = App::make('currencies')->toKeyed();
    }
    
    /**
     * @inheritDoc
     */
    public function basePrice2FinalPrice(Product $product, Price $price, bool $useProductCurrency = false, bool $ignorePromocode = false, bool $useDiscounts = false, bool $useDiscountsForBasket = false, bool $useDiscount1c = false): FinalPrice
    {
        return FinalPrice::noPrice();
    }

    /**
     * Рассчитывает базовую цену в рублях из конечной в валюте, введённой пользователем
     *
     * @param string $finalPrice
     * @param boolean $isPriceTo
     * @return string
     */
    public function finalPrice2BasePrice(string $finalPrice, bool $isPriceTo = false): string
    {
        return $finalPrice;
    }

    /**
     * Возвращает форматированную базовую цену товара
     *
     * @param Product $product
     * @param Price $price
     * @return string
     */
    public function FormatBasePrice(Product $product, Price $price): string
    {
        $basePrice = $price->price;
        if ($basePrice == 0) {
            return 'Нет';
        }
        $basicCurrency = $this->currencies[$product->currency_link] ?? null;
        return $basicCurrency->formatSum($basePrice);
    }
    
    /**
     * Возвращает форматированную итоговую цену товара
     *
     * @param Product $product
     * @param Price $price
     * @return string
     */
    public function FormatFinalPrice(Product $product, Price $price): string
    {
        $finalPrice = $this->basePrice2FinalPrice($product, $price);
        return $finalPrice->getPriceFormatted();
    }

    /**
     * Форматирует цену
     *
     * @param Currency $currency
     * @param string $price
     * @return string
     */
    public function formatPrice(Currency $currency, string $price, bool $basePriceTrigger = false): string
    {
        return $currency->formatSum($price, $basePriceTrigger);
    }

    /**
     * Собирает итоговую цену из частичных
     *
     * @param PartialPricePosition $position1
     * @param PartialPricePosition ...$more
     * @return FullPricePosition
     */
    public static function composePartialPositions(PartialPricePosition $position1, PartialPricePosition...$more): FullPricePosition
    {
        /**
         * Точность в процессе расчётов
         */
        $processualAccuracy = 4;
        /**
         * Итоговая точность
         */
        $finalAccuracy = 2;
        /*
         * Если есть только одна частичная позиция, то упаковываем данные из неё
         */
        if(count($more) == 0) {
            $positionSum = bcmul($position1->price->getPrice(), $position1->quantity, $finalAccuracy);
            $full = new FullPricePosition($position1->price->getPrice(), $position1->quantity, $positionSum);
            return $full;
        }
        /*
         * Иначе считаем суммы по частичным позициям, собираем вместе
         */
        $sum = bcmul($position1->price->getPrice(), $position1->quantity, $processualAccuracy);
        $quantity = $position1->quantity;
        foreach($more as $position) {
            $positionSum = bcmul($position->price->getPrice(), $position->quantity, $processualAccuracy);
            $sum = bcadd($sum, $positionSum, $processualAccuracy);
            $quantity += $position->quantity;
        }
        $price = bcdiv($sum, $quantity, $processualAccuracy);
        /*
         * Округляем для отдачи
         */
        $sum = bcmul($sum, '1', $finalAccuracy);
        $price = bcmul($price, '1', $finalAccuracy);
        $full = new FullPricePosition($price, $quantity, $sum);
        return $full;
    }
}
