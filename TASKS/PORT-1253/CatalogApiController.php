<?php

namespace App\Http\Controllers;

use App\Classes\ApiAccess\Response;
use App\Classes\ChangeOrder\ChangeOrder;
use App\Classes\Common\MagicClass;
use App\Classes\Compozite\DataStamp;
use App\Classes\Compozite\Model;
use App\Classes\Compozite\ModelStorage;
use App\Classes\Compozite\Query;
use App\Classes\Date\Date;
use App\Classes\Discounts\DiscountStorage;
use App\Classes\Filer\Filer;
use App\Classes\Pricing\Contracts\ProcessPrices;
use App\Models\Availability;
use App\Models\CharValue;
use App\Models\Country;
use App\Models\FiltersData;
use App\Models\Product;
use App\Models\Language;
use App\Models\SearchHint;
use App\Models\SectionFilter;
use App\Models\Zone;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Stock;
use App\Models\Supply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use stdClass;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Classes\Pricing\FinalPrice;
use App\Classes\Utilities\Timer;
use App\EloquentModels\User;
use App\Models\Brand;
use App\Models\Discount;
use App\Models\Section;
use Illuminate\Support\Facades\Cache;
use App\Models\Char;
use App\Models\ProductFile;
use Carbon\Exceptions\InvalidFormatException;

class CatalogApiController extends Controller
{
    /**
     * Элементов на странице по умолчанию
     */
    protected const PER_PAGE = 10;
    /**
     * Сортировка по умолчанию
     */
    protected const DEFAULT_SORT = 'popularity';
    /**
     * Возможные варианты порядка сортировки
     */
    protected const SORT_ORDERS = ['asc', 'desc', 'ASC', 'DESC'];
    /**
     * Все каталоги товаров
     */
    public const CATALOGS_FULL = [1, 2, 3];
    /**
     * Каталог ЗИП
     */
    public const CATALOGS_ZIP = [2];
    /**
     * Каталоги товаров по умолчанию
     */
    public const DEFAULT_CATALOGS = [1, 3];
    /**
     * Значение параметра наличия: сейчас на складе
     */
    protected const AVAILABILITY_NOW = 'in_stock';
    /**
     * Значение параметра наличия: к дате
     */
    protected const AVAILABILITY_TO_DATE = 'to_date';
    /**
     * Значение параметра наличия: безразлично
     */
    protected const AVAILABILITY_ALL = 'all';
    /**
     * Номер характеристики "Страна"
     */
    protected const COUNTRY_CHAR = 10;
    /**
     * Варианты количества продуктов на странице
     */
    protected const MAX_PERPAGE = 100;
    /**
     * Варианты количества продуктов на странице
     */
    protected const DEFAULT_PERPAGE = 10;
    /**
     * Каталог запчастей
     */
    protected const CATALOG_PARTS = 2;
    /**
     * Промо склад(Екатеринбург)
     */
    protected const PROMO_STOCK_LINK = 2;
    /**
     * Промо склад(Екатеринбург)
     */
    protected const PROMO_BRAND_LINKS = [60, 112, 122, 149, 151, 153, 591, 592, 593];
    /**
     * Прайс-лист "Не установлен"
     */
    protected const PRICE_LIST_NOT_SET = 14;
    /**
     * Прайс-лист "Не установлен"
     */
    protected const UAE_REGIONS = [
        'SHOW_PRODUCTS_FOR_SAR' => 'sar',
        'SHOW_PRODUCTS_EXCEPT_SAR' => 'uae'
    ];
    /**
     * Собирает коллекцию товаров на основе заданных параметров
     *
     * @param array $parameters
     * @return DataStamp {ModelStorage $products, array $meta}
     */
    public static function collectProducts(array $parameters): DataStamp
    {
        /* Разбор параметров */
        $pageNumber = array_see('page', $parameters, 1);
        $perPage = array_see('per_page', $parameters, static::PER_PAGE);
        $sort = array_see('sort', $parameters, static::DEFAULT_SORT);
        setIfNull($sort, static::DEFAULT_SORT);

        $is_accessories = array_see('accessories', $parameters);
        $is_change_order = array_see('change_order', $parameters);
        $is_equipcad = array_see('equipcad', $parameters);
        $btn_obj_data = array_see('btn_obj_data', $parameters);

        $activeWarehouses = getActiveWarehouses();
        $promo = array_see('promo', $parameters);
        $activeWarehousesIds = app('warehouses')->whereIn('code', $activeWarehouses)->pluck('uid')->all();

        // [equipcad] - склад только Родники
        if ($is_equipcad === '1') {
            $activeWarehouses = ['msk'];
        }


        /*
         * Отбираем все выбранные склады плюс распродажные по зоне продаж
         */
        $currentStocksQuery = Stock::queryUsingZone()->nestedWhere(function (Query $query) use ($activeWarehouses, $activeWarehousesIds) {
            $query->whereIn('parent_stock', $activeWarehousesIds)
                    ->orWhereIn('code', $activeWarehouses);
        });
        $dbStocks = $currentStocksQuery->get();


        /* Сбор запроса */
        $productsCodes = static::makeProductsQuery($parameters, $dbStocks);
        $productsPaginator = $productsCodes->paginate($perPage);
        $chunkFiltersData = $productsPaginator->getPage($pageNumber);
        $products = static::processProducts($chunkFiltersData, $dbStocks, ($is_accessories === '1'), ($is_change_order === '1'), $btn_obj_data);
        $meta = array(
            'page' => $pageNumber,
            'pages' => $productsPaginator->getPagesCount(),
            'perpage' => $perPage,
            'total' => $productsPaginator->getTotalItems(),
            'sort' => 'asc',
            'field' => $sort
        );

        return DataStamp::use('main.extended_products')->compose([
            'products' => $products,
            'meta' => $meta
        ]);
    }

