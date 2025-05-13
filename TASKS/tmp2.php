@extends('layouts.master')
@section('title', $title)
{{-- {{ dd($group); }} --}}
@section('scripts')
    <script src="/common/js/order/detail/lang/{{ $currentLanguage->code }}.js?version={{ date('Ymd') }}"></script>
    {{-- <script src="/common/js/order/detail/order-detail.js?version={{ time() }}"></script> --}}
    {{-- <script src="/common/js/order/detail/create-specification.js?version={{ time() }}"></script> --}}
    <script src="/common/js/wadreport/wad_report.js?version={{ time() }}"></script>
@endsection

@section('styles')
    <style>
        .mh-60 {
            max-height: 60px;
        }

        .mw-60 {
            max-width: 60px;
        }

        .mh-50 {
            max-height: 50px;
        }

        .mw-50 {
            max-width: 50px;
        }

        .accordion.accordion-toggle-arrow .card .card-header .card-title:after {
            padding-top: 6px;
            position: relative;
        }

        .accordion .card .card-header .card-title {
            font-size: 13px;
        }

        .timeline.timeline-6 .timeline-item .timeline-label {
            width: 85px;
        }

        .timeline.timeline-6:before {
            left: 86px;
        }

        .timeline .timeline-item:last-child {
            height: 100%;
            background: #fff;
        }

        @media screen and (max-width: 768px) {
            #order_actions_panel {
                max-width: 400px;
            }
        }

        @media screen and (max-width: 575px) {
            .timeline.timeline-6:before {
                /* display: none; */
                left: 14px;
                top: 30px;
            }

            .card.card-custom>.card-body {
                padding: 0.75rem;
            }

            .label.label-inline.order_status {
                padding-top: 1.75rem;
                padding-bottom: 1.75rem;
            }

            .order_shipment_appeal_creation_date {
                min-width: 125px;
            }

            .order_shipment_appeal_tin {
                min-width: 175px;
            }

            .order_products_table th,
            .order_products_table tr td {
                padding-right: 2rem;
            }

            .order_shipment_appeal_table th,
            .order_shipment_appeal_table tr td {
                padding-right: 2rem;
            }

            .order_info table tr {
                display: flex;
                flex-direction: column;
                padding-bottom: 0.6rem;
            }

            .order_info table tr td {
                text-align: left !important;
                padding: 0.1rem 0.4rem;
            }

            .order_mobile_sidebar .dropdown-item {
                padding: 0.4rem 0.75rem;
            }

            .order_mobile_sidebar .dropdown-item span {
                white-space: break-spaces;
            }

            .fancybox__content {
                padding: 20px;
            }
        }
    </style>
@endsection

