<?php

namespace App\Classes\Pricing;

use App\Classes\Compozite\DataStamp;
use App\Classes\Compozite\Model;
use App\Classes\Compozite\Pseudo;
use App\Classes\Rates\RateStorage;
use App\EloquentModels\User;
use App\Exceptions\PriceCalculationFailedException;
use App\Models\Currency;
use App\Models\Dealer;
use App\Models\DealerCountry;
use App\Models\Discount;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Stock;
use App\Models\Zone;
use Illuminate\Support\Facades\App;

class EUPricesProcessor extends BasicPricesProcessor
{
    /**
     * Стандартная ставка НДС по каталогу
     */
    protected const ORDINARY_NDS = 20;
    /**
     * Скидка по умолчанию
     */
    protected const DEFAULT_DISCOUNT = 50;
    /**
     * Точность в процессе вычислений
     */
    protected const PROCESSUAL_ACCURACY = 8;
    /**
     * Добавка при конвертации
     */
    protected const CURRENCY_EXCHANGE_ADDITION = 0.015;
    /**
     * Итоговая точность
     */
    protected const FINAL_ACCURACY = 4;
    /**
     * Формат данных, возвращаемых при конвертации
     */
    protected const CONVERSION_FORMAT = ['value'=>'string', 'detailCalculation'=>'string'];
    /**
     * Пользователь
     */
    protected ?User $user;
    /**
     * Привязанный дилер
     *
     * @var Model
     */
    protected Model $dealer;
    /**
     * Страна дилера
     *
     * @var DealerCountry|null
     */
    protected ?DealerCountry $dealerCountry = null;
    /**
     * Юр.лицо
     */
    protected Model $legalEntity;
    /**
     * Курсы валют
     */
    protected RateStorage $rates;
    /**
     * Склады
     *
     * @var array<int, Stock>
     */
    protected array $stocks;
    /**
     * Внутренние курсы
     *
     * @var array
     */
    protected array $ownRates = [];
    /**
     * Рубль
     */
    protected Currency $rouble;
    /**
     * Промежуточная валюта
     *
     * @var Currency
     */
    protected Currency $intermediateCurrency;

    /**
     * Конструктор
     */
    public function __construct(?Dealer $dealer = null, ?LegalEntity $legalEntity = null)
    {
        $this->user = User::actual();
        $this->dealer = $this->resolveDealer($dealer);
        if($this->dealer instanceof Dealer) {
            $this->dealerCountry = $this->dealer->country;
        }
        $this->legalEntity = $this->resolveLegalEntity($legalEntity);
        $this->currency = App::make(Currency::class);
        $currencies = App::make('currencies');
        $this->intermediateCurrency = $currencies->firstWhere('letter_code', 'USD');
        $this->rouble = $currencies->firstWhere('letter_code', 'RUB');
        $this->rates = App::make(RateStorage::class); 
        /**
         * @var ModelStorage<Stock>
         */
        $stocks = App::make('warehouses');
        $this->stocks = $stocks->toKeyed();
        if (config('common.pricing.useOwnRates')) {
            $ownRates = app('ownRates');
            /**
             * @var array<float>
             */
            $ownRates = array_map(function ($item) {
                return (float) $item->value;
            }, $ownRates);
            $this->ownRates = $ownRates;
        }
        /**
         * @var Zone
         */
        $zone = app(Zone::class);
        if($zone->isEu()) {
            $this->intermediateCurrency = $currencies->firstWhere('letter_code', 'EUR');
        } else {
            $this->intermediateCurrency = $currencies->firstWhere('letter_code', 'USD');
        }
        $this->currencies = $currencies->toKeyed();
        
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
        stackStore('[properties]', ['dealer'=>$this->dealer, 'legalEntity'=>$this->legalEntity, 'dealerCountry'=>$this->dealerCountry, 'rates'=>$this->rates], __CLASS__, __FUNCTION__, __LINE__);
    }

