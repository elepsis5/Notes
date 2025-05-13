<?php

namespace App\Classes\Pricing\Contracts;

use App\Classes\Common\Contracts\StrictMatching;
use App\Classes\Pricing\DTO\FullPricePosition;
use App\Classes\Pricing\DTO\PartialPricePosition;
use App\Classes\Pricing\FinalPrice;
use App\Models\Price;
use App\Models\Product;

/**
 * Требования к прайс-процессору
 */
interface ProcessPrices extends StrictMatching
{
    /**
     * Обрабатывает цену, высчитывая величину итоговой цены
     *
     * @param Product $product Товар
     * @param Price $price Цена
     * @param boolean $useProductCurrency Использовать ли валюту товара
     * @param boolean $ignorePromocode Игнорировать ли промокод
     * @param boolean $useDiscounts Учитывать ли скидки
     * @return FinalPrice
     */
    public function basePrice2FinalPrice(Product $product, Price $price, bool $useProductCurrency = false, bool $ignorePromocode = false, bool $useDiscounts = false, bool $useDiscountsForBasket = false, bool $useDiscount1c = false): FinalPrice;

    /**
     * Рассчитывает базовую цену в рублях из конечной в валюте, введённой пользователем
     *
     * @param string $finalPrice
     * @param boolean $isPriceTo
     * @return string
     */
    public function finalPrice2BasePrice(string $finalPrice, bool $isPriceTo = false): string;

    /**
     * Возвращает форматированную базовую цену товара
     *
     * @param Product $product
     * @param Price $price
     * @return string
     */
    public function FormatBasePrice(Product $product, Price $price): string;
    
    /**
     * Возвращает форматированную итоговую цену товара
     *
     * @param Product $product
     * @param Price $price
     * @return string
     */
    public function FormatFinalPrice(Product $product, Price $price): string;
    
    /**
     * Собирает итоговую цену из частичных
     *
     * @param PartialPricePosition $position1
     * @param PartialPricePosition ...$more
     * @return FullPricePosition
     */
    public static function composePartialPositions(PartialPricePosition $position1, PartialPricePosition...$more): FullPricePosition;
}
