<?php

namespace App\Classes\Pricing;

use App\Classes\Compozite\Model;
use App\Classes\Compozite\Pseudo;
use Illuminate\Support\Facades\App;
use App\EloquentModels\User;
use App\Classes\Compozite\ModelStorage;
use App\Classes\Rates\RateStorage;
use App\Classes\Utilities\Timer;
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

class EurasPricesProcessor extends BasicPricesProcessor
{
    /**
     * Стандартная ставка НДС по каталогу
     */
    protected const ORDINARY_NDS = 20;
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
    protected const CONVERSION_FORMAT = ['value' => 'string', 'detailCalculation' => 'string'];
    /**
     * Пользователь
     */
    protected ?User $user;
    /**
     * Юр.лицо
     */
    protected Model $legalEntity;
    /**
     * Контрагент
     *
     * @var Dealer|null
     */
    protected ?Dealer $dealer;
    /**
     * Курсы валют
     *
     * @var array<string, float>
     */
    protected array $rates;
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
     *
     * @param Dealer|null $dealer Контрагент
     * @param LegalEntity|null $legalEntity Юр.лицо
     */
    public function __construct(?Dealer $dealer = null, ?LegalEntity $legalEntity = null)
    {
        $this->user = User::actual();
        $this->dealer = $this->resolveDealer($dealer);
        $this->legalEntity = $this->resolveLegalEntity($legalEntity);
        $this->currency = App::make(Currency::class);
        $currencies = App::make('currencies');
        $this->rouble = $currencies->firstWhere('letter_code', 'RUB');
        $this->intermediateCurrency = $currencies->firstWhere('letter_code', 'USD');
        $this->currencies = $currencies->toKeyed();
        $this->rates = App::make(RateStorage::class)->getRateValues();
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

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
        stackStore('[properties]', ['dealer' => $this->dealer, 'legalEntity' => $this->legalEntity, 'rates' => $this->rates], __CLASS__, __FUNCTION__, __LINE__);
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

        $stock = $this->getStockByUid($price->stock_link);
        if (!$stock instanceof Stock) {
            /**
             * Не найден склад
             */
            return FinalPrice::noPrice();
        } else {
            if ($stock->country instanceof DealerCountry) {
                $stockCountry = $stock->country;
            } else {
                /**
                 * Не найдена страна склада
                 */
                return FinalPrice::noPrice();
            }
        }

        /**
         * @var Currency
         */
        $basicCurrency = $this->currencies[$product->currency_link] ?? null;
        if ($basicCurrency->letter_code == 'NOTSET') {
            return FinalPrice::noPrice();
        }

        if (!$useProductCurrency) {
            $currency = $this->currency;
            if($currency->is($this->rouble) && !$basicCurrency->is($this->rouble)) {
                /**
                 * @var Currency
                 */
                $basicCurrency = $this->currencies[$product->getCurrencyLinkForConversion()];
                $price = $price->getConversionalPrice();
            }
        } else {
            /* Валюта не требует конвертации */
            $currency = $basicCurrency;
        }

        $promotion = 0.0;
        $base_currency_code = $basicCurrency->letter_code;
        $currency_code = $currency->letter_code;
        $intermediate_currency_code = $this->intermediateCurrency->letter_code;
        $calculationParameters = [
            'base_price'                    => $price->price,
            'base_currency_code'            => $base_currency_code,
            'base_currency_rate'            => $this->rates[$base_currency_code],
            'result_currency_code'          => $currency_code,
            'result_currency_rate'          => $this->rates[$currency_code],
            'intermediate_currency_code'    => $intermediate_currency_code,
            'intermediate_currency_rate'    => $this->rates[$intermediate_currency_code],
            'add_percent'                   => $this->legalEntity->addpercent,
            'promotion'                     => $promotion
        ];

        $priceIsSale = ($stock->virtual == 1);

        /**
         * @var PromoCode|null
         */
        $promocode = $product->getPromocode();
        if (!$ignorePromocode && !$priceIsSale && $promocode instanceof PromoCode && (!$product->discount_portal instanceof Discount || ($promocode->isBetterThan($product->discount_portal)))) {
            $calculationParameters['promocode'] = $promocode->value;
            $product->resetDiscountPortal();
        }

        if ($useDiscounts && !$priceIsSale) {
            $calculationParameters['discounts'] = $product->discounts->all();
        } elseif ($useDiscount1c) {
            $discounts = $product->discounts->filter(function($item) {
                return $item->priority === 1;
            });
            $calculationParameters['discounts'] = $discounts->all();
        }


        if (config('common.pricing.useOwnRates')) {
            $calculationParameters['own_rates'] = $this->ownRates;
        }

        $isVat = $this->dealer->country->is($stockCountry);
        if (!$isVat) {
            $calculationParameters['nds'] = $stockCountry->tax;
        }
        $personalPrice = new PersonalPrice($calculationParameters);
        $finalPrice = $personalPrice->getResultPrice();
        // dd(get_defined_vars(), debug_backtrace());
        if ($finalPrice === false) {
            reportError('⚠️ внутренняя ошибка (расчёт цены)', new PriceCalculationFailedException($personalPrice->getErrorMessage()));
            return FinalPrice::noPrice();
        }
        $detailCalculation = $personalPrice->getFormula();
        /* Форматируем начальную и конечную суммы в формуле */
        $formattedBasePrice = $this->formatPrice($basicCurrency, $price->price, true);
        $detailCalculation = str_ireplace($price->price . $basicCurrency->letter_code, $formattedBasePrice, $detailCalculation);
        $formattedFinalPrice = $this->formatPrice($currency, $finalPrice);
        $detailCalculation = 'Цена по прайсу: ' . str_ireplace($finalPrice . $this->currency->letter_code, $formattedFinalPrice, $detailCalculation);

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        return FinalPrice::compose($finalPrice, $formattedFinalPrice, $isVat, $detailCalculation);
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
        $stocks = App::make('warehouses');
        /**
         * @var Stock
         */
        $stockMsk = $stocks->firstWhere('code', 'msk');

        /* Пересчитываем НДС */

        if ($this->dealer instanceof Dealer && !$this->dealer->country->is($stockMsk->country)) {
            $withNds = bcmul(
                $finalPrice,
                (string) (1 + static::ORDINARY_NDS / 100),
                static::PROCESSUAL_ACCURACY
            );
        } else {
            $withNds = $finalPrice;
        }

        /* Применяем правило 1,5% */
        if ($isPriceTo) {
            $withAddition = bcmul($withNds, (string)(1 + static::CURRENCY_EXCHANGE_ADDITION));
        } else {
            $withAddition = $withNds;
        }
        /* Конвертируем в рубли */
        if (!$this->currency->is($this->rouble)) {
            if (config('common.pricing.useOwnRates', false)) {
                $internalRate = null;

                if (array_key_exists($this->currency->letter_code, $this->ownRates)) {
                    /* Из тех валют, которые есть в rate_own, в рубли всегда по внутреннему курсу */
                    $crossRate = $this->ownRates[$this->currency->letter_code];
                } else {
                    /* Из других валют в рубли — сначала в промежуточную валюту по кросс-курсу, затем в рубли по внутреннему курсу */
                    $basicRate = $this->rates[$this->currency->letter_code];
                    $intermediateRate = $this->rates[$this->intermediateCurrency->letter_code];
                    $crossRate = bcdiv((string) $basicRate, (string) $intermediateRate, static::PROCESSUAL_ACCURACY);
                    $internalRate = $this->ownRates[$this->intermediateCurrency->letter_code];
                }
                $converted = bcmul($withAddition, $crossRate, static::PROCESSUAL_ACCURACY);
                if (!is_null($internalRate)) {
                    $converted = bcmul($converted, $internalRate, static::PROCESSUAL_ACCURACY);
                }
            } else {
                $converted = bcmul($withAddition, (string) $this->rates[$this->currency->letter_code], static::FINAL_ACCURACY);
            }
            /* Округление НДС для бухгалтерских документов */
            $converted = (round(((float)$converted / 1.2) / 0.05, 0, PHP_ROUND_HALF_UP) * 0.05) * 1.2;
            $converted = bcmul($converted, '1', static::FINAL_ACCURACY);
        } else {
            $converted = bcmul($withAddition, '1', static::FINAL_ACCURACY);
        }

        return $converted;
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
        if (!$this->dealer instanceof Dealer) {
            return false;
        }
        return true;
    }

    /**
     * Определяет, требуется ли конвертация
     *
     * @param Currency $currency Базовая валюта
     * @return boolean
     */
    protected function conversionNeeded(Currency $currency): bool
    {
        return !$this->currency->is($currency);
    }

    /**
     * Определяет дилера
     *
     * @param Dealer|null $dealer
     * @return Dealer|null
     */
    protected function resolveDealer(?Dealer $dealer): ?Dealer
    {
        if ($dealer instanceof Dealer) {
            return $dealer;
        }
        if ($this->user instanceof User) {
            return $this->user->data->dealer;
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
        if ($legalEntity instanceof LegalEntity) {
            return $legalEntity;
        }
        if ($this->user instanceof User) {
            return ($this->user->data->selectedLegalEntity !== null) ?
                $this->user->data->selectedLegalEntity :
                Pseudo::compose(['nds' => static::ORDINARY_NDS, 'addpercent' => 1.5]);
        } else {
            return Pseudo::compose(['nds' => static::ORDINARY_NDS, 'addpercent' => 1.5]);
        }
    }
}
