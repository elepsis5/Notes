<?php

namespace App\Http\Controllers;

use App\Classes\FilteredLists\ListAggregate;
use App\EloquentModels\User;
use App\Classes\Breadcrumb\Breadcrumb;
use App\Classes\Compozite\ModelStorage;
use App\Classes\DTO\ListAggregateDTO;
use App\Classes\FilteredLists\FiltersData;
use App\Classes\FilteredLists\ParametersParser;
use App\Classes\FilteredLists\SearchData;
use App\Classes\FilteredLists\SortData;
use App\Classes\OrderProduct\OrderProductRequests;
use App\Classes\Orders\DTO\OrderHint;
use App\Classes\Rates\RateStorage;
use App\ModelQueries\OrderQuery;
use App\Models\Currency;
use App\Models\Dealer;
use App\Models\DeliveryType;
use App\Models\Language;
use App\Models\LegalEntity;
use App\Models\Order;
use App\Models\OrderFile;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentAppeal;
use App\Models\ShipmentAppealProduct;
use App\Models\Stock;
use App\Models\ShipmentGroup;
use App\Models\ShipmentTimeWindow;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Jenssegers\Date\Date;
use stdClass;

class ShipmentRequestsController extends Controller
{
    /**
     * Тип файла: основное изображение портала
     */
    protected const PORTAL_MAIN_IMAGE_TYPE = 10;

    /**
     * Статусы сделок без доступа оформить ЗНО
     */
    protected const ORDER_STATUS_LINKS = [7, 10, 11, 12, 14];

    /**
     * Посчитаные сделки
     */
    private array $ordersCounted = [];
    /**
     * Выбранные товары
     */
    private array $checkedProductsUids = [];


    /**
     * Показывает страницу списка заявок на отгрузку
     *
     * Route: [GET] /warehouse/shipments/
     *
     * @param Request $request
     * @return View
     */
    public function list(Request $request)
    {
        $user = User::actual();
        $language = App::make(Language::class);
        $filters = $request->all();
        $filters = array_map(function ($item) {
            return is_string($item) ? removeUnicodeSymbols($item) : $item;
        }, $filters);

        $date = '';
        if (array_see('date', $filters)) {
            $date = array_see('date', $filters);
        }
        $products = null;
        $productCodes = array_see('contain', $filters);
        if ($productCodes) {
            $products = Product::whereIn('code1c', $productCodes)->get();
        }

        $legalEntityId = array_see('legal_entity', $filters);
        if ($legalEntityId !== null) {
            $legalEntity = LegalEntity::where(['inn', '=', $legalEntityId])->apply('access')->get()->transform(function ($item) {
                $item->text = __('interface.ORDER_TIN') . ': ' . $item->inn;
                if (!empty($item->name)) {
                    $item->text = $item->name . ' (' . __('interface.ORDER_TIN') . ': ' . $item->inn . ')';
                }
                return $item;
            })->first();
        } else {
            $legalEntity = null;
        }

        $orders = null;
        $ordersUids = array_see('number', $filters);
        if ($ordersUids) {
            $db_orders = Order::access($user)->whereIn('uid', $ordersUids)->get();
            foreach ($db_orders as $order) {
                $orders[] = new OrderHint($order);
            }
        }

        $stocks = null;
        $stocksCodes = array_see('stock', $filters);
        if ($stocksCodes !== null) {
            $stocks = Stock::translate($language)->whereIn('code', $stocksCodes)->get();
        }

        $sort = sortParse($request->all());
        if (count($sort) > 0) {
            $filters['sort'] = $sort;
        }
        $filteredList = $this->shipmentsLogic($filters);
        $shipmentGroups = $filteredList->getItems();

        $breadcrumbs = new Breadcrumb;
        $breadcrumbs->addInitial()->add(sroute('warehouse.shipments'), __('interface.PORTAL_MENU_WAREHOUSE_SHIPMENTS'));

        return view('warehouse.shipments.list', [
            'breadcrumbs'  =>  $breadcrumbs,
            'shipmentGroups'  =>  $shipmentGroups,
            'orders'  =>  $orders,
            'name' => array_see('name', $filters),
            'products'  =>  $products,
            'stocks'  =>  $stocks,
            'navigation'  => $filteredList->getStats(),
            'date'  =>  $date,
            'filters'  =>  $filters,
            'legalEntity'  =>  $legalEntity,
        ]);
    }