    /**
     * Собирает основной запрос для отбора товаров
     *
     * @param User $user
     * @param array $parameters
     * @param ModelStorage $dbStocks
     * @return Query
     */
    public static function makeProductsQuery(array $parameters, ModelStorage $dbStocks): Query
    {
        /*
            Метод выделен из collectProducts() путём копирования и минимальной чистки явно ненужного.
            Сохраняется частичное дублирование разбора параметров.
            TODO: требуется сделать полноценный рефакторинг этих методов, чтобы их зоны ответственности не пересекались
         */
        $user = user();
        $sort = array_see('sort', $parameters, static::DEFAULT_SORT);
        setIfNull($sort, static::DEFAULT_SORT);
        $catalog = (string)array_see('catalog', $parameters);
        $catalog_ids = [];
        switch($catalog) {
            case '1': $catalog_ids = [1, 3];
            break;
            case '2': $catalog_ids = [(int)$catalog];
            break;
            case '0': $catalog_ids = static::CATALOGS_FULL;
            break;
            default: $catalog_ids = static::DEFAULT_CATALOGS;
        }

        $filter = array_see('search_catalog', $parameters, array_see('search', $parameters));

        // TODO: Временное решение проблемы символов Юникода, требуется заменить на полноценное
        // TEMPFIX: Создано в связи с ошибкой [717693B1]
        if (is_string($filter)) {
            $filter = normalizeTextEncoding(removeUnicodeSymbols($filter));
        }
        $sections = array_see('sections', $parameters);
        $sections = unpackIfJsonArray($sections);
        if (is_array($sections)) {
            $sections = makeNumeralArray($sections);
        } else {
            $sections = null;
        }
        $promo = array_see('promo', $parameters);
        $countries = array_see('countries', $parameters);
        $countries = unpackIfJsonArray($countries);
        $brands = array_see('brands', $parameters);
        $ids = array_see('ids', $parameters);

        $is_accessories = array_see('accessories', $parameters);
        $is_change_order = array_see('change_order', $parameters);
        $is_equipcad = array_see('equipcad', $parameters);
        $availability = array_see('availability', $parameters, 'all');
        $brands = unpackIfJsonArray($brands);
        if($brands !== null)
        {
            $brands = Brand::whereIn('name', $brands)->get()->pluck('uid')->all();
        }
        // $availability = array_see('availability', $parameters, 'all');
        $availabilityDate = array_see('availability_date', $parameters);

        $state = array_see('state', $parameters, 'all');

        $minPrice = array_see('price_min', $parameters);
        $maxPrice = array_see('price_max', $parameters);
        $priceStrict = array_see('price_strict', $parameters, 0);

        if(((int)$minPrice == 0 && (int)$priceStrict == 0)|| !($user instanceof User))
        {
            $minPrice = null;
        }
        if(((int)$maxPrice == 0) || !($user instanceof User))
        {
            $maxPrice = null;
        }

        $language = App::make(Language::class);
        $lang_code = $language->code;
        /**
         * @var Zone
         */
        $zone = App::make(Zone::class);

        if ($state == 'new') {
            $currentStocks = $dbStocks->where('virtual', 0)->getUids();
            $currentStocksCodes = $dbStocks->where('virtual', 0)->pluck('code')->toArray();
        } elseif ($state == 'sale') {
            $currentStocks = $dbStocks->where('virtual', 1)->getUids();
            $currentStocksCodes = $dbStocks->where('virtual', 1)->pluck('code')->toArray();
        } else {
            $currentStocks = $dbStocks->getUids();
            $currentStocksCodes = $dbStocks->pluck('code')->toArray();
        }
        $mainStocksCodes = $dbStocks->where('virtual', 0)->pluck('code')->unique()->all();

        $priceList = null;
        $priceListId = getPriceList();
        if ($priceListId !== 0 && getUserSettingValue('SHOW_RETAIL_PRICES') !== '1' && $priceListId !== static::PRICE_LIST_NOT_SET) {
            $priceList = PriceList::find($priceListId);
            $priceListRub = PriceList::find($priceList->relate_to_rub);
        } else {
            $priceListRub = PriceList::find(PriceList::RETAIL_RUB);
        }

        // [accessories | change_order | equipcad] - каталоги дополняются ЗИП
        if ($is_accessories === '1' || $is_change_order === '1' || $is_equipcad === '1') {
            $catalog_ids = [1, 2, 3];
        }

        /**
         * @var ProcessPrices
         */
        $minPriceForDb = null;
        $maxPriceForDb = null;
        if ($user instanceof User) {
            $priceProcessor = App::make(ProcessPrices::class);
            if($minPrice !== null && $minPrice != 0 && $minPrice !== '') {
                $minPriceForDb = $priceProcessor->finalPrice2BasePrice($minPrice);
            }
            if($maxPrice !== null && $maxPrice !== 0 && $maxPrice !== '')
            {
                $maxPriceForDb = $priceProcessor->finalPrice2BasePrice($maxPrice);
            }
        }
        $priceFiltering = (int)$minPriceForDb !== 0 || (int)$maxPriceForDb !== 0 || $priceStrict;

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        $mainStocksPricesCastingExpression = CatalogApiController::prepareStockPricesCastingExpression($mainStocksCodes, $priceListRub->code);

        $isSupplier = $user instanceof User && $user->refersToGroup('supplier');

        $filtersDataQuery = FiltersData::query()
            ->selectCoreFields()
            ->apply('accessDetail', $user)
            ->where([['active', '=', 1], ['sync', '=', 1]])
            ->when(!$isSupplier && $zone->isReal(), function($query) use ($zone) {
                $query->where(['zones', 'like', '%'.$zone->uid.'%']);
            })
            ->whereIn('catalog_num', $catalog_ids)
            ->when(($filter !== null), function (Query $query) use ($filter, $lang_code) {
                $words = explode(' ', $filter);
                $hints = SearchHint::whereIn('from', $words)->get();
                if (count($hints)>0) {
                    foreach ($hints as $hint) {
                        $replaceWords[$hint->from] = $hint->to;
                        $filter = str_replace($hint->from, $hint->to, $filter);
                    }
                }
                $words = explode(' ', $filter);
                foreach($words as &$word) {
                    $word = Str::replace(['"', '\''], '\\\\"', $word);
                    $word = Str::replace('/', '\\\\/', $word);
                }
                $preparedFilter = '%"%'.implode('%', $words).'%"%';
                $codeFilter = is_numeric($filter) ? (int)$filter : 0;
                $searchField = $lang_code === 'en' ? 'search_en' : 'search_ru';
                $query->nestedWhere(function (Query $query) use ($preparedFilter, $codeFilter, $searchField) {
                    $query->where([$searchField, 'like', $preparedFilter])
                        ->when($codeFilter > 0, function (Query $query) use ($codeFilter, $searchField) {
                            $query->orWhere([$searchField, 'like', "%$codeFilter%"]);
                        });
                });
            })
            ->when(($sections !== null), function (Query $query) use (&$sections) {
                $query->where(['categories','rlike','/'.implode('/|/',$sections).'/']);
            })
            ->when(($countries !== null), function (Query $query) use ($countries) {
                $query->whereIn('country', $countries);
            })
            ->when(($brands !== null), function (Query $query) use ($brands) {
                $query->whereIn('brand_link', $brands);
            })
            ->when(($ids !== null && $is_accessories === '1'), function (Query $query) use ($ids) {
                $query->whereIn('product_id', $ids);
            })
            ->when(($state === 'sale'), function (Query $query) use ($currentStocksCodes) {
                $preparedFilter = implode('|', $currentStocksCodes);
                $query->nestedWhere(function (Query $query) use ($preparedFilter) {
                    $query->where(['availability', 'rlike', "$preparedFilter"]);
                });
            })
            ->when(($state !== 'sale'), function (Query $query) use ($availability, $currentStocksCodes, $availabilityDate, $lang_code) {
                switch ($availability) {
                    case static::AVAILABILITY_ALL: break;
                    case static::AVAILABILITY_NOW:
                        $preparedFilter = implode('|', $currentStocksCodes);
                        $query->nestedWhere(function (Query $query) use ($preparedFilter) {
                            $query->where(['availability', 'rlike', "$preparedFilter"]);
                        });
                        break;
                    case static::AVAILABILITY_TO_DATE:
                        try{
                            $availabilityDate = Carbon::parse($availabilityDate)->format('Y-m-d');
                        } catch(InvalidFormatException $exception) {
                            $availabilityDate = null;
                        }
                        $preparedFilter = implode('|', $currentStocksCodes);
                        $query->nestedWhere(function (Query $query) use ($preparedFilter, $availabilityDate, $currentStocksCodes) {
                            $query->where(['availability', 'rlike', "$preparedFilter"])
                                    ->when($availabilityDate !== null, function (Query $query) use ($currentStocksCodes, $availabilityDate) {
                                        foreach ($currentStocksCodes as $code) {
                                            $query->orWhere(['commercial->supplies->' . $code, '<=', $availabilityDate]);
                                            // $query->orWhere(['commercial->supplies_all->' . $code, '->general<=', $availabilityDate]);
                                        }
                                    });
                        });
                        break;
                    default:
                        $preparedFilter = implode('|', $currentStocksCodes);
                        $query->nestedWhere(function (Query $query) use ($preparedFilter) {
                            $query->where(['availability', 'rlike', "$preparedFilter"]);
                        });
                }
            })
            ->when($zone->code1c === 'ec', function (Query $query) use ($catalog_ids, $currentStocksCodes) {
                $preparedFilter = implode('|', $currentStocksCodes);
                $query->when($catalog_ids || is_array($catalog_ids), function (Query $query) use ($catalog_ids, $preparedFilter) {
                    $query->when(in_array(static::CATALOG_PARTS, $catalog_ids), function (Query $query) use ($catalog_ids, $preparedFilter) {
                        $partsCatalogPosition = array_search(static::CATALOG_PARTS, $catalog_ids);
                        unset($catalog_ids[$partsCatalogPosition]);
                        $query->nestedWhere(function (Query $query) use ($catalog_ids, $preparedFilter) {
                            $query->whereIn('catalog_num', $catalog_ids)
                                ->orNestedWhere(function (Query $query) use ($preparedFilter) {
                                    $query->where(['catalog_num', static::CATALOG_PARTS])
                                        ->where(['availability', 'rlike', "$preparedFilter"]);
                                });
                        });
                    });
                });
            })
            ->when($zone->code1c !== 'ec', function (Query $query) use ($catalog_ids) {
                $query->whereIn('catalog_num', $catalog_ids);
            })
            ->when($zone->code1c === 'uae', function (Query $query) {
                $settings = [];
                foreach(static::UAE_REGIONS as $setting => $region)
                {
                    $settingValue = (int) getUserSettingValue($setting);
                    if($settingValue == 1) {
                        $settings[] = $region;
                    }
                }
                if($settings === [])
                {
                    $query->stop();
                    return;
                }
                $query->nestedWhere(function (Query $query) use ($settings) {
                    foreach($settings as $region) {
                        $query->orWhere(['uae_regions', 'like', '%'.$region.'%']);
                    }
                });
            })
            ->when($priceFiltering, function (Query $query) use ($mainStocksPricesCastingExpression) {
                $query->getBuilder()->whereRaw($mainStocksPricesCastingExpression.' != "'.Str::repeat('Z', 22).'"');
            })
            ->when((!in_array($minPriceForDb, [null, 0])), function (Query $query) use ($minPriceForDb, $mainStocksPricesCastingExpression) {
                $query->getBuilder()->whereRaw($mainStocksPricesCastingExpression.'>='.'"'.CatalogApiController::preparePriceValueForFilter($minPriceForDb).'"');
            })
            ->when((!in_array($maxPriceForDb, [null, 0])), function (Query $query) use ($maxPriceForDb, $mainStocksPricesCastingExpression) {
                $query->getBuilder()->whereRaw($mainStocksPricesCastingExpression.'<='.'"'.CatalogApiController::preparePriceValueForFilter($maxPriceForDb).'"');
            });

        /* Расширенный фильтр */
        $extendedFilterValues = static::getExtendedFilterValues($parameters);
        if (count($extendedFilterValues->filters) != 0) {
            static::applyExtendedFilter($filtersDataQuery, $extendedFilterValues, $lang_code);
        }
        $priceListCode = $priceListRub ? $priceListRub->code : 'notset';
        $productsQuery = static::addSort($filtersDataQuery, $sort, $currentStocksCodes, $lang_code, $priceListCode);

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        return $filtersDataQuery;
    }

    /**
     * Возвращает коллекцию товаров по параметрам, переданным в запросе
     *
     * Route: [POST] /catalog/get_products/
     * Route: [POST] /zip/get_products/
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apiGetProducts(Request $request): JsonResponse
    {
        $is_change_order = (int)$request->input('change_order', 0);
        $is_change_order_accessories = (int)$request->input('accessories', 0);

        $group_id = $request->input('groupId');
        $btn_obj_data = ($is_change_order) ? new ChangeOrder($group_id) : basket();

        $accessories_ids = [];
        $relate_for = [];

        if ($is_change_order_accessories) {
            $items_code1c = $btn_obj_data->getItems()->pluck('code1c')->unique()->all();

            $products_for = Product::whereIn('code1c', $items_code1c)
                ->access()
                ->get();

            $accessories = DB::table('pr_relations')
                ->whereIn('relate_from', $items_code1c)
                ->where('type', 'accessories')
                ->get();

            $accessories_codes = $accessories->pluck('relate_to')->all();

            $products = Product::whereIn('code1c', $accessories_codes)->get();
            $accessories_ids = $products->pluck('uid')->all();

            foreach ($accessories as $accessory) {
                $relate_for[$accessory->relate_to] = $products_for->where('code1c', $accessory->relate_from)->first();
            }
        }

        /* В текущем варианте имена параметров приспособлены под KTDatatables,
            при необходимости их можно поменять */

        $params = $request->all();

        if (($page = array_see('pagination.page', $params)) !== null && is_numeric($page)) {
            $params['page'] = (int) $page;
        }
        if (($currentPage = array_see('page', $request->all())) !== null && is_numeric($currentPage)) {
            $params['page'] = (int) $currentPage;
        }
        if (($perpage = array_see('pagination.perpage', $params)) !== null) {
            $perpage = (int)$perpage;
            if(is_int($perpage) && ($perpage > static::DEFAULT_PERPAGE) && $perpage <= static::MAX_PERPAGE)
            {
                $params['per_page'] = $perpage;
            }
        } else {
            $params['per_page'] = static::DEFAULT_PERPAGE;
        }

        if (($sortField = array_see('sort.field', $params)) !== null) {
            $params['sort_field'] = $sortField;
        }
        if (($sortOrder = array_see('sort.sort', $params)) !== null) {
            $params['sort_order'] = $sortOrder;
        }
        if (($filter = array_see('query.generalSearch', $params)) !== null) {
            $params['search_catalog'] = $filter;
        }

