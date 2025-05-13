var productState = 'all';

$(document).ready(function () {

    if ($("#product-gallery").length) {
        var galleryTop = new Swiper('.gallery-top', {
            spaceBetween: 10,
            autoHeight: true,
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
            keyboard: {
                enabled: true,
            },
        });

        var galleryThumbs = new Swiper('.gallery-thumbs', {
            spaceBetween: 10,
            centeredSlides: true,
            slidesPerView: 'auto',
            touchRatio: 0.2,
            slideToClickedSlide: true
        });
        galleryTop.controller.control = galleryThumbs;
        galleryThumbs.controller.control = galleryTop;
    }

    $('#product_quick_panel_toggle').click(function () {
        $('#kt_quick_panel_toggle').click();
    });

});

$(document).on('click', 'a[data-type=local_href]', function (event) {
    event.preventDefault();

    let e = $(this);
    let target = e.data('target'); // css селектор элемента, до которого прокручивать (id или уникальный стиль)
    let indent = e.data('indent'); // отступ, если есть фиксированная шапка, если нет поставить 0

    $('html, body').animate({
        scrollTop: $(target).offset().top - indent
    }, 500);

    return false;
});

function createDatatable(params) {
    var id = $("input[name=code1c]").val();
    var uid = $("input[name=uid]").val();
    var onPage = getCookie('eq-catalog-perpage');
    if ((onPage == 0) || (onPage == null)) {
        onPage = 10;
    }


    if ($("#onstock_product").length) {

        var subtableColumns = [
            {
                field: 'title',
                title: '<span>' + window.get_translate('CATALOG_DETAIL', 'STOCK') + '</span>',
                sortable: !1,
                autoHide: false,
                template: function (data) {
                    let stockTitle = ''
                    if (data.hasCoordinates && pricesAvailable) {
                        stockTitle = '<a href="javascript:;" class="modal_stock__info dashed text-dark-75" data-stock-uid="' + data.uid + '">' + data.title + '</a>';
                    } else {
                        stockTitle = data.title;
                    }
                    return '<span style="width: 150px;" class="text-dark-75 font-weight-bolder d-block font-size-lg"> <i class="fa fa-map-marker-alt icon-nm text-danger mr-1"></i>' + stockTitle + '</span>';
                }
            }, {
                field: 'state',
                title: '<span>' + window.get_translate('CATALOG_DETAIL', 'CONDITION') + '</span>',
                textAlign: "right",
                template: function (data) {
                    if (data.stateSign == 'sale') {
                        return '<span class="text-danger font-weight-bolder">' + data.state + '</span>';
                    } else {
                        return '<span class="text-success font-weight-bolder">' + data.state + '</span>';
                    }
                }
            }, {
                field: 'availabilityStatus',
                title: window.get_translate('CATALOG_DETAIL', 'RESERVE'),
                textAlign: "left",
                template: function (data) {
                    let availInfo = '';
                    availInfo += '<span class="d-block availabilityStatus">';
                    if (data.onStockFree > 0) {
                        availInfo += '<span class="d-block font-size-sm font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'AVAILABLE') + ' ' + data.onStockFree + ' ' + window.get_translate('CATALOG_DETAIL', 'PCS') + '</span>';
                    } else {
                        availInfo += '<span class="d-block text-muted font-size-sm font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'NOT_AVAILABLE') + '</span>';
                    }
                    if (data.onStockUnpaid > 0) {
                        availInfo += '<span class="font-weight-bold text-muted font-size-sm d-block pl-5">' + data.onStockUnpaid + ' ' + window.get_translate('CATALOG_DETAIL', 'PCS') + '. - ' + window.get_translate('CATALOG_DETAIL', 'UNPAID_RESERVE') + '</span>';
                    }

                    if (data.stockSupplies && !data.virtual) {
                        let text_muted = '';
                        if (data.onStockFree > 0) {
                            text_muted = 'text-muted';
                        }
                        availInfo += '<span class="d-block font-size-sm font-weight-bold ' + text_muted + '">' + window.get_translate('CATALOG_DETAIL', 'SUPPLIES_ON_STOCK') + ': </span>';
                        data.stockSupplies.forEach(function (supply) {
                            availInfo += '<span class="font-weight-bold d-block nowrap font-size-sm pl-5 ' + text_muted + '">' + supply.date + ' / ' + supply.free + ' ' + window.get_translate('CATALOG_DETAIL', 'PCS') + '. </span>';
                            if (supply.unpaid > 0) {
                                availInfo += `<span class="font-weight-bold text-muted font-size-xs nowrap pl-5">${supply.unpaid} ${window.get_translate('CATALOG_DETAIL', 'PCS')} — ${window.get_translate('CATALOG_DETAIL', 'UNPAID_RESERVE')}</span>`;
                            }
                        });
                    } else if (!data.stockSupplies && !data.virtual) {
                        availInfo += '<span class="d-block font-size-sm text-muted font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'NOT_IN_SUPPLIES') + '</span>';
                    } else {
                        availInfo += '<span class="d-block font-size-sm text-muted font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'DELIVERY_IS_NOT_CARRIES_OUT') + '</span>';
                    }

                    let delivery_text = '';
                    if (data.direct_delivery == 1) {
                        switch (data.deliveryTime) {
                            case 'not_defined':
                            case null:
                                delivery_text += window.get_translate('CATALOG_DETAIL', 'CHECK_WITH_THE_MANAGER');
                                break;
                            case 'not_carry':
                                delivery_text += window.get_translate('CATALOG_DETAIL', 'DO_NOT_CARRY_ANYMORE');
                                break;
                            default:
                                delivery_text += window.get_translate('CATALOG_DETAIL', 'UNSER_ORDER') + ' ' + data.deliveryTime + ' ' + window.get_translate('CATALOG_DETAIL', 'DAYS');
                        }
                        if (data.onStockFree > 0 || data.stockSupplies) {
                            availInfo += '<span class="d-block font-size-sm text-muted font-weight-bold">' + delivery_text + '</span>';
                        } else {
                            availInfo += '<span class="d-block font-size-sm font-weight-bold">' + delivery_text + '</span>';
                        }
                    } else {
                        if (!data.virtual) {
                            if (data.onStockFree > 0 && data.deliveryTime != "not_carry") {
                                availInfo += '<span class="d-block font-size-sm font-weight-bold text-muted">' + window.get_translate('CATALOG_DETAIL', 'CHECK_WITH_THE_MANAGER') + '</span>';
                            } else if (!data.onStockFree && data.deliveryTime != "not_carry") {
                                availInfo += '<span class="d-block font-size-sm font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'CHECK_WITH_THE_MANAGER') + '</span>';
                            } else {
                                availInfo += '<span class="d-block font-size-sm font-weight-bold">' + window.get_translate('CATALOG_DETAIL', 'DO_NOT_CARRY_ANYMORE') + '</span>';
                            }
                        }
                    }

                    availInfo += '</span>';
                    return availInfo;
                }
            }
        ];

        if (pricesAvailable) {
            subtableColumns.push({
                field: 'price',
                title: '',
                // width: 150,
                textAlign: "right",
                template: function (data) {
                    let prices = '';

                    let price = data.price;
                    let priceBase = data.priceBase;
                    let priceNotSale = data.priceNotSale;
                    let priceBaseNotSale = data.priceBaseNotSale;
                    let priceWithDiscounts = '';
                    let tooltipDiscounts = '';
                    let discountDescription = '';
                    let tooltipDiscount1c = '';
                    let discountDescription1c = '';
                    let priceOnlyWithDiscount1cValue = data.priceOnlyWithDiscount1cValue;
                    if((data.discount_portal !== null) && typeof data.discount_portal.description !== 'undefined') {
                        console.log('data.discount_portal');
                            discountDescription = data.discount_portal.description+' '+window.get_translate('MAIN','FOR_ONLINE_REGISTRATION');// window.get_translate('MAIN', 'NO_INFORMATION'))
                            tooltipDiscounts = '<span class="label label-light-primary label-pill label-inline" data-toggle="tooltip" data-html="true" title="'+discountDescription+'">ONLINE</span>';
                    }
                    
                    if(data.promocode)
                        {
                        let discountPromocode = data.promo_tooltip_description;
                        if(data.discount_1c) {
                            discountPromocode = data.discount_1c.description;
                        }
                        priceWithDiscounts = data.priceWithDiscountsValue;
                        tooltipDiscounts = '<span data-toggle="tooltip" data-html="true" title="'+discountPromocode+'">'+discountSvg+''+'</span> / ';
                    }else if(showDiscounts == 1 && data.priceWithDiscountsValue !== price) {
                        priceWithDiscounts = data.priceWithDiscountsValue;
                        if(data.discount_1c)
                        {
                            discountDescription += '<br>'+data.discount_1c.description;
                            discountDescription1c += data.discount_1c.description;
                        }
                        if (data.discount_portal) {
                            tooltipDiscount1c = '<span data-toggle="tooltip" data-html="true" title="'+discountDescription1c+'">'+discountSvg+''+'</span> ';
                            tooltipDiscounts = '<span class="label label-light-primary label-pill label-inline" data-toggle="tooltip" data-html="true" title="'+discountDescription+'">ONLINE</span>';

                        } else {
                            tooltipDiscounts = '<span data-toggle="tooltip" data-html="true" title="'+discountDescription+'">'+discountSvg+''+'</span> ';
                        }
                    }


                    let tooltipNds = '';
                    let tooltipNdsSale = '';
                    tooltipNds = data.tooltip;
                    tooltipNdsSale =data.saleTooltip;
                    let tooltipPromocodes = '';
                    let tooltipOnline = '';
                    let tooltipPromo = '';
                    if(data.promo)
                        {
                            console.log('data.promo');
                            
                            tooltipPromo = '<span data-toggle="tooltip" data-html="true" title="'+data.promo_tooltip_description+'">'+discountSvg+'</span>';
                        }
                        tooltipPromocodes = '<span class="label label-light-primary label-pill label-inline" data-toggle="tooltip" data-html="true" title="'+data.promo_tooltip_description+'">PROMO</span>';
                    // console.log(priceWithDiscounts, data.discount, data.price);

                    if (data.stateSign == 'sale') {
                        // console.log('sale');
                        if (price == window.get_translate('MAIN', 'NO_INFORMATION')) {
                            price = window.get_translate('MAIN', 'NO_INFO');
                        }
                        if (priceNotSale == window.get_translate('MAIN', 'NO_INFORMATION')) {
                            priceNotSale = window.get_translate('MAIN', 'NO_INFO');
                        }
                        prices += '<span class="font-weight-bolder d-lg-block font-size-lg text-nowrap"><span class="text-danger">' + price + '</span><span class="text-dark text-left font-size-sm font-weight-bolder"> / <s>' + priceNotSale + '</s>' + tooltipNdsSale + '</span></span>';
                        prices += '<span class="text-muted text-left font-size-xs font-weight-bolder text-nowrap">' + data.priceBase + '<span class="text-muted text-left font-size-xs font-weight-bolder"> / <s>' + data.priceBaseNotSale + '</s></span></span>';
                    } else {
                        if(data.promocode){
                            console.log('data.promocode');
                            console.log(data.promocode.value);

                            prices += '<span class="text-dark font-weight-bolder d-lg-block font-size-lg">' + priceWithDiscounts + ' ' + tooltipPromocodes + '<span class="font-size-sm"></span></span>'
                            if (priceOnlyWithDiscount1cValue !== data.price) {
                                prices += '<span class="text-dark font-weight-bolder d-lg-block font-size-lg">' + priceOnlyWithDiscount1cValue + ' ' + tooltipDiscounts + '<span class="font-size-sm"></span></span>'
                            }
                            prices += '<span class="text-muted text-left font-size-xs font-weight-bolder text-nowrap">' + data.price + tooltipNds + '</span>';
                        }
                        else if(priceWithDiscounts !== '' && data.discount !== null) {
                            prices += '<span class="text-dark font-weight-bolder d-lg-block font-size-lg">' + priceWithDiscounts + tooltipDiscounts + '<span class="font-size-sm"></span></span>'
                            if (priceOnlyWithDiscount1cValue !== data.price) {
                                prices += '<span class="text-dark font-weight-bolder d-lg-block font-size-lg">' + priceOnlyWithDiscount1cValue + ' ' + tooltipDiscount1c + '<span class="font-size-sm"></span></span>'
                            }
                            prices += '<span class="text-muted text-left font-size-xs font-weight-bolder text-nowrap">' + data.price + tooltipNds + '</span>';
                        }
                        else {
                            console.log('data.price');
                            prices += '<span class="text-dark font-weight-bolder d-lg-block font-size-lg">' + data.price + tooltipPromo + tooltipNds + '</span>'
                            // prices += '<span class="text-muted text-left font-size-xs font-weight-bolder text-nowrap">' + data.priceBase + '</span>';
                        }
                        if(data.appliablePromocode){
                            prices += '<span class="font-weight-bold d-block font-size-sm pt-3"><span class="text-dark">-'+data.appliablePromocode.value+'% ' + window.get_translate('CATALOG_DETAIL', 'BY_PROMOCODE') + '<code>'+data.appliablePromocode.code+'</code></span></span>' ;
                        }
                    }

                    if (data.price_request == 1 && (data.is_dishes == 0 || data.has_price == 0 || data.available_on_stock == 0)) {
                        prices = '<span class="text-muted text-left font-size-xs font-weight-bolder">' + window.get_translate("CATALOG_DETAIL", "PRICE_ON_REQUEST") + '</span>';
                    }
                    return prices;
                }
            });
        }

        subtableColumns.push({
            field: 'action',
            textAlign: "right",
            title: '',
            sortable: false,
            template: function (data) {
                return '<span class="eq-maintable btn-render-container">' + data.c_btn__render + '</span>';
            },
        });



        var datatableOnstockProduct = $('#onstock_product').KTDatatable({
            data: {
                type: 'remote',
                source: {
                    read: {
                        url: '/catalog/get_offers/',
                        params: {
                            _token: $('meta[name="csrf-token"]').attr('content'),
                            product_id: uid,
                            state: $('#state-select').val(),
                            here: window.location.href
                        },
                    },
                },
                pageSize: 100,
                serverPaging: true,
                serverFiltering: false,
                serverSorting: true,
            },

            pagination: false,

            layout: {
                scroll: true,
                footer: false,
                class: 'eq-subtable',
                spinner: {
                    type: 1,
                    theme: 'default',
                },
            },
            translate: {
                records: {
                    processing: window.get_translate('MAIN', 'DATATABLE_LOADING'),
                    noRecords: window.get_translate('MAIN', 'DATATABLE_NO_RECORDS')
                },
                toolbar: {
                    pagination: {
                        items: {
                            default: {
                                first: window.get_translate('MAIN', 'DATATABLE_FIRST'),
                                prev: window.get_translate('MAIN', 'DATATABLE_PREV'),
                                next: window.get_translate('MAIN', 'DATATABLE_NEXT'),
                                last: window.get_translate('MAIN', 'DATATABLE_LAST'),
                                more: window.get_translate('MAIN', 'DATATABLE_MORE'),
                                input: window.get_translate('MAIN', 'DATATABLE_PAGE_NUMBER'),
                                select: window.get_translate('MAIN', 'DATATABLE_SELECT'),
                                all: window.get_translate('MAIN', 'DATATABLE_ALL')
                            },
                            info: window.get_translate('MAIN', 'DATATABLE_INFO')
                        }
                    }
                }
            },

            sortable: false,
            columns: subtableColumns
        }).on('datatable-on-layout-updated', function (event, args) {
            $('[data-toggle="tooltip"]').tooltip();
        });
    }
};

$(document).ready(function () {
    var params = [];
    params._token = $('meta[name="csrf-token"]').attr('content');
    createDatatable(params);

    function loadOtherPage() {
        let id = $("input[name=code1c]").val();

        Swal.fire({
            title: window.get_translate('CATALOG_DETAIL', 'PLEASE_WAIT'),
            allowOutsideClick: true,
            showCancelButton: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading()
            },
        });

        $("<iframe>")
            .hide()
            .attr("src", "/catalog/product/print_product_pdf/" + id + "/")
            .appendTo("body").on("load", function () {
                swal.close();
            });

    }
    $('.detail-card-btn-print').on('click', loadOtherPage);
});