    /**
     * Показывает страницу "В пути на склад и на складе"
     *
     * Route: [GET] /warehouse/products/
     *
     * @return View
     */
    public function list_products(Request $request): View
    {
        $language = App::make(Language::class);
        $filters = $request->all();
        $filters = array_map(function ($item) {
            return is_string($item) ? removeUnicodeSymbols($item) : $item;
        }, $filters);

        $sort_string = array_see('sort', $filters, null);
        $sort = sortParse($filters);

        if (is_string($sort_string)) {
            $filters['sort'] = $sort;
        }

        $legalEntityId = array_see('legal_entity', $filters);
        if ($legalEntityId !== null) {
            $legalEntity = LegalEntity::where(['inn', '=', $legalEntityId])->first();
        } else {
            $legalEntity = null;
        }

        $dealerByCode = '';
        if (array_see('dealer_by_code', $filters)) {
            $dealer = Dealer::where(['dealer_code', '=', array_see('dealer_by_code', $filters)])->first();
            if ($dealer instanceof Dealer) {
                $dealerByCode = $dealer->dealer_code;
            }
        }

        $dealerByName = '';
        if (array_see('dealer_by_name', $filters)) {
            $dealer = Dealer::translate($language)->where(['dealer_code', '=', array_see('dealer_by_name', $filters)])->first();
            if ($dealer instanceof Dealer) {
                $dealerByName = $dealer->name;
            }
        }
        $orders = Order::whereIn('uid', array_see('numbers', $filters, []))->get();

        $product_name = '';
        if (array_see('product', $filters)) {
            $product = Product::translate($language)->where(['uid', (int)array_see('product', $filters)])->first();
            if ($product instanceof Product) {
                $product_name = $product->name;
            }
        }

        $stocks = Stock::translate($language)->whereIn('code', array_see('stock', $filters, []))->get();
        $productsDto = $this->requestProducts($filters);
        $breadcrumbs = new Breadcrumb;
        $breadcrumbs->addInitial()->add(sroute('warehouse.products.list'), __('interface.INDELIVERY_AND_INSTOCK'));
        $checked = [
            'all' => false,
            'checked' => []
        ];
        return view('warehouse.products.list', [
            'breadcrumbs'  =>  $breadcrumbs,
            'filters'  =>  $filters,
            'dealerByCode'  =>  $dealerByCode,
            'dealerByName'  =>  $dealerByName,
            'legalEntity'  =>  $legalEntity,
            'orders'  =>  $orders,
            'product_name'  =>  $product_name,
            'stocks'  =>  $stocks,
            'showDealer'    =>  (int)can('order_dealer_information_view') + user()->data->additional_dealers->count(),
            'products'  =>  $productsDto->getItems(),
            'navigation'  =>  $productsDto->getStats(),
            'checked' => $checked
        ]);
    }

    /**
     * Возвращает список товаров на отгрузку
     *
     * Route: [POST] /warehouse/products/api/
     * 
     * @param Request $request Экземляр запроса
     * @return JsonResponse
     */
    public function apiGetProducts(Request $request): JsonResponse
    {
        /* Разбор параметров */
        $inputs = $request->input('params', []);
        $parameters = $request->all();
        $sort = array_see('sort', $parameters, null);
        $sort_string = sortString($parameters);
        if (is_string($sort_string)) {
            $inputs['sort'] = $sort;
        }
        $checked = array_see('checked', $parameters, [
            'all' => 'false',
            'checked' => []
        ]);
        $this->checkedProductsUids = $checked['checked'] ?? [];
        $this->validateCheckedProducts($this->checkedProductsUids);
        $productsDto = $this->requestProducts($inputs);
        if (is_string($sort_string)) {
            $inputs['sort'] = $sort_string;
        }
        $parameterString = prepareFiltersGetParams($inputs);

        return response()->json([
            "uri" => $parameterString,
            "sections" => [
                "items" => view('warehouse.products.items', ['products' => $productsDto->getItems(), 'navigation' => $productsDto->getStats(), 'filters' => $parameters, 'showDealer'    =>  (int)can('order_dealer_information_view') + user()->data->additional_dealers->count(), 'checked' => $checked])->render(), //вёрстка блока "список элементов"
                "actions" => view('warehouse.products.actions', ['checked' => $checked])->render(),
                "pagination_info" => view('layouts.pagination.show_items', ['navigation' => $productsDto->getStats()])->render(),
                "pagination_links" => view('layouts.pagination.pagination_links', ['navigation' => $productsDto->getStats()])->render(),
                "per_page" => view('layouts.pagination.per_page_select', ['navigation' => $productsDto->getStats()])->render(),
                "sort" => "",
            ],
            'checked' => $this->checkedProductsUids,
            'checked_obj' => $checked
        ]);
    }


