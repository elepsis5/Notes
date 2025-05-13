<?php

namespace App\Models;

use App\Classes\Compozite\DataStamp;
use App\Classes\Compozite\Model;
use App\Classes\Compozite\ModelStorage;
use App\Classes\Compozite\Query;
use App\Classes\Pricing\Contracts\ProcessPrices;
use App\Classes\Pricing\FinalPrice;
use Closure;
use Illuminate\Support\Facades\App;

/**
 * Цена по товару

 * @property float $price Цена
 * @property int $product_link (Связка) Товар
 * @property int $stock_link (Связка) Склад
 * @property int $currency_link (Связка) Валюта
 * @property int $price_list_link (Связка) Прайс-лист
 * @property-read string $date_create Дата добавления
 * @property-read string $date_update Дата обновления
 *
 * @property-read Product|null $product Связь с моделью Product: многие->один
 * @property-read Stock|null $stock Связь с моделью Stock: многие->один
 * @property-read Currency|null $currency Связь с моделью Currency: многие->один
 *
 * @property-read FinalPrice $finalPrice [Вычисляемое] Итоговая цена
 * @property-read FinalPrice $finalPriceWithoutPromocode [Вычисляемое] Итоговая цена без промокода
 *
 * @see /config/compozite/price.php
 */
class Price extends Model
{
    protected static $entityName = "price";

    public static array $calculatedFields = [
        'finalPrice' => 'getFinalPrice',
        'originalFinalPrice' => 'getOriginalFinalPrice'
    ];

    /**
     * ID розничного прайс-листа
     */
    public const RETAIL_PRICE_LIST = 7;
    /**
     * ID розничного рублёвого прайс-листа
     */
    public const RETAIL_RUB_PRICE_LIST = 8;

    protected ?ProcessPrices $priceProcessor = null;

    /**
     * Цена для конвертации
     *
     * @var self
     */
    protected self $conversionalPrice;

    public function setConversionalPrice(self $conversionalPrice): void
    {
        $this->conversionalPrice = $conversionalPrice;
    }

    public function getConversionalPrice(): self
    {
        return $this->conversionalPrice;
    }

    /**
     * Добавляет фильтр по прайс-листу
     * (номер должен быть передан в первом аргументе)
     *
     * @param Query $query
     * @param array $args
     * @return Query
     */
    public static function priceList(Query $query, array $args): Query
    {
        $priceLists = $args[0];
        if (is_int($priceLists)) {
            return $query->where(['price_list_link', '=', $priceLists]);
        }
        if (is_array($priceLists)) {
            return $query->whereIn('price_list_link', $priceLists);
        }
        return $query;
    }

    /**
     * Добавляет фильтр по складам
     *
     * @param Query $query
     * @param array $args [0 => $stockUids]
     * @return Query
     */
    public static function stock(Query $query, array $args): Query
    {
        $stocks = $args[0];
        if (is_int($stocks)) {
            return $query->where(['stock_link', '=', $stocks]);
        }
        if (is_array($stocks)) {
            return $query->whereIn('stock_link', $stocks);
        }
        return $query;
    }

    /**
     * Добавляет фильтр по товарам
     *
     * @param Query $query
     * @param array $args [0 => $productUids]
     * @return Query
     */
    public static function product(Query $query, array $args): Query
    {
        $products = $args[0];
        if (is_int($products)) {
            return $query->where(['product_link', '=', $products]);
        }
        if (is_array($products)) {
            return $query->whereIn('product_link', $products);
        }
        return $query;
    }