    /**
     * Ищет склад в отобранных складах
     *
     * @param integer $uid
     * @return Stock|null
     */
    protected function getStockByUid(int $uid): ?Stock
    {
        return $this->stocks[$uid] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function basePrice2FinalPrice(Product $product, Price $price, bool $useProductCurrency = false, bool $ignorePromocode = false, bool $useDiscounts = false, bool $useDiscountsForBasket = false, bool $useDiscount1c = false): FinalPrice
    {
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        /**
         * Цена не может быть посчитана
         */
        if (!$this->priceCanBeComputed()) {
            return FinalPrice::noPrice();
        }

        /**
         * Нулевая цена == отсутствие цены
         */
        if ($price->price == 0) {
            return FinalPrice::noPrice();
        }

        /**
         * Склад
         */
        $stock = app('warehouses')->firstWhere('uid', $price->stock_link);
        if (!$stock instanceof Stock) {
            return FinalPrice::noPrice();
        }
        /**
         * Страна склада
         */
        $stockCountry = $stock->country;
        if (!$stockCountry instanceof DealerCountry) {
            return FinalPrice::noPrice();
        }
        if ($stockCountry->tax === null) {
            return FinalPrice::noPrice();
        }

        /**
         * Валюта товара и её курс
         */
        $basicCurrency = $this->currencies[$product->currency_link] ?? null;
        if($basicCurrency->letter_code == 'NOTSET') {
            return FinalPrice::noPrice();
        }

        if (!$useProductCurrency) {
            $currency = $this->currency;
        } else {
            /* Валюта не требует конвертации */
            $currency = $basicCurrency;
        }

        $calculationParameters = [
            'base_price'                    => $price->price,
            'base_currency_code'            => $basicCurrency->letter_code,
            'base_currency_rate'            => (float) $this->rates->getByCode($basicCurrency->letter_code)->value,
            'result_currency_code'          => $currency->letter_code,
            'result_currency_rate'          => (float) $this->rates->getByCode($currency->letter_code)->value,
            'intermediate_currency_code'    => $this->intermediateCurrency->letter_code,
            'intermediate_currency_rate'    => (float) $this->rates->getByCode($this->intermediateCurrency->letter_code)->value,
            'add_percent'                   => (float) $this->legalEntity->addpercent,
        ];

        $priceIsSale = ($stock->virtual == 1);

        /**
         * @var PromoCode|null
         */
        $promocode = $product->getPromocode();
        if(!$ignorePromocode && !$priceIsSale && $promocode instanceof PromoCode && (!$product->discount_portal instanceof Discount || ($promocode->isBetterThan($product->discount_portal)))) {
            $calculationParameters['promocode'] = $promocode->value;
            $product->resetDiscountPortal();
        }

        if($useDiscounts && !$priceIsSale) {
            $calculationParameters['discounts'] = $product->discounts->all();
        } elseif ($useDiscount1c) {
            $discounts = $product->discounts->filter(function($item) {
                return $item->priority === 1;
            });
            $calculationParameters['discounts'] = $discounts->all();
        }

        $sameCountry = $this->dealerCountry->is($stockCountry);

        /* Добавляем VAT в двух случаях: плательщик VAT и отгружает внутри одной страны, и строго наоборот (неплательщик и не внутри страны) */
        $addVat = !(($sameCountry) xor ($this->legalEntity->is_vat_payer));
        $calculationParameters['vat'] = $addVat ? $stockCountry->tax : 0.0;

        if (config('common.pricing.useOwnRates')) {
            $ownRates = app('ownRates');
            /**
             * @var array<float>
             */
            $ownRates = array_map(function ($item) {
                return (float) $item->value;
            }, $ownRates);
            $calculationParameters['own_rates'] = $ownRates;
        }

        $personalPrice = new PersonalPrice($calculationParameters);
        $finalPrice = $personalPrice->getResultPrice();
        if ($finalPrice === false) {
            reportError('⚠️ внутренняя ошибка (расчёт цены)', new PriceCalculationFailedException($personalPrice->getErrorMessage()));
            return FinalPrice::noPrice();
        }
        $detailCalculation = $personalPrice->getFormula();
        /* Форматируем начальную и конечную суммы в формуле */
        $formattedBasePrice = $this->formatPrice($basicCurrency, $price->price);
        $detailCalculation = str_ireplace($price->price.$basicCurrency->letter_code, $formattedBasePrice, $detailCalculation);
        $formattedFinalPrice = $this->formatPrice($currency, $finalPrice);
        $detailCalculation = str_ireplace(bcmul($finalPrice, '1', 2).$this->currency->letter_code, $formattedFinalPrice, $detailCalculation);

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        return FinalPrice::compose($finalPrice, $formattedFinalPrice, $addVat, $detailCalculation);
    }

    /**
     * @inheritDoc
     */
    public function finalPrice2BasePrice(string $finalPrice, bool $isPriceTo = false): string
    {
        /* Пересчитываем скидку */
        $discount = $this->dealer->discount;
        $withoutDiscount = bcdiv((string)$finalPrice, (string)(1-$discount/100));

        /* Конвертируем в рубли */
        if (!$this->currency->is($this->rouble)) {
            $basePrice = bcmul($this->convertToRouble($withoutDiscount), '1', static::FINAL_ACCURACY);
        } else {
            $basePrice = bcmul($withoutDiscount, '1', static::FINAL_ACCURACY);
        }
        return $basePrice;
    }

    /**
     * Определяет, может ли цена вообще быть вычислена
     *
     * @return boolean
     */
    protected function priceCanBeComputed(): bool
    {
        if (!$this->user instanceof User) {
            return false;
        }
        if (!$this->user->accountIsActive()) {
            return false;
        }
        if (!$this->dealerCountry instanceof DealerCountry) {
            return false;
        }
        if ($this->dealerCountry->tax === null) {
            return false;
        }
        return true;
    }

    /**
     * Переводит в рубли
     *
     * @param string $price
     * @return string
     */
    protected function convertToRouble(string $price): string
    {
        if (config('common.pricing.useOwnRates', false)) {
            $internalRate = null;

            if (array_key_exists($this->currency->letter_code, $this->ownRates)) {
                /* Из тех валют, которые есть в rate_own, в рубли всегда по внутреннему курсу */
                $crossRate = $this->ownRates[$this->currency->letter_code];
            } else {
                /* Из других валют в рубли — сначала в промежуточную валюту по кросс-курсу, затем в рубли по внутреннему курсу */
                $basicRate = $this->rates->getByCode($this->currency->letter_code)->value;
                $intermediateRate = $this->rates->getByCode($this->intermediateCurrency->letter_code)->value;
                $crossRate = bcdiv((string) $basicRate, (string) $intermediateRate, static::PROCESSUAL_ACCURACY);
                $internalRate = $this->ownRates[$this->intermediateCurrency->letter_code];
            }
            $converted = bcmul($price, $crossRate, static::PROCESSUAL_ACCURACY);
            if (!is_null($internalRate)) {
                $converted = bcmul($converted, $internalRate, static::FINAL_ACCURACY);
            } else {
                $converted = bcmul($converted, '1', static::FINAL_ACCURACY);
            }
        } else {
            $converted = bcmul($price, (string) $this->rates->getByCode($this->currency->letter_code)->value, static::FINAL_ACCURACY);
        } 
        return $converted;
    }

    /**
     * Определяет дилера
     *
     * @param Dealer|null $dealer
     * @return Dealer|Pseudo
     */
    protected function resolveDealer(?Dealer $dealer)
    {
        if($dealer instanceof Dealer) {
            return $dealer;
        }
        if($this->user instanceof User) {
            $dealer = $this->user->data->dealer;
            if ($dealer instanceof Dealer) {
                return $dealer;
            } else {
                return Pseudo::compose(['discount'=>static::DEFAULT_DISCOUNT]);
            }
        } else {
            return Pseudo::compose(['discount'=>static::DEFAULT_DISCOUNT]);
        }
        return null;
    }

    /**
     * Определяет юр.лицо
     *
     * @param LegalEntity|null $legalEntity
     * @return LegalEntity|Pseudo
     */
    protected function resolveLegalEntity(?LegalEntity $legalEntity)
    {
        if($legalEntity instanceof LegalEntity) {
            return $legalEntity;
        }
        if ($this->user instanceof User) {
            return ($this->user->data->selectedLegalEntity !== null) ?
                                    $this->user->data->selectedLegalEntity :
                                    Pseudo::compose(['nds'=>static::ORDINARY_NDS, 'addpercent'=>1.5, 'vat'=>0, 'is_vat_payer'=>0]);
        } else {
            return Pseudo::compose(['nds'=>static::ORDINARY_NDS, 'addpercent'=>1.5, 'vat'=>0, 'is_vat_payer'=>0]);
        }
    }


}