    /**
     * Возвращает данные для списка истории заявок на отгрузку
     *
     * @param array $requestParameters - параметры запроса
     * @return ListAggregate
     */
    protected function shipmentsLogic(array $requestParameters): ListAggregate
    {
        $lang = App::make(Language::class);
        $perPage =  array_see('perpage', $requestParameters) ?? 10;
        $pageNumber = array_see('page', $requestParameters) ?? 1;

        $parameters = [];
        $parameters['pagination']['page'] = (int)$pageNumber;
        $parameters['pagination']['perpage'] = (int)$perPage;
        $parameters['sort'] = array_see('sort', $requestParameters, ['date_create' => 'desc']);
        $parameters['search']['fields'] = [''];
        $parameters['search']['value'] = '';
        $parameters['filters'] =  [];

        foreach ($requestParameters as $field => $filter_val) {
            if ($field == 'date' and !empty($filter_val)) {
                // фильтр приходит в виде 17.04.2024 - 26.04.2024, поэтому его приходится разбивать
                $dates = explode(' - ', $filter_val);
                $dateFrom = date('Y-m-d', strtotime($dates[0]));
                $dateTo = date('Y-m-d', strtotime($dates[1]));

                $parameters['filters'][$field]['value'] = $dateFrom;
                $parameters['filters'][$field]['value2'] = $dateTo;
            } else {
                if (isset($filter_val)) {
                    $parameters['filters'][$field]['value'] = $filter_val;
                }
            }
        }
        $listAggregate = getFilteredList(new ShipmentGroup, $parameters);
        $shipmentAppealsData = $listAggregate->getItems()->getLinkedData('shipment_appeal');
        $dealers_ids = $shipmentAppealsData->pluck('dealer_link')->toArray();
        $orders_ids = $shipmentAppealsData->pluck('order_link')->toArray();
        $stocks_ids = $shipmentAppealsData->pluck('stock_link')->toArray();
        $entity_inns = $shipmentAppealsData->pluck('inn')->toArray();
        $db_dealers = Dealer::whereIn('uid', $dealers_ids)->get();
        $db_orders = Order::whereIn('uid', $orders_ids)->get();
        $db_stocks = Stock::whereIn('uid', $stocks_ids)->translate($lang)->get();
        $db_entities = LegalEntity::whereIn('inn', $entity_inns)->active()->get();
        $timeWindows = ShipmentTimeWindow::all();

        stackStore('parameters', $parameters, __CLASS__, __FUNCTION__, __LINE__);
        $listAggregate->getItems()->setModelProcessor(function () use ($db_dealers, $db_entities, $db_orders, $db_stocks, $timeWindows) {
            $dealers = collect();
            $entities = collect();
            $shipment_appeals = $this->shipment_appeal;
            foreach ($shipment_appeals as $shipment) {
                $dialerId = $shipment->dealer_link;
                $orderId = $shipment->order_link;
                $stockId = $shipment->stock_link;
                $entityInn = $shipment->inn;
                $dealer = $db_dealers->firstWhere('uid', $dialerId);
                $entity = $db_entities->firstWhere('inn', $entityInn);
                $order = $db_orders->firstWhere('uid', $orderId);
                $stock = $db_stocks->firstWhere('uid', $stockId);
                $shipment->order = $order->order_link_1c;
                $shipment->order_group = $order->group;
                $shipment->order_id = $order->uid;

                $shipment->stock = $stock->name;
                $shipment->shipment_date = date(app('DigitalDateFormat'), strtotime($shipment->shipment_date));
                $timeWindow = $timeWindows->firstWhere('uid', $shipment->time_window_link);
                $shipment->time_window = $timeWindow instanceof ShipmentTimeWindow ? $timeWindow->name : '';
                if ($dealer instanceof Dealer) {
                    $issetDealer = $dealers->get($dialerId);
                    $issetEntity = $entities->get($entityInn);

                    $dealerElement = new stdClass;
                    $dealerElement->name = $dealer->name . '<br>' . $dealer->dealer_code;
                    $dealerElement->rowspan = 1;
                    $dealerElement->entities = collect();

                    $entityElement = new stdClass;
                    $entityElement->name = $entity instanceof LegalEntity ? $entity->name . '<br>' . $entity->inn : __('interface.NOT_SET');
                    $entityElement->shipments = collect();
                    $entityElement->rowspan = 1;

                    if (!$entityInn) $entityInn = 'null';
                    if ($issetDealer === null) {
                        $dealers->put($dialerId, $dealerElement);
                        if ($issetEntity === null) {
                            $entities->put($entityInn, collect());
                            $dealers[$dialerId]->entities->put($entityInn, $entityElement);
                            $dealers[$dialerId]->entities[$entityInn]->shipments->push($shipment);
                        } else {
                            $dealers[$dialerId]->entities[$entityInn]->shipments->push($shipment);
                            $dealers[$dialerId]->entities[$entityInn]->rowspan += 1;
                        }
                    } else {
                        $dealers[$dialerId]->rowspan += 1;
                        if ($issetEntity === null) {
                            $entities->put($entityInn, collect());
                            $dealers[$dialerId]->entities->put($entityInn, $entityElement);
                            $dealers[$dialerId]->entities[$entityInn]->shipments->push($shipment);
                        } else {
                            $dealers[$dialerId]->entities[$entityInn]->shipments->push($shipment);
                            $dealers[$dialerId]->entities[$entityInn]->rowspan += 1;
                        }
                    }
                }
            }
            $user = User::find($this->user_link);
            $this->rowspan = $shipment_appeals->count();
            $this->user_name = $user instanceof User ? $user->LAST_NAME . ' ' .  $user->name  . ' ' . $user->SECOND_NAME : $user;
            $this->dealers = $dealers;
            $this->date_create = date(app('DigitalDateFormat'), strtotime($this->date_create));
        });

        //         foreach ($listAggregate->getItems() as $item) {
        //             dump($item);
        //         }
        // dd(123);
        return $listAggregate;
    }