    /**
     * Получает цены для товаров и фильтрует по прайс-листу
     *
     * @param ModelStorage $products
     * @param integer $userPriceListId
     * @param array $stockIds
     * @param array $sort
     * @return ModelStorage<int, static>
     */
    public static function getForProducts(ModelStorage $products, int $userPriceListId, array $stockIds = [], array $sort = [], ?Dealer $dealer = null): ModelStorage
    {
        $discounts = Discount::getAppliableForProducts($products, $dealer);
        $priceListsIds = !is_null($discounts->getPriceLists()) ? $discounts->getPriceLists()->getUids() : [];
        $priceListsIds[] = $userPriceListId;
        /**
         * @var ModelStorage<int, PriceList>
         */
        $allPriceLists = app('priceLists');
        $userRubPriceListId = $allPriceLists->firstWhere('uid', $userPriceListId)->relate_to_rub ?? null;
        $extraRatesRequired = $products->pluck('use_extra_rates')->unique()->contains(1);
        if($extraRatesRequired) {
            $roublePriceListsIds = $allPriceLists->whereIn('uid', $priceListsIds)->pluck('relate_to_rub')->all();
            $priceListsIds = array_merge($priceListsIds, $roublePriceListsIds);
        }
        if(!$products->hasLinkedData('availabilities'))
        {
            $availabilities = Availability::whereLinkedWith('product', $products)
                ->whereIn('stock_link', $stockIds)
                ->nestedWhere(function(Query $query) {
                    $query->where(['quantity_free', '>', 0])
                    ->orWhere(['quantity_unpaid_reserve', '>', 0]);
                })->get();
        } else {
            $availabilities = $products->getLinkedData('availabilities');
        }
        $products = $products->transform(function ($item) use ($discounts, $availabilities) {
            $product = Product::compose((array)$item);
            $item->discounts = $discounts->forProduct($product);
            $item->availabilities = $availabilities->where('product_link', $item->uid);
            return $item;
        });
        $products->makeIndex(['uid']);
        $prices = static::whereLinkedWith('product', $products)
            ->apply('priceList', $priceListsIds)
            ->when(!empty($sort), function (Query $query) use ($sort) {
                $query->orderBy(...$sort);
            })
            ->when(!empty($stockIds), function (Query $query) use ($stockIds){
                $query->whereIn('stock_link', $stockIds);
            })
            ->get();
        $conversionalPrices = [];
        if($extraRatesRequired) {
            $conversionalPrices = $prices->filter(function ($item) use ($products, $userRubPriceListId) {
                /* Фильтруем по прайс-листу: либо подменный прайс-лист скидок для товара (если есть), либо основной прайс-лист пользователя */
                /**
                 * @var Product|null
                 */
                $product = $products->xfirstWhere('uid', $item->product_link);
                if(!$product instanceof Product || !$product->extraRatesRequired()) {
                    return false;
                }
                if(!$product->discountPriceList instanceof PriceList) {
                    return $item->price_list_link == $userRubPriceListId;
                }
                $discountPriceListId = $product->discountPriceList->relate_to_rub;
                
                return ($discountPriceListId == $item->price_list_link);
            })->toKeyed('product_link', 'stock_link');
        }
        
        $prices = $prices->filter(function ($item) use ($products, $userPriceListId) {
            /* Фильтруем по прайс-листу: либо подменный прайс-лист скидок для товара (если есть), либо основной прайс-лист пользователя */
            /**
             * @var Product|null
             */
            $product = $products->xfirstWhere('uid', $item->product_link);
            if(!$product->discountPriceList instanceof PriceList) {
                return $item->price_list_link == $userPriceListId;
            }
            $discountPriceListId = $product->discountPriceList->uid;
            return ($discountPriceListId == $item->price_list_link);
        });
        
        $prices->transform(function ($item) use ($conversionalPrices) {
            $conversionalPrice = $conversionalPrices[$item->product_link][$item->stock_link] ?? null;
            if($conversionalPrice instanceof Price) {
                $item->conversional_price = $conversionalPrice;
            }
        });
        $prices->bindLinkedCollection('product', $products);
        return $prices;
    }

    /**
     * Получает розничные цены для товаров
     *
     * @param ModelStorage $products Товары
     * @param array $stockIds Склады (список ID)
     * @param array $sort Сортировка (поле, порядок)
     * @return ModelStorage<int, static>
     */
    public static function getRetailForProducts(ModelStorage $products, array $stockIds = [], array $sort = []): ModelStorage
    {
        $products->makeIndex(['uid']);
        $priceLists = [static::RETAIL_PRICE_LIST];
        $extraRatesRequired = $products->pluck('use_extra_rates')->unique()->contains(1);
        if($extraRatesRequired) {
            $priceLists[] = static::RETAIL_RUB_PRICE_LIST;
        }
        $prices = static::whereLinkedWith('product', $products)
            ->apply('priceList', $priceLists)
            ->when(!empty($sort), function (Query $query) use ($sort) {
                $query->orderBy(...$sort);
            })
            ->when(!empty($stockIds), function (Query $query) use ($stockIds){
                $query->whereIn('stock_link', $stockIds);
            })
            ->get();
            $conversionalPrices = [];
            if($extraRatesRequired) {
                $conversionalPrices = $prices->filter(function ($item) use ($products) {
                    /* Фильтруем по прайс-листу: либо подменный прайс-лист скидок для товара (если есть), либо основной прайс-лист пользователя */
                    /**
                     * @var Product|null
                     */
                    $product = $products->xfirstWhere('uid', $item->product_link);
                    if(!$product instanceof Product || !$product->extraRatesRequired()) {
                        return false;
                    }
                    return $item->price_list_link === static::RETAIL_RUB_PRICE_LIST;
                })->toKeyed('product_link', 'stock_link');
            }
        $prices = $prices->filter(function ($item) {
            return $item->price_list_link === static::RETAIL_PRICE_LIST;
        });
        $prices->transform(function ($item) use ($conversionalPrices) {
            $conversionalPrice = $conversionalPrices[$item->product_link][$item->stock_link] ?? null;
            if($conversionalPrice instanceof Price) {
                $item->conversional_price = $conversionalPrice;
            }
        });
        $prices->bindLinkedCollection('product', $products);
        return $prices;
    }

