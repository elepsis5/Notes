<?php

namespace App\Models;

use App\Classes\Compozite\Model;
use App\Classes\Compozite\ModelStorage;
use App\Classes\Compozite\Query;
use App\Classes\Discounts\DiscountStorage;
use App\EloquentModels\User;
use App\ModelQueries\DiscountQuery;
use Illuminate\Support\Facades\App;

/**
 * Скидка
 *
 * @property int $priority Приоритет скидки
 * @property string $dealer_code Код дилера (поставщика)
 * @property int $section_link (Связка) Категория
 * @property string $categories Путь категорий
 * @property int $brand_link (Связка) Бренд
 * @property string $price_list_code Код прайс-листа
 * @property string $value Значение скидки
 * @property int $base Является ли скидка базовой
 * @property int $active Значение используется (активно)
 * @property-read mixed $date_create Дата создания
 * @property-read mixed $date_update Дата обновления
 *
 * @property-read Section|null $section Связь с моделью Section: многие->один
 * @property-read Brand|null $brand Связь с моделью Brand: многие->один
 * @property-read PriceList|null $price_list Связь с моделью PriceList: многие->один
 * @property-read Dealer|null $dealer Связь с моделью Dealer: многие->один
 */

class Discount extends Model
{
    protected static $entityName = "discount";

    protected static array $calculatedFields = [
        'description' => 'getDescription'
    ];

    /**
     * Приоритет скидки 1С
    */
    public const PRIORITY_1C =  1;

    /**
     * Приоритет скидки портала
    */
    public const PRIORITY_PORTAL =  2;

    /**
     * @inheritDoc
     *
     * @return DiscountQuery
     */
    public static function query(): DiscountQuery
    {
        return new DiscountQuery(static::class);
    }

    /**
     * Возвращает набор применимых для товаров скидок
     *
     * @param ModelStorage $products
     * @return DiscountStorage<int, Product>
     */
    public static function getAppliableForProducts(ModelStorage $products, ?Dealer $dealer = null): DiscountStorage
    {
        $products->expectModelClassIs(Product::class);
        if($products->isEmpty()) {
            return DiscountStorage::empty(static::class);
        }
        $user = user();
        if(!$user instanceof User) {
            return DiscountStorage::empty(static::class);
        }
        if (getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS') == 0) {
            return DiscountStorage::empty(static::class);
        }
        $language = App::make(Language::class);
        $brandUids = $products->pluck('brand_link')->unique()->all();
        $fullBrandUids = array_merge($brandUids, [0]);
        $dbSectionsPaths = $products->pluck('categories')->unique()->all();
        $catalogUids = $products->pluck('catalog_num')->unique()->all();
        $sectionsPaths = [];
        foreach($dbSectionsPaths as $dbSectionsPath) {
            if(empty($dbSectionsPath)) {
                continue;
            }
            $parts = explode('/', $dbSectionsPath);
            $path = '/';
            foreach($parts as $part) {
                if($part === '') {
                    continue;
                }
                $path .= $part.'/';
                $sectionsPaths[$path] = $path;
            }
        }

        if(is_null($dealer)) {
            /**
             * @var Dealer
             */
            $dealer = user()->data->dealer;
        }
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        $discounts = static::query()->dealer($dealer)
                                    ->translate($language)
                                    ->whereIn('brand_link', $fullBrandUids)
                                    ->nestedWhere(
                                        function (Query $query) use ($catalogUids) {
                                            $query->whereIn('catalog_num', $catalogUids)
                                                    ->orWhereNull('catalog_num');
                                        }
                                    )
                                    ->with(['price_list', 'section', 'brand'])
                                    ->whereIn('categories', $sectionsPaths)
                                    ->orderBy('priority', 'asc')
                                    ->orderBy('base', 'asc')
                                    ->where(['active', 1])
                                    ->get();

        stackStore('discounts', $discounts);

        return DiscountStorage::from($discounts);
    }


    /**
     * Собирает описание скидки
     *
     * @return string
     */
    public function getDescription(): string
    {
        $description = ucfirst(__('interface.PERSONAL_DISCOUNT')) . ' ';

        if($this->price_list_code)
        {
            $description .= htmlspecialchars(__('interface.BY_PRICERETAIL_LIST')).' ';
        }
        if($this->catalog_num == 2 and is_null($this->brand) && $this->brand instanceof Brand)
        {
            return $description.__('interface.ON_PARTS');
        }
        if($this->catalog_num == 2 and !is_null($this->brand) && $this->brand instanceof Brand)
        {
            return $description.__('interface.ON_BRAND_PARTS').' '.$this->brand->name;
        }
        if(($this->brand_link !== 0) && $this->brand instanceof Brand and ($this->section_link !== 0) && $this->section instanceof Section)
        {            
            $description =  $description.__('interface.ALL_PRODUCTS_BRAND').' '.$this->brand->name.' '.__('interface.CATEGORY').' '.$this->section->name .' '.$this->value.'% ';
            return $description;
        }
        if(($this->brand_link !== 0) && $this->brand instanceof Brand){
            return $description.__('interface.ALL_PRODUCTS_BRAND').' '.$this->brand->name;
        }
        if(($this->section_link !== 0) && $this->section instanceof Section){
            return $description.__('interface.ON_PRODUCTS_CATEGORY').' '.$this->section->name;
        }
        if($this->brand_link == 0 && $this->section_link == 0) {
            return $description.__('interface.ALL_ASSORTIMENT');
        }

        return '';
    }
}