@section('content')
    @include('layouts.parts.subheader_with_title')
    <div class="row">
        <!-- Основной сайдбар -->
        <div class="d-none d-md-block col-md-4 offset-md-0 col-lg-3 col-xxl-2 order_detail_sidebar">
            <div class="flex-md-row-auto">
                <div class="card card-custom gutter-b shadow">
                    <div class="card-header">
                        <div class="card-title">
                            <h3 class="card-label">
                                {{ __('interface.SELECT_SHIPMENT_APPEAL') }}
                            </h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <ul class="nav flex-column nav-pills">
                            @foreach ($group->shipment_appeal as $key => $shipment)
                                <li class="nav-item" role="presentation">
                                    <a class="nav-link btn mb-2 btn-outline-primary md_report_order @if ($shipment->activeTab) active @endif"
                                       id="order_tab_{{ $key }}" data-toggle="tab"
                                       href="#order_{{ $key }}" role="tab"
                                       aria-controls="order_{{ $key }}"
                                       aria-selected="@if ($shipment->activeTab) true @else false @endif">{{ $shipment->code1c }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="card card-custom gutter-b shadow" id="order_actions_panel">
                    <div class="card-header pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span
                                class="card-label font-weight-bolder text-dark">{{ __('interface.ORDER_OPERATIONS') }}</span>
                        </h3>
                    </div>
                    <div class="card-body pt-4">
                        <a href="javascript:;" class="btn-group mt-2 w-100" role="group" data-toggle="tooltip"
                            data-group="{{ $group->group }}" style="">
                            <button type="button" class="btn btn-primary btn-sm w-25"><i
                                    class="fas fa-file-download icon-md pr-0" ></i></button>
                            <button class="btn btn-outline-primary btn-block btn-sm w-75" type="button">
                                {{ __('interface.DOWNLOAD_TO_EXCEL') }}
                            </button>
                        </a>

                        <hr>

                        <div class="btn-group mt-2 w-100" role="group" data-toggle="tooltip">
                            <button type="button" class="btn btn-primary btn-sm w-25"><i
                                    class="fa fa-weight-hanging icon-md pr-0"></i></button>
                            <button class="btn btn-outline-primary btn-block btn-sm w-75" type="button"
                                    data-toggle="dropdown">{{ __('interface.MAJORBARIS_REPORT') }}</button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item wad_report__excel"
                                    href="{{ sroute('wadreport.createecxel') }}">Excel</a>
                                <a class="dropdown-item wad_report__pdf"
                                    href="{{ sroute('wadreport.createpdf') }}">PDF</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Альтернативный сайдбар для мобильного варианта -->
        <div class="col-12 d-md-none order_mobile_sidebar">
            <div class="card card-custom gutter-b">
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 col-sm-6 form-group">
                            <select class="form-control orders_select_mobile">
                                @foreach ($group->shipment_appeal as $key => $shipment)
                                    <option value="order_{{ $key }}"
                                            @if ($shipment->activeTab) selected @endif>{{ $shipment->code1c }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <button class="btn btn-primary font-weight-bold order_actions_button" type="button">
                                {{ __('interface.ORDER_OPERATIONS') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-8 col-lg-9 col-xxl-10 order_detail_main">
            <div class="tab-content" id="myTabContent">
                @foreach ($group->shipment_appeal as $key => $shipment)
                    <div class="tab-pane fade @if ($shipment->activeTab) show active @endif"
                         id="order_{{ $key }}" role="tabpanel" aria-labelledby="order_tab_{{ $key }}">
                        <div class="row">
                            <div class="col-xl-6">
                                <div class="card card-custom card-stretch gutter-b shadow">
                                    <div class="card-header">
                                        <div class="card-title">
                                            <span class="card-icon">
                                                <i class="fa fa-info-circle text-primary"></i>
                                            </span>
                                            <h3 class="card-label">{{ __('interface.CONDITION') }}</h3>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-borderless mb-0">
                                            <tr>
                                                <td class="font-weight-bold">{{ __('interface.CREATION_DATE') }}</td> 
                                                {{-- изменить перевод!!!!!!! --}}
                                                <td class="text-right">
                                                    <span class="text-info font-weight-bolder d-block font-size-lg">
                                                        {{ $shipment->shipment_date_create }}
                                                    </span>
                                                    {{-- @if ($shipment->order->orderDateTime != null)
                                                        <span class="text-muted font-weight-bold d-block">
                                                            {{ $shipment->order->orderDateTime }}
                                                        </span>
                                                    @endif --}}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    {{ __('interface.STATUS') }}
                                                </td>
                                                <td class="text-right">
                                                    <span
                                                        class="label label-inline label-{{ $statusesStyles[$shipment->status_code] }} font-weight-bold order_status py-xl-8">
                                                        {{ $shipment->status_name }}
                                                    </span>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td class="font-weight-bold">
                                                    {{ __('interface.SUM') }}
                                                </td>
                                                <td class="text-primary font-size-h3 font-weight-boldest text-right">
                                                    {{ $shipment->total_sum }}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-6">
                                <div class="card card-custom card-stretch gutter-b shadow order_info">
                                    <div class="card-header">
                                        <div class="card-title">
                                            <span class="card-icon">
                                                <i class="fa fa-info-circle text-primary"></i>
                                            </span>
                                            <h3 class="card-label">
                                                {{ __('interface.INTELLIGENCE') }}
                                            </h3>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-borderless mb-0">
                                            <tr>
                                                <td class="font-weight-bold">{{ __('interface.ORDER_NUMBER') }}</td>
                                                <td class="text-right">
                                                    <span class="text-primary font-size-h3 font-weight-boldest d-block">
                                                        {{ $shipment->order_code1c }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    <span class="d-block">{{ __('interface.PAYER') }}</span>
                                                    <span
                                                        class="text-muted d-block">{{ __('interface.ORDER_TIN') }}</span>
                                                </td>
                                                <td class="text-right">
                                                    <span class="text-primary font-weight-bolder d-block font-size-lg">
                                                        {{ $shipment->entity }}
                                                    </span>
                                                    <span class="text-muted d-block">
                                                        {{ $shipment->inn }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">{{ __('interface.SHIPMENT_DATE') }}</td>
                                                <td class="text-right">
                                                    <span class="text-info font-weight-bolder d-block font-size-lg">
                                                        {{ $shipment->shipment_date }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">{{ __('interface.TIME_INTERVAL') }}</td>
                                                <td class="text-right">
                                                    <span class="text-info font-weight-bolder d-block font-size-lg">
                                                        {{ $shipment->time_window_interval }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">{{ __('interface.COMMENT') }}</td>
                                                <td class="text-right">
                                                    @if ($shipment->comment)
                                                        <span class="font-weight-bold text-dark-75">
                                                            {{ $shipment->comment }}
                                                        </span>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-12">
                                <div class="card card-custom card-stretch gutter-b shadow">
                                    <div class="card-header">
                                        <div class="card-title">
                                            <span class="card-icon">
                                                <i class="fa fa-folder text-primary"></i>
                                            </span>
                                            <h3 class="card-label">
                                                {{ __('interface.ORDER_DOCUMENTS') }}
                                            </h3>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        @if (!empty($shipment->order->bills) || !empty($shipment->order->invoices) || !empty($shipment->order->invs))
                                            <div class="row flex-column flex-lg-row overflow-auto">
                                                @if (!empty($shipment->order->bills))
                                                    @foreach ($shipment->order->bills as $bill)
                                                        <div
                                                            class="d-flex align-items-center justify-content-between mb-5 col-12 col-lg-4">
                                                            <a href="{{ $bill['url'] }}" target='_blank'
                                                               class="d-flex align-items-center text-hover-primary ">
                                                                <div class="symbol symbol-circle symbol-50 mr-3">
                                                                    <span class="symbol-label"><i
                                                                            class="fa fa-file-contract icon-2x"></i></span>
                                                                </div>
                                                                <div class="d-flex flex-column">
                                                                    <span
                                                                        class="text-dark-75 font-weight-bold font-size-lg">
                                                                        {{ $bill['date'] }}
                                                                    </span>
                                                                    <span class="font-weight-bold font-size-sm">
                                                                        {{ $bill['text'] }}
                                                                    </span>
                                                                    <span
                                                                        class="text-dark-50 font-weight-bold font-size-sm">
                                                                        {{ __('interface.BILL_CONTRACT') }}
                                                                    </span>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                @endif
                                                @if (!empty($shipment->order->offers))
                                                    @foreach ($shipment->order->offers as $offer)
                                                        <div
                                                            class="d-flex align-items-center justify-content-between mb-5 col-12 col-lg-4">
                                                            <a href="{{ $offer['url'] }}" target='_blank'
                                                               class="d-flex align-items-center text-hover-primary ">
                                                                <div class="symbol symbol-circle symbol-50 mr-3">
                                                                    <span class="symbol-label"><i
                                                                            class="fa fa-file-contract icon-2x"></i></span>
                                                                </div>
                                                                <div class="d-flex flex-column">
                                                                    <span
                                                                        class="text-dark-75 font-weight-bold font-size-lg">
                                                                        {{ $offer['date'] }}
                                                                    </span>
                                                                    <span class="font-weight-bold font-size-sm">
                                                                        {{ $offer['text'] }}
                                                                    </span>
                                                                    <span
                                                                        class="text-dark-50 font-weight-bold font-size-sm">
                                                                        {{ __('interface.OFFER_DOC') }}
                                                                    </span>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                @endif
                                                @if (!empty($shipment->order->invoices))
                                                    @foreach ($shipment->order->invoices as $invoice)
                                                        <div
                                                            class="d-flex align-items-center justify-content-between mb-5 col-12 col-lg-4">
                                                            <a href="{{ $invoice['url'] }}" target='_blank'
                                                               class="d-flex align-items-center text-hover-primary ">
                                                                <div class="symbol symbol-circle symbol-50 mr-3">
                                                                    <span class="symbol-label"><i
                                                                            class="fa fa-file-invoice icon-2x"></i></span>
                                                                </div>
                                                                <div class="d-flex flex-column">
                                                                    <span
                                                                        class="text-dark-75 font-weight-bold font-size-lg">
                                                                        {{ $invoice['date'] }}
                                                                    </span>
                                                                    <span class="font-weight-bold font-size-sm">
                                                                        {{ $invoice['text'] }}
                                                                    </span>
                                                                    <span
                                                                        class="text-dark-50 font-weight-bold font-size-sm">
                                                                        {{ __('interface.INVOICE') }}
                                                                    </span>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                @endif
                                                @if (!empty($shipment->order->invs))
                                                    @foreach ($shipment->order->invs as $inv)
                                                        <div
                                                            class="d-flex align-items-center justify-content-between mb-5 col-4">
                                                            <a href="{{ $inv['url'] }}" target='_blank'
                                                               class="d-flex align-items-center text-hover-primary ">
                                                                <div class="symbol symbol-circle symbol-50 mr-3">
                                                                    <span class="symbol-label"><i
                                                                            class="fa fa-file-contract icon-2x"></i></span>
                                                                </div>
                                                                <div class="d-flex flex-column">
                                                                    <span
                                                                        class="text-dark-75 font-weight-bold font-size-lg">
                                                                        {{ $inv['date'] }}
                                                                    </span>
                                                                    <span class="font-weight-bold font-size-sm">
                                                                        {{ $inv['text'] }}
                                                                    </span>
                                                                    <span
                                                                        class="text-dark-50 font-weight-bold font-size-sm">
                                                                        {{ __('interface.INVOICE') }}
                                                                    </span>
                                                                </div>
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </div>
                                        @else
                                            <div class="alert alert-custom alert-outline alert-outline-warning show mb-0">
                                                <div class="alert-icon"><i class="flaticon-warning"></i></div>
                                                <div class="alert-text">{{ __('interface.ORDER_DOCUMENTS_FOR') }}
                                                    {{ $shipment->order->order_link_1c }}
                                                    {{ __('interface.HAVE_NOT_FOUND') }}.
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card card-custom gutter-b shadow">
                            <div class="card-header">
                                <div class="card-title">
                                    <span class="card-icon">
                                        <i class="fa fa-boxes text-primary"></i>
                                    </span>
                                    <h3 class="card-label">
                                        {{ __('interface.APPEAL_PRODUCTS') }}
                                    </h3>
                                </div>
                            </div>
                            <div class="card-body">
                                @if (!empty($shipment->products))
                                    <div class="table-responsive">
                                        <table class="table order_products_table">
                                            <thead>
                                            <tr class="bg-gray-100">
                                                <th></th>
                                                <th></th>
                                                <th>{{ __('interface.PRODUCT_NAME') }}</th>
                                                <th nowrap class="text-right">{{ __('interface.QUANTITY') }}
                                                </th>
                                                <th class="text-right">{{ __('interface.PRODUCT_PRICE') }}</th>
                                                <th class="text-right">{{ __('interface.SUM') }}</th>
                                                <th class="text-right min-w-120px">{!! __('interface.SHIPMENT_FINAL_DATE') !!}</th>
                                                <th class="text-right">{{ __('interface.COND_SHIPMENT') }}</th>
                                                <th class="text-right min-w-120px">{!! __('interface.SHIPMENT_DATE') !!}</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($shipment->products as $key => $item)
                                                @if($item->is_service)
                                                    <tr class="service">
                                                        <td class="align-top">
                                                            <span
                                                                class="text-muted font-weight-bold">{{ $key + 1 }}</span>
                                                        </td>
                                                        <td>
                                                            <div class="symbol symbol-white symbol-50 ">
                                                                <span class="symbol-label">
                                                                    <span class="svg-icon svg-icon-primary svg-icon-3x"><!--begin::Svg Icon | path:/var/www/preview.keenthemes.com/metronic/releases/2021-05-14-112058/theme/html/demo1/dist/../src/media/svg/icons/General/Expand-arrows.svg--><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24px" height="24px" viewBox="0 0 24 24" version="1.1">
                                                                        <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                                                            <polygon points="0 0 24 0 24 24 0 24"/>
                                                                            <path d="M12.9336061,16.072447 L19.36,10.9564761 L19.5181585,10.8312381 C20.1676248,10.3169571 20.2772143,9.3735535 19.7629333,8.72408713 C19.6917232,8.63415859 19.6104327,8.55269514 19.5206557,8.48129411 L12.9336854,3.24257445 C12.3871201,2.80788259 11.6128799,2.80788259 11.0663146,3.24257445 L4.47482784,8.48488609 C3.82645598,9.00054628 3.71887192,9.94418071 4.23453211,10.5925526 C4.30500305,10.6811601 4.38527899,10.7615046 4.47382636,10.8320511 L4.63,10.9564761 L11.0659024,16.0730648 C11.6126744,16.5077525 12.3871218,16.5074963 12.9336061,16.072447 Z" fill="#000000" fill-rule="nonzero"/>
                                                                            <path d="M11.0563554,18.6706981 L5.33593024,14.122919 C4.94553994,13.8125559 4.37746707,13.8774308 4.06710397,14.2678211 C4.06471678,14.2708238 4.06234874,14.2738418 4.06,14.2768747 L4.06,14.2768747 C3.75257288,14.6738539 3.82516916,15.244888 4.22214834,15.5523151 C4.22358765,15.5534297 4.2250303,15.55454 4.22647627,15.555646 L11.0872776,20.8031356 C11.6250734,21.2144692 12.371757,21.2145375 12.909628,20.8033023 L19.7677785,15.559828 C20.1693192,15.2528257 20.2459576,14.6784381 19.9389553,14.2768974 C19.9376429,14.2751809 19.9363245,14.2734691 19.935,14.2717619 L19.935,14.2717619 C19.6266937,13.8743807 19.0546209,13.8021712 18.6572397,14.1104775 C18.654352,14.112718 18.6514778,14.1149757 18.6486172,14.1172508 L12.9235044,18.6705218 C12.377022,19.1051477 11.6029199,19.1052208 11.0563554,18.6706981 Z" fill="#000000" opacity="0.3"/>
                                                                        </g>

                                                                    </svg><!--end::Svg Icon--></span>
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="text-dark-75 font-weight-bolder mb-1 font-size-lg"
                                                            >{{ $item->product_name }}</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->quantity }}
                                                                {{ __('interface.PCS') }}.</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            $shipment->order                                     <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->price }}</span>
                                                        </td>

                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->sum }}</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">-</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">-</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">-</span>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr class="md_report_item product"
                                                        data-md_report_item_code_1c="{{ $item->product_code1c }}"
                                                        data-md_report_item_quantity="{{ $item->quantity }}">
                                                        <td class="align-top">
                                                            <span
                                                                class="text-muted font-weight-bold">{{ $key + 1 }}</span>
                                                        </td>
                                                        <td>
                                                            <div class="symbol symbol-white symbol-50 ">
                                                                <span class="symbol-label">
                                                                    <img src="{{ $item->thumb }}"
                                                                         class="mw-60 mh-60 align-self-center"
                                                                         alt="{{ $item->product_name }}">
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <a class="text-dark-75 font-weight-bolder text-hover-primary mb-1 font-size-lg"
                                                               href="{{ sroute('catalog.product', [$item->product_code1c]) }}"
                                                               target="_blank">{{ $item->product_name }}</a>
                                                            <span
                                                                class="text-muted font-weight-bold d-block">{{ $item->product_code1c }}</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->quantity }}
                                                                {{ __('interface.PCS') }}.</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->price }}</span>
                                                        </td>

                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->sum }}</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            @if ($item->finalDateExpired)
                                                                <span
                                                                    class="text-danger label label-lg label-light-danger label-inline font-weight-bold">{{ $item->final_shipment_date }}</span>
                                                            @elseif($item->finalDateComing)
                                                                <span
                                                                    class="text-warning label label-lg label-light-warning label-inline font-weight-bold">{{ $item->final_shipment_date }}</span>
                                                            @else
                                                                <span
                                                                    class="text-dark-75 font-weight-bold d-block">{{ $item->final_shipment_date }}</span>
                                                            @endif
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                            class="text-dark-75 font-weight-bold d-block">{{ $item->shipment_status }}</span>
                                                        </td>
                                                        <td nowrap class="text-right">
                                                            <span
                                                                class="text-dark-75 font-weight-bold d-block">{{ $item->shipment_date }}</span>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="alert alert-custom alert-outline alert-outline-warning show mb-0">
                                        <div class="alert-icon"><i class="flaticon-warning"></i></div>
                                        <div class="alert-text">{{ __('interface.ORDER_PRODUCTS') }}
                                            {{ $shipment->order->order_link_1c }} {{ __('interface.HAVE_NOT_FOUND') }}.</div>
                                    </div>
                                @endif
                            </div>
                        </div>

                    </div>
                @endforeach
            </div>
        </div>
    </div>


@endsection