    /**
     * Рассчитывает итоговую цену
     *
     * @param Dealer|null $dealer Контрагент
     * @param LegalEntity|null $legalEntity Юридическое лицо
     * @return FinalPrice
     */
    public function getFinalPrice(?Dealer $dealer = null, ?LegalEntity $legalEntity = null): FinalPrice
    {
        $finalPrice = $this->resolvePriceProcessor($dealer, $legalEntity)->basePrice2FinalPrice($this->product, $this);
        return $finalPrice;
    }

    /**
     * Рассчитывает итоговую цену в валюте продажи
     *
     * @return FinalPrice
     */
    public function getOriginalFinalPrice(): FinalPrice
    {
        $finalPrice = $this->resolvePriceProcessor()->basePrice2FinalPrice($this->product, $this, true);
        return $finalPrice;
    }

    /**
     * Рассчитывает итоговую цену с учётом дополнительных показателей
     *
     * @param Dealer|null $dealer
     * @param LegalEntity|null $legalEntity
     * @param boolean $useProductCurrency
     * @param boolean $ignorePromocode
     * @param boolean $useDiscounts
     * @return FinalPrice
     */
    public function getFinalPriceWithFlags(?Dealer $dealer = null, ?LegalEntity $legalEntity = null, bool $useProductCurrency = false, bool $ignorePromocode = false, bool $useDiscounts = false, bool $useDiscountsForBasket = false, bool $useDiscount1c = false): FinalPrice
    {
        $finalPrice = $this->resolvePriceProcessor()->basePrice2FinalPrice($this->product, $this, $useProductCurrency, $ignorePromocode, $useDiscounts, $useDiscountsForBasket, $useDiscount1c);
        // dd($finalPrice);
        return $finalPrice;
    }

    /**
     * Возвращает базовую цену
     *
     * @return string
     */
    public function getBasePrice()
    {
        $basePrice = $this->price;
        return $basePrice;
    }

    /**
     * Возвращает форматированную итоговую цену
     *
     * @return string
     */
    public function getFinalPriceFormatted()
    {
        $finalPrice = $this->resolvePriceProcessor()->FormatFinalPrice($this->product, $this);
        return $finalPrice;
    }

    /**
     * Возвращает форматированную базовую цену
     *
     * @return string
     */
    public function getBasePriceFormatted()
    {
        $finalPrice = $this->resolvePriceProcessor()->FormatBasePrice($this->product, $this);
        return $finalPrice;
    }

    /**
     * Устанавливает прайс-процессор
     *
     * @param ProcessPrices $priceProcessor
     * @return void
     */
    public function setPriceProcessor(ProcessPrices $priceProcessor): void
    {
        $this->priceProcessor = $priceProcessor;
    }

    public function onRetrieve(): void
    {
        $conversionalPrice = (isset($this->conversional_price) && $this->conversional_price instanceof self) 
                                ? $this->conversional_price 
                                : $this;
        $this->conversionalPrice = $conversionalPrice;
    }

    /**
     * Определяет и сохраняет прайс-процессор
     *
     * @param Dealer|null $dealer
     * @param LegalEntity|null $legalEntity
     * @return ProcessPrices
     */
    public function resolvePriceProcessor(?Dealer $dealer = null, ?LegalEntity $legalEntity = null): ProcessPrices
    {
        if($this->priceProcessor instanceof ProcessPrices) {
            return $this->priceProcessor;
        }
        /**
         * @var ProcessPrices
         */
        $priceProcessor = App::make(ProcessPrices::class, [$dealer, $legalEntity]);
        $this->priceProcessor = $priceProcessor;
        return $priceProcessor;
    }