    /**
     * Возвращает данные для списка истории заявок на отгрузку
     *
     * Route: [POST] /warehouse/shipment-history/api/
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apiGetlist(Request $request)
    {
        $user = User::actual();
        $language = App::make(Language::class);
        $parameters = $request->all();
        $parameters = array_map(function ($item) {
            return is_string($item) ? removeUnicodeSymbols($item) : $item;
        }, $parameters);
        $legalEntity = array_see('legal_entity', $parameters);
        $name = array_see('name', $parameters);

        $inputs = $request->input('params', []);
        $parameters = $request->all();
        $sort = array_see('sort', $parameters, null);
        $sort_string = sortString($parameters);
        if (is_string($sort_string)) {
            $inputs['sort'] = $sort;
        }
        $shipmentDto = $this->shipmentsLogic($inputs);
        $shipmentGroups = $shipmentDto->getItems();
        if (is_string($sort_string)) {
            $inputs['sort'] = $sort_string;
        }

        $parameterString = prepareFiltersGetParams($inputs);

        return response()->json([
            "uri" => $parameterString,
            "sections" => [
                "items" => view('warehouse.shipments.items', [
                    'shipmentGroups' => $shipmentGroups,
                    'navigation' => $shipmentDto->getStats(),
                    'filters' => $parameters,
                    'legalEntity' => $legalEntity,
                ])->render(), //вёрстка блока "список элементов"
                "actions" => "",
                "pagination_info" => view('layouts.pagination.show_items', ['navigation' => $shipmentDto->getStats()])->render(),
                "pagination_links" => view('layouts.pagination.pagination_links', ['navigation' => $shipmentDto->getStats()])->render(),
                "per_page" => view('layouts.pagination.per_page_select', ['navigation' => $shipmentDto->getStats()])->render(),
                "sort" => "",
            ]
        ]);
    }


    /**
     * Получение данных для таблицы товаров пути и на складе
     *
     * @param array $requestParameters - параметры запроc
     * @return ListAggregateDTO
     */
    protected function requestProducts(array $requestParameters): ListAggregateDTO
    {
        $language = App::make(Language::class);
        $perPage =  array_see('perpage', $requestParameters) ?? 10;
        $pageNumber = array_see('page', $requestParameters) ?? 1;

        $parameters = [];
        $parameters['pagination']['page'] = (int)$pageNumber;
        $parameters['pagination']['perpage'] = (int)$perPage;
        $parameters['sort'] = array_see('sort', $requestParameters, ['final_date' => 'desc']);
        $parameters['search']['fields'] = [''];
        $parameters['search']['value'] = '';
        $parameters['filters'] =  [];
        foreach ($requestParameters as $field => $filter_val) {
            if (($field == 'date' || $field == 'shipment_date') and !empty($filter_val)) {
                // фильтр приходит в виде 17.04.2024 - 26.04.2024, поэтому его приходится разбивать
                $dates = explode(' - ', $filter_val);
                $dateFrom = date('Y-m-d', strtotime($dates[0]));
                $dateTo = date('Y-m-d', strtotime($dates[1]));

                $parameters['filters'][$field]['value'] = $dateFrom;
                $parameters['filters'][$field]['value2'] = $dateTo;
            } else {
                if (isset($filter_val)) {
                    $parameters['filters'][$field]['value'] = $filter_val;
                }
            }
        }
        $parameters['filters']['place']['value'] = 'for_request';

        $listAggregate = getFilteredList(new OrderProduct, $parameters);

        stackStore('parameters', $parameters, __CLASS__, __FUNCTION__, __LINE__);

        $queryInStock = $listAggregate->getItems();


        $productCodes = Product::translate($language)
            ->whereIn('code1c', $queryInStock->pluck('product_link')->all())
            ->get()
            ->toKeyed('code1c');

        $getroductFileByCode = [];
        $productFiles = DB::table('pr_files')->where('FILE_TYPE_ID', '=', static::PORTAL_MAIN_IMAGE_TYPE)->whereIn('CODE_1C', $productCodes)->get();
        foreach ($productFiles as $productFile) {
            $getroductFileByCode[$productFile->CODE_1C] = getResizedImageFromPath($productFile->PATH, 'middle-data', 50, 50);
        }

        $products = Product::whereLinkedWith('order_products', $queryInStock)
            ->translate($language)
            ->get();
        $queryInStock->bindLinkedCollection('product', $products);
        $orders = Order::whereLinkedWith('products', $queryInStock)
            ->translate($language)
            ->get();
        $entities = LegalEntity::whereLinkedWith('orders', $orders)
            ->translate($language)
            ->get();
        $dealer = Dealer::whereLinkedWith('orders', $orders)
            ->translate($language)
            ->get();
        $orders->bindLinkedCollection('entity', $entities);
        $orders->bindLinkedCollection('dealer', $dealer);
        $rates = App::make(RateStorage::class);
        $currencies = Currency::query()->get();
        $orders->bindLinkedCollection('currency', $currencies);
        $orders->setModelProcessor(function () use ($rates) {
            $currency = $this->currency;
            $rate = $rates->getByCode($currency->letter_code);
            $rateRub = $rates->getByCode('RUB');
            $this->less_to_pay = convertCurrency($this->total_sum - $this->paid, $rate->value, $rateRub->value);
        });
        $queryInStock->bindLinkedCollection('order', $orders);
        $stocks = Stock::whereLinkedWith('order_products', $queryInStock)
            ->translate($language)
            ->get();
        $queryInStock->bindLinkedCollection('stock', $stocks);
        $currencies = Currency::query()->get();
        $ordersCounted = $this->ordersCounted;
        $checkedProducts = $this->checkedProductsUids;
        $queryInStock->setModelProcessor(function () use ($getroductFileByCode, $currencies, &$ordersCounted, $checkedProducts) {
            $zno_rules = [];
            if (($this->order->inn === '') ||  ($this->order->inn === null)) {
                $this->legal_name = __('interface.NOT_SET');
                $zno_rules['failed_entity'] = __('interface.LEGAL_ENTITY_INFORM_NOT_FOUND_IN_ORDER');
            } else {
                $this->legal_name = $this->order->legal_entity_name . '(' . $this->order->inn . ')';
            }
            $this->name = $this->product->name ?? __('interface.NO_INFORMATION');
            $this->code1c = $this->product->code1c ?? __('interface.NO_INFORMATION');
            $this->link_1c = $this->order->order_link_1c ?? __('interface.NO_INFORMATION');
            $this->stock = $this->stock->name ?? __('interface.NO_INFORMATION');
            $this->order_url = sroute('orders.show', [$this->order->group], false, ['order_id' => $this->order_link]);
            $this->actualShipmentDate = date(app('DigitalDateFormat'), strtotime($this->actual_shipment_date));
            $this->date = date(app('DigitalDateFormat'), strtotime($this->shipment_date));
            $this->final_date = date(app('DigitalDateFormat'), strtotime($this->final_date));
            $this->last_time =  ceil((time() - strtotime($this->date)) / 86400);
            $this->product_photo = array_key_exists($this->product->code1c, $getroductFileByCode) ? $getroductFileByCode[$this->product->code1c] : '/img/noproductpicture-50.svg';
            $this->order_link_1c = sroute('catalog.product', [$this->product->code1c]);
            $this->product_url = sroute('catalog.product', [$this->product->code1c]);
            $this->comment = $this->comment;
            $this->dealer_code = $this->order->dealer_code;
            $this->dealer_name = $this->order->dealer_name;
            $this->status = '';
            $this->overduedebt = 0;
            $this->debt = 0;
            $this->credit_limit = 0;
            $this->shipment_limit = 0;
            $this->prepayment = 100;
            if ($this->status_link === 2) {
                $this->status = 'indelivery';
                $this->status_name = __('interface.WAREHOUSE_INDELIVERY');
                $zno_rules['failed_entity'] = __('interface.WAREHOUSE_INDELIVERY');
                $this->actualShipmentDateDiff = Carbon::parse(Carbon::today())->diffInDays($this->actual_shipment_date, false);
                $this->inDeliveryDays = Carbon::parse($this->order->order_date)->diffInDays(Carbon::now(), false);
                $this->inDeliveryDaysText = __('interface.ON_WAY') . ' ' . $this->inDeliveryDays . ' ' . __('interface.DAYS_BRIEF');
                $this->inDeliveryColor = 'primary';
                if ($this->actualShipmentDateDiff == 0) {
                    $this->actualShipmentDateDiffText = __('interface.TODAY');
                    $this->inDeliveryPercent = 100;
                    $this->inDeliveryColor = 'success';
                } elseif ($this->actualShipmentDateDiff < 0) {
                    $this->actualShipmentDateDiffText = __('interface.DELAYED');
                    $this->inDeliveryPercent = 100;
                    $this->inDeliveryColor = 'danger';
                } else {
                    $this->actualShipmentDateDiffText = __('interface.DAYS_IN') . ' ' .
                        $this->actualShipmentDateDiff . ' ' . __('interface.DAYS_BRIEF');
                    $this->inDeliveryPercent = ((int)$this->inDeliveryDays / ((int)$this->inDeliveryDays + (int)$this->actualShipmentDateDiff)) * 100;
                }
            }
            $this->finalDateExpired = false;
            $this->finalDateComing = false;
            if ($this->status_link === 3) {
                $this->status = 'instock';
                $this->status_name = __('interface.IN_STOCK');
                $finalDate = Carbon::parse($this->final_date)->startOfDay();
                $nowDate = Carbon::now()->startOfDay();
                if ($finalDate->lt($nowDate)) {
                    $this->finalDateExpired = true;
                } elseif ($nowDate->diff($finalDate)->days <= 7) {
                    $this->finalDateComing = true;
                }
            }
            if ($this->order->tags != null) {
                $this->tags = json_decode($this->order->tags);
            } else {
                $this->tags = [];
            }
            if (in_array($this->order->order_status_link, ShipmentRequestsController::ORDER_STATUS_LINKS)) {
                $zno_rules['order_status'] = __('interface.SHIPMENT_IS_NOT_POSSIBLE_IN_THE_STATUS') . ' "' . $this->order->order_status_name . '".';
            }
            if ($this->order->entity instanceof LegalEntity) {
                $this->prepayment = (int)$this->order->entity->prepayment;
            } else {
                $zno_rules['failed_entity'] = __('interface.LEGAL_ENTITY_INFORM_NOT_FOUND_IN_ORDER');
            }
            if ($this->order->dealer instanceof Dealer) {
                $this->overduedebt    = (int) $this->order->dealer->overduedebt;
                $this->debt           = (int) $this->order->dealer->debt;
                $this->credit_limit   = (int) $this->order->dealer->credit_limit;
                if ($this->order->dealer->dealer_code == '00002387') {
                    $this->prepayment    = 0;
                }
            }
            if ($this->overduedebt > 0) {
                $overduedebt_formated = $currencies->where('letter_code', 'RUB')
                    ->first()
                    ->formatSum($this->overduedebt);
                $zno_rules['overduedebt'] = '<b>' . __('interface.SHIPMENT_IS_NOT_POSSIBLE') . '.</b><br>' . __('interface.YOU_HAVE_DEBT') . ' <b>' . $overduedebt_formated . '</b>';
            }
            //(!(($this->total_sum > 0) && ((($this->paid + 3) / ($this->total_sum / 100)) > $this->prepayment))) || ($this->credit_limit - $this->less_to_pay) <= 0
            if ((!(($this->order->total_sum > 0) && ((($this->order->paid + 3) / ($this->order->total_sum / 100)) > $this->prepayment)) || (($this->credit_limit - $this->order->less_to_pay) <= 0)) && (!isset($ordersCounted[$this->order_link]))) {
                $zno_rules['prepayment'] = __('interface.UNABLE_TO_CREATE_SHIPPING_REQUISITION_PAYMENT_IS_LOWER');
            }
            $this->order_status_code = $this->order->order_status_code;
            $this->order_status_name = $this->order->order_status_name;
            $this->available_for_shipment = count($zno_rules) === 0;
            $this->zno_rules = $zno_rules;
            $this->checked = in_array($this->uid, $checkedProducts);
            return $this;
        });

        return new ListAggregateDTO($queryInStock, $listAggregate->getStats());
    }