        if ($is_change_order_accessories) {
            $params['ids'] = $accessories_ids;
        }

        $params['btn_obj_data'] = $btn_obj_data;
        // dd($btn_obj_data);

        $products = static::collectProducts($params);
        $productsArray = array_map(function (Model $item) {
            return $item->getData();
        }, $products->products->allModels());

        return response()->json(['data' => $productsArray, 'meta' => $products->meta]);
    }

    /**
     * Возвращает данные о товарных предложениях по товару
     *
     * Route: [POST] /catalog/get_offers/
     * Route: [POST] /zip/get_offers/
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apiGetCommodityOffers(Request $request)
    {
        $productUid = (int)($request->input('product_id', 0));

        $product = Product::findOrFail($productUid);
        $language = App::make(Language::class);
        $zone = App::make(Zone::class);


        $data = $this->collectCurrentStocks($request, $language, $zone, $product);

        if (($perPage = array_see('pagination.perpage', $request->all())) === null) {
            $perPage = 10;
        }

        $meta = array(
            'page' => 1,
            'pages' => ceil($data['actualStocksCount'] / $perPage),
            'perpage' => $perPage,
            'total' => $data['actualStocksCount'],
            'sort' => 'asc',
            'field' => 'uid'
        );

        return Response::success(['data' => $data['stocksArray'], 'meta' => $meta]);
    }

    /**
     * Собирает данные о текущих складах для товара
     *
     * @param Request $request
     * @param Language $language
     * @param Zone $zone
     * @param Product $product
     * @return array
     */
    public static function collectCurrentStocks(Request $request, Language $language, Zone $zone, Product $product): array
    {
        $state = $request->input('state', 'all');
        setIfNull($state, 'all');
        $state = removeUnicodeSymbols($state);
        $is_change_order = (int)$request->input('change_order', 0);
        $group_id = $request->input('groupId');
        $btn_obj_data = ($is_change_order) ? new ChangeOrder($group_id) : basket();
        $activeWarehouses = getActiveWarehouses();
        $promo = array_see('promo', $request->all());
        $activeWarehousesIds = app('warehouses')->whereIn('code', $activeWarehouses)->pluck('uid')->all();

        if ($state !== 'sale') {
            $currentStocks = Stock::queryUsingZone()->select(['name', 'code', 'virtual', 'active', 'sort', 'parent_stock', 'direct_delivery', 'geo_lat', 'geo_lon'])
                ->translate($language)
                ->onlySelected($activeWarehouses)
                ->active()
                ->with(['parent', 'children'])
                ->when(
                    ($state === 'all'),
                    function ($query) use ($activeWarehousesIds) {
                        // $query->anyType($zone); // Отключено временно, с учётом новой логики получения распродажи (только для выбранных складов)
                        $query->where(['virtual', '=', 0])
                                ->orNestedWhere(function (Query $query) use ($activeWarehousesIds) {
                                    $query->where(['virtual', '=', 1])
                                            ->whereIn('parent_stock', $activeWarehousesIds);
                                });
                    }
                )->when(
                    ($state === 'new'),
                    function ($query) {
                        $query->real();
                    }
                )
                ->get();
        } else {
            $currentStocks = Stock::queryUsingZone()->select(['name', 'code', 'virtual', 'active', 'sort', 'parent_stock', 'direct_delivery', 'geo_lat', 'geo_lon'])
                ->translate($language)
                ->with(['parent', 'children'])
                ->byZone($zone)
                ->whereIn('parent_stock', $activeWarehousesIds) // Добавлено временно, с учётом новой логики получения распродажи (только для выбранных складов)
                ->active()
                ->virtual()
                ->get();
        }
        $stockUids = $currentStocks->getUids();
        $offers = $product->relation('availabilities')
            ->stock($stockUids)
            ->translate($language)
            ->get();

        if(getUserSettingValue('SHOW_RETAIL_PRICES') == '1') {
            $prices = Price::getRetailForProducts(ModelStorage::fromModel($product), $stockUids, ['date_update', 'desc']);
        } else {
            $prices = Price::getForProducts(ModelStorage::fromModel($product), getPriceList(), $stockUids, ['date_update', 'desc']);
        }

        $prices->setModelProcessor(function () {
            $this->calculate('finalPrice', 'originalFinalPrice');
        });
        $supplies = Supply::query()
            ->stock($stockUids)
            ->product($product->uid)
            ->actual()
            ->orderBy('supply_date', 'asc')
            ->get();
        $allQuantity = $offers->sum('quantity_free');

        /* Убрать из списка складов те виртуальные, по которым нет предложений */
        $virtualStocks = $currentStocks->where('virtual', 1);
        $realStocks = $currentStocks->where('virtual', 0);
        $saleOffersStocks = $offers->whereIn('stock_link', $virtualStocks->getUids())->where('quantity_free', '>', 0)->pluck('stock_link')->all();
        $actualStockUids = array_merge($realStocks->getUids(), $saleOffersStocks);
        $actualStocks = $currentStocks->whereIn('uid', $actualStockUids);

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        /* Добавляем дополнительные поля */
        $actualStocks->setModelProcessor(
            function () use ($supplies, $offers, $product, $allQuantity, $prices, $is_change_order, $btn_obj_data, $request) {
                $stockSupplies = $supplies->where('stock_link', $this->uid)->transform(function($item) {
                    $item->quantityAvailable = (int)$item->quantity_free + (int)$item->quantity_unpaid_reserve;
                    return $item;
                });
                stackStore('this', $this);
                $this->promo = false;
                $this->product_id = $product->uid;
                if ($this->virtual === 0) {
                    $this->state = __('interface.CONST__NEW');
                    $this->stateSign = 'new';
                } else {
                    $this->state = str_replace($this->parent->name, '', $this->name);
                    $this->stateSign = 'sale';
                }

                if ($this->hasParent()) {
                    $this->title = $this->parent->name;
                } else {
                    $this->title = $this->name;
                }
                $this->price_request = $product->price_request;
                $this->canBeOrdered = false;
                $this->calculate('hasCoordinates');

                $this->onStockFree = 0;
                $this->onStockUnpaid = 0;

                $offer = $offers->firstWhere('stock_link', $this->uid);
                if (($offer instanceof Availability)) {
                    $this->canBeOrdered = true;
                    $this->onStockFree = $offer->quantity_free;
                    $this->onStockUnpaid = $offer->quantity_unpaid_reserve;
                }
                if ($stockSupplies->firstWhere('quantityAvailable', '>', 0) !== null) {
                    $this->canBeOrdered = true;
                    $supplies = [];
                    foreach ($stockSupplies as $key => $supply) {
                        if ($supply->quantityAvailable > 0) {
                            $supplies[$key]['date'] = Date::convertFromDb('Y-m-d', $supply->supply_date, 'digital');
                            $supplies[$key]['free'] = $supply->quantity_free;
                            $supplies[$key]['unpaid'] = $supply->quantity_unpaid_reserve;
                        }
                    }
                    $this->stockSupplies = array_values($supplies);
                }

                $this->prepareField('deliveryTime', function () use ($product) {
                    if (($product->delivery_time !== null) && ($product->delivery_time != '0')) {
                        return $product->delivery_time;
                    } else {
                        return 'not_defined';
                    }
                });
                $this->allQuantity = $allQuantity;
                $this->hasDirectDelivery = $allQuantity;

                if (user()) {
                    $this->legalEntity = user()->data->selectedLegalEntity;
                } else {
                    $this->legalEntity = null;
                }

                $this->detailPriceCalculation = '';
                $this->priceOnlyWithDiscount1cValue = '';
                $price = $prices->firstWhere('stock_link', $this->uid);
                $this->available_on_stock = ($this->onStockFree > 0) ? 1 : 0;
                $this->is_dishes = $product->isDishes() ? 1 : 0;
                if (($price instanceof Price) && ($price->finalPrice->getSuccessful())) {
                    /**
                     * @var FinalPrice
                     */
                    $this->has_price = 1;
                    $finalPrice = $price->getFinalPriceWithFlags(null, null, false, $product->hasPromocode(), false);
                    $this->price = $finalPrice->getPriceFormatted();

                    if (user()->hasPermission('price_calculation_view')) {
                        $this->detailPriceCalculation = $finalPrice->getDetailCalculation();
                    }
                    $priceBase =  $price->getFinalPriceWithFlags(null, null, true, $product->hasPromocode(), false);
                    $this->priceBase = $priceBase->getPriceFormatted();

                    $useDiscounts = getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS');
                    if($useDiscounts == 1) {
                        $this->priceWithDiscountsValue = $price->getFinalPriceWithFlags(null, null, false, false, true)->getPriceFormatted();
                        // dd($this->priceWithDiscountsValue);
                        $this->priceOnlyWithDiscount1cValue = $price->getFinalPriceWithFlags(null, null, false, true, false, false, true)->getPriceFormatted();
                        // dd($this->price, $this->priceWithDiscountsValue, $this->priceOnlyWithDiscount1cValue);
                        $this->priceWithDiscountsBaseValue = $price->getFinalPriceWithFlags(null, null, true, false, true)->getPriceFormatted();

                    } else {
                        $this->priceWithDiscountsValue = $price->getFinalPriceWithFlags(null, null, false, false, false)->getPriceFormatted();
                        $this->priceWithDiscountsBaseValue = $price->getFinalPriceWithFlags(null, null, true, false, false)->getPriceFormatted();
                    }
                    $this->originalFinalPriceValue = $priceBase->getPrice();
                    $this->isVat = $finalPrice->getVatIncluded();
                    $this->hasPrice = true;
                    stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
                } else {
                    $this->has_price = 0;
                    $this->price = __('interface.NO_INFORMATION');
                    $this->priceBase = __('interface.NO_INFORMATION');
                    $this->priceWithDiscountsValue = '';
                    $this->priceWithDiscountsBaseValue = '';
                    $this->isVat = false;
                    $this->hasPrice = false;
                }
                $useDiscounts = getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS');
                $promocode = $product->getPromocode();
                $this->discount_portal = $product->discount_portal;
                $this->discount_1c = $product->discount_1c;
                $this->promocode = ($promocode && $useDiscounts && $this->has_price == 1 && (!$product->discount_portal or ($product->discount_portal instanceof Discount && $promocode->isBetterThan($this->discount_portal)) )) ? $promocode : null;

                // dd($this->discount_1c->getDescription().$this->discount_portal->getDescription());
                $this->promo_tooltip_description='';
                if($this->promocode )
                {
                    if( $product->discount_1c instanceof Discount ){
                        $discount1C = $product->discount_1c;
                        if($discount1C->section instanceof Section or $discount1C->brand instanceof Brand)
                        {
                            $description = __('interface.ON_PRODUCT');
                            $sectionName = $discount1C->section instanceof Section ? $discount1C->section->name : '';
                            $brandName = $discount1C->brand instanceof Brand ? $discount1C->brand->name : '';
                            $description = $sectionName.$brandName;
                        }else{
                            $description = __('interface.ALL_ASSORTIMENT');
                        }

                        $this->promo_tooltip_description = $discount1C->value.'% '.$description.'<br>';
                        $this->promo_tooltip_description .= $this->promocode->value.'% '.__('interface.BY_PROMOCODE').' '.$this->promocode->code;
                    }else{
                        $this->promo_tooltip_description .= $this->promocode->value.'% '.__('interface.BY_PROMOCODE').' '.$this->promocode->code ;
                    }
                }

                if ($this->price_request == 1) {
                    if ($this->is_dishes == 1) {
                        if ($this->available_on_stock == 0 || $this->has_price == 0) {
                            $this->price = __('interface.NO_INFORMATION');
                            $this->priceBase = __('interface.NO_INFORMATION');
                            $this->detailPriceCalculation = '';
                            $this->originalFinalPriceValue = '';
                            $this->priceWithDiscountsValue = '';
                            $this->priceWithDiscountsBaseValue = '';
                            $this->isVat = false;
                            $this->hasPrice = false;
                        }
                    } else {
                        $this->price = __('interface.NO_INFORMATION');
                        $this->priceBase = __('interface.NO_INFORMATION');
                        $this->detailPriceCalculation = '';
                        $this->originalFinalPriceValue = '';
                        $this->priceWithDiscountsValue = '';
                        $this->priceWithDiscountsBaseValue = '';
                        $this->isVat = false;
                        $this->hasPrice = false;
                    }
                }
                if ($this->virtual) {
                    $priceList = getPriceList();
                    $b_price = Price::query()
                        ->product($product->uid)
                        ->priceList($priceList)
                        ->stock([$this->parent_stock])
                        ->orderBy('date_update', 'desc')
                        ->first();

                    $this->priceNotSale = ($b_price instanceof Price) ? $b_price->getFinalPriceWithFlags(null, null, false, true, false)->getPriceFormatted() : __('interface.NO_INFORMATION'); //Цена распродажи
                    $this->priceBaseNotSale = ($b_price instanceof Price) ?  $b_price->getFinalPriceWithFlags(null, null, true, true, false)->getPriceFormatted() : __('interface.NO_INFORMATION'); //Цена распродажи
                }else{
                    $this->priceNotSale      = __('interface.NO_INFORMATION');
                    $this->priceBaseNotSale  = __('interface.NO_INFORMATION');
                }

                $this->product_id = $product->uid;
                $this->discount = $product->discounts->first();

                if ($is_change_order) {
                    $this->c_btn__render = c_change_order__btn($btn_obj_data, $product, $this->uid);
                } else {
                    $this->c_btn__render = c_basket__btn($btn_obj_data, $product, $this->uid, $request->input('here', '/'));
                }
                $price = $this->price;
                $priceBase = $this->priceBase;
                $priceNotSale = $this->priceNotSale;
                $priceBaseNotSale = $this->priceBaseNotSale;
                $priceWithDiscounts = '';
                $tooltipDiscounts = '';
                $discountDescription = '';

                $is_nds = '';
                if ($this->isVat) {
                    $is_nds =  __('interface.WITH_VAT');
                } else {
                    $is_nds =__('interface.WITHOUT_VAT');
                }


                $showDiscounts = (int)getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS');
                $tooltipNds = '';
                if ($price != __('interface.NO_INFORMATION')) {
                    $tooltipNdsText='';
                    if ($this->detailPriceCalculation !== '') {
                        $tooltipNdsText = $this->detailPriceCalculation;
                    } else {
                        $tooltipNdsText = __('interface.PRICE').': '.$price.' '.$is_nds.' <br>'.__('interface.PRICE_IN_CURRENCY').': '.$priceBase.' '.$is_nds;
                        if($showDiscounts == 1 && $this->priceWithDiscountsValue !== $price) {
                            $tooltipNdsText .= ' <br>'.__('interface.PRICE_DISCOUNT').': '.$this->priceWithDiscountsValue.' '.
                            $is_nds.' <br>'.__('interface.PRICE_DISCOUNT_IN_CURRENCY') .': '. $this->priceWithDiscountsBaseValue.' '.
                            $is_nds;
                        }
                    }
                    $tooltipNds = '<span class="label label-sm label-rounded align-middle ml-2" data-html="true" data-toggle="tooltip" title="'.$tooltipNdsText.'">?</span>';
                }
                $this->tooltip =  $tooltipNds;

                $tooltipNdsSale = '';
                if ($priceNotSale !=  __('interface.NO_INFORMATION')) {
                    $tooltipNdsSaleText='';
                    if ($this->detailPriceCalculation !== '') {
                        $tooltipNdsSaleText = $this->detailPriceCalculation;
                    } else {
                        $tooltipNdsSaleText =  __('interface.PRICE'). ': '.$priceNotSale.' '.$is_nds.' <br> '.__('interface.PRICE_IN_CURRENCY').': '.$priceBaseNotSale. ' ' .$is_nds;
                    }
                    $tooltipNdsSale = '<span class="label label-sm label-rounded align-middle ml-2" data-html="true" data-toggle="tooltip" title="' .$tooltipNdsSaleText.'">?</span>';
                }

                $this->saleTooltip = $tooltipNdsSale;

                stackStore('this', $this, __CLASS__, __FUNCTION__, __LINE__);
                stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
            }
        );

        $stocksArray = array_map(function (Model $item) {
            return $item->getData();
        }, $actualStocks->allModels());

        return array(
            'stocksArray' => $stocksArray,
            'actualStocksCount' => $actualStocks->count()
        );
    }

    /**
     * Добавляет сортировку в запрос
     *
     * @param Query $query Запрос
     * @param string $sort Правило сортировки
     * @return Query
     */
    protected static function addSort(Query $query, string $sort, array $stocksCodes, string $lang_code, string $priceListCode = 'notset'): Query
    {
        /**
         * @var Zone
         */
        $zone = app(Zone::class);
        /**
         * @var ModelStorage<int, Stock>
         */
        $warehouses = app('warehouses');
        $mainStocksCodes = $warehouses->whereIn('code', $stocksCodes)->where('virtual', 0)->pluck('code')->all();
        $saleFlag = ($mainStocksCodes === []);
        if($saleFlag) {
            if($zone->allSaleStocksPresentIn($stocksCodes)) {
                $mainStocksCodes = $zone->stocks->where('virtual', 0)->where('active', 1)->pluck('code')->all();
            } else {
                $parentStocks = $warehouses->whereIn('code', $stocksCodes)->pluck('parent_stock')->all();
                $mainStocksCodes = $warehouses->whereIn('uid', $parentStocks)->pluck('code')->all();
            }
        }
        $overSort = Str::repeat('Z', 22);
        $dataField = 'commercial';
        switch ($sort) {
            case 'popularity': $query->orderBy('sort', 'desc')->orderBy('popularity', 'desc');
            break;
            case 'quantity':
                $allStocksPresent = (!$saleFlag && $zone->allMainStocksPresentIn($mainStocksCodes))
                                    || ($saleFlag && $zone->allSaleStocksPresentIn($stocksCodes));
                if($zone->isReal() && $allStocksPresent) {
                    if($saleFlag) {
                        /* Считаем только распродажу */
                        $queryString = 'JSON_VALUE('.$dataField.', "$.zones_quantity.'. $zone->code1c.'.sale")';
                    } else {
                        /* Считаем всё наличие */
                        $queryString = 'JSON_VALUE('.$dataField.', "$.zones_quantity.'. $zone->code1c.'.all")';
                    }
                    $query->getBuilder()->orderByRaw($queryString.' IS NULL ASC, '.$queryString .' <= 0 ASC, CAST('. $queryString .' AS INTEGER) DESC');
                } else {
                    $queryString = '';
                    foreach ($stocksCodes as $stock) {
                        if($queryString !== '') {
                            $queryString.=', ';
                        }
                        $partQuery = 'LPAD(IFNULL(IF(JSON_VALUE('.$dataField.', "$.availability.'. $stock .'") = "null", NULL, CAST(JSON_VALUE('.$dataField.', "$.availability.'. $stock .'")*100 AS INTEGER)),"'.$overSort.'"), 22, "0")';
                        // $partQuery = 'LPAD(IFNULL(IF(JSON_VALUE('.$dataField.', "$.availability_all.'. $stock .'.total") = "null", NULL, CAST(JSON_VALUE('.$dataField.', "$.availability_all.'. $stock .'.total")*100 AS INTEGER)),"'.$overSort.'"), 22, "0")';
                        $queryString .= $partQuery;
                    }
                    if(count($stocksCodes) === 1)
                    {
                        $queryString .= ',"'.$overSort.'"';
                    }
                    if($queryString !== '')
                    {
                        $query->getBuilder()->orderByRaw('CAST(LEAST('. $queryString .') AS INTEGER) <= 0 ASC, LEAST('. $queryString .') DESC');
                    }
                }
                // $queryString = '';
                // foreach ($stocksCodes as $stock) {
                //     $partQuery = 'IFNULL(JSON_VALUE('.$dataField.', "$.availability.'. $stock .'"),0)+';
                //     $queryString .= $partQuery;
                // }
                // $queryString = substr ($queryString, 0, strlen ($queryString)-1);
                // $queryString .= ' desc';

                // $query->getBuilder()->orderByRaw($queryString);
                break;
            case 'supply_date':
                $queryString = '';
                $overDate = date("Y-m-d", time()*2);
                foreach ($stocksCodes as $stock) {
                    $partQuery = 'IFNULL(JSON_VALUE('. $dataField .', "$.supplies.'. $stock .'"), "'.$overDate.'"),';
                    $queryString .= $partQuery;
                }
                $queryString = substr ($queryString, 0, strlen ($queryString)-1);
                if($queryString !== '')
                {
                    if(count($stocksCodes) === 1)
                    {
                        $query->getBuilder()->orderByRaw( $queryString .' ASC');
                    }
                    else
                    {
                        $query->getBuilder()->orderByRaw('LEAST('. $queryString .') ASC');
                    }
                }
                break;
            case 'price_asc':
                $expression = static::prepareStockPricesCastingExpression($mainStocksCodes, $priceListCode);
                $query->getBuilder()->orderByRaw('CAST('. $expression .' AS INTEGER) <= 0 ASC,'. $expression .' ASC');
                break;
            case 'price_desc':
                $expression = static::prepareStockPricesCastingExpression($mainStocksCodes, $priceListCode);
                $query->getBuilder()->orderByRaw('CAST('. $expression .' AS INTEGER) <= 0 ASC,'. $expression .' DESC');
                break;
        }
        return $query;
    }

    private static function prepareStockPricesCastingExpression(array $stocks, string $priceListCode): string
    {
        $overSort = Str::repeat('Z', 22);
        $dataField = 'commercial';
        $queryString = '';
        /**
         * @var Zone
         */
        $zone = app(Zone::class);
        if($zone->isReal() && $zone->allMainStocksPresentIn($stocks)) {
            $queryString = 'LPAD(IFNULL(IF(JSON_VALUE('. $dataField .', "$.zones_min_prices.'.$zone->code1c.'.'. $priceListCode.'") = "null", NULL, CAST(JSON_VALUE('. $dataField .',  "$.zones_min_prices.'.$zone->code1c.'.'.$priceListCode.'")*100 AS INTEGER)),"'.$overSort.'"), 22, "0")';
        } else {
            foreach ($stocks as $stock) {
                if($queryString !== '') {
                    $queryString.=', ';
                }
                $partQuery = 'LPAD(IFNULL(IF(JSON_VALUE('. $dataField .', "$.prices.'. $stock .'.'. $priceListCode.'") = "null", NULL, CAST(JSON_VALUE('. $dataField .', "$.prices.'. $stock .'.'. $priceListCode.'")*100 AS INTEGER)),"'.$overSort.'"), 22, "0")';
                $queryString .= $partQuery;
            }
            // if($queryString !== '') {
            //     $queryString.=', ';
            // }
            // $partQuery = 'LPAD(IFNULL(IF(JSON_VALUE('. $dataField .', "$.suppliers_prices.min") = "null", NULL, CAST(JSON_VALUE('. $dataField .', "$.suppliers_prices.min")*100 AS INTEGER)),"'.$overSort.'"), 22, "0")';
            // $queryString .= $partQuery;
            if(count($stocks) === 1)
            {
                $queryString .= ',"'.$overSort.'"';
            }
            if($queryString !== '')
            {
                $queryString = 'LEAST('. $queryString .')';
            }
            else
            {
                $queryString = "''";
            }
        }

        return $queryString;
    }

    private static function preparePriceValueForFilter(float $priceValue): string
    {
        $intValue = (int)($priceValue * 100);
        return Str::padLeft($intValue, 22, '0');
    }

    /**
     * Применяет расширенный фильтр, возвращает готовую коллекцию
     *
     * @param Query $query
     * @param DataStamp $extendedFilters
     * @param array|null $sections
     * @return Query
     */
    protected static function applyExtendedFilter(Query $query, DataStamp $extendedFilters, string $lang_code): Query
    {
        $dataField = $lang_code === 'en' ? 'data_en' : 'data_ru';
        $filters = collect();
        // dump($extendedFilters);
        foreach ($extendedFilters->filters as $filter) {
            $data = $filter->all();
            $filters->push($data);
        }
        /* Применяем фильтры */
        foreach ($filters as $filter) {
            $char = Char::where(['ext_id', $filter['charUid']])->first();
            if ($char instanceof Char) {
                $charExtId = $char->ext_id;
                if (is_array($filter['charValue'])) {
                    $type = array_see('type', $filter['charValue']) ?? array_see('type', $filter);
                } else {
                    $type = array_see('type', $filter);
                }
                $charUids[] = $charExtId;
                if ($filter['charOperation'] === '') {
                    if ($type === 'range') {
                        $min = null;
                        $max = null;
                        if (is_array($filter['charValue'])) {
                            $min = array_see('min', $filter['charValue']) ?? array_see(0, $filter['charValue']);
                            $max = array_see('max', $filter['charValue']) ?? array_see(1, $filter['charValue']);
                        }
                        $query->when($min !== null, function (Query $query) use ($min, $dataField, $charExtId, $filter) {
                            // $query->where([$dataField .'->props->' . $charExtId, '>=', (int) floor(getConvertedValue( $min, $filter['dimension'], 'metric'))]);
                            $query->nestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                $query->nestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) >= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) <= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNotNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'")) AS DECIMAL(20,2)) >= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->min');
                                    $query->whereNotNull($dataField .'->' . $charExtId);
                                });
                            });
                        })
                        ->when($max !== null, function (Query $query) use ($max, $dataField, $charExtId, $filter){
                            // $query->where([$dataField .'->props->' . $charExtId.'->min', '<=', (int) ceil(getConvertedValue( $max, $filter['dimension'], 'metric'))]);
                            $query->nestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) <= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.max")) AS DECIMAL(20,2)) >= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNotNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'")) AS DECIMAL(20,2)) <= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->min');
                                    $query->whereNotNull($dataField .'->' . $charExtId);
                                });
                            });
                        });
                    } else {
                        if ($type === 'radio' && $filter['charValue'] === 'all') {
                            continue;
                        }
                        if(is_array($filter['charValue']) && isset($filter['charValue']['values'])) {
                            $values = $filter['charValue']['values'];
                        } else {
                            $values = $filter['charValue'];
                        }
                        if ($values !== null && $values !== []) {
                            if (is_scalar($values)) {
                                $query->where([$dataField .'->' . $charExtId, '=', $char->is_numeric ? getConvertedValue($values, $filter['dimension'], 'metric') : $values]);
                            } elseif (is_array($values) && !in_array(null, $values)) {
                                foreach($values as $key=> $value)
                                {
                                    $values[$key] = $char->is_numeric ? getConvertedValue($value, $filter['dimension'], 'metric') : $value;
                                }
                                $query->whereIn($dataField .'->' . $charExtId, $values);
                            }
                        }
                    }
                }
                else {
                    if ($filter['type'] == 'range') {
                        $min = 0;
                        $max = 0;
                        if ($filter['charOperation'] === 'min') {
                            $min = $filter['charValue'];
                            $query->nestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                $query->nestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) >= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) <= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNotNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($min, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'")) AS DECIMAL(20,2)) >= '.getConvertedValue( $min, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->min');
                                    $query->whereNotNull($dataField .'->' . $charExtId);
                                });
                            });
                        } elseif ($filter['charOperation'] === 'max') {
                            $max = $filter['charValue'];
                            $query->nestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.min")) AS DECIMAL(20,2)) <= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'.max")) AS DECIMAL(20,2)) >= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNotNull($dataField .'->' . $charExtId.'->max');
                                });
                                $query->orNestedWhere(function(Query $query) use ($max, $dataField, $charExtId, $filter){
                                    $query->getBuilder()->whereRaw(
                                        'CAST(json_unquote(json_extract(`pr_filters_data`.`'.Str::upper($dataField).'`, "$.'.$charExtId.'")) AS DECIMAL(20,2)) <= '.getConvertedValue( $max, $filter['dimension'], 'metric'));
                                    $query->whereNull($dataField .'->' . $charExtId.'->min');
                                    $query->whereNotNull($dataField .'->' . $charExtId);
                                });
                            });
                        }
                    }
                }
            }
        }

        return $query;
    }

    /**
     * Добавляет в коллекцию товаров дополнительные поля с помощью обработчика
     *
     * @param ModelStorage $filtersData
     * @param ModelStorage $currentStocks
     * @param bool $isAccessories
     * @param bool $isChangeOrder
     * @param null $btn_obj_data
     * @return ModelStorage
     */
    public static function processProducts(ModelStorage $filtersData, ModelStorage $currentStocks, bool $isAccessories = false, bool $isChangeOrder = false, $btn_obj_data = null): ModelStorage
    {
        $language = App::make(Language::class);
        $lang_code = $language->code;

        $priceList = null;
        $priceListId = getPriceList();
        if ($priceListId !== 0 && getUserSettingValue('SHOW_RETAIL_PRICES') !== '1') {
            $priceList = PriceList::find($priceListId);
            $replacePriceList = $priceList->relate_to_rub;
        } else {
            $replacePriceList = PriceList::RETAIL_RUB;
        }
        $priceListRub = is_int($replacePriceList) ? PriceList::find($replacePriceList) : null;

        $filteredData = $filtersData->map(function ($item, $key) use ($priceListRub) {
            stackStoreMulti(compact('item', 'key'), __CLASS__, __FUNCTION__, __LINE__);
            $class = new stdClass();
            $commercial = json_decode($item->commercial);
            $availability = [];
            if(isset($commercial->availability))
            {
                $availability = $commercial->availability;
            }
            $quantity = 0;
            foreach($availability as $count) {
                if (is_int((int)$count))
                    $quantity += $count;
            }
            $basePrice = 0;
            $priceListCode = $priceListRub instanceof PriceList ? $priceListRub->code : null;
            if(isset($commercial->prices))
            {
                foreach ($commercial->prices as $stock) {
                    if (property_exists($stock, $priceListCode))
                        $basePrice = $stock->$priceListCode;
                    if ($basePrice > 0)
                        break;
                }
            }
            $class->uid = $item->uid;
            $class->code = $item->code;
            $class->quantity = $quantity;
            $class->base_price = $basePrice;

            return $class;
        });
        $productsCodes = $filteredData->pluck('code')->toArray();
        $products = Product::translate($language)->whereIn('code1c', $productsCodes)->with(['sections'])->get();
        $sortCollection = collect();
        foreach ($productsCodes as $code) {
            $product = $products->firstWhere('code1c', $code);
            if($product instanceof Product)
            {
                $productData = (object)($product->getData());
                $sortCollection->push($productData);
            }
        }
        $sections = $products->getLinkedData('sections');
        $pivots = $products->getPivots();
        $pivotsSection = collect([]);
        if(array_key_exists('sections', $pivots))
        {
            $pivotsSection = $pivots['sections'];
        }
        $productsId = $products->pluck('uid')->all();

        $sectionFilters = SectionFilter::whereLinkedWith('section', $sections)->translate($language)->get();
        $sectionFiltersCharExtIds = $sectionFilters->pluck('char_ext_id')->all();
        $sectionFiltersCharacteristics = Char::whereIn('ext_id',$sectionFiltersCharExtIds)->get();
        $sectionFiltersCharIds = $sectionFiltersCharacteristics->pluck('uid')->all();
        $sectionFiltersChars = CharValue::whereIn('catalog_num', static::CATALOGS_FULL)
                            ->whereIn('char_name_link', $sectionFiltersCharIds)
                            ->whereIn('product_link', $productsId)
                            ->where([['active', '=', 1]])
                            ->whereNotNull('value')
                            ->orderBy('char_sort', 'asc')
                            ->translate($language)
                            ->get();
        $products = new ModelStorage(Product::class, $sortCollection);
        $files = ProductFile::whereIn('code1c', $productsCodes)->where(['active', '=', 1])->whereIn('type', Product::TYPE_PICTURES)->orderBy('sort', 'asc')->get();
        $products->bindLinkedCollection('files', $files);
        $products->bindLinkedCollection('sections', $sections, $pivotsSection);
        $mainStocks = $currentStocks->where('virtual', 0);
        $saleStocks = $currentStocks->where('virtual', 1);
        $parentStocks = Stock::queryUsingZone()->whereLinkedWith('children', $saleStocks)->active()->get();

        if ($btn_obj_data == null) {
            $btn_obj_data = basket();
        }

        $availabilities = Availability::whereLinkedWith('product', $products)
                                        ->stock($currentStocks->getUids())
                                        ->nestedWhere(function(Query $query) {
                                            $query->where(['quantity_free', '>', 0])
                                                ->orWhere(['quantity_unpaid_reserve', '>', 0]);
                                        })
                                        ->get();
        $products->bindLinkedCollection('availabilities', $availabilities);

        if(getUserSettingValue('SHOW_RETAIL_PRICES') == '1') {
            $productPrices = Price::getRetailForProducts($products);
        } else {
            $productPrices = Price::getForProducts($products, getPriceList());
        }
        $productPrices->makeIndex(['product_link']);

        /**
         * @var ProcessPrices
         */
        $priceProcessor = App::make(ProcessPrices::class);

        $products->bindLinkedCollection('prices', $productPrices);
        $productPrices->setModelProcessor(function () use ($priceProcessor)  {
            stackStore('this', $this);
            $this->setPriceProcessor($priceProcessor);

            $this->finalPrice = $this->getFinalPriceWithFlags(null, null, false, true, false);
            $this->originalFinalPrice = $this->getFinalPriceWithFlags(null, null, true,true , false);
            $this->finalPriceValue = $this->finalPrice->getPrice();

            if(getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS') == '1') {
                $this->finalPriceWithDiscounts = $this->getFinalPriceWithFlags(null, null, false, false, true);
                $this->finalPriceWithDiscountsValue = $this->finalPriceWithDiscounts->getPrice();
                $this->originalFinalPriceWithDiscounts = $this->getFinalPriceWithFlags(null, null, true, false, true);
                $this->originalFinalPriceWithDiscountsValue = $this->originalFinalPriceWithDiscounts->getPrice();
            } else {
                $this->finalPriceWithDiscounts = null;
                $this->finalPriceWithDiscountsValue = '';
                $this->originalFinalPriceWithDiscounts = null;
                $this->originalFinalPriceWithDiscountsValue = '';
            }

            $this->originalFinalPriceValue = $this->originalFinalPrice->getPrice();
        });


        $productPrices->recollect();
        $descriptions = CharValue::whereLinkedWith('product', $products)->where(['name_original', '=', 'Описание'])->translate($language)->get();
        $descriptions->setModelProcessor(function () {
            $this->requireTranslation(['value']);
        });
        $descriptions->recollect();

        $products->bindLinkedCollection('chars', $descriptions);

        $supplies = Supply::whereLinkedWith('product', $products)
        ->stock($currentStocks->getUids())
        ->actual()
        ->nestedWhere(function(Query $query) {
            $query->where(['quantity_free', '>', 0])
            ->orWhere(['quantity_unpaid_reserve', '>', 0]);
        })
        ->get();
        $products->bindLinkedCollection('supplies', $supplies);

        /**
         * @var ModelStorage<Price>
         */
        $mainPrices = $productPrices->whereIn('stock_link', $mainStocks->getUids());
        /**
         * @var ModelStorage<Price>
         */
        $salePrices = $productPrices->whereIn('stock_link', $saleStocks->getUids());
        /**
         * @var ModelStorage<Price>
         */
        $parentPrices = $productPrices->whereIn('stock_link', $parentStocks->getUids());

        $order_items_code1c = [];
        if ($isAccessories) {
            $order_items_ids = DB::table('or_change_orders')->get()->pluck('PRODUCT_ID')->unique()->all();
            $order_items_code1c = Product::query()
                ->whereIn('uid', $order_items_ids)
                ->get()
                ->pluck('code1c')
                ->all();
        }
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
        $useDiscounts = getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS') == '1';
        $discounts = ($useDiscounts) ? Discount::getAppliableForProducts($products) : DiscountStorage::empty(Discount::class);
        $products->setModelProcessor(function () use ($productPrices,
                                                        $mainPrices,
                                                        $salePrices,
                                                        $parentPrices,
                                                        $isChangeOrder,
                                                        $btn_obj_data,
                                                        $order_items_code1c,
                                                        $isAccessories,
                                                        $mainStocks,
                                                        $saleStocks,
                                                        $language,
                                                        $useDiscounts,
                                                        $filteredData,
                                                        $sectionFiltersChars,
                                                        $discounts
        ){
            stackStore('this', $this);
            $this->discounts = $discounts->forProduct($this);
            $this->quantity = 0;
            $this->base_price = 0;
            $this->promo = false;
            $filteredDataItem = $filteredData->where('code', $this->code1c)->first();
            if ($filteredDataItem instanceof FiltersData) {
                $quantity = $filteredDataItem->quantity;
                $basePrice = $filteredDataItem->base_price;
                $this->quantity = $quantity;
                $this->base_price = $basePrice;
            }
            $sectionFiltersChars = $sectionFiltersChars->where('product_link', $this->uid);
            $files = $this->files;
            $files1c = $files->where('type', CatalogDetailController::MAIN_IMAGE);
            if($files1c->count() > 1 || !$sectionFiltersChars->isEmpty())
            {
                $files = $files1c;
            }
            $this->thumb = Filer::getResizedImage($files->first(), Product::THUMB_WIDTH, Product::THUMB_HEIGHT);
            if (is_null($this->name)) {
                $this->name = __('interface.NO_NAME');
            }
            if (is_null($this->delivery_time)) {
                $this->delivery_time = 'not_defined';
            }


            if (in_array($this->catalog_num, CatalogApiController::CATALOGS_ZIP)) {
                $this->url = sroute('zip.product', [$this->code1c]);
            } else {
                $this->url = sroute('catalog.product', [$this->code1c]);
            }

            if (!$this->chars->isEmpty()) {
                $this->descriptionFull = $this->chars->first()->value;
                $this->description = Str::limit($this->chars->first()->value, 170, '...');
            } else {
                $this->description = '';
            }
            unset($this->chars);
            $this->section = $this->sections->first();
            unset($this->sections);

            $this->supplyDate = null;
            $this->supplyDateDiff = null;
            $this->supplyQuantity = null;
            $this->supplies->setModelProcessor(function () {
                $this->quantityAvailable = (int)$this->quantity_free + (int)$this->quantity_unpaid_reserve;
            });
            $firstActiveSupply = null;
            foreach ($this->supplies as $supply) {
                if($supply->quantity_free > 0) {
                    $firstActiveSupply = $supply;
                }
                if ($supply->quantityAvailable > 0) {
                    $this->supplyDate = Date::convertFromDb('Y-m-d', $supply->supply_date, app('DigitalDateFormat'));
                    $this->supplyDateDiff = Carbon::parse(Carbon::now())->diffInDays($supply->supply_date, false);
                    $this->supplyQuantityFree = $supply->quantity_free;
                    $this->supplyQuantityUnpaid = $supply->quantity_unpaid_reserve;
                    if($firstActiveSupply instanceof Supply) {
                        break;
                    }
                }
            }


            if ($firstActiveSupply instanceof Supply) {
                $this->availabilityStatus = __('interface.PRODUCT_IN_SUPPLY');
            } elseif ($this->delivery_time !== Product::STATUS_NOT_CARRY) {
                $this->availabilityStatus = __('interface.PRODUCT_ON_ORDER');
            } else {
                $this->availabilityStatus = __('interface.PRODUCT_NOT_CARRY');
            }


            $this->prices = $productPrices->xwhere('product_link', $this->uid);
            $mainProductPrices = $mainPrices->xwhere('product_link', $this->uid);
            $saleProductPrices = $salePrices->xwhere('product_link', $this->uid);
            $parentProductPrices = $parentPrices->xwhere('product_link', $this->uid);
            // relate
            //---------------------------

            if ($isAccessories) {
                $this->relate_name = null;
                $this->relate_from = null;

                $relate = DB::table('pr_relations')
                    ->where('relate_to', $this->code1c)
                    ->whereIn('relate_from', $order_items_code1c)
                    ->where('type', 'accessories')
                    ->first();

                if ($relate instanceof stdClass) {
                    $relate_product = Product::query()->where(['code1c', $relate->relate_from])->translate($language)->first();

                    if ($relate_product !== null) {
                        $this->relate_name = $relate_product->name;
                    }

                    $this->relate_from = $relate_product;
                };
            }

            $mainAvailabilities = $this->availabilities->whereIn('stock_link', $mainStocks->getUids());
            $sales = $this->availabilities->whereIn('stock_link', $saleStocks->getUids());
            /**
             * @property bool есть ли распродажа на выбранных складах
             */
            $this->hasSale = !($sales->isEmpty());

            $showDetailCalculation = (user() instanceof User) ? user()->hasPermission('price_calculation_view') : false;
            if (!$this->hasSale) {
                $this->mainPriceStats = Price::computeStats($mainProductPrices, true, $showDetailCalculation, false);
                if($useDiscounts) {
                    $this->mainPriceWithDiscountsStats = Price::computeStats($mainProductPrices, true, $showDetailCalculation, true);
                } else {
                    $this->mainPriceWithDiscountsStats = CatalogApiController::makeEmptyStats();
                }
            } else {
                $this->mainPriceStats = Price::computeStats($parentProductPrices, true, $showDetailCalculation, false);
                if($useDiscounts) {
                    $this->mainPriceWithDiscountsStats = Price::computeStats($parentProductPrices, true, $showDetailCalculation, true);
                } else {
                    $this->mainPriceWithDiscountsStats = CatalogApiController::makeEmptyStats();
                }
            }
            $this->salePriceStats = Price::computeStats($saleProductPrices, true, $showDetailCalculation, false);
            $this->salePriceWithDiscountsStats = $this->salePriceStats;

            $this->has_price = (int)!$mainProductPrices->isEmpty();

            $promocode = $this->getPromocode();

            $this->discount_portal = $this->discount_portal;
            $this->discount_1c = $this->discount_1c;
            $this->promocode = ($promocode && $useDiscounts && $this->has_price == 1 && (!$this->discount_portal or ($this->discount_portal instanceof Discount && $promocode->isBetterThan($this->discount_portal))) ) ? $promocode : null;
            $this->calculate('appliablePromocode');

            $this->promo_tooltip_description = '';
            if($this->promocode)
            {
                if($this->discount_1c instanceof Discount) {
                    $discount1C = $this->discount_1c;
                    if($discount1C->section instanceof Section or $discount1C->brand instanceof Brand)
                    {
                        $description = __('interface.ON_PRODUCT');
                        $sectionName = $discount1C->section instanceof Section ? $discount1C->section->name : '';
                        $brandName = $discount1C->brand instanceof Brand ? $discount1C->brand->name : '';
                        $description = $sectionName.$brandName;
                    } else {
                        $description = __('interface.ALL_ASSORTIMENT');
                    }

                    $this->promo_tooltip_description = $discount1C->value.'% '.$description.'<br>';
                    $this->promo_tooltip_description .= $this->promocode->value.'% '.__('interface.BY_PROMOCODE').' '.$this->promocode->code;
                } else {
                    $this->promo_tooltip_description .= $this->promocode->value.'% '.__('interface.BY_PROMOCODE').' '.$this->promocode->code ;
                }
            }

            $this->available_on_stock = 1;
            $this->available_on_stock = $this->quantity > 0 ? 1 : 0;
            $this->is_dishes = $this->isDishes() ? 1 : 0;
            if ($this->price_request == 1) {
                if ($this->is_dishes == 1) {
                    if ($this->quantity == 0 || $this->base_price == 0) {
                        $this->base_price = '';
                        $this->mainPriceStats = CatalogApiController::makeEmptyStats();
                        $this->mainPriceWithDiscountsStats = CatalogApiController::makeEmptyStats();
                    }
                } else {
                    $this->base_price = '';
                    $this->mainPriceStats = CatalogApiController::makeEmptyStats();
                    $this->mainPriceWithDiscountsStats = CatalogApiController::makeEmptyStats();
                }
            }

            $this->mainStockStats = [
                'available' => $mainAvailabilities->sum('quantity_free'),
                'unpaidReserve' => $mainAvailabilities->sum('quantity_unpaid_reserve'),
            ];

            $this->saleStockStats = [
                'available' => $sales->sum('quantity_free'),
                'unpaidReserve' => $sales->sum('quantity_unpaid_reserve'),
            ];

            if (user()) {
                $this->legalEntity = user()->data->selectedLegalEntity;
            } else {
                $this->legalEntity = null;
            }

            $request = App::make(Request::class);
            $state = $request->input('state');
            if ($state !== 'sale') {
                $this->hasBuyButton = (count($mainStocks) === 1 && !$this->hasSale);
                if ($this->hasBuyButton) {
                    $stockId = $mainStocks->first()->uid;
                }
            } else {
                $this->hasBuyButton = (count($sales) === 1);
                if ($this->hasBuyButton) {
                    $stockId = $sales->first()->stock_link;
                }
            }
            if ($this->hasBuyButton) {

                if ($isChangeOrder) {
                    $this->buyButtonLayout = c_change_order__btn($btn_obj_data, $this, $stockId);
                } else {
                    $this->buyButtonLayout = c_basket__btn($btn_obj_data, $this, $stockId, $request->input('here', '/'));
                }
            }


            $targetCurrencyPriceMin = $this->mainPriceStats->min->targetCurrencyPrice;
            $originCurrencyPriceMin = $this->mainPriceStats->min->originalCurrencyPrice;
            $saleTargetCurrencyPriceMin = $this->salePriceStats->min->targetCurrencyPrice;
            $saleOriginalCurrencyPriceMin =$this->salePriceStats->min->originalCurrencyPrice;
            $mainDetailCalculation =$this->mainPriceStats->min->detailCalculation;
            $saleDetailCalculation =$this->mainPriceStats->min->detailCalculation;
            $priceWithDiscountsValue = '';
            $priceWithDiscountsBaseValue = '';
            $salePriceWithDiscountsValue = '';
            $salePriceWithDiscountsBaseValue = '';
            $is_nds_main = '';





            $this->tooltip = '';
            $this->saleTooltip = '';
            $tooltipNdsText = '';
            $tooltipNdsText = '';
            $tooltipNdsSale = '';
            $tooltipNds = '';
            $tooltipNdsSaleText = '';

            if ($this->mainPriceStats->min->vatIncluded) {
                $is_nds_main = __('interface.WITH_VAT');
            } else {
                $is_nds_main = __('interface.WITHOUT_VAT');
            }

            $is_nds_sale = '';
            if ($this->salePriceStats->min->vatIncluded) {
                $is_nds_sale = __('interface.WITH_VAT');
            } else {
                $is_nds_sale = __('interface.WITHOUT_VAT');
            }

            $showDiscounts = (int)getUserSettingValue('SHOW_PRICES_WITH_DISCOUNTS');



            if ($targetCurrencyPriceMin && $originCurrencyPriceMin) {
                if ($mainDetailCalculation !== '') {
                    $tooltipNdsText = $mainDetailCalculation;
                } else {
                    $priceWithDiscountsValue = $this->mainPriceWithDiscountsStats->min->targetCurrencyPrice;
                    $priceWithDiscountsBaseValue = $this->mainPriceWithDiscountsStats->min->originalCurrencyPrice;
                    $tooltipNdsText = __('interface.PRICE').': '.$targetCurrencyPriceMin.' '.$is_nds_main.' <br> '.__('interface.PRICE_IN_CURRENCY').': '.$originCurrencyPriceMin.' '.$is_nds_main;
                    if($showDiscounts == 1 && $priceWithDiscountsValue !== $targetCurrencyPriceMin) {
                        $tooltipNdsText .= ' <br>'.__('interface.PRICE_DISCOUNT').': '.$priceWithDiscountsValue.' '.$is_nds_main.' <br>'.__('interface.PRICE_DISCOUNT_IN_CURRENCY').': '. $priceWithDiscountsBaseValue.' '.$is_nds_main;
                    }
                }
                $tooltipNds = '<span class="label label-sm label-rounded align-middle ml-2" data-html="true" data-toggle="tooltip" title="'.$tooltipNdsText.'">?</span>';
            }


            $this->tooltip = $tooltipNds;


            if ($saleTargetCurrencyPriceMin && $saleOriginalCurrencyPriceMin) {
                if ($saleDetailCalculation !== '') {
                    $tooltipNdsSaleText = $saleDetailCalculation;
                } else {
                    $tooltipNdsSaleText = __('interface.PRICE').': '.$saleTargetCurrencyPriceMin.' '.$is_nds_sale.' <br> '.__('interface.PRICE_IN_CURRENCY').': ' .$saleOriginalCurrencyPriceMin.' '.$is_nds_sale;
                    if($showDiscounts == 1 && $salePriceWithDiscountsValue !== $saleTargetCurrencyPriceMin) {
                        $tooltipNdsSaleText .= ' <br>'.__('interface.PRICE_DISCOUNT').': '.$salePriceWithDiscountsValue.' '.$is_nds_sale.' <br>'.__('interface.PRICE_DISCOUNT_IN_CURRENCY').': '.$salePriceWithDiscountsBaseValue.' '.$is_nds_sale;
                    }
                }
                $tooltipNdsSale = '<span class="label label-sm label-rounded align-middle ml-2" data-html="true" data-toggle="tooltip" title="'.$tooltipNdsSaleText.'">?</span>';
            }

            stackStore('this', $this, __CLASS__, __FUNCTION__, __LINE__);
            stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
        });

        return $products;
    }

    /**
     * Создаёт заглушку с пустыми данными по ценам
     *
     * @return DataStamp
     */
    public static function makeEmptyStats(): DataStamp
    {
        $min = (object)[
            'targetCurrencyPrice' => '',
            'originalCurrencyPrice' => '',
            'vatIncluded' => '',
            'detailCalculation' => ''
        ];
        $max = (object)[
            'targetCurrencyPrice' => '',
            'originalCurrencyPrice' => '',
            'vatIncluded' => '',
            'detailCalculation' => ''
        ];
        return DataStamp::use('main.priceStats')->compose(['min'=>$min, 'max'=>$max, 'stock_link' => null]);
    }

    /**
     * Разбирает и возвращает значения параметров расширенного фильтра
     *
     * @param array $parameters
     * @return DataStamp
     */
    public static function getExtendedFilterValues(array $parameters): DataStamp
    {
        $types = array_see('types', $parameters) ?? [];
        $hintsRequest = array_see('hintsRequest', $parameters);
        $receivedExtendedFilters = array_filter($parameters, function ($key) {
            return mb_substr($key, 0, 5) === 'char_';
        }, ARRAY_FILTER_USE_KEY);

        /* Разбираем полученные данные, приводим в удобный вид */
        $extendedFilters = [];
        $charUids = [];
        $charExtIds = [];
        $language = App::make(Language::class);
        foreach($receivedExtendedFilters as $key => $value)
        {
            $charExtIds[] = str_replace('char_', '', $key);
        }
        $sections = array_see('sections', $parameters);
        $sections = unpackIfJsonArray($sections);
        if (is_array($sections)) {
            $sections = makeNumeralArray($sections);
        } else {
            $sections = null;
        }
        $sectionFilters = SectionFilter::whereIn('char_ext_id', $charExtIds)
            ->translate($language)
            ->where(['filter_show', 1])
            ->when(($sections !== null), function (Query $query) use ($sections) {
                $query->whereIn('section_link', $sections);
            })
            ->get();
        foreach ($receivedExtendedFilters as $key => $value) {
            $dimension = '';
            $newFilter = true;
            $charExtId = str_replace('char_', '', $key);
            $sectionFilter = $sectionFilters->where('char_ext_id', $charExtId)->first();
            if ($sectionFilter instanceof SectionFilter) {
                $dimension = $sectionFilter->dimension;
            }
            if (is_array($value)) {
                $type = array_key_exists('type', $value) ? $value['type'] : null;
            } else {
                $type = array_see($key, $types);
            }
            if ($type === null) {
                if ($sectionFilter instanceof SectionFilter) {
                    $type = $sectionFilter->filter_view;
                } else {
                    $char = Char::findBy('ext_id', $charExtId);
                    $type = $char->filter_view ?? null;
                    $newFilter = false;
                }
            }
            if ($type !== null) {
                if($newFilter) {
                    if (is_array($value)) {
                        if ($type !== 'range') {
                            $checked = array_key_exists('checked', $value) ? $value['checked'] : null;
                            if ($checked) {
                                if ($type === 'radio' && in_array('all', $checked)) {
                                    continue;
                                }
                                if (array_key_exists('values', $value)) {
                                    $value['values'] = $checked;
                                }
                            } elseif (!$hintsRequest) {
                                $value['values'] = $value;
                            } else {
                                continue;
                            }
                        }
                    }
                }
            } else {
                continue;
            }
            $charSignature = mb_substr($key, 5);
            $filter = new MagicClass();
            if (strpos($charSignature, '_') !== false) {
                $charParts = explode('_', $charSignature);
                $filter->charUid = (int)$charParts[0];
                $filter->charOperation = $charParts[1];
            } else {
                $filter->charUid = (int)$charSignature;
                $filter->charOperation = '';
            }
            $value = unpackIfJsonArray($value, true);
            $filter->charValue = $value;
            $filter->type = $type;
            $filter->dimension = $dimension;
            array_push($extendedFilters, $filter);
            if (!in_array($filter->charUid, $charUids)) {
                array_push($charUids, $filter->charUid);
            }
        }
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);
        return DataStamp::use('main.extended_filter_values')->compose(['filters' => $extendedFilters, 'charUids' => $charUids]);
    }

    /**
     * Возвращает список актуальных брендов для фильтра
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBrandsForFilter(Request $request, Zone $zone, ?string $promo = null): JsonResponse
    {
        /* 1. Собрать основной запрос с учётом полученных данных */
        $parameters = $request->all();
        $activeWarehouses = getActiveWarehouses();
        $promo = array_see('promo', $parameters);
        $brands_exclude = [];
        if(array_key_exists('brands',$parameters))
        {
            $brands_exclude = $parameters['brands'];
            unset($parameters['brands']);
        }
        $parameters['sort'] = 'popularity';

        $activeWarehousesIds = app('warehouses')->whereIn('code', $activeWarehouses)->pluck('uid')->all();
        /*
        * Отбираем все выбранные склады плюс распродажные по зоне продаж
        */
        $currentStocksQuery = Stock::queryUsingZone()->nestedWhere(function (Query $query) use ($activeWarehouses, $activeWarehousesIds) {
            $query->whereIn('parent_stock', $activeWarehousesIds)
                    ->orWhereIn('code', $activeWarehouses);
        });
        $dbStocks = $currentStocksQuery->get();
        $productQuery = static::makeProductsQuery($parameters, $dbStocks);
        $brandFilter = array_see('brand_search', $parameters);

        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        /* Если бренды есть в кэше, взять оттуда */
        $cacheKey = '_catalog_brands_' . md5($productQuery->sql());
        if (Cache::has($cacheKey)) {
            stackStore('_state', 'FROM:CACHE', __CLASS__, __FUNCTION__, __LINE__);
            $brands = Cache::get($cacheKey);
        } else {
            stackStore('_state', 'FROM:DB', __CLASS__, __FUNCTION__, __LINE__);

            /* 2. Выполнить на полной выборке */
            $products = $productQuery->tap(function (Query $query) {
                $query->with([]);
            })
                ->get();
            /* 3. Вытащить необходимые данные по брендам */
            $brands = Brand::query()->active()->get();
            $productsCounts = $products->pluck('brand_link')->countBy();
            $productsCountByBrands =  $productsCounts->mapWithKeys(function ($item, $key) use ($brands) {
                $brand = $brands->where('uid', $key)->first();
                $brandCode = '';
                if ($brand instanceof Brand) {
                    $brandCode =  $brand->code1c;
                }

                return [$brandCode => $item];
            });

            $brandsCollection = $productsCountByBrands->sortKeys();

            stackStore('brandsCollection', $brandsCollection, __CLASS__, __FUNCTION__, __LINE__);

            $dbBrands = Brand::query()->active()->whereNotIn('uid', $brands_exclude)->get()->toKeyed('code1c');
            $brands = [];

            foreach ($brandsCollection as $key => $count) {
                if(isset($dbBrands[$key]))
                {
                    if(!isset($brands[$dbBrands[$key]->name]))
                    {
                        $brands[$dbBrands[$key]->name] = ['id' => $dbBrands[$key]->name ?? 0, 'text' => $dbBrands[$key]->name, 'count' => $count, 'own' => $dbBrands[$key]->own ?? 0];
                    }
                    else
                    {
                        $brands[$dbBrands[$key]->name]['count'] += $count;
                    }
                }
            }
            $brands = collect(array_values($brands));
            $brands = array_values($brands->sortBy([['own', 'desc'], ['text', 'asc']])->transform(function($item){
                $item = ['id' => $item['id'], 'text' => $item['text'], 'count' => $item['count']];
                return $item;
            })->all());
            Cache::put($cacheKey, $brands, 3600);
        }
        if (!is_null($brandFilter)) {
            $brands = array_values(array_filter($brands, function ($item) use ($brandFilter) {
                return Str::contains(mb_strtoupper($item['text']), mb_strtoupper($brandFilter));
            }));
        }

        return Response::success(['options' => $brands]);
    }

    /**
     * Возвращает список актуальных стран для фильтра
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCountriesForFilter(Request $request)
    {
        /* 1. Собрать основной запрос с учётом полученных данных */
        $parameters = $request->all();
        $parameters['sort'] = 'popularity';
        $activeWarehouses = getActiveWarehouses();
        $promo = array_see('promo', $parameters);
        $countries_exclude = [];
        if(array_key_exists('countries',$parameters))
        {
            $countries_exclude = $parameters['countries'];
            unset($parameters['countries']);
        }

        /**
         * @var Zone
         */
        $zone = app(Zone::class);

        $activeWarehousesIds = app('warehouses')->whereIn('code', $activeWarehouses)->pluck('uid')->all();
        /*
        * Отбираем все выбранные склады плюс распродажные по зоне продаж
        */
        $currentStocksQuery = Stock::queryUsingZone()->nestedWhere(function (Query $query) use ($activeWarehouses, $activeWarehousesIds) {
            $query->whereIn('parent_stock', $activeWarehousesIds)
                    ->orWhereIn('code', $activeWarehouses);
        });
        $dbStocks = $currentStocksQuery->get();
        $productQuery = static::makeProductsQuery($parameters, $dbStocks)->selectAllFields();
        $countryFilter = array_see('country_search', $parameters);
        $language = App::make(Language::class);
        stackStoreMulti(get_defined_vars(), __CLASS__, __FUNCTION__, __LINE__);

        /* Если страны есть в кэше, взять оттуда */
        $cacheKey = '_catalog_countries_' . md5($productQuery->sql()) . '_' . $language->code;
        if (Cache::has($cacheKey)) {
            stackStore('_state', 'FROM:CACHE', __CLASS__, __FUNCTION__, __LINE__);
            $countries = Cache::get($cacheKey);
        } else {
            stackStore('_state', 'FROM:DB', __CLASS__, __FUNCTION__, __LINE__);

            /* 2. Выполнить на полной выборке */
            /**
             * @var ModelStorage<CharValue>
             */
//            $countries = CharValue::selectCoreFields()->where(['char_name_link', '=', static::COUNTRY_CHAR])
//                ->active()
//                ->get();
//
//            $productIdsKeyed = static::makeProductsQuery($parameters, $dbStocks)
//                                        ->getBuilder()
//                                        ->get()
//                                        ->pluck('uid')
//                                        ->flip();
//            $productsCountByCountries = $countries->getData()->filter(function (stdClass $item) use ($productIdsKeyed) {
//                return isset($productIdsKeyed[$item->product_link]);
//            })->countBy(function ($char) {
//                return $char->value;
//            });

            $productQuery = static::makeProductsQuery($parameters, $dbStocks);
            $products = $productQuery->tap(function (Query $query) {
                $query->with([]);
            })
                ->get();
            $countries = Country::all();
            $productsCountByCountries = $products->getData()->countBy(function ($char) use ($countries) {
                $country = $countries->where('uid', $char->country)->first();
                if ($country instanceof Country) {
                    return  $country->name;
                }
            });
            $countriesCollection = $productsCountByCountries->sortKeys();

            stackStore('countriesCollection', $countriesCollection, __CLASS__, __FUNCTION__, __LINE__);

            $dbCountries = Country::query()->addOriginalName()->whereNotIn('uid', $countries_exclude)->translate($language)->all();
            $countries = [];
            foreach ($countriesCollection as $name => $count) {
                $dbCountry = $dbCountries->firstWhere('name_original', $name);
                if ($dbCountry instanceof Country) {
                    $countries[] = ['id' => $dbCountry->uid, 'text' => $dbCountry->name, 'count' => $count];
                }
            }
            Cache::put($cacheKey, $countries, 3600);
        }
        if (!is_null($countryFilter)) {
            $countries = array_values(array_filter($countries, function ($item) use ($countryFilter) {
                return Str::contains(mb_strtoupper($item['text']), mb_strtoupper($countryFilter));
            }));
        }

        return Response::success(['options' => $countries]);
    }
}