    /**
     * Вычисляет статистику по коллекции цен: минимальную, максимальную в выбранной валюте и валюте продажи
     *
     * @param ModelStorage<self> $prices Коллекция цен товара
     * @param boolean $formatPrices Требуется ли форматировать цены
     * @param bool $showDetailCalculation Нужно ли показывать детальный расчёт
     * @param bool $useDiscounts Считать ли цены со скидками
     * @return DataStamp {stdClass {string $targetCurrencyPrice, string $originalCurrencyPrice, bool $vatIncluded, string $detailCalculation} $min,
     *                    stdClass {string $targetCurrencyPrice, string $originalCurrencyPrice, bool $vatIncluded} $max}
     */
    public static function computeStats(ModelStorage $prices, bool $formatPrices = false, bool $showDetailCalculation = false, bool $useDiscounts = false): DataStamp
    {
        $stocks = collect([]);
        $priceField = 'finalPrice';
        $priceValueField = 'finalPriceValue';
        $originalPriceValueField = 'originalFinalPriceValue';
        if($useDiscounts) {
            $priceField = 'finalPriceWithDiscounts';
            $priceValueField = 'finalPriceWithDiscountsValue';
            $originalPriceValueField = 'originalFinalPriceWithDiscountsValue';
        }
        $sortedPrices = $prices->sortBy($priceValueField);
        $minPrice = $sortedPrices->where('price', '>', 0)->first();
        if(!$minPrice instanceof Price)
        {
            $minPrice = $sortedPrices->first();
        }
        $maxPrice = $sortedPrices->last();
        $actualCurrency = null;

        if ($minPrice instanceof Price) {
            $stocks->push($minPrice->stock_link);
            $minTargetCurrencyPrice = $minPrice->$priceValueField;
            $minOriginalCurrencyPrice = $minPrice->$originalPriceValueField;
            $minVatIncluded = $minPrice->$priceField->getVatIncluded();
            if ($showDetailCalculation) {
                $minDetailPriceCalculation = $minPrice->$priceField->getDetailCalculation();
            } else {
                $minDetailPriceCalculation = '';
            }
        } else {
            $minTargetCurrencyPrice = '';
            $minOriginalCurrencyPrice = '';
            $minVatIncluded = false;
            $minDetailPriceCalculation = '';
        }

        if ($maxPrice instanceof Price) {
            $maxTargetCurrencyPrice = $maxPrice->$priceValueField;
            $maxOriginalCurrencyPrice = $maxPrice->$originalPriceValueField;
            $maxVatIncluded = $maxPrice->$priceField->getVatIncluded();
            if ($showDetailCalculation) {
                $maxDetailPriceCalculation = $maxPrice->$priceField->getDetailCalculation();
            } else {
                $maxDetailPriceCalculation = '';
            }
        } else {
            $maxTargetCurrencyPrice = '';
            $maxOriginalCurrencyPrice = '';
            $maxVatIncluded = false;
            $maxDetailPriceCalculation = '';
        }

        $actualCurrency = App::make(Currency::class);
        $price = $prices->first();

        if ($formatPrices && $actualCurrency instanceof Currency) {
            $minTargetCurrencyPrice = $actualCurrency->formatSum($minTargetCurrencyPrice);
            $maxTargetCurrencyPrice = $actualCurrency->formatSum($maxTargetCurrencyPrice);
            if ($price instanceof Price) {
                $stocks->push($price->stock_link);
                /**
                 * @var ModelStorage<Currency>
                 */
                $allCurrencies = App::make('currencies');
                /**
                 * @var Currency
                 */
                $basicCurrency = $allCurrencies->firstWhere('uid', $prices->first()->product->currency_link);
                $minOriginalCurrencyPrice = $basicCurrency->formatSum($minOriginalCurrencyPrice);
                $maxOriginalCurrencyPrice = $basicCurrency->formatSum($maxOriginalCurrencyPrice);
            } else {
                $minOriginalCurrencyPrice = '';
                $maxOriginalCurrencyPrice = '';
            }
        }
        $stock_link = $stocks->first();
        $min = (object)[
            'targetCurrencyPrice' => $minTargetCurrencyPrice,
            'originalCurrencyPrice' => $minOriginalCurrencyPrice,
            'vatIncluded' => $minVatIncluded,
            'detailCalculation' => $minDetailPriceCalculation
        ];
        $max = (object)[
            'targetCurrencyPrice' => $maxTargetCurrencyPrice,
            'originalCurrencyPrice' => $maxOriginalCurrencyPrice,
            'vatIncluded' => $maxVatIncluded,
            'detailCalculation' => $maxDetailPriceCalculation
        ];
        return DataStamp::use('main.priceStats')->compose(['min'=>$min, 'max'=>$max, 'stock_link' => $stock_link]);
    }
}