    /**
     * Выбор товаров на отгрузку
     *
     * * Route: [POST] /warehouse/products/api/checkbox
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkbox(Request $request): JsonResponse
    {
        $checked = $request->input('checked', [
            'all' => false,
            'checked' => []
        ]);
        $all = $checked['all'] ?? false;
        $requestParameters = $request->input('params', []);
        $perPage =  array_see('perpage', $requestParameters) ?? 10;
        $pageNumber = array_see('page', $requestParameters) ?? 1;
        $parameters = [];
        $parameters['pagination']['page'] = (int)$pageNumber;
        $parameters['pagination']['perpage'] = (int)$perPage;
        $parameters['sort'] = array_see('sort', $requestParameters, ['uid' => 'desc']);
        $parameters['search']['fields'] = [''];
        $parameters['search']['value'] = '';
        $parameters['filters'] =  [];
        foreach ($requestParameters as $field => $filter_val) {
            if (($field == 'date' || $field == 'shipment_date') and !empty($filter_val)) {
                // фильтр приходит в виде 17.04.2024 - 26.04.2024, поэтому его приходится разбивать
                $dates = explode(' - ', $filter_val);
                $dateFrom = date('Y-m-d', strtotime($dates[0]));
                $dateTo = date('Y-m-d', strtotime($dates[1]));

                $parameters['filters'][$field]['value'] = $dateFrom;
                $parameters['filters'][$field]['value2'] = $dateTo;
            } else {
                if (isset($filter_val)) {
                    $parameters['filters'][$field]['value'] = $filter_val;
                }
            }
        }
        $parameters['filters']['place']['value'] = 'instock';
        $parser = new ParametersParser($parameters);
        if ($all === 'true') {
            $orders = Order::query()
                ->whereNotNull('inn')
                ->where(['inn', '!=', ''])
                ->active()
                ->where(['dealer.overduedebt', '=', 0])
                ->join('uid', '=', 'order_product.order_link', 'inner')
                ->where(['order_product.price', '>', 0])
                ->where(['payment_status', '!=', 'none'])->where(['order_product.status_link', 3])
                ->where(['order_product.status_quantity', '>', 0])
                ->access(user())
                ->get();
            $orderProductQuery = OrderProduct::whereLinkedWith('order', $orders)->access(user());
            $orderProducts = (new OrderProductRequests($orderProductQuery))->execute($parser->filtersData, $parser->searchData, $parser->sortData)->get();
            $orders = Order::whereIn('uid', $orderProducts->pluck('order_link')->all())
                ->with(['dealer', 'entity', 'currency'])
                ->whereNotNull('inn')
                ->where(['inn', '!=', ''])
                ->active()
                ->where(['dealer.overduedebt', '=', 0])
                ->join('uid', '=', 'order_product.order_link', 'inner')
                ->where(['order_product.price', '>', 0])
                ->where(['payment_status', '!=', 'none'])->where(['order_product.status_link', 3])
                ->where(['order_product.status_quantity', '>', 0])
                ->get();
            $shipment_limits = $orders->getLinkedData('dealer')->transform(function ($item) {
                $item->shipment_limit = $item->credit_limit -= $item->debt;
                return $item;
            })->pluck('shipment_limit', 'uid')->all();
            $ordersCounted = [];
            /**
             * @var RateStorage
             */
            $rates = App::make(RateStorage::class);
            $orders->setModelProcessor(function () use ($rates, $shipment_limits) {
                $currency = $this->currency;
                $rate = $rates->getByCode($currency->letter_code);
                $rateRub = $rates->getByCode('RUB');
                $this->less_to_pay = convertCurrency($this->total_sum - $this->paid, $rate->value, $rateRub->value);
                $this->prepayment = (int)$this->entity->prepayment;
                $this->credit_limit   = (int) $shipment_limits[$this->dealer_link];
                if ($this->dealer->dealer_code == '00002387') {
                    $this->prepayment    = 0;
                }

                if ((!(($this->total_sum > 0) && ((($this->paid + 3) / ($this->total_sum / 100)) > $this->prepayment))) || ($this->credit_limit - $this->less_to_pay) <= 0) {
                    $this->valid = 0;
                } else {
                    $this->valid = 1;
                }
                return $this;
            });
            $orders = $orders->recollect()->where('valid', '=', true);
            $orderProducts = $orderProducts->whereIn('order_link', $orders->pluck('uid')->all());
            $orderProducts->bindLinkedCollection('order', $orders);
            $orderProducts->setModelProcessor(function () use (&$ordersCounted, &$shipment_limits) {
                if ((int) $shipment_limits[$this->order->dealer_link] - $this->order->less_to_pay >= 0 || isset($ordersCounted[$this->order_link])) {
                    $this->checked = true;
                    if (!isset($ordersCounted[$this->order_link])) {
                        $shipment_limits[$this->order->dealer_link] -= $this->order->less_to_pay;
                        $ordersCounted[$this->order_link] = true;
                    }
                } else {
                    $this->checked = false;
                }
            });
            $orderProducts->recollect();
            $checkedProducts = $orderProducts->where('checked', '=', true);
            $this->ordersCounted = $ordersCounted;
        } else {
            $checkedUids = $checked['checked'] ?? [];
            $checkedUids = array_unique($checkedUids);
            $checkedProducts = $this->validateCheckedProducts($checkedUids)->where('valid', '=', 1);
            if ($checkedProducts->count() !== count($checkedUids)) {
                return response([
                    'success' => false,
                    'message' => __('interface.CANT_BE_CHECK')
                ]);
            }
        }
        $checkedProductsUids = $checkedProducts->pluck('uid')->all();
        return response()->json([
            'success' => true,
            'message' => '',
            'checked' => [
                'all' => $all,
                'checked' => $checkedProductsUids
            ],
        ]);
    }

    /**
     * Валидация выбранных товаров на отгрузку
     *
     * @param array $checked айдишник выбранных товаров на отгрузку
     * @return ModelStorage
     */
    public function validateCheckedProducts(array $checked): ModelStorage
    {
        $orderProducts = OrderProduct::whereIn('uid', $checked)->get();
        $filtersData = new FiltersData(['place' => ['value' => 'for_request']]);
        $orderProductQuery = OrderProduct::whereIn('uid', $checked)->access(user());
        $inputs = [];
        $inputs['pagination'] = ['page' => 1, 'perpage' => 10];
        $inputs['sort'] = ['page' => 'desc'];
        $inputs['search'] = ['fields' => [''], 'value' => ''];
        $inputs['filters'] = [];
        $parser = new ParametersParser($inputs);
        $orderProductsAccess = (new OrderProductRequests($orderProductQuery))->execute($filtersData, new SearchData([''], ''), $parser->sortData)->whereIn('uid', $checked)->get();
        $orders = Order::whereLinkedWith('products', $orderProductsAccess)->access(user())->active()->get();

        $dealers = Dealer::whereLinkedWith('orders', $orders)
            ->where(['overduedebt', '=', 0])
            ->get();
        $entities = LegalEntity::whereLinkedWith('orders', $orders)
            ->get();
        $shipment_limits = $dealers->transform(function ($item) {
            $item->shipment_limit = $item->credit_limit -= $item->debt;
            return $item;
        })->pluck('shipment_limit', 'uid')->all();
        /**
         * @var RateStorage
         */
        $rates = App::make(RateStorage::class);
        $currencies = Currency::query()->get();
        $orders->bindLinkedCollection('currency', $currencies);
        $orders->bindLinkedCollection('entity', $entities);
        $orders->bindLinkedCollection('dealer', $dealers);
        $orders->setModelProcessor(function () use ($rates, $shipment_limits) {
            $currency = $this->currency;
            $rate = $rates->getByCode($currency->letter_code);
            $rateRub = $rates->getByCode('RUB');
            $this->less_to_pay = convertCurrency($this->total_sum - $this->paid, $rate->value, $rateRub->value);
            $this->prepayment = (int)$this->entity->prepayment;
            $this->credit_limit   = (int) $shipment_limits[$this->dealer_link] ?? 0;
            if ($this->dealer->dealer_code == '00002387') {
                $this->prepayment    = 0;
            }
            if ((!(($this->total_sum > 0) && ((($this->paid + 3) / ($this->total_sum / 100)) > $this->prepayment))) || ($this->credit_limit - $this->less_to_pay) <= 0) {
                $this->valid = false;
            } else {
                $this->valid = true;
            }
            return $this;
        });
        $orders = $orders->recollect()->where('valid', '=', true);
        $orderProductsAccess->bindLinkedCollection('order', $orders);
        $ordersCounted = [];
        $orderProducts->transform(function ($item) use ($orderProductsAccess, &$shipment_limits, &$ordersCounted) {
            $item->valid = 1;
            $orderProduct = $orderProductsAccess->xfirstWhere('uid', $item->uid);
            if (!$orderProduct instanceof OrderProduct || $item->status_link !== 3) {
                $item->valid = 0;
                return $item;
            }
            if (!$orderProduct->order instanceof Order) {
                $item->valid = 0;
                return $item;
            }
            if (!$orderProduct->order->entity instanceof LegalEntity) {
                $item->valid = 0;
                return $item;
            }
            if (!$orderProduct->order->dealer instanceof Dealer) {
                $item->valid = 0;
                return $item;
            }
            if (in_array($orderProduct->order->order_status_link, ShipmentRequestsController::ORDER_STATUS_LINKS)) {
                $item->valid = 0;
                return $item;
            }
            if ($shipment_limits[$orderProduct->order->dealer_link] - $orderProduct->order->less_to_pay >= 0 || isset($ordersCounted[$orderProduct->order_link])) {
                if (!isset($ordersCounted[$orderProduct->order_link])) {
                    $shipment_limits[$orderProduct->order->dealer_link] -= $orderProduct->order->less_to_pay;
                    $ordersCounted[$orderProduct->order_link] = true;
                }
            } else {
                $item->valid = 0;
                return $item;
            }
            return $item;
        });
        $this->ordersCounted = $ordersCounted;
        return $orderProducts;
    }

    /**
     * Показывает детальную группы ЗНО
     *
     * Route: [GET] /warehouse/shipments/{id}/
     *
     * @param Request $request
     * @param integer $id
     * @return View
     */
    public function show(Request $request, string $id): View
    {
        $statusesStyles = [
            'created'       => 'light',
            'success'       => 'success',
            'prepare'       => 'warning',
            'closed'        => 'dark',
            'canceled'      => 'danger',
            'new'           => 'warning',
            'sendto1c'      => 'light'
        ];

        $lang = App::make(Language::class);
        $group = ShipmentGroup::access(user())->with(['shipment_appeal'])->translate($lang)->where(['group', $id])->first();
        $shipments = $group->shipment_appeal;

        $activeShipmentId = $request->input('shipment_id');
        if ($activeShipmentId === null) {
            $activeShipmentId = $shipments->first()->uid;
        } else {
            $activeShipmentId = (int) $activeShipmentId;
        }

        $shipmentsProducts = ShipmentAppealProduct::whereLinkedWith('appeal', $shipments)->get();
        $orders = Order::whereLinkedWith('shipments', $shipments)->get();
        $ordersProducts = OrderProduct::whereLinkedWith('order', $orders)->get();
        $products = Product::whereLinkedWith('shipment_appeal_products', $shipmentsProducts)->get();
        $currencies = Currency::whereLinkedWith('orders', $orders)->get();;
        $entities = LegalEntity::whereLinkedWith('orders', $orders)->get();
        $stocks = Stock::translate($lang)->whereLinkedWith('shipment_appeals', $shipments)->get();
        $deliveryTypes = DeliveryType::translate($lang)->whereLinkedWith('shipment_appeals', $shipments)->get();
        $timeWindows = ShipmentTimeWindow::whereLinkedWith('shipment_appeal', $shipments)->get();
        $files = OrderFile::whereLinkedWith('order', $orders)->get();

        $shipmentsProducts->bindLinkedCollection('product', $products);
        $orders->bindLinkedCollection('entity', $entities);
        $orders->bindLinkedCollection('currency', $currencies);
        $orders->bindLinkedCollection('products', $ordersProducts);
        $orders->bindLinkedCollection('files', $files);
        $shipments->bindLinkedCollection('order', $orders);
        $shipments->bindLinkedCollection('stock', $stocks);
        $shipments->bindLinkedCollection('shipment_appeal_products', $shipmentsProducts);
        $shipments->bindLinkedCollection('delivery', $deliveryTypes);
        $shipments->bindLinkedCollection('time_window', $timeWindows);

        $shipmentsCodes = $shipments->pluck('code1c')->toArray();
        $allProductsShipped = Shipment::whereIn('shipment_appeal_code', $shipmentsCodes)->get();

        $shipments->setModelProcessor(function () use ($activeShipmentId, $allProductsShipped, &$group) {
            $this->sum = 0;
            $this->order_code1c = '';
            $this->entity = '';
            $this->stock_name = '';
            $this->time_window_interval = '';

            $this->order->calculate('bills', 'invoices', 'offers', 'invs');
            $order = $this->order;
            if ($order instanceof Order) {
                $this->order_code1c = $order->order_link_1c;
                $legalEntity = $this->order->entity;
                $this->entity = $legalEntity instanceof LegalEntity ? $legalEntity->name : '';
                $orderProductsArray = $this->order->products->toKeyed('product_link');
                $shipmentSum = 0;
                $products = [];
                foreach ($this->shipment_appeal_products as $shipmentProduct) {
                    $shipmentProduct->price = 0;
                    $shipmentProduct->sum = 0;
                    $shipmentProduct->shipment_status = '-';
                    $shipmentProduct->is_service = false;
                    $shipmentProduct->finalDateExpired = false;
                    $shipmentProduct->finalDateComing = false;
                    $shipmentProduct->thumb = '';
                    $product = $shipmentProduct->product;
                    if ($product instanceof Product) {
                        $shipmentProduct->is_service = $product->is_service;
                        $shipmentProduct->thumb = $product->thumb;
                        $orderProduct = $orderProductsArray[$product->uid] ?? null;
                        $shipmentSum += $orderProduct instanceof OrderProduct ? $orderProduct->price : 0;
                        $price = $orderProduct instanceof OrderProduct ? $orderProduct->price : 0;
                        $currency_symbol = $order->currency->symbol_code ?? '';
                        $shipmentProduct->price = number_format(((int)$price), 2, '.', ' ') . ' ' . $currency_symbol;
                        $shipmentProduct->sum = number_format((int)($price * $shipmentProduct->quantity), 2, '.', ' ') . ' ' . $currency_symbol;
                        $shipmentProduct->shipment_date = Date::parse($orderProduct->shipment_date)->format(app('DigitalDateFormat'));
                        $shipmentProduct->final_shipment_date = Date::parse($orderProduct->final_date)->format(app('DigitalDateFormat'));

                        $finalDate = Carbon::parse($orderProduct->final_date)->startOfDay();
                        $nowDate = Carbon::now()->startOfDay();
                        if ($finalDate->lt($nowDate)) {
                            $shipmentProduct->finalDateExpired = true;
                        } elseif ($nowDate->diff($finalDate)->days <= 7) {
                            $shipmentProduct->finalDateComing = true;
                        }

                        $shippedProducts = $allProductsShipped
                            ->where('shipment_appeal_code', $this->code1c)
                            ->where('product_link', $product->uid);
                        $shippedQuantity = 0;
                        foreach ($shippedProducts as $product) {
                            $shippedQuantity += $product->quantity;
                        }

                        if (($shipmentProduct->quantity - $shippedQuantity) == 0)
                            $shipmentProduct->shipment_status = __('interface.SHIPPED');

                        $products[] = $shipmentProduct;
                    }
                }
                $this->products = $products;
                $currency = $this->order->currency;
                $currency_symbol = $currency instanceof Currency ? $currency->symbol_code : '';
                $this->total_sum = number_format(($shipmentSum), 2, '.', '') . ' ' . $currency_symbol;
            }

            $this->stock_name = $this->stock instanceof Stock ? $this->stock->name : '';
            $this->delivery_type = $this->delivery instanceof DeliveryType ? $this->delivery->text : '';
            $timeWindow = $this->time_window;
            $start_time = $timeWindow instanceof ShipmentTimeWindow ? Carbon::parse($timeWindow->start_time)->format('H:i') : '';
            $end_time = $timeWindow instanceof ShipmentTimeWindow ? Carbon::parse($timeWindow->end_time)->format('H:i') : '';
            $this->time_window_interval = $start_time . ' - ' . $end_time;
            $this->shipment_date_create = Date::parse($this->shipment_date_create)->format(app('VerboseDateFormatWithYear'));
            $this->shipment_date = Date::parse($this->shipment_date)->format(app('VerboseDateFormatWithYear'));
            $this->activeTab = ($activeShipmentId === $this->uid);
        });
        $group->shipment_appeal = $shipments;

        $shipmentsLinkForTitle = $shipments->pluck('code1c');
        $title = __('interface.SIPMENTS_GROUP') . ' [' . $shipmentsLinkForTitle->implode(', ') . ']';


        // foreach ($group->shipment_appeal as $shipment) {
        //     dd($shipment);
        // }

        return view(
            'warehouse.shipments.detail',
            [
                'statusesStyles' => $statusesStyles,
                'title' => $title,
                'group' => $group
            ]
        );
    }
}
