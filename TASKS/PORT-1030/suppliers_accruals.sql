create table suppliers_accruals
(
    `ID`                 int auto_increment,
    `DATE_CREATE`        datetime                default current_timestamp() not null,
    `DATE_UPDATE`        datetime                default current_timestamp() not null on update current_timestamp(),
    `STATUS`             varchar(20)    not null,
    `PRODUCT_STATUS`     varchar(20)    not null,
    `SUPPLIER`           varchar(20)    not null,
    `SUPPLY_CODE`        varchar(50)    not null,
    `TVP_CODE_1C`        varchar(50)    not null,
    `SDE_CODE_1C`        varchar(50)    not null,
    `ORDER_CODE_1C`      varchar(50)    null,
    `PURCHASE_INVOICE`   varchar(50)    not null,
    `TYPE`               varchar(20)    not null,
    `STOCK_CODE`         varchar(50)    not null,
    `STOCK_TO`           varchar(50)    null     default null,
    `PRODUCT_CODE`       varchar(50)    not null,
    `PRODUCT_EXT_ID`     varchar(50)    not null,
    `VOLUME`             DOUBLE(12, 2)  not null,
    `DATE_START`         DATE           not null,
    `DATE_END`           DATE           null,
    `DAY_SUM`            DECIMAL(20, 2) not null default 0.00,
    `TOTAL_SUM`          DECIMAL(20, 2) not null default 0.00,
    `CURRENCY_CODE`      varchar(10)    not null,
    `AVAILABILITY_ID`    int(11)        null     default null,
    `MOVEMENT_ID`        int(11)        null     default null,
    `MOVEMENT_CODE_1C`   varchar(50)    null     default null,
    `ZNO_ID`             int(11)        null     default null,
    `ZNO_CODE_1C`        varchar(50)    null     default null,
    `GUID`               varchar(50)    not null,
    `PAYMENT_PRODUCT_ID` int(11)        null     default null,
    `PAYMENT_CODE`       varchar(50)    null     default null,
    `CONDITION`          text           null,
    primary key (ID),
    constraint `CONDITION`
        check (json_valid(`CONDITION`))
)
    charset = utf8mb4
    with system versioning;